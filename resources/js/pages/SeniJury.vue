<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Delete } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { storeJuryScore } from '@/actions/App/Http/Controllers/Api/SeniScoringController';
import FightFullscreenButton from '@/components/fight/FightFullscreenButton.vue';
import FightWaitingState from '@/components/fight/FightWaitingState.vue';
import { useFullscreenLock } from '@/composables/useFullscreenLock';

type ScoreKey =
    | 'wiraga'
    | 'wirasa'
    | 'wirama'
    | 'kualitas_teknik'
    | 'kuantitas_teknik'
    | 'ketangkasan'
    | 'stamina'
    | 'kemantapan'
    | 'musik';

type PunishmentKey =
    | 'waktu'
    | 'keluar_garis'
    | 'senjata_jatuh_atau_tidak_sesuai_deskripsi'
    | 'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi'
    | 'akeseoris_jatuh';

type ScoreCriterion = {
    key: ScoreKey;
    label: string;
    min: number;
    max: number;
};

type PunishmentCriterion = {
    key: PunishmentKey;
    label: string;
    amount: number;
};

type SeniJuryScore = Partial<Record<ScoreKey, string | number | null>> & {
    jury_number: number;
};

type SeniJuryPunishment = Partial<
    Record<PunishmentKey | 'keluar garis', string | number | null>
> & {
    jury_number: number;
};

type SeniMatch = {
    id: number;
    matches_code: string | null;
    atletes: string | string[] | null;
    contingent: string | null;
    type: string | null;
    category: string | null;
    group: string | null;
    round_match: string | null;
    status: string;
    is_active: boolean;
    no_order: number | null;
    jury_scores?: SeniJuryScore[];
    jury_punishments?: SeniJuryPunishment[];
};

const props = defineProps<{
    arena: any;
    activeMatch?: SeniMatch | null;
}>();

const page = usePage<any>();
const userName = computed(() => page.props.auth?.user?.name || 'Juri 1');
const currentMatch = ref<SeniMatch | null>(props.activeMatch ?? null);
const selectedScoreKey = ref<ScoreKey>('wiraga');
const isReloadingScoreState = ref(false);
const saveQueues = new Map<string, Promise<boolean>>();

const {
    buttonTitle,
    exitClickCount,
    isFullscreen,
    remainingExitClicks,
    requiredExitClicks,
    triggerFullscreen,
} = useFullscreenLock();

const tgrCriteria: ScoreCriterion[] = [
    { key: 'wiraga', label: 'Wiraga', min: 40, max: 100 },
    { key: 'wirasa', label: 'Wirasa', min: 20, max: 80 },
    { key: 'wirama', label: 'Wirama', min: 10, max: 60 },
];

const techniqueCriteria: ScoreCriterion[] = [
    { key: 'kualitas_teknik', label: 'Kualitas Teknik', min: 40, max: 100 },
    { key: 'kuantitas_teknik', label: 'Kuantitas Teknik', min: 20, max: 80 },
    { key: 'ketangkasan', label: 'Ketangkasan', min: 20, max: 80 },
    { key: 'stamina', label: 'Stamina', min: 10, max: 70 },
    { key: 'kemantapan', label: 'Kemantapan', min: 10, max: 70 },
    { key: 'musik', label: 'Musik / Irama', min: 10, max: 70 },
];

const tgrPunishments: PunishmentCriterion[] = [
    { key: 'waktu', label: 'Waktu', amount: 5 },
    { key: 'keluar_garis', label: 'Setiap kali keluar garis', amount: 5 },
    {
        key: 'senjata_jatuh_atau_tidak_sesuai_deskripsi',
        label: 'Senjata jatuh tidak sesuai deskripsi',
        amount: 10,
    },
    { key: 'akeseoris_jatuh', label: 'Aksesoris jatuh', amount: 5 },
];

