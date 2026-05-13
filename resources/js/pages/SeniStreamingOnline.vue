<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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
};

type PunishmentCriterion = {
    key: PunishmentKey;
    label: string;
};

type SeniJuryScore = Partial<Record<ScoreKey, string | number | null>> & {
    jury_number: number;
    total_score?: string | number | null;
    is_accepted?: boolean | number;
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
    is_disqualified?: boolean;
    is_passed?: boolean;
    no_order: number | null;
    rank?: number | null;
    total_score?: string | number | null;
    total_wiraga?: string | number | null;
    total_wirasa?: string | number | null;
    total_wirama?: string | number | null;
    total_kualitas_teknik?: string | number | null;
    total_kuantitas_teknik?: string | number | null;
    total_ketangkasan?: string | number | null;
    total_stamina?: string | number | null;
    total_kemantapan?: string | number | null;
    total_musik?: string | number | null;
    total_punishment?: string | number | null;
    time?: string | number | null;
    jury_scores?: SeniJuryScore[];
    jury_punishments?: SeniJuryPunishment[];
};

type MatchDisplayStatus = 'not_started' | 'ongoing' | 'paused' | 'done';

type TimerState = {
    id: number | null;
    is_display: boolean;
    started_at: string | null;
    started_at_milliseconds?: number | null;
    status: 'running' | 'paused' | 'stopped';
    stored_status?: 'running' | 'paused' | 'stopped';
    is_countdown: boolean;
    second: number;
    is_autostop: boolean;
    elapsed_seconds: number;
    elapsed_milliseconds?: number;
    display_seconds: number;
    display_milliseconds?: number;
};

const props = defineProps<{
    arena: any;
    activeMatch?: SeniMatch | null;
    rankedMatches?: SeniMatch[];
    timer?: TimerState;
}>();

const currentMatch = ref<SeniMatch | null>(props.activeMatch ?? null);
const rankedMatches = ref<SeniMatch[]>(props.rankedMatches ?? []);
const hasReloadedInitialDatabaseState = ref(false);
const localTimer = ref<TimerState>(
    props.timer ?? {
        id: null,
        is_display: false,
        started_at: null,
        started_at_milliseconds: null,
        status: 'stopped',
        is_countdown: true,
        second: 120,
        is_autostop: false,
        elapsed_seconds: 0,
        elapsed_milliseconds: 0,
        display_seconds: 120,
        display_milliseconds: 120000,
    },
);
const timerNowTick = ref(Date.now());
const { buttonTitle, triggerFullscreen } = useFullscreenLock();

const juries = [1, 2, 3, 4, 5];

const tgrCriteria: ScoreCriterion[] = [
    { key: 'wiraga', label: 'Wiraga' },
    { key: 'wirasa', label: 'Wirasa' },
    { key: 'wirama', label: 'Wirama' },
];

const techniqueCriteria: ScoreCriterion[] = [
    { key: 'kualitas_teknik', label: 'Kualitas Teknik' },
    { key: 'kuantitas_teknik', label: 'Kuantitas Teknik' },
    { key: 'ketangkasan', label: 'Ketangkasan' },
    { key: 'stamina', label: 'Stamina' },
    { key: 'kemantapan', label: 'Kemantapan' },
    { key: 'musik', label: 'Musik' },
];

const tgrPunishments: PunishmentCriterion[] = [
    { key: 'waktu', label: 'Waktu' },
    { key: 'keluar_garis', label: 'Keluar Garis' },
    {
        key: 'senjata_jatuh_atau_tidak_sesuai_deskripsi',
        label: 'Senjata Jatuh / Tidak Sesuai Deskripsi',
    },
    { key: 'akeseoris_jatuh', label: 'Aksesoris Jatuh' },
];

const techniquePunishments: PunishmentCriterion[] = [
    { key: 'waktu', label: 'Waktu' },
    { key: 'keluar_garis', label: 'Keluar Garis' },
    {
        key: 'senjata_jatuh_atau_tidak_sesuai_deskripsi',
        label: 'Senjata Jatuh / Tidak Sesuai Deskripsi',
    },
    {
        key: 'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi',
        label: 'Senjata Tidak Jatuh / Tidak Sesuai Deskripsi',
    },
];

const isTechniqueMatch = (match: SeniMatch | null | undefined) => {
    const matchText = [match?.type, match?.category, match?.group]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return matchText.includes('ganda') || matchText.includes('trio');
};

const hasActiveMatch = computed(() => currentMatch.value !== null);

const shouldShowWinnerTable = computed(() => {
    return (
        rankedMatches.value.length > 0 &&
        rankedMatches.value.every(
            (match) => match.rank !== null && match.rank !== undefined,
        )
    );
});

const winnerRows = computed(() => {
    return [...rankedMatches.value].sort((first, second) => {
        const orderComparison =
            Number(first.no_order ?? 0) - Number(second.no_order ?? 0);

        if (orderComparison !== 0) {
            return orderComparison;
        }

        return Number(first.rank ?? 0) - Number(second.rank ?? 0);
    });
});

const winnerScoringMode = computed<'tgr' | 'technique'>(() => {
    return isTechniqueMatch(winnerRows.value[0]) ? 'technique' : 'tgr';
});

