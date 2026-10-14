<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import FightFullscreenButton from '@/components/fight/FightFullscreenButton.vue';
import FightWaitingState from '@/components/fight/FightWaitingState.vue';
import TvStage from '@/components/TvStage.vue';
import { useFullscreenLock } from '@/composables/useFullscreenLock';
import { useSyncedTimer } from '@/composables/useSyncedTimer';
import type { SyncedTimerState } from '@/composables/useSyncedTimer';

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

type TimerState = SyncedTimerState & {
    id: number | null;
    is_display: boolean;
    stored_status?: 'running' | 'paused' | 'stopped';
};

const defaultTimer: TimerState = {
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
};

const props = defineProps<{
    arena: any;
    activeMatch?: SeniMatch | null;
    scoringJuries?: number[];
    rankedMatches?: SeniMatch[];
    timer?: TimerState;
}>();

const page = usePage<any>();
const userName = computed(() => page.props.auth?.user?.name || 'Sekretaris');
const currentMatch = ref<SeniMatch | null>(props.activeMatch ?? null);
const rankedMatches = ref<SeniMatch[]>(props.rankedMatches ?? []);
const hasReloadedInitialDatabaseState = ref(false);
const { formattedTimer, localTimer, syncTimer } = useSyncedTimer(
    props.timer ?? defaultTimer,
);

const {
    buttonTitle,
    exitClickCount,
    isFullscreen,
    remainingExitClicks,
    requiredExitClicks,
    triggerFullscreen,
} = useFullscreenLock();

const threeJuries = [1, 2, 3];
const defaultJuries = [1, 2, 3, 4, 5];

const isOngoingMatch = (match: SeniMatch | null | undefined) => {
    return match?.status === 'ongoing';
};

const setRankedMatches = (matches: SeniMatch[] | null | undefined) => {
    rankedMatches.value = isOngoingMatch(currentMatch.value)
        ? []
        : (matches ?? []);
};

const setCurrentMatch = (match: SeniMatch | null | undefined) => {
    currentMatch.value = match ?? null;

    if (isOngoingMatch(currentMatch.value)) {
        rankedMatches.value = [];
    }
};

setRankedMatches(rankedMatches.value);

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

const secretaryLabel = computed(() => {
    if (userName.value.toLowerCase().includes('sekretaris')) {
        return userName.value;
    }

    return 'Sekretaris';
});

const isWaiting = computed(() => {
    return (
        rankedMatches.value.length === 0 &&
        (!currentMatch.value || currentMatch.value.status === 'not_started')
    );
});

const shouldShowWinnerTable = computed(() => {
    if (isOngoingMatch(currentMatch.value)) {
        return false;
    }

    return (
        rankedMatches.value.length > 0 &&
        rankedMatches.value.every(
            (match) => match.rank !== null && match.rank !== undefined,
        )
    );
});