const techniquePunishments: PunishmentCriterion[] = [
    { key: 'waktu', label: 'Waktu', amount: 5 },
    { key: 'keluar_garis', label: 'Setiap kali keluar garis', amount: 5 },
    {
        key: 'senjata_jatuh_atau_tidak_sesuai_deskripsi',
        label: 'Senjata jatuh tidak sesuai deskripsi',
        amount: 10,
    },
    {
        key: 'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi',
        label: 'Senjata tidak jatuh sesuai deskripsi',
        amount: 10,
    },
];

const scoreDraft = reactive<Record<ScoreKey, number>>({
    wiraga: 40,
    wirasa: 20,
    wirama: 10,
    kualitas_teknik: 40,
    kuantitas_teknik: 20,
    ketangkasan: 20,
    stamina: 10,
    kemantapan: 10,
    musik: 10,
});

const punishmentCounts = reactive<Record<PunishmentKey, number>>({
    waktu: 0,
    keluar_garis: 0,
    senjata_jatuh_atau_tidak_sesuai_deskripsi: 0,
    senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi: 0,
    akeseoris_jatuh: 0,
});

const juryNumber = computed(() => {
    const match = userName.value.match(/\d+/);

    return match ? parseInt(match[0], 10) : 1;
});

const juryLabel = computed(() => {
    if (userName.value.toLowerCase().includes('juri')) {
        return userName.value;
    }

    return `Juri ${juryNumber.value}`;
});

const isLoading = computed(() => {
    return (
        isReloadingScoreState.value ||
        !currentMatch.value ||
        currentMatch.value.status !== 'ongoing'
    );
});

const isTechniqueMatch = (match: SeniMatch | null | undefined) => {
    const matchText = [match?.type, match?.category, match?.group]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return matchText.includes('ganda') || matchText.includes('trio');
};

const scoreCriteria = computed(() => {
    return isTechniqueMatch(currentMatch.value)
        ? techniqueCriteria
        : tgrCriteria;
});

const punishmentCriteria = computed(() => {
    return isTechniqueMatch(currentMatch.value)
        ? techniquePunishments
        : tgrPunishments;
});

const matchMetaLabel = computed(() => {
    return [
        currentMatch.value?.round_match,
        currentMatch.value?.group,
        currentMatch.value?.category,
    ]
        .filter(Boolean)
        .join(' ');
});

const athleteNames = computed(() => {
    const athletes = currentMatch.value?.atletes;

    if (Array.isArray(athletes)) {
        return athletes.filter(Boolean);
    }

    if (!athletes) {
        return ['-'];
    }

    return athletes
        .split(/[,;|]/)
        .map((athlete) => athlete.trim())
        .filter(Boolean);
});

const totalScores = computed(() => {
    return scoreCriteria.value.reduce(
        (total, criterion) => total + scoreDraft[criterion.key],
        0,
    );
});

const totalPunishments = computed(() => {
    return punishmentCriteria.value.reduce((total, punishment) => {
        return total + punishmentCounts[punishment.key] * punishment.amount;
    }, 0);
});

const finalScore = computed(() => {
    return totalScores.value - totalPunishments.value;
});

const selectedCriterion = computed(() => {
    return (
        scoreCriteria.value.find(
            (criterion) => criterion.key === selectedScoreKey.value,
        ) ?? scoreCriteria.value[0]
    );
});

const selectedScoreValues = computed(() => {
    const criterion = selectedCriterion.value;

    if (!criterion) {
        return [];
    }

    return Array.from(
        {
            length: criterion.max - criterion.min + 1,
        },
        (_, index) => criterion.min + index,
    );
});

const clampScore = (criterion: ScoreCriterion, value: number) => {
    return Math.min(criterion.max, Math.max(criterion.min, value));
};

const persistJuryInput = async (
    matchId: number,
    type: 'score' | 'punishment',
    field: ScoreKey | PunishmentKey,
    value: number,
) => {
    try {
        await axios.post(storeJuryScore.url(matchId), {
            jury_number: juryNumber.value,
            type,
            field,
            value,
        });

        return true;
    } catch (e) {
        console.error('Failed to save seni jury score:', e);

        return false;
    }
};