const winnerStatusMeta = (match: SeniMatch) => {
    if (match.is_disqualified) {
        return {
            label: 'Diskualifikasi',
            class: 'border-red-300/40 bg-red-600/35 text-red-100',
        };
    }

    if (match.is_passed) {
        return {
            label: 'Lolos',
            class: 'border-green-300/40 bg-green-600/35 text-green-100',
        };
    }

    return {
        label: 'Tidak Lolos',
        class: 'border-white/15 bg-black/35 text-white/65',
    };
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

const matchStatus = computed<MatchDisplayStatus>(() => {
    if (!currentMatch.value || currentMatch.value.status === 'not_started') {
        return 'not_started';
    }

    if (currentMatch.value.status === 'ongoing') {
        return 'ongoing';
    }

    if (currentMatch.value.status === 'paused') {
        return 'paused';
    }

    if (currentMatch.value.status === 'done') {
        return 'done';
    }

    return 'not_started';
});

const shouldShowCurrentMatchRecap = computed(() => {
    return (
        hasActiveMatch.value &&
        (matchStatus.value === 'paused' || matchStatus.value === 'done')
    );
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

const athleteNamesFor = (athletes: string | string[] | null | undefined) => {
    if (Array.isArray(athletes)) {
        return athletes.filter(Boolean);
    }

    if (!athletes) {
        return ['-'];
    }

    return athletes
        .split(/[,;|/]/)
        .map((athlete) => athlete.trim())
        .filter(Boolean);
};

const formatAthleteNames = (names: string[]) => {
    if (names.length === 0) {
        return '-';
    }

    if (names.length === 1) {
        return names[0];
    }

    if (names.length === 2) {
        return `${names[0]} & ${names[1]}`;
    }

    return `${names.slice(0, -1).join(', ')} & ${names[names.length - 1]}`;
};

const athleteNames = computed(() =>
    athleteNamesFor(currentMatch.value?.atletes),
);

const athleteDisplay = computed(() => formatAthleteNames(athleteNames.value));

const athleteDisplayFor = (match: SeniMatch) => {
    return formatAthleteNames(athleteNamesFor(match.atletes));
};

const juryScores = computed(() => currentMatch.value?.jury_scores ?? []);
const juryPunishments = computed(
    () => currentMatch.value?.jury_punishments ?? [],
);

const findScore = (juryNumber: number) => {
    return (
        juryScores.value.find(
            (score) => Number(score.jury_number) === juryNumber,
        ) ?? null
    );
};

const findPunishment = (juryNumber: number) => {
    return (
        juryPunishments.value.find(
            (punishment) => Number(punishment.jury_number) === juryNumber,
        ) ?? null
    );
};

const isAccepted = (juryNumber: number) => {
    const accepted = findScore(juryNumber)?.is_accepted;

    return accepted === true || accepted === 1;
};

const juryStateClass = (juryNumber: number) => {
    return isAccepted(juryNumber) ? 'bg-green-400/10' : 'bg-red-500/10';
};

const juryHeaderClass = (juryNumber: number) => {
    return isAccepted(juryNumber)
        ? 'border-t-2 border-t-green-400 bg-black/65 text-green-300'
        : 'border-t-2 border-t-red-400 bg-black/65 text-red-200';
};

const numericValue = (value: string | number | null | undefined) => {
    const numberValue = Number(value ?? 0);

    return Number.isFinite(numberValue) ? numberValue : 0;
};

const scoreValue = (criterion: ScoreCriterion, juryNumber: number) => {
    return numericValue(findScore(juryNumber)?.[criterion.key]);
};

const punishmentValue = (
    criterion: PunishmentCriterion,
    juryNumber: number,
) => {
    const punishment = findPunishment(juryNumber);
    const rawValue =
        criterion.key === 'keluar_garis'
            ? (punishment?.keluar_garis ?? punishment?.['keluar garis'])
            : punishment?.[criterion.key];

    return Math.abs(numericValue(rawValue));
};

const acceptedScoreTotal = (criterion: ScoreCriterion) => {
    return juries.reduce((total, juryNumber) => {
        if (!isAccepted(juryNumber)) {
            return total;
        }

        return total + scoreValue(criterion, juryNumber);
    }, 0);
};

const acceptedPunishmentTotal = (criterion: PunishmentCriterion) => {
    return juries.reduce((total, juryNumber) => {
        if (!isAccepted(juryNumber)) {
            return total;
        }

        return total + punishmentValue(criterion, juryNumber);
    }, 0);
};

const scoreSubtotalForJury = (juryNumber: number) => {
    return scoreCriteria.value.reduce(
        (total, criterion) => total + scoreValue(criterion, juryNumber),
        0,
    );
};

const finalScoreForJury = (juryNumber: number) => {
    return numericValue(findScore(juryNumber)?.total_score);
};

const punishmentSubtotalForJury = (juryNumber: number) => {
    return punishmentCriteria.value.reduce(
        (total, criterion) => total + punishmentValue(criterion, juryNumber),
        0,
    );
};

const totalScore = computed(() => {
    return scoreCriteria.value.reduce(
        (total, criterion) => total + acceptedScoreTotal(criterion),
        0,
    );
});

const totalPunishment = computed(() => {
    return punishmentCriteria.value.reduce(
        (total, criterion) => total + acceptedPunishmentTotal(criterion),
        0,
    );
});

const finalScore = computed(() => {
    return numericValue(currentMatch.value?.total_score);
});

const timerElapsedMilliseconds = computed(() => {
    let elapsed =
        localTimer.value.elapsed_milliseconds ??
        (Number(localTimer.value.elapsed_seconds) || 0) * 1000;

    if (localTimer.value.status === 'running' && localTimer.value.started_at) {
        elapsed += Math.max(
            0,
            timerNowTick.value -
                (localTimer.value.started_at_milliseconds ??
                    Date.parse(localTimer.value.started_at)),
        );
    }

    return elapsed;
});

const timerDisplayMilliseconds = computed(() => {
    if (localTimer.value.is_countdown) {
        return Math.max(
            0,
            localTimer.value.second * 1000 - timerElapsedMilliseconds.value,
        );
    }

    if (localTimer.value.is_autostop) {
        return Math.min(
            timerElapsedMilliseconds.value,
            localTimer.value.second * 1000,
        );
    }

    return timerElapsedMilliseconds.value;
});

const formattedTimer = computed(() => {
    const safeMilliseconds = Math.max(
        0,
        Math.floor(timerDisplayMilliseconds.value),
    );
    const minutes = Math.floor(safeMilliseconds / 60000);
    const seconds = Math.floor((safeMilliseconds % 60000) / 1000);
    const milliseconds = safeMilliseconds % 1000;

    return `${minutes.toString().padStart(2, '0')}:${seconds
        .toString()
        .padStart(2, '0')}:${milliseconds.toString().padStart(3, '0')}`;
});

const shouldShowActiveTimer = computed(() => {
    return (
        matchStatus.value === 'ongoing' && Boolean(localTimer.value.is_display)
    );
});

const juryTotalRows = computed(() => {
    return juries.map((juryNumber) => ({
        juryNumber,
        totalScore: finalScoreForJury(juryNumber),
    }));
});

const recapCards = computed(() => {
    const match = currentMatch.value;

    if (isTechniqueMatch(match)) {
        return [
            {
                label: 'Nilai Akhir',
                value: formatScore(numericValue(match?.total_score)),
                valueClass: 'text-black',
                cardClass: 'border-yellow-300/40 bg-yellow-300 text-black',
            },
            {
                label: 'Kualitas Teknik',
                value: formatScore(numericValue(match?.total_kualitas_teknik)),
                valueClass: 'text-white',
                cardClass: 'border-white/10 bg-black/45',
            },
            {
                label: 'Kuantitas Teknik',
                value: formatScore(numericValue(match?.total_kuantitas_teknik)),
                valueClass: 'text-white',
                cardClass: 'border-white/10 bg-black/45',
            },
            {
                label: 'Total Hukuman',
                value: formatCell(numericValue(match?.total_punishment), true),
                valueClass: 'text-red-200',
                cardClass: 'border-red-300/25 bg-red-950/45',
            },
            {
                label: 'Waktu',
                value: formatTime(match?.time),
                valueClass: 'text-white',
                cardClass: 'border-white/10 bg-black/45',
            },
        ];
    }

    return [
        {
            label: 'Nilai Akhir',
            value: formatScore(numericValue(match?.total_score)),
            valueClass: 'text-black',
            cardClass: 'border-yellow-300/40 bg-yellow-300 text-black',
        },
        {
            label: 'Wiraga',
            value: formatScore(numericValue(match?.total_wiraga)),
            valueClass: 'text-white',
            cardClass: 'border-white/10 bg-black/45',
        },
        {
            label: 'Wirasa',
            value: formatScore(numericValue(match?.total_wirasa)),
            valueClass: 'text-white',
            cardClass: 'border-white/10 bg-black/45',
        },
        {
            label: 'Total Hukuman',
            value: formatCell(numericValue(match?.total_punishment), true),
            valueClass: 'text-red-200',
            cardClass: 'border-red-300/25 bg-red-950/45',
        },
        {
            label: 'Waktu',
            value: formatTime(match?.time),
            valueClass: 'text-white',
            cardClass: 'border-white/10 bg-black/45',
        },
    ];
});

const formatScore = (value: number) => {
    return value.toLocaleString('id-ID', {
        minimumFractionDigits: Number.isInteger(value) ? 0 : 3,
        maximumFractionDigits: 3,
    });
};

const formatCell = (value: number, negative = false) => {
    if (!value) {
        return '0';
    }

    return `${negative ? '-' : ''}${formatScore(value)}`;
};

const formatTime = (value: string | number | null | undefined) => {
    if (value === null || value === undefined || value === '') {
        return '00:00';
    }

    const seconds = Number(value);

    if (Number.isNaN(seconds)) {
        return '00:00';
    }

    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = Math.max(0, Math.floor(seconds % 60));

    return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
};

const arenaDisplayName = computed(
    () => props.arena?.arena_name ?? props.arena?.gelanggang_id ?? '-',
);

const partaiLabel = computed(() => currentMatch.value?.matches_code ?? '-');

const reloadScoreStateFromDatabase = () => {
    router.reload({
        only: ['activeMatch', 'rankedMatches'],
    });
};

const reloadInitialScoreStateFromDatabase = () => {
    if (
        hasReloadedInitialDatabaseState.value ||
        currentMatch.value?.status !== 'ongoing'
    ) {
        return;
    }

    hasReloadedInitialDatabaseState.value = true;
    reloadScoreStateFromDatabase();
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
            updatedMatch.status === 'ongoing') ||
        updatedMatch.status === 'done'
    );
};

const mergeJuryScore = (score: SeniJuryScore) => {
    if (!currentMatch.value) {
        return;
    }

    const scores = [...(currentMatch.value.jury_scores ?? [])];
    const index = scores.findIndex(
        (item) => Number(item.jury_number) === Number(score.jury_number),
    );

    if (index >= 0) {
        scores[index] = { ...scores[index], ...score };
    } else {
        scores.push(score);
    }

    currentMatch.value = {
        ...currentMatch.value,
        jury_scores: scores,
    };
};

const mergeJuryPunishment = (punishment: SeniJuryPunishment) => {
    if (!currentMatch.value || !punishment.jury_number) {
        return;
    }

    const punishments = [...(currentMatch.value.jury_punishments ?? [])];
    const index = punishments.findIndex(
        (item) => Number(item.jury_number) === Number(punishment.jury_number),
    );

    if (index >= 0) {
        punishments[index] = { ...punishments[index], ...punishment };
    } else {
        punishments.push(punishment);
    }

    currentMatch.value = {
        ...currentMatch.value,
        jury_punishments: punishments,
    };
};

watch(
    () => props.activeMatch,
    (match) => {
        currentMatch.value = match ?? null;
    },
    { deep: true },
);

watch(
    () => props.rankedMatches,
    (matches) => {
        rankedMatches.value = matches ?? [];
    },
    { deep: true },
);

watch(
    () => props.timer,
    (timer) => {
        if (timer) {
            localTimer.value = { ...localTimer.value, ...timer };
        }
    },
    { deep: true },
);

let echoStatusChannel: any = null;
let echoScoreChannel: any = null;
let echoTimerChannel: any = null;
let timerTickInterval: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timerTickInterval = setInterval(() => {
        timerNowTick.value = Date.now();
    }, 25);

    reloadInitialScoreStateFromDatabase();

    const echo = (window as any).Echo;

    if (!echo) {
        return;
    }

    echoStatusChannel = echo
        .channel('seni.match.status')
        .listen('.SeniMatchUpdated', (event: any) => {
            if (event.status === 'rank_updated') {
                reloadScoreStateFromDatabase();

                return;
            }

            if (!event.match) {
                return;
            }

            const shouldReload = shouldReloadScoreStateFromDatabase(
                event.match,
            );

            currentMatch.value = {
                ...(currentMatch.value ?? {}),
                ...event.match,
            } as SeniMatch;

            if (shouldReload) {
                reloadScoreStateFromDatabase();
            }
        });

    echoScoreChannel = echo
        .channel('seni.match.score')
        .listen('.SeniJuryScoreUpdated', (event: any) => {
            if (
                currentMatch.value &&
                event.match?.id &&
                Number(event.match.id) !== currentMatch.value.id
            ) {
                return;
            }

            if (event.match?.jury_scores) {
                currentMatch.value = event.match;

                return;
            }

            if (event.match) {
                currentMatch.value = {
                    ...(currentMatch.value ?? {}),
                    ...event.match,
                } as SeniMatch;
            }

            if (event.score?.jury_number) {
                mergeJuryScore(event.score);
            }

            if (event.punishment?.jury_number) {
                mergeJuryPunishment(event.punishment);
            }
        });

    echoTimerChannel = echo
        .channel('timer')
        .listen('.TimerUpdated', (event: any) => {
            if (event.timer) {
                localTimer.value = { ...localTimer.value, ...event.timer };
            }
        });
});