const isTechniqueMatch = (match: SeniMatch | null | undefined) => {
    const matchText = [match?.type, match?.category, match?.group]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return matchText.includes('ganda') || matchText.includes('trio');
};

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
            class: 'border-red-500/30 bg-red-500/15 text-red-400',
        };
    }

    if (match.is_passed) {
        return {
            label: 'Lolos',
            class: 'border-green-500/30 bg-green-500/15 text-green-400',
        };
    }

    return {
        label: 'Tidak Lolos',
        class: 'border-stone-700 bg-zinc-950 text-muted-foreground',
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

const juryScores = computed(() => currentMatch.value?.jury_scores ?? []);
const juryPunishments = computed(
    () => currentMatch.value?.jury_punishments ?? [],
);
const normalizeJuryNumbers = (juryNumbers: Array<number | string>) => {
    return [...new Set(juryNumbers.map((juryNumber) => Number(juryNumber)))]
        .filter((juryNumber) => Number.isInteger(juryNumber))
        .filter((juryNumber) => juryNumber >= 1 && juryNumber <= 5)
        .sort((first, second) => first - second);
};
const juries = computed(() => {
    return normalizeJuryNumbers(props.scoringJuries ?? []).length === 3
        ? threeJuries
        : defaultJuries;
});

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

/**
 * Accepted / rejected, in the same language the tanding board uses for a
 * winning corner: a solid fill with contrasting text on the state itself, and
 * only a wash of that colour behind the numbers.
 */
const STAGE_WIDTH = 1920;
const BOARD_PADDING = 28;
const MAX_TOTAL_FONT_SIZE = 176;
const MAX_BREAKDOWN_FONT_SIZE = 72;

/**
 * Accepted / rejected shows on the final score alone - the rows above it stay
 * neutral, so the eye lands on the one cell carrying the verdict.
 *
 * The fill is `green-700`, not a brighter green: against this board's gold
 * (`yellow-400`) and warm red, a light mint green reads cold and fights the
 * header bar for attention, while a deep green sits under the gold and still
 * says "valid". It also carries white text at 5.0:1, where `green-500` managed
 * 2.3:1 and had to fall back to black. A rejected score stays legible at
 * 7.0:1, just unhighlighted.
 */
const juryTotalClass = (juryNumber: number) => {
    return isAccepted(juryNumber)
        ? 'bg-green-700 text-white'
        : 'bg-zinc-900 text-stone-400';
};

/**
 * The total is the one number read from the back of the hall, so it is sized
 * to the cell rather than picked. The board is laid out on a fixed 1920px
 * canvas, so the column width is known up front: no measuring, no reflow, and
 * a five-jury column simply gets a smaller number than a three-jury one.
 */
const juryTotalFontSize = computed(() => {
    const columnCount = juries.value.length || 1;
    const cellWidth = (STAGE_WIDTH - BOARD_PADDING * 2) / columnCount - 48;
    const longestTotal = juries.value.reduce((longest, juryNumber) => {
        return Math.max(
            longest,
            formatScore(finalScoreForJury(juryNumber)).length,
        );
    }, 1);

    // Black-weight tabular digits run about 0.58em wide.
    return Math.round(
        Math.min(MAX_TOTAL_FONT_SIZE, cellWidth / (longestTotal * 0.62)),
    );
});

/** The same fit, for the two half-width cells above the total. */
const juryBreakdownFontSize = computed(() => {
    const columnCount = juries.value.length || 1;
    const cellWidth = (STAGE_WIDTH - BOARD_PADDING * 2) / columnCount / 2 - 20;
    const longestValue = juries.value.reduce((longest, juryNumber) => {
        return Math.max(
            longest,
            formatScore(scoreSubtotalForJury(juryNumber)).length,
            formatCell(punishmentSubtotalForJury(juryNumber), true).length,
        );
    }, 1);

    return Math.round(
        Math.min(MAX_BREAKDOWN_FONT_SIZE, cellWidth / (longestValue * 0.58)),
    );
});

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

const recapCards = computed(() => {
    const match = currentMatch.value;

    if (isTechniqueMatch(match)) {
        return [
            {
                label: 'Nilai Akhir',
                value: formatScore(numericValue(match?.total_score)),
                valueClass: 'text-black',
                cardClass: 'border-yellow-600 bg-yellow-400 text-black',
            },
            {
                label: 'Kualitas Teknik',
                value: formatScore(numericValue(match?.total_kualitas_teknik)),
                valueClass: 'text-white',
                cardClass: 'border-stone-800 bg-zinc-900',
            },
            {
                label: 'Kuantitas Teknik',
                value: formatScore(numericValue(match?.total_kuantitas_teknik)),
                valueClass: 'text-white',
                cardClass: 'border-stone-800 bg-zinc-900',
            },
            {
                label: 'Total Hukuman',
                value: formatCell(numericValue(match?.total_punishment), true),
                valueClass: 'text-red-500',
                cardClass: 'border-stone-800 bg-zinc-900',
            },
            {
                label: 'Waktu',
                value: formatTime(match?.time),
                valueClass: 'text-white',
                cardClass: 'border-stone-800 bg-zinc-900',
            },
        ];
    }

    return [
        {
            label: 'Nilai Akhir',
            value: formatScore(numericValue(match?.total_score)),
            valueClass: 'text-black',
            cardClass: 'border-yellow-600 bg-yellow-400 text-black',
        },
        {
            label: 'Wiraga',
            value: formatScore(numericValue(match?.total_wiraga)),
            valueClass: 'text-white',
            cardClass: 'border-stone-800 bg-zinc-900',
        },
        {
            label: 'Wirasa',
            value: formatScore(numericValue(match?.total_wirasa)),
            valueClass: 'text-white',
            cardClass: 'border-stone-800 bg-zinc-900',
        },
        {
            label: 'Total Hukuman',
            value: formatCell(numericValue(match?.total_punishment), true),
            valueClass: 'text-red-500',
            cardClass: 'border-stone-800 bg-zinc-900',
        },
        {
            label: 'Waktu',
            value: formatTime(match?.time),
            valueClass: 'text-white',
            cardClass: 'border-stone-800 bg-zinc-900',
        },
    ];
});

const isTimerDisplayed = computed(() => Boolean(localTimer.value.is_display));

/** What the header's centre cell falls back to, as on the tanding board. */
const timerFallbackLabel = computed(
    () => props.arena?.arena_name ?? props.arena?.gelanggang_id ?? '-',
);

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

    const isStartingMatch =
        ['not_started', 'done'].includes(currentMatch.value.status) &&
        updatedMatch.status === 'ongoing';

    return (
        getMatchId(currentMatch.value) !== getMatchId(updatedMatch) ||
        isStartingMatch
    );
};