const queueJuryInput = (
    matchId: number,
    type: 'score' | 'punishment',
    field: ScoreKey | PunishmentKey,
    value: number,
) => {
    const queueKey = `${type}:${field}`;
    const previousSave = saveQueues.get(queueKey) ?? Promise.resolve(true);
    const queuedSave = previousSave
        .catch(() => true)
        .then(() => persistJuryInput(matchId, type, field, value));

    saveQueues.set(queueKey, queuedSave);

    queuedSave.finally(() => {
        if (saveQueues.get(queueKey) === queuedSave) {
            saveQueues.delete(queueKey);
        }
    });

    return queuedSave;
};

const setScore = (criterion: ScoreCriterion, value: number) => {
    if (!currentMatch.value || currentMatch.value.status !== 'ongoing') {
        return;
    }

    const matchId = currentMatch.value.id;
    const previousValue = scoreDraft[criterion.key];
    const nextValue = clampScore(criterion, value);
    scoreDraft[criterion.key] = nextValue;

    queueJuryInput(matchId, 'score', criterion.key, nextValue).then((saved) => {
        if (
            !saved &&
            currentMatch.value?.id === matchId &&
            scoreDraft[criterion.key] === nextValue
        ) {
            scoreDraft[criterion.key] = previousValue;
        }
    });
};

const adjustPunishment = (punishment: PunishmentCriterion, amount: number) => {
    if (!currentMatch.value || currentMatch.value.status !== 'ongoing') {
        return;
    }

    const matchId = currentMatch.value.id;
    const previousCount = punishmentCounts[punishment.key];
    const nextCount = Math.max(0, previousCount + amount);

    punishmentCounts[punishment.key] = nextCount;

    queueJuryInput(
        matchId,
        'punishment',
        punishment.key,
        nextCount * punishment.amount,
    ).then((saved) => {
        if (
            !saved &&
            currentMatch.value?.id === matchId &&
            punishmentCounts[punishment.key] === nextCount
        ) {
            punishmentCounts[punishment.key] = previousCount;
        }
    });
};

const selectCriterion = (criterion: ScoreCriterion) => {
    selectedScoreKey.value = criterion.key;
};

const formatScore = (value: number) => {
    return value.toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
    });
};

const currentJuryScore = (match: SeniMatch | null) => {
    return (
        match?.jury_scores?.find(
            (score) => Number(score.jury_number) === juryNumber.value,
        ) ?? null
    );
};

const currentJuryPunishment = (match: SeniMatch | null) => {
    return (
        match?.jury_punishments?.find(
            (punishment) => Number(punishment.jury_number) === juryNumber.value,
        ) ?? null
    );
};

const punishmentCountFromValue = (
    punishment: SeniJuryPunishment | null,
    criterion: PunishmentCriterion,
) => {
    const rawValue =
        criterion.key === 'keluar_garis'
            ? (punishment?.keluar_garis ?? punishment?.['keluar garis'])
            : punishment?.[criterion.key];

    const numericValue = Math.abs(Number(rawValue ?? 0));

    if (!numericValue) {
        return 0;
    }

    return Math.round(numericValue / criterion.amount);
};

const resetDraftFromMatch = (
    match: SeniMatch | null,
    options: { resetSelectedCriterion?: boolean } = {},
) => {
    for (const criterion of scoreCriteria.value) {
        scoreDraft[criterion.key] = criterion.min;
    }

    for (const punishment of Object.keys(punishmentCounts) as PunishmentKey[]) {
        punishmentCounts[punishment] = 0;
    }

    const juryScore = currentJuryScore(match);
    const juryPunishment = currentJuryPunishment(match);

    for (const criterion of scoreCriteria.value) {
        const value = Number(juryScore?.[criterion.key] ?? criterion.min);
        scoreDraft[criterion.key] = clampScore(criterion, value);
    }

    for (const punishment of punishmentCriteria.value) {
        punishmentCounts[punishment.key] = punishmentCountFromValue(
            juryPunishment,
            punishment,
        );
    }

    if (
        options.resetSelectedCriterion ||
        !scoreCriteria.value.some(
            (criterion) => criterion.key === selectedScoreKey.value,
        )
    ) {
        selectedScoreKey.value = scoreCriteria.value[0]?.key ?? 'wiraga';
    }
};