onUnmounted(() => {
    if (timerTickInterval) {
        clearInterval(timerTickInterval);
    }

    const echo = (window as any).Echo;

    if (echoStatusChannel) {
        echoStatusChannel.stopListening('.SeniMatchUpdated');
    }

    if (echoScoreChannel) {
        echoScoreChannel.stopListening('.SeniJuryScoreUpdated');
    }

    if (echoTimerChannel) {
        echoTimerChannel.stopListening('.TimerUpdated');
    }

    if (echo) {
        echo.leaveChannel('seni.match.status');
        echo.leaveChannel('seni.match.score');
        echo.leaveChannel('timer');
    }
});
</script>

<template>
    <Head title="Seni Streaming Online - Tapak Suci" />

    <div
        class="relative h-dvh w-screen overflow-hidden bg-[#00ff00] text-white"
    >
        <Transition name="broadcast-status" mode="out-in">
            <article
                v-if="shouldShowWinnerTable"
                key="winner-table"
                class="overlay-shell absolute inset-x-0 bottom-[4vh] mx-auto max-h-[82vh] w-[min(92vw,1440px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/65 text-white shadow-2xl backdrop-blur-sm"
                :title="buttonTitle"
                @click="triggerFullscreen"
            >
                <header
                    class="overlay-topbar flex h-8 items-center justify-between gap-4 border-b border-white/15 bg-black/85 px-6 text-[10px] font-black tracking-widest uppercase"
                >
                    <span>Hasil Keputusan Seni</span>
                    <span class="text-yellow-300"
                        >Gelanggang {{ arenaDisplayName }}</span
                    >
                    <span>juarasilat.com</span>
                </header>

                <div class="overflow-x-auto p-3">
                    <table
                        class="w-full min-w-[1040px] border-collapse text-xs"
                    >
                        <thead>
                            <tr
                                class="border-b border-white/15 bg-black/45 text-left text-[10px] font-black tracking-widest text-white/70 uppercase"
                            >
                                <th class="px-3 py-2 text-center">No Urut</th>
                                <th class="px-3 py-2 text-center">Rank</th>
                                <th class="px-3 py-2">Kontingen</th>
                                <th class="px-3 py-2">Atlet</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <template v-if="winnerScoringMode === 'tgr'">
                                    <th class="px-3 py-2 text-right">Wiraga</th>
                                    <th class="px-3 py-2 text-right">Wirasa</th>
                                </template>
                                <template v-else>
                                    <th class="px-3 py-2 text-right">
                                        Kualitas Teknik
                                    </th>
                                    <th class="px-3 py-2 text-right">
                                        Kuantitas Teknik
                                    </th>
                                </template>
                                <th class="px-3 py-2 text-right">Hukuman</th>
                                <th class="px-3 py-2 text-center">Waktu</th>
                                <th class="px-3 py-2 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr
                                v-for="(match, index) in winnerRows"
                                :key="match.id"
                                class="overlay-stat-row bg-black/30 text-white"
                                :style="{
                                    '--row-delay': `${420 + index * 70}ms`,
                                }"
                            >
                                <td
                                    class="px-3 py-2 text-center font-bold tabular-nums"
                                >
                                    {{ match.no_order ?? '-' }}
                                </td>
                                <td
                                    class="px-3 py-2 text-center text-2xl font-black text-yellow-300 tabular-nums"
                                >
                                    {{ match.rank ?? '-' }}
                                </td>
                                <td class="px-3 py-2 font-bold uppercase">
                                    {{ match.contingent ?? '-' }}
                                </td>
                                <td class="px-3 py-2 uppercase">
                                    {{ athleteDisplayFor(match) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right text-lg font-black text-yellow-300 tabular-nums"
                                >
                                    {{
                                        formatScore(
                                            numericValue(match.total_score),
                                        )
                                    }}
                                </td>
                                <template v-if="winnerScoringMode === 'tgr'">
                                    <td
                                        class="px-3 py-2 text-right text-base font-black tabular-nums"
                                    >
                                        {{
                                            formatScore(
                                                numericValue(
                                                    match.total_wiraga,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right text-base font-black tabular-nums"
                                    >
                                        {{
                                            formatScore(
                                                numericValue(
                                                    match.total_wirasa,
                                                ),
                                            )
                                        }}
                                    </td>
                                </template>
                                <template v-else>
                                    <td
                                        class="px-3 py-2 text-right text-base font-black tabular-nums"
                                    >
                                        {{
                                            formatScore(
                                                numericValue(
                                                    match.total_kualitas_teknik,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right text-base font-black tabular-nums"
                                    >
                                        {{
                                            formatScore(
                                                numericValue(
                                                    match.total_kuantitas_teknik,
                                                ),
                                            )
                                        }}
                                    </td>
                                </template>
                                <td
                                    class="px-3 py-2 text-right text-lg font-black text-red-200 tabular-nums"
                                >
                                    {{
                                        formatCell(
                                            numericValue(
                                                match.total_punishment,
                                            ),
                                            true,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    {{ formatTime(match.time) }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span
                                        :class="[
                                            'inline-flex rounded-md border px-2 py-0.5 text-[10px] font-black tracking-widest uppercase',
                                            winnerStatusMeta(match).class,
                                        ]"
                                    >
                                        {{ winnerStatusMeta(match).label }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <section
                v-else-if="hasActiveMatch && matchStatus === 'not_started'"
                key="not-started"
                class="absolute inset-x-0 bottom-[5vh] flex justify-center px-4"
            >
                <div
                    class="overlay-shell w-[min(78vw,1180px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/80 shadow-2xl backdrop-blur-sm"
                    :title="buttonTitle"
                    @click="triggerFullscreen"
                >
                    <div
                        class="overlay-topbar flex h-6 items-center justify-between gap-4 border-b border-white/15 bg-black/90 px-5 text-[9px] font-black tracking-widest text-zinc-200 uppercase"
                    >
                        <span>{{ matchMetaLabel || 'Seni' }}</span>
                        <span class="shrink-0 text-yellow-300">
                            Next Match
                        </span>
                    </div>

                    <div
                        class="grid min-h-16 grid-cols-[minmax(0,1fr)_9rem] items-stretch"
                    >
                        <div
                            class="overlay-yellow flex min-w-0 flex-col justify-center gap-0.5 bg-yellow-300 px-5 text-black"
                        >
                            <ul
                                v-if="athleteNames.length > 1"
                                class="max-w-full list-disc space-y-0.5 pl-5 text-[clamp(0.95rem,1.25vw,1.35rem)] leading-tight font-black break-words uppercase drop-shadow-sm"
                            >
                                <li
                                    v-for="athlete in athleteNames"
                                    :key="athlete"
                                >
                                    {{ athlete }}
                                </li>
                            </ul>
                            <h2
                                v-else
                                class="max-w-full text-[clamp(1rem,1.45vw,1.65rem)] leading-tight font-black break-words uppercase drop-shadow-sm"
                            >
                                {{ athleteNames[0] || '-' }}
                            </h2>
                            <p
                                class="max-w-full text-xs leading-tight font-bold tracking-wide break-words uppercase"
                            >
                                {{ currentMatch?.contingent || '-' }}
                            </p>
                        </div>

                        <div
                            class="overlay-center relative z-10 flex flex-col items-center justify-center gap-0.5 border-l border-white/15 bg-zinc-950/95 px-3 text-center shadow-[0_0_35px_rgba(0,0,0,0.55)]"
                        >
                            <div
                                class="text-xs leading-tight font-black tracking-widest text-yellow-300 uppercase"
                            >
                                Partai {{ partaiLabel }}
                            </div>
                            <div
                                class="text-[10px] font-black tracking-widest text-zinc-400 uppercase"
                            >
                                No Penampil
                            </div>
                            <div
                                class="max-w-full text-[10px] leading-tight font-black break-words text-zinc-100 uppercase"
                            >
                                {{ currentMatch?.no_order ?? '-' }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="overlay-footer flex h-6 items-center justify-between gap-4 border-t border-white/15 bg-black/90 px-5 text-[10px] font-black tracking-widest uppercase"
                    >
                        <span>Gelanggang {{ arenaDisplayName }}</span>
                        <span>juarasilat.com</span>
                    </div>
                </div>
            </section>

            <section
                v-else-if="hasActiveMatch && matchStatus === 'ongoing'"
                key="ongoing"
                class="absolute inset-x-0 bottom-[4.5vh] flex flex-col items-center gap-1 px-4"
            >
                <div
                    class="overlay-topbar rounded-md border border-white/20 bg-black/90 px-4 py-1 text-[10px] font-black tracking-widest text-yellow-300 uppercase shadow-xl"
                >
                    {{ matchMetaLabel || 'Seni' }}
                </div>

                <div
                    class="overlay-shell w-[min(80vw,1280px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/80 shadow-2xl backdrop-blur-sm"
                    :title="buttonTitle"
                    @click="triggerFullscreen"
                >
                    <header
                        :class="[
                            'grid min-h-14 items-stretch border-b border-white/15',
                            shouldShowActiveTimer
                                ? 'grid-cols-[minmax(0,1fr)_15rem]'
                                : 'grid-cols-[minmax(0,1fr)_9rem]',
                        ]"
                    >
                        <div
                            class="overlay-yellow flex min-w-0 flex-col justify-center bg-yellow-300 px-5 text-black"
                        >
                            <h2
                                class="max-w-full text-[clamp(0.95rem,1.25vw,1.35rem)] leading-tight font-black break-words uppercase"
                            >
                                {{ athleteDisplay || '-' }}
                            </h2>
                            <p
                                class="max-w-full text-[10px] leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent || '-' }}
                            </p>
                        </div>

                        <div
                            class="overlay-center flex flex-col items-center justify-center gap-0.5 border-l border-white/15 bg-zinc-950/95 px-3 text-center"
                        >
                            <div
                                class="text-xs leading-tight font-black tracking-widest text-yellow-300 uppercase"
                            >
                                Partai {{ partaiLabel }}
                            </div>
                            <div
                                v-if="shouldShowActiveTimer"
                                class="overlay-score font-mono text-[clamp(1.35rem,2.15vw,2.35rem)] leading-none font-black tracking-wider text-white tabular-nums"
                            >
                                {{ formattedTimer }}
                            </div>
                            <template v-else>
                                <div
                                    class="text-[10px] font-black tracking-widest text-zinc-400 uppercase"
                                >
                                    No Penampil
                                </div>
                                <div
                                    class="max-w-full text-[10px] leading-tight font-black break-words text-zinc-100 uppercase"
                                >
                                    {{ currentMatch?.no_order ?? '-' }}
                                </div>
                            </template>
                            <div
                                v-if="shouldShowActiveTimer"
                                class="max-w-full text-[10px] leading-tight font-black break-words text-zinc-100 uppercase"
                            >
                                No Penampil {{ currentMatch?.no_order ?? '-' }}
                            </div>
                        </div>
                    </header>

                    <div class="grid grid-cols-5 gap-1.5 p-2">
                        <div
                            v-for="(jury, index) in juryTotalRows"
                            :key="jury.juryNumber"
                            class="overlay-stat-row rounded-md border border-white/10 bg-black/45 p-2 text-center text-white"
                            :style="{
                                '--row-delay': `${560 + index * 85}ms`,
                            }"
                        >
                            <p
                                class="text-[10px] font-black tracking-widest text-white/60 uppercase"
                            >
                                Juri {{ jury.juryNumber }}
                            </p>
                            <p
                                class="overlay-score mt-0.5 text-2xl font-black text-yellow-300 tabular-nums"
                            >
                                {{ formatScore(jury.totalScore) }}
                            </p>
                        </div>
                    </div>

                    <footer
                        class="overlay-footer flex h-6 items-center justify-between gap-4 border-t border-white/15 bg-black/90 px-5 text-[10px] font-black tracking-widest uppercase"
                    >
                        <span>Gelanggang {{ arenaDisplayName }}</span>
                        <span>juarasilat.com</span>
                    </footer>
                </div>
            </section>

            <article
                v-else-if="shouldShowCurrentMatchRecap"
                key="detail"
                class="overlay-shell absolute inset-x-0 bottom-[4vh] mx-auto max-h-[82vh] w-[min(92vw,1450px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/65 text-white shadow-2xl backdrop-blur-sm"
                :title="buttonTitle"
                @click="triggerFullscreen"
            >
                <header
                    class="grid min-h-20 grid-cols-[minmax(0,1fr)_12rem] border-b border-white/15"
                >
                    <div
                        class="overlay-yellow flex min-w-0 flex-col justify-center gap-0.5 bg-yellow-300 px-6 text-black"
                    >
                        <h2
                            class="max-w-full text-2xl leading-tight font-black break-words uppercase"
                        >
                            {{ athleteDisplay || '-' }}
                        </h2>
                        <p
                            class="max-w-full text-base leading-tight font-bold break-words uppercase"
                        >
                            {{ currentMatch?.contingent || '-' }}
                        </p>
                    </div>

                    <div
                        class="overlay-center flex flex-col items-center justify-center gap-1 border-l border-white/15 bg-black/80 px-5 text-center"
                    >
                        <span
                            class="text-[10px] font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Nilai Akhir
                        </span>
                        <div
                            class="overlay-score text-3xl leading-none font-black text-yellow-300 tabular-nums"
                        >
                            {{ formatScore(finalScore) }}
                        </div>
                    </div>
                </header>

                <div
                    class="overlay-footer flex h-7 items-center justify-between gap-4 border-b border-white/15 bg-black/80 px-6 text-xs font-black tracking-widest uppercase"
                >
                    <span>{{ matchMetaLabel || 'Seni' }}</span>
                    <span>Partai {{ partaiLabel }}</span>
                    <span>Gelanggang {{ arenaDisplayName }}</span>
                </div>

                <div class="grid gap-3 p-3">
                    <div
                        class="overflow-x-auto rounded-md border border-white/10"
                    >
                        <table
                            class="w-full min-w-[820px] border-collapse bg-black/35 text-xs"
                        >
                            <thead>
                                <tr
                                    class="border-b border-white/10 bg-black/55 text-center text-[10px] font-black tracking-widest text-white uppercase"
                                >
                                    <th
                                        class="w-[220px] border-r border-white/10 px-3 py-1.5 text-left text-yellow-300"
                                    >
                                        Kriteria
                                    </th>
                                    <th
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        :class="[
                                            'border-r border-white/10 px-2 py-1.5',
                                            juryHeaderClass(juryNumber),
                                        ]"
                                    >
                                        Juri {{ juryNumber }}
                                    </th>
                                    <th class="w-[100px] px-2 py-1.5">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(criterion, index) in scoreCriteria"
                                    :key="criterion.key"
                                    class="overlay-stat-row border-b border-white/10 text-center"
                                    :style="{
                                        '--row-delay': `${620 + index * 60}ms`,
                                    }"
                                >
                                    <th
                                        class="border-r border-white/10 bg-black/35 px-3 py-1.5 text-left font-bold tracking-wide text-white uppercase"
                                    >
                                        {{ criterion.label }}
                                    </th>
                                    <td
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        :class="[
                                            'border-r border-white/10 px-2 py-1.5 text-base font-bold text-white tabular-nums',
                                            juryStateClass(juryNumber),
                                        ]"
                                    >
                                        {{
                                            formatCell(
                                                scoreValue(
                                                    criterion,
                                                    juryNumber,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-base font-bold text-yellow-300 tabular-nums"
                                    >
                                        {{
                                            formatScore(
                                                acceptedScoreTotal(criterion),
                                            )
                                        }}
                                    </td>
                                </tr>

                                <tr
                                    class="overlay-stat-row border-b border-white/10 bg-black/50 text-center"
                                    :style="{ '--row-delay': '820ms' }"
                                >
                                    <th
                                        class="border-r border-white/10 px-3 py-1.5 text-left text-base font-bold tracking-wide text-yellow-300 uppercase"
                                    >
                                        Total
                                    </th>
                                    <td
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        :class="[
                                            'border-r border-white/10 px-2 py-1.5 text-base font-bold text-yellow-300 tabular-nums',
                                            juryStateClass(juryNumber),
                                        ]"
                                    >
                                        {{
                                            formatScore(
                                                scoreSubtotalForJury(
                                                    juryNumber,
                                                ),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-base font-bold text-yellow-300 tabular-nums"
                                    >
                                        {{ formatScore(totalScore) }}
                                    </td>
                                </tr>

                                <tr
                                    class="border-b border-white/10 bg-black/60 text-center text-[12px] font-black tracking-widest text-white uppercase"
                                >
                                    <th
                                        class="px-3 py-1 text-left text-red-200"
                                    >
                                        Hukuman
                                    </th>
                                    <td
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        class="px-2 py-1.5"
                                    ></td>
                                    <td class="px-2 py-1.5"></td>
                                </tr>

                                <tr
                                    v-for="(
                                        punishment, index
                                    ) in punishmentCriteria"
                                    :key="punishment.key"
                                    class="overlay-stat-row border-b border-white/10 text-center"
                                    :style="{
                                        '--row-delay': `${900 + index * 60}ms`,
                                    }"
                                >
                                    <th
                                        class="border-r border-white/10 bg-black/35 px-3 py-1.5 text-left font-bold tracking-wide text-white uppercase"
                                    >
                                        {{ punishment.label }}
                                    </th>
                                    <td
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        :class="[
                                            'border-r border-white/10 px-2 py-1.5 text-base font-bold text-white tabular-nums',
                                            juryStateClass(juryNumber),
                                        ]"
                                    >
                                        {{
                                            formatCell(
                                                punishmentValue(
                                                    punishment,
                                                    juryNumber,
                                                ),
                                                true,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-base font-bold text-red-200 tabular-nums"
                                    >
                                        {{
                                            formatCell(
                                                acceptedPunishmentTotal(
                                                    punishment,
                                                ),
                                                true,
                                            )
                                        }}
                                    </td>
                                </tr>

                                <tr
                                    class="overlay-stat-row border-b border-white/10 bg-black/50 text-center"
                                    :style="{ '--row-delay': '1160ms' }"
                                >
                                    <th
                                        class="border-r border-white/10 px-3 py-1.5 text-left text-base font-bold tracking-wide text-red-200 uppercase"
                                    >
                                        Total Hukuman
                                    </th>
                                    <td
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        :class="[
                                            'border-r border-white/10 px-2 py-1.5 text-base font-bold text-red-200 tabular-nums',
                                            juryStateClass(juryNumber),
                                        ]"
                                    >
                                        {{
                                            formatCell(
                                                punishmentSubtotalForJury(
                                                    juryNumber,
                                                ),
                                                true,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-base font-bold text-red-200 tabular-nums"
                                    >
                                        {{ formatCell(totalPunishment, true) }}
                                    </td>
                                </tr>

                                <tr
                                    class="overlay-stat-row bg-black/60 text-center"
                                    :style="{ '--row-delay': '1240ms' }"
                                >
                                    <th
                                        class="border-r border-white/10 px-3 py-1.5 text-left text-base font-bold tracking-wide text-yellow-300 uppercase"
                                    >
                                        Nilai Akhir
                                    </th>
                                    <td
                                        v-for="juryNumber in juries"
                                        :key="juryNumber"
                                        :class="[
                                            'border-r border-white/10 px-2 py-1.5 text-base font-bold text-yellow-300 tabular-nums',
                                            juryStateClass(juryNumber),
                                        ]"
                                    >
                                        {{
                                            formatScore(
                                                finalScoreForJury(juryNumber),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-base font-bold text-yellow-300 tabular-nums"
                                    >
                                        {{ formatScore(finalScore) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-5 gap-2">
                        <div
                            v-for="(card, index) in recapCards"
                            :key="card.label"
                            :class="[
                                'overlay-stat-row rounded-md border p-2 shadow-lg',
                                card.cardClass,
                            ]"
                            :style="{
                                '--row-delay': `${1320 + index * 65}ms`,
                            }"
                        >
                            <p
                                class="text-[9px] font-black tracking-[0.16em] uppercase opacity-75"
                            >
                                {{ card.label }}
                            </p>
                            <p
                                :class="[
                                    'mt-0.5 text-xl font-black tabular-nums',
                                    card.valueClass,
                                ]"
                            >
                                {{ card.value }}
                            </p>
                        </div>
                    </div>
                </div>

                <footer
                    class="overlay-footer flex h-7 items-center justify-between gap-4 border-t border-white/15 bg-black/75 px-6 text-xs font-black tracking-widest uppercase"
                >
                    <span>{{
                        matchStatus === 'paused' ? 'Sementara' : 'Selesai'
                    }}</span>
                    <span>juarasilat.com</span>
                </footer>
            </article>
        </Transition>
    </div>
</template>

<style scoped>
.broadcast-status-enter-active,
.broadcast-status-leave-active {
    transition:
        opacity 760ms ease,
        transform 760ms cubic-bezier(0.16, 1, 0.3, 1),
        filter 760ms ease;
    transform-origin: center bottom;
}

.broadcast-status-leave-active {
    transition-duration: 520ms;
    pointer-events: none;
}

.broadcast-status-enter-from {
    opacity: 0;
    transform: translateY(28px) scale(0.982);
    filter: blur(8px);
}

.broadcast-status-enter-to,
.broadcast-status-leave-from {
    opacity: 1;
    transform: translateY(0) scale(1);
    filter: blur(0);
}

.broadcast-status-leave-to {
    opacity: 0;
    transform: translateY(-18px) scale(0.988);
    filter: blur(6px);
}

.broadcast-status-leave-active.overlay-shell,
.broadcast-status-leave-active .overlay-shell {
    animation: overlay-shell-out 520ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.broadcast-status-leave-active .overlay-topbar,
.broadcast-status-leave-active .overlay-footer {
    animation: overlay-strip-out 420ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.broadcast-status-leave-active .overlay-blue {
    animation: overlay-blue-out 460ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.broadcast-status-leave-active .overlay-yellow {
    animation: overlay-yellow-out 460ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.broadcast-status-leave-active .overlay-center {
    animation: overlay-center-out 420ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.broadcast-status-leave-active .overlay-score {
    animation: overlay-score-out 360ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.broadcast-status-leave-active .overlay-stat-row,
.broadcast-status-leave-active .overlay-winner {
    animation: overlay-stat-row-out 360ms cubic-bezier(0.7, 0, 0.84, 0) both;
}

.overlay-shell {
    animation: overlay-shell-in 980ms cubic-bezier(0.16, 1, 0.3, 1) both;
    transform-origin: center bottom;
}

.overlay-topbar {
    animation: overlay-drop-in 820ms cubic-bezier(0.16, 1, 0.3, 1) 160ms both;
}

.overlay-yellow {
    animation: overlay-yellow-in 960ms cubic-bezier(0.16, 1, 0.3, 1) 260ms both;
    transform-origin: right center;
}

.overlay-blue {
    animation: overlay-blue-in 960ms cubic-bezier(0.16, 1, 0.3, 1) 320ms both;
    transform-origin: left center;
}

.overlay-center {
    animation: overlay-center-in 860ms cubic-bezier(0.16, 1, 0.3, 1) 430ms both;
}

.overlay-score {
    animation: overlay-score-pop 820ms cubic-bezier(0.16, 1, 0.3, 1) 560ms both;
}

.overlay-footer {
    animation: overlay-footer-in 760ms cubic-bezier(0.16, 1, 0.3, 1) 640ms both;
}

.overlay-stat-row {
    animation: overlay-stat-row-in 720ms cubic-bezier(0.16, 1, 0.3, 1)
        var(--row-delay, 700ms) both;
}

.overlay-winner {
    animation: overlay-score-pop 860ms cubic-bezier(0.16, 1, 0.3, 1) 1120ms both;
}

@keyframes overlay-shell-in {
    from {
        opacity: 0;
        transform: translateY(24px) scaleX(0.97);
        filter: blur(5px);
    }

    to {
        opacity: 1;
        transform: translateY(0) scaleX(1);
        filter: blur(0);
    }
}

@keyframes overlay-shell-out {
    from {
        opacity: 1;
        transform: translateY(0) scaleX(1);
        filter: blur(0);
    }

    to {
        opacity: 0;
        transform: translateY(-18px) scaleX(0.985);
        filter: blur(6px);
    }
}

@keyframes overlay-drop-in {
    from {
        opacity: 0;
        transform: translateY(-45%);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes overlay-yellow-in {
    from {
        opacity: 0;
        transform: translateX(36px) scaleX(0.97);
    }

    to {
        opacity: 1;
        transform: translateX(0) scaleX(1);
    }
}

@keyframes overlay-yellow-out {
    from {
        opacity: 1;
        transform: translateX(0) scaleX(1);
    }

    to {
        opacity: 0;
        transform: translateX(30px) scaleX(0.98);
    }
}

@keyframes overlay-blue-in {
    from {
        opacity: 0;
        transform: translateX(-36px) scaleX(0.97);
    }

    to {
        opacity: 1;
        transform: translateX(0) scaleX(1);
    }
}

@keyframes overlay-blue-out {
    from {
        opacity: 1;
        transform: translateX(0) scaleX(1);
    }

    to {
        opacity: 0;
        transform: translateX(-30px) scaleX(0.98);
    }
}

@keyframes overlay-center-in {
    from {
        opacity: 0;
        transform: scaleY(0.94);
    }

    to {
        opacity: 1;
        transform: scaleY(1);
    }
}

@keyframes overlay-center-out {
    from {
        opacity: 1;
        transform: scaleY(1);
    }

    to {
        opacity: 0;
        transform: scaleY(0.92);
    }
}

@keyframes overlay-score-pop {
    from {
        opacity: 0;
        transform: scale(0.82);
    }

    72% {
        opacity: 1;
        transform: scale(1.02);
    }

    to {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes overlay-score-out {
    from {
        opacity: 1;
        transform: scale(1);
    }

    to {
        opacity: 0;
        transform: scale(0.9);
    }
}

@keyframes overlay-footer-in {
    from {
        opacity: 0;
        transform: translateY(45%);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes overlay-strip-out {
    from {
        opacity: 1;
        transform: translateY(0);
    }

    to {
        opacity: 0;
        transform: translateY(35%);
    }
}

@keyframes overlay-stat-row-in {
    from {
        opacity: 0;
        transform: translateX(-12px);
    }

    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes overlay-stat-row-out {
    from {
        opacity: 1;
        transform: translateX(0);
    }

    to {
        opacity: 0;
        transform: translateX(10px);
    }
}
</style>