const syncScoreStateFromPage = (page: any) => {
    setCurrentMatch((page.props.activeMatch ?? null) as SeniMatch | null);
    setRankedMatches((page.props.rankedMatches ?? []) as SeniMatch[]);
};

const reloadScoreStateFromDatabase = () => {
    router.reload({
        only: ['activeMatch', 'rankedMatches'],
        onSuccess: syncScoreStateFromPage,
    });
};

const reloadWinnerTableFromDatabase = () => {
    router.reload({
        only: ['activeMatch', 'rankedMatches'],
        onSuccess: syncScoreStateFromPage,
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

watch(
    () => props.activeMatch,
    (match) => {
        setCurrentMatch(match);
    },
    { deep: true },
);

watch(
    () => props.rankedMatches,
    (matches) => {
        setRankedMatches(matches);
    },
    { deep: true },
);

watch(
    () => props.timer,
    (timer) => {
        if (timer) {
            syncTimer(timer);
        }
    },
    { deep: true },
);

let echoStatusChannel: any = null;
let echoScoreChannel: any = null;
let echoTimerChannel: any = null;

onMounted(() => {
    reloadInitialScoreStateFromDatabase();

    const echo = (window as any).Echo;

    if (!echo) {
        return;
    }

    echoStatusChannel = echo
        .channel('seni.match.status')
        .listen('.SeniMatchUpdated', (event: any) => {
            if (event.status === 'rank_updated') {
                reloadWinnerTableFromDatabase();

                return;
            }

            if (!event.match) {
                return;
            }

            const shouldReload = shouldReloadScoreStateFromDatabase(
                event.match,
            );

            setCurrentMatch({
                ...(currentMatch.value ?? {}),
                ...event.match,
            } as SeniMatch);

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
                setCurrentMatch(event.match);

                return;
            }

            if (event.match) {
                setCurrentMatch({
                    ...(currentMatch.value ?? {}),
                    ...event.match,
                } as SeniMatch);
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
                syncTimer(event.timer);
            }
        });
});

onUnmounted(() => {
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
    <Head title="Sekretaris Seni - Tapak Suci" />

    <TvStage
        :on-fullscreen="triggerFullscreen"
        stage-class="flex h-full w-full overflow-hidden bg-zinc-900 text-foreground"
    >
        <template v-if="isWaiting">
            <FightWaitingState clickable :on-logo-click="triggerFullscreen" />
        </template>

        <template v-else-if="shouldShowWinnerTable">
            <div class="relative z-10 flex h-full w-full flex-col bg-zinc-950">
                <div
                    class="grid h-14 w-full shrink-0 grid-cols-[1fr_auto_1fr] items-center border-b border-stone-800 bg-zinc-900 px-6 text-[11px] font-bold tracking-widest text-muted-foreground uppercase shadow-sm"
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
                        <span class="font-bold text-yellow-500">{{
                            secretaryLabel
                        }}</span>
                    </div>

                    <div class="text-center text-lg font-black text-white">
                        HASIL KEPUTUSAN SENI
                    </div>

                    <div class="justify-self-end">
                        Gelanggang
                        {{
                            props.arena?.arena_name ??
                            props.arena?.gelanggang_id ??
                            '-'
                        }}
                    </div>
                </div>

                <main
                    class="custom-scrollbar min-h-0 flex-1 overflow-y-auto p-6"
                >
                    <section
                        class="overflow-hidden rounded-lg border border-stone-800 bg-zinc-900 shadow-[0_18px_50px_rgba(0,0,0,0.28)]"
                    >
                        <table class="w-full min-w-[1180px] border-collapse">
                            <thead>
                                <tr
                                    class="border-b border-stone-800 bg-zinc-950/70 text-left text-xs font-black tracking-widest text-muted-foreground uppercase"
                                >
                                    <th class="px-4 py-4 text-center">
                                        No Urut
                                    </th>
                                    <th class="px-4 py-4 text-center">Rank</th>
                                    <th class="px-4 py-4">Kontingen</th>
                                    <th class="px-4 py-4">Atlet</th>
                                    <th class="px-4 py-4 text-right">Total</th>
                                    <template
                                        v-if="winnerScoringMode === 'tgr'"
                                    >
                                        <th class="px-4 py-4 text-right">
                                            Wiraga
                                        </th>
                                        <th class="px-4 py-4 text-right">
                                            Wirasa
                                        </th>
                                    </template>
                                    <template v-else>
                                        <th class="px-4 py-4 text-right">
                                            Kualitas Teknik
                                        </th>
                                        <th class="px-4 py-4 text-right">
                                            Kuantitas Teknik
                                        </th>
                                    </template>
                                    <th class="px-4 py-4 text-right">
                                        Hukuman
                                    </th>
                                    <th class="px-4 py-4 text-center">Waktu</th>
                                    <th class="px-4 py-4 text-center">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-800">
                                <tr
                                    v-for="match in winnerRows"
                                    :key="match.id"
                                    :class="[
                                        'text-white',
                                        match.is_passed
                                            ? 'bg-green-500/10'
                                            : 'bg-zinc-900',
                                    ]"
                                >
                                    <td
                                        class="px-4 py-4 text-center font-bold tabular-nums"
                                    >
                                        {{ match.no_order ?? '-' }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-center text-4xl font-black text-yellow-400 tabular-nums"
                                    >
                                        {{ match.rank ?? '-' }}
                                    </td>
                                    <td class="px-4 py-4 font-bold uppercase">
                                        {{ match.contingent ?? '-' }}
                                    </td>
                                    <td class="px-4 py-4 uppercase">
                                        {{ match.atletes ?? '-' }}
                                    </td>
                                    <td
                                        class="px-4 py-4 text-right text-xl font-black text-yellow-400 tabular-nums"
                                    >
                                        {{
                                            formatScore(
                                                numericValue(match.total_score),
                                            )
                                        }}
                                    </td>
                                    <template
                                        v-if="winnerScoringMode === 'tgr'"
                                    >
                                        <td
                                            class="px-4 py-4 text-right text-lg font-black tabular-nums"
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
                                            class="px-4 py-4 text-right text-lg font-black tabular-nums"
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
                                            class="px-4 py-4 text-right text-lg font-black tabular-nums"
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
                                            class="px-4 py-4 text-right text-lg font-black tabular-nums"
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
                                        class="px-4 py-4 text-right text-xl font-black text-red-500 tabular-nums"
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
                                    <td class="px-4 py-4 text-center">
                                        {{ formatTime(match.time) }}
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <span
                                            :class="[
                                                'inline-flex rounded-md border px-3 py-1 text-xs font-black tracking-widest uppercase',
                                                winnerStatusMeta(match).class,
                                            ]"
                                        >
                                            {{ winnerStatusMeta(match).label }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </main>
            </div>
        </template>

        <template v-else>
            <div class="relative z-10 flex h-full w-full flex-col bg-zinc-900">
                <div
                    class="grid h-16 w-full shrink-0 grid-cols-[1fr_auto_1fr] items-center border-b border-stone-800 bg-zinc-900 px-6 text-base font-bold tracking-widest text-muted-foreground uppercase shadow-sm"
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
                        <span class="font-bold text-yellow-500">{{
                            secretaryLabel
                        }}</span>
                        <div class="h-6 w-px bg-stone-800"></div>
                        <span>
                            Gelanggang
                            {{
                                props.arena?.arena_name ??
                                props.arena?.gelanggang_id ??
                                '-'
                            }}
                        </span>
                    </div>

                    <div class="px-4 text-center text-xl text-white">
                        {{ matchMetaLabel || '-' }}
                    </div>

                    <div class="flex items-center gap-3 justify-self-end">
                        <div class="h-6 w-px bg-stone-800"></div>
                        <span>
                            No Partai
                            <span class="ml-1 text-xl text-white tabular-nums">
                                {{ currentMatch?.matches_code ?? '-' }}
                            </span>
                        </span>
                        <div class="h-6 w-px bg-stone-800"></div>
                        <span>
                            No Penampil
                            <span class="ml-1 text-xl text-white tabular-nums">
                                {{ currentMatch?.no_order ?? '-' }}
                            </span>
                        </span>
                    </div>
                </div>

                <!--
                    The tanding board's header, with seni's own figures: a
                    title row that doubles as the fullscreen target, then one
                    mono row of the three numbers an audience looks for. The
                    timer lives here rather than in a bar of its own, so the
                    board stops shifting when a performance starts and ends.
                -->
                <div
                    class="flex h-52 w-full shrink-0 bg-zinc-950 px-7 pt-5 text-center"
                >
                    <div
                        class="flex h-full w-full flex-col overflow-hidden rounded-2xl border border-stone-800 bg-zinc-900 shadow-2xl"
                    >
                        <div
                            class="flex h-24 shrink-0 cursor-pointer flex-col items-center justify-center border-b border-stone-800 bg-yellow-400 px-8 text-black"
                            :title="buttonTitle"
                            @click="triggerFullscreen"
                        >
                            <div
                                class="flex flex-wrap items-center justify-center gap-x-4"
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
                                class="mt-1.5 text-2xl font-black tracking-widest uppercase"
                            >
                                {{ currentMatch?.contingent ?? '-' }}
                            </p>
                        </div>

                        <div
                            class="grid min-h-0 flex-1 grid-cols-[1fr_1.35fr_1fr] font-mono text-stone-100 uppercase tabular-nums"
                        >
                            <div
                                class="flex items-center justify-center border-r border-stone-800 bg-zinc-900"
                            >
                                <span class="text-6xl font-black">
                                    PARTAI
                                    {{ currentMatch?.matches_code ?? '-' }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-center border-r border-stone-800 bg-zinc-900"
                            >
                                <span
                                    v-if="isTimerDisplayed"
                                    class="text-6xl font-black tracking-widest"
                                >
                                    {{ formattedTimer }}
                                </span>
                                <span
                                    v-else
                                    class="max-w-full truncate px-6 text-4xl font-black tracking-widest"
                                >
                                    {{ timerFallbackLabel }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-center bg-zinc-900"
                            >
                                <span class="text-6xl font-black">
                                    PENAMPIL
                                    {{ currentMatch?.no_order ?? '-' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <main
                    class="flex min-h-0 flex-1 flex-col gap-5 overflow-hidden bg-zinc-950 p-7"
                >
                    <!--
                        One column per jury, built like the tanding board's
                        jury recap: muted header bands, a labelled split row,
                        then the final score - the only cell that carries the
                        accepted / rejected verdict.
                    -->
                    <div
                        class="grid min-h-0 flex-1 overflow-hidden rounded-2xl border border-stone-800 bg-zinc-900 shadow-2xl"
                        :style="{
                            gridTemplateColumns: `repeat(${juries.length}, minmax(0, 1fr))`,
                        }"
                    >
                        <div
                            v-for="(juryNumber, index) in juries"
                            :key="juryNumber"
                            :class="[
                                'flex min-w-0 flex-col',
                                index === juries.length - 1
                                    ? ''
                                    : 'border-r border-stone-800',
                            ]"
                        >
                            <div
                                class="flex h-12 shrink-0 items-center justify-center border-b border-stone-800 bg-black/40 text-xl font-black tracking-widest text-stone-200 uppercase"
                            >
                                Juri {{ juryNumber }}
                            </div>

                            <div
                                class="grid h-9 shrink-0 grid-cols-2 border-b border-stone-800 bg-black/40 text-center text-sm font-black tracking-widest text-muted-foreground uppercase"
                            >
                                <div
                                    class="flex items-center justify-center border-r border-stone-800"
                                >
                                    Nilai
                                </div>
                                <div class="flex items-center justify-center">
                                    Hukuman
                                </div>
                            </div>

                            <div
                                class="grid h-24 shrink-0 grid-cols-2 border-b border-stone-800 text-center leading-none font-black tabular-nums"
                                :style="{
                                    fontSize: `${juryBreakdownFontSize}px`,
                                }"
                            >
                                <div
                                    class="flex items-center justify-center border-r border-stone-800 px-2 text-white"
                                >
                                    {{
                                        formatScore(
                                            scoreSubtotalForJury(juryNumber),
                                        )
                                    }}
                                </div>
                                <div
                                    class="flex items-center justify-center px-2 text-red-500"
                                >
                                    {{
                                        formatCell(
                                            punishmentSubtotalForJury(
                                                juryNumber,
                                            ),
                                            true,
                                        )
                                    }}
                                </div>
                            </div>

                            <div
                                :class="[
                                    'flex min-h-0 flex-1 items-center justify-center px-3 leading-none font-black tabular-nums',
                                    juryTotalClass(juryNumber),
                                ]"
                                :style="{ fontSize: `${juryTotalFontSize}px` }"
                            >
                                {{ formatScore(finalScoreForJury(juryNumber)) }}
                            </div>
                        </div>
                    </div>

                    <!-- The partai's own totals, one card each. -->
                    <section
                        class="grid shrink-0 gap-4"
                        :style="{
                            gridTemplateColumns: `repeat(${recapCards.length}, minmax(0, 1fr))`,
                        }"
                    >
                        <div
                            v-for="card in recapCards"
                            :key="card.label"
                            :class="[
                                'flex flex-col items-center overflow-hidden rounded-xl border px-4 py-4 text-center shadow-lg',
                                card.cardClass,
                            ]"
                        >
                            <p
                                class="text-sm font-black tracking-widest uppercase opacity-70"
                            >
                                {{ card.label }}
                            </p>
                            <p
                                :class="[
                                    'mt-2 text-6xl leading-none font-black tabular-nums',
                                    card.valueClass,
                                ]"
                            >
                                {{ card.value }}
                            </p>
                        </div>
                    </section>
                </main>
            </div>
        </template>
    </TvStage>
</template>