const getMatchId = (match: SeniMatch | null | undefined) => {
    return Number(match?.id ?? 0);
};

const shouldReloadScoreStateFromDatabase = (
    updatedMatch: SeniMatch | null | undefined,
) => {
    if (!updatedMatch) {
        return false;
    }

    if (!currentMatch.value) {
        return true;
    }

    return (
        getMatchId(currentMatch.value) !== getMatchId(updatedMatch) ||
        (currentMatch.value.status === 'not_started' &&
            updatedMatch.status === 'ongoing')
    );
};

const reloadScoreStateFromDatabase = (
    options: { resetSelectedCriterion?: boolean } = {},
) => {
    isReloadingScoreState.value = true;

    router.reload({
        only: ['activeMatch'],
        onSuccess: (page: any) => {
            const activeMatch = (page.props.activeMatch ??
                null) as SeniMatch | null;

            currentMatch.value = activeMatch;
            resetDraftFromMatch(activeMatch, {
                resetSelectedCriterion: options.resetSelectedCriterion,
            });
        },
        onFinish: () => {
            isReloadingScoreState.value = false;
        },
    });
};

watch(
    () => props.activeMatch,
    (match) => {
        currentMatch.value = match ?? null;
    },
    { deep: true },
);

watch(
    () => [
        currentMatch.value?.id,
        currentMatch.value?.type,
        currentMatch.value?.status,
        juryNumber.value,
    ],
    (currentValues, previousValues) => {
        const [matchId, matchType, matchStatus, currentJuryNumber] =
            currentValues;
        const [
            previousMatchId,
            previousMatchType,
            previousMatchStatus,
            previousJuryNumber,
        ] = previousValues ?? [];
        const isNewMatch = matchId !== previousMatchId;
        const isDifferentMatchType = matchType !== previousMatchType;
        const isDifferentJury = currentJuryNumber !== previousJuryNumber;
        const isStartingMatch =
            previousMatchStatus === 'not_started' && matchStatus === 'ongoing';

        resetDraftFromMatch(currentMatch.value, {
            resetSelectedCriterion:
                isNewMatch ||
                isDifferentMatchType ||
                isDifferentJury ||
                isStartingMatch,
        });
    },
    { immediate: true },
);

let echoStatusChannel: any = null;

onMounted(() => {
    const echo = (window as any).Echo;

    if (echo) {
        echoStatusChannel = echo
            .channel('seni.match.status')
            .listen('.SeniMatchUpdated', (event: any) => {
                if (event.match) {
                    const shouldReload = shouldReloadScoreStateFromDatabase(
                        event.match,
                    );

                    if (shouldReload) {
                        reloadScoreStateFromDatabase({
                            resetSelectedCriterion: true,
                        });

                        return;
                    }

                    currentMatch.value = event.match;
                }
            });
    }
});

onUnmounted(() => {
    if (echoStatusChannel) {
        echoStatusChannel.stopListening('.SeniMatchUpdated');

        const echo = (window as any).Echo;

        if (echo) {
            echo.leaveChannel('seni.match.status');
        }
    }
});
</script>

<template>
    <Head title="Juri Seni - Tapak Suci" />

    <div class="flex h-screen overflow-hidden bg-zinc-950 text-foreground">
        <template v-if="isLoading">
            <FightWaitingState clickable :on-logo-click="triggerFullscreen" />
        </template>

        <template v-else>
            <div class="relative z-10 flex h-full w-full flex-col">
                <div
                    class="grid h-11 w-full shrink-0 grid-cols-[1fr_auto_1fr] items-center border-b border-stone-800 bg-zinc-900 px-4 text-[10px] font-bold tracking-[0.18em] text-muted-foreground uppercase shadow-sm"
                >
                    <div class="flex items-center gap-3 justify-self-start">
                        <FightFullscreenButton
                            :exit-click-count="exitClickCount"
                            :is-fullscreen="isFullscreen"
                            :remaining-exit-clicks="remainingExitClicks"
                            :required-exit-clicks="requiredExitClicks"
                            :title="buttonTitle"
                            :on-trigger="triggerFullscreen"
                        />
                        <span class="font-black text-yellow-500">{{
                            juryLabel
                        }}</span>
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span>
                            Gelanggang
                            {{
                                props.arena?.arena_name ??
                                props.arena?.gelanggang_id ??
                                '-'
                            }}
                        </span>
                    </div>

                    <div class="px-4 text-center text-[10px] text-white">
                        {{ matchMetaLabel || '-' }}
                    </div>

                    <div class="flex items-center gap-3 justify-self-end">
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span>
                            No Partai
                            <span class="ml-1 text-xs text-white tabular-nums">
                                {{ currentMatch?.matches_code ?? '-' }}
                            </span>
                        </span>
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span>
                            No Penampil
                            <span class="ml-1 text-xs text-white tabular-nums">
                                {{ currentMatch?.no_order ?? '-' }}
                            </span>
                        </span>
                    </div>
                </div>

                <div
                    class="z-10 flex min-h-[6.25rem] w-full shrink-0 items-center justify-center border-b border-yellow-600/40 bg-yellow-400 px-6 py-3.5 text-black shadow-xl"
                >
                    <div class="min-w-0 text-center">
                        <div
                            class="flex flex-wrap items-center justify-center gap-x-3"
                        >
                            <h1
                                v-for="athlete in athleteNames"
                                :key="athlete"
                                class="text-3xl leading-none font-black tracking-wide uppercase"
                            >
                                {{ athlete }}
                            </h1>
                        </div>
                        <p
                            class="mt-1 py-1 text-[18px] font-bold text-black uppercase"
                        >
                            {{ currentMatch?.contingent ?? '-' }}
                        </p>
                    </div>
                </div>

                <div
                    class="custom-scrollbar min-h-0 flex-1 overflow-y-auto bg-zinc-950 p-4"
                >
                    <section
                        class="grid h-[356px] grid-cols-[minmax(0,1fr)_280px] items-stretch gap-4"
                    >
                        <div
                            class="h-full overflow-hidden rounded-md border border-stone-800 bg-zinc-900 p-4 shadow-lg"
                        >
                            <div
                                class="grid h-full auto-rows-[2.5rem] grid-cols-10 content-start gap-2"
                            >
                                <button
                                    v-for="value in selectedScoreValues"
                                    :key="value"
                                    type="button"
                                    :class="[
                                        'flex h-10 items-center justify-center rounded-md border text-sm font-black tabular-nums transition',
                                        selectedCriterion &&
                                        scoreDraft[selectedCriterion.key] ===
                                            value
                                            ? 'border-yellow-400 bg-yellow-400 text-black shadow-[0_0_18px_rgba(250,204,21,0.2)]'
                                            : 'border-stone-700 bg-zinc-950 text-white hover:border-yellow-400/60 hover:bg-zinc-800',
                                    ]"
                                    @click="
                                        selectedCriterion &&
                                        setScore(selectedCriterion, value)
                                    "
                                >
                                    {{ value }}
                                </button>
                            </div>
                        </div>

                        <aside
                            class="h-full rounded-md border border-stone-800 bg-zinc-900 p-2.5 shadow-lg"
                        >
                            <div
                                :class="[
                                    'grid',
                                    scoreCriteria.length > 3
                                        ? 'h-full grid-rows-6 gap-1.5'
                                        : 'gap-2',
                                ]"
                            >
                                <label
                                    v-for="criterion in scoreCriteria"
                                    :key="criterion.key"
                                    :class="[
                                        'flex min-h-0 cursor-pointer items-center gap-2 rounded-md border px-2.5 transition',
                                        scoreCriteria.length > 3
                                            ? 'py-1.5'
                                            : 'py-2.5',
                                        selectedScoreKey === criterion.key
                                            ? 'border-yellow-400 bg-yellow-400 text-black shadow-[0_0_18px_rgba(250,204,21,0.18)]'
                                            : 'border-stone-800 bg-zinc-950 text-white hover:border-yellow-400/50',
                                    ]"
                                    @click="selectCriterion(criterion)"
                                >
                                    <input
                                        v-model="selectedScoreKey"
                                        type="radio"
                                        class="sr-only"
                                        :value="criterion.key"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span
                                            :class="[
                                                'block leading-tight font-black uppercase',
                                                scoreCriteria.length > 3
                                                    ? 'text-sm'
                                                    : 'text-lg',
                                            ]"
                                        >
                                            {{ criterion.label }}
                                        </span>
                                    </span>
                                    <span
                                        :class="[
                                            'leading-none font-black tabular-nums',
                                            scoreCriteria.length > 3
                                                ? 'text-lg'
                                                : 'text-xl',
                                        ]"
                                    >
                                        {{ scoreDraft[criterion.key] }}
                                    </span>
                                </label>
                            </div>
                        </aside>
                    </section>

                    <section class="mt-2 grid gap-2">
                        <div
                            v-for="punishment in punishmentCriteria"
                            :key="punishment.key"
                            class="grid min-h-12 grid-cols-[minmax(0,1fr)_280px] gap-4"
                        >
                            <div
                                class="grid grid-cols-[minmax(0,1fr)_76px_76px] items-center gap-2 rounded-md border border-stone-800 bg-zinc-900 px-3 py-1 shadow-lg"
                            >
                                <p
                                    class="text-base leading-tight font-black tracking-wide break-words text-white uppercase"
                                >
                                    {{ punishment.label }}
                                </p>
                                <button
                                    type="button"
                                    class="flex h-10 items-center justify-center rounded-md bg-red-500 text-sm font-black text-white transition hover:bg-red-600"
                                    @click="adjustPunishment(punishment, 1)"
                                >
                                    -{{ punishment.amount }}
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'flex h-10 items-center justify-center rounded-md text-white transition disabled:cursor-not-allowed disabled:opacity-35',
                                        punishmentCounts[punishment.key] > 0
                                            ? 'bg-red-500 hover:bg-red-600'
                                            : 'bg-zinc-950 hover:bg-zinc-800',
                                    ]"
                                    :disabled="
                                        punishmentCounts[punishment.key] === 0
                                    "
                                    :aria-label="`Hapus ${punishment.label}`"
                                    @click="adjustPunishment(punishment, -1)"
                                >
                                    <Delete class="h-6 w-6 stroke-[3]" />
                                </button>
                            </div>
                            <div
                                class="flex items-center justify-end rounded-md border border-stone-800 bg-zinc-900 px-4 py-2.5 shadow-lg"
                            >
                                <p
                                    class="text-xl font-black text-red-400 tabular-nums"
                                >
                                    -{{
                                        punishmentCounts[punishment.key] *
                                        punishment.amount
                                    }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="mt-2 grid grid-cols-3 gap-3">
                        <div
                            class="rounded-md border border-stone-800 bg-zinc-900 p-4 shadow-lg"
                        >
                            <p
                                class="text-[10px] font-black tracking-[0.16em] text-muted-foreground uppercase"
                            >
                                Total Nilai
                            </p>
                            <p
                                class="mt-1.5 text-3xl font-black text-white tabular-nums"
                            >
                                {{ formatScore(totalScores) }}
                            </p>
                        </div>
                        <div
                            class="rounded-md border border-stone-800 bg-zinc-900 p-4 shadow-lg"
                        >
                            <p
                                class="text-[10px] font-black tracking-[0.16em] text-muted-foreground uppercase"
                            >
                                Total Hukuman
                            </p>
                            <p
                                class="mt-1.5 text-3xl font-black text-red-400 tabular-nums"
                            >
                                -{{ formatScore(totalPunishments) }}
                            </p>
                        </div>
                        <div
                            class="rounded-md border border-yellow-500/30 bg-yellow-400 p-4 text-black shadow-lg"
                        >
                            <p
                                class="text-[10px] font-black tracking-[0.16em] uppercase opacity-70"
                            >
                                Nilai Akhir
                            </p>
                            <p class="mt-1.5 text-4xl font-black tabular-nums">
                                {{ formatScore(finalScore) }}
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </template>
    </div>
</template>
