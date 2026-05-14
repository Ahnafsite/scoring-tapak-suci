<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useFullscreenLock } from '@/composables/useFullscreenLock';
import {
    useSyncedTimer,
    type SyncedTimerState,
} from '@/composables/useSyncedTimer';

const props = defineProps<{
    arena: any;
    activeMatch?: any;
    recapPoints?: any[];
    yellowPoints?: any[];
    bluePoints?: any[];
    timer?: TimerState;
}>();

type TimerState = SyncedTimerState & {
    id: number | null;
    is_display: boolean;
    stored_status?: 'running' | 'paused' | 'stopped';
};

type MatchDisplayStatus = 'not_started' | 'ongoing' | 'paused' | 'done';

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

const currentMatch = ref<any>(props.activeMatch ?? null);
const { buttonTitle, triggerFullscreen } = useFullscreenLock();
const localRecapPoints = ref<any[]>([...(props.recapPoints || [])]);
const localYellowPoints = ref<any[]>([...(props.yellowPoints || [])]);
const localBluePoints = ref<any[]>([...(props.bluePoints || [])]);
const {
    formattedTimer,
    localTimer,
    syncTimer,
} = useSyncedTimer(props.timer ?? defaultTimer);
const scoreNameById: Record<number, string> = {
    1: '20',
    2: '10+20',
    3: '30',
    4: '10+30',
    5: '40',
    6: '10+40',
    7: '50',
};
const punishmentScoreById: Record<number, number> = {
    1: 10,
    2: 20,
    3: 30,
    4: 40,
};

const shouldReloadScoresForMatchUpdate = (updatedMatch: any) => {
    if (!updatedMatch) {
        return false;
    }

    if (!currentMatch.value) {
        return true;
    }

    const statusChanged = currentMatch.value.status !== updatedMatch.status;
    const shouldRefreshRecap = ['paused', 'done'].includes(updatedMatch.status);

    return (
        currentMatch.value.id !== updatedMatch.id ||
        currentMatch.value.round_number !== updatedMatch.round_number ||
        (updatedMatch.status === 'ongoing' &&
            currentMatch.value.status !== 'ongoing') ||
        (statusChanged && shouldRefreshRecap)
    );
};

const applyScoreStateFromProps = (pageProps: any) => {
    localRecapPoints.value = [...(pageProps.recapPoints || [])];
    localYellowPoints.value = [...(pageProps.yellowPoints || [])];
    localBluePoints.value = [...(pageProps.bluePoints || [])];
    currentMatch.value = pageProps.activeMatch ?? null;
};

const reloadScoreStateFromDatabase = () => {
    router.reload({
        only: ['activeMatch', 'recapPoints', 'yellowPoints', 'bluePoints'],
        onSuccess: (page: any) => {
            applyScoreStateFromProps(page.props);
        },
    });
};

const isScoreEventForCurrentMatch = (event: any) => {
    if (!event.partaiId || !currentMatch.value?.id) {
        return true;
    }

    return Number(event.partaiId) === Number(currentMatch.value.id);
};

watch(
    () => [
        props.activeMatch,
        props.recapPoints,
        props.yellowPoints,
        props.bluePoints,
    ],
    ([activeMatch, recapPoints, yellowPoints, bluePoints]) => {
        applyScoreStateFromProps({
            activeMatch,
            recapPoints,
            yellowPoints,
            bluePoints,
        });
    },
    { deep: true },
);
watch(
    () => props.timer,
    (newVal) => {
        if (newVal) {
            syncTimer(newVal);
        }
    },
    { deep: true },
);

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

const hasActiveMatch = computed(() => currentMatch.value !== null);

const isSameRound = (left: any, right: any) => Number(left) === Number(right);

const activeRoundRecap = computed(() => {
    if (!currentMatch.value) {
        return null;
    }

    return (
        localRecapPoints.value.find((recap: any) =>
            isSameRound(recap.round_number, currentMatch.value.round_number),
        ) ?? null
    );
});

const upsertRecapPoint = (recap: any) => {
    const index = localRecapPoints.value.findIndex((item: any) =>
        isSameRound(item.round_number, recap.round_number),
    );

    if (index !== -1) {
        localRecapPoints.value.splice(index, 1, recap);

        return;
    }

    localRecapPoints.value.push(recap);
};

const getCornerStats = (cornerPoints: any[], roundNumbers = [1, 2, 3]) => {
    const stats: Record<string, number> = {
        '20': 0,
        '10+20': 0,
        '30': 0,
        '10+30': 0,
        '40': 0,
        '10+40': 0,
        '50': 0,
        punishmentPoints: 0,
    };

    roundNumbers.forEach((roundNumber) => {
        const pointsArray = cornerPoints.filter(
            (point: any) => isSameRound(point.round_number, roundNumber),
        );
        const juryPointCounts: Record<number, Record<string, number>> = {
            1: {},
            2: {},
            3: {},
            4: {},
        };
        const idToScore: Record<string, any> = {};

        pointsArray.forEach((point: any) => {
            const juryNumber = point.jury_number;

            if (juryNumber >= 1 && juryNumber <= 4) {
                const id = point.ref_score_id
                    ? `s:${point.ref_score_id}`
                    : `p:${point.ref_punishment_id}`;

                juryPointCounts[juryNumber][id] =
                    (juryPointCounts[juryNumber][id] || 0) + 1;
                idToScore[id] = point;
            }
        });

        const allIds = new Set<string>();
        [1, 2, 3, 4].forEach((juryNumber) =>
            Object.keys(juryPointCounts[juryNumber]).forEach((key) =>
                allIds.add(key),
            ),
        );

        allIds.forEach((id) => {
            let maxOccurrences = 0;

            for (let juryNumber = 1; juryNumber <= 4; juryNumber++) {
                maxOccurrences = Math.max(
                    maxOccurrences,
                    juryPointCounts[juryNumber][id] || 0,
                );
            }

            let validCount = 0;

            for (let index = 1; index <= maxOccurrences; index++) {
                let juryCount = 0;

                for (let juryNumber = 1; juryNumber <= 4; juryNumber++) {
                    if ((juryPointCounts[juryNumber][id] || 0) >= index) {
                        juryCount++;
                    }
                }

                if (juryCount >= 3) {
                    validCount++;
                }
            }

            if (validCount > 0) {
                const point = idToScore[id];

                if (id.startsWith('s:')) {
                    const scoreName =
                        point.score?.name ??
                        scoreNameById[Number(point.ref_score_id)];

                    if (scoreName && stats[scoreName] !== undefined) {
                        stats[scoreName] += validCount;
                    }
                } else if (id.startsWith('p:')) {
                    const punishmentValue =
                        point.punishment?.score !== undefined
                            ? Math.abs(Number(point.punishment.score))
                            : (punishmentScoreById[
                                  Number(point.ref_punishment_id)
                              ] ?? 0) ||
                              Math.abs(
                                  parseInt(point.punishment?.name || '0') || 0,
                              );

                    stats.punishmentPoints += punishmentValue * validCount;
                }
            }
        });
    });

    return stats;
};

const scoringStats = [
    { label: 'Pukulan Katak', score: '20' },
    { label: 'Mawar Lepas Katak Masuk', score: '10+20' },
    { label: 'Tendangan Harimau', score: '30' },
    { label: 'Mawar Lepas Harimau Masuk', score: '10+30' },
    { label: 'Terkaman', score: '40' },
    { label: 'Mawar Lepas Terkaman Masuk', score: '10+40' },
    { label: 'Jatuhan / Sapuan', score: '50' },
] as const;

const currentRoundNumber = computed(
    () => Number(currentMatch.value?.round_number) || 1,
);

const roundWinnerCards = [
    { round: 1, label: 'Ronde 1' },
    { round: 2, label: 'Ronde 2' },
    { round: 3, label: 'Ronde TBH' },
] as const;

const getRoundWinner = (roundNumber: number) =>
    localRecapPoints.value.find((recap: any) =>
        isSameRound(recap.round_number, roundNumber),
    )?.winner ?? null;

const roundWinnerLabel = (winner: any) => {
    if (winner === 'yellow') {
        return 'Kuning';
    }

    if (winner === 'blue') {
        return 'Biru';
    }

    if (winner === 'draw') {
        return 'Seri';
    }

    return '-';
};

const roundWinnerClass = (winner: any) => {
    if (winner === 'yellow') {
        return 'border-yellow-500 bg-yellow-400 text-black';
    }

    if (winner === 'blue') {
        return 'border-blue-500 bg-blue-600 text-white';
    }

    if (winner === 'draw') {
        return 'border-white/50 bg-white/20 text-white';
    }

    return 'border-white/10 bg-black/35 text-white/45 shadow-none';
};

const matchStats = computed(() => {
    let roundWinBlue = 0;
    let roundWinYellow = 0;
    let totalPoinBlue = 0;
    let totalPoinYellow = 0;

    localRecapPoints.value.forEach((recap: any) => {
        if (recap.winner === 'blue') {
            roundWinBlue++;
        }

        if (recap.winner === 'yellow') {
            roundWinYellow++;
        }

        totalPoinBlue += Number(recap.total_poin_blue) || 0;
        totalPoinYellow += Number(recap.total_poin_yellow) || 0;
    });

    return {
        scoreRound: `${roundWinBlue} - ${roundWinYellow}`,
        totalPoinBlue,
        totalPoinYellow,
        blueStats: getCornerStats(localBluePoints.value),
        yellowStats: getCornerStats(localYellowPoints.value),
    };
});

const isMatchDone = computed(() => currentMatch.value?.status === 'done');

const displayedBlueScore = computed(() => {
    if (isMatchDone.value) {
        return matchStats.value.totalPoinBlue;
    }

    return Number(activeRoundRecap.value?.total_poin_blue) || 0;
});

const displayedYellowScore = computed(() => {
    if (isMatchDone.value) {
        return matchStats.value.totalPoinYellow;
    }

    return Number(activeRoundRecap.value?.total_poin_yellow) || 0;
});

const currentRoundStats = computed(() => ({
    blueStats: getCornerStats(localBluePoints.value, [
        currentRoundNumber.value,
    ]),
    yellowStats: getCornerStats(localYellowPoints.value, [
        currentRoundNumber.value,
    ]),
}));

const displayedBlueStats = computed(() =>
    isMatchDone.value
        ? matchStats.value.blueStats
        : currentRoundStats.value.blueStats,
);

const displayedYellowStats = computed(() =>
    isMatchDone.value
        ? matchStats.value.yellowStats
        : currentRoundStats.value.yellowStats,
);

const isTimerDisplayed = computed(() => Boolean(localTimer.value.is_display));

const updateScoreDetail = (event: any) => {
    if (!isScoreEventForCurrentMatch(event)) {
        return;
    }

    if (event.scoreDetail) {
        const targetPoints =
            event.corner === 'yellow' ? localYellowPoints : localBluePoints;

        if (event.scoreDetail.deleted) {
            const targetId = Number(event.scoreDetail.id);
            targetPoints.value = targetPoints.value.filter(
                (point: any) => point.id !== targetId,
            );
        } else {
            targetPoints.value.push(event.scoreDetail);
        }
    }

    if (event.recap) {
        upsertRecapPoint(event.recap);
    }
};

let echoStatusChannel: any = null;
let echoScoreChannel: any = null;
let echoTimerChannel: any = null;

onMounted(() => {
    const echo = (window as any).Echo;

    if (!echo) {
        return;
    }

    echoStatusChannel = echo
        .channel('match.status')
        .listen('.ActiveMatchUpdated', (event: any) => {
            if (event.match) {
                const shouldReloadScores = shouldReloadScoresForMatchUpdate(
                    event.match,
                );

                if (shouldReloadScores) {
                    reloadScoreStateFromDatabase();

                    return;
                }

                currentMatch.value = event.match;
            }
        });

    echoScoreChannel = echo
        .channel('match.score')
        .listen('.JuryScoreUpdated', updateScoreDetail);

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

    if (!echo) {
        return;
    }

    if (echoStatusChannel) {
        echoStatusChannel.stopListening('.ActiveMatchUpdated');
        echo.leaveChannel('match.status');
    }

    if (echoScoreChannel) {
        echoScoreChannel.stopListening('.JuryScoreUpdated');
        echo.leaveChannel('match.score');
    }

    if (echoTimerChannel) {
        echoTimerChannel.stopListening('.TimerUpdated');
        echo.leaveChannel('timer');
    }
});

const roundLabel = computed(() =>
    currentMatch.value?.round_number === 3
        ? 'TBH'
        : (currentMatch.value?.round_number ?? '-'),
);

const matchTitle = computed(() =>
    [
        currentMatch.value?.match_round,
        currentMatch.value?.group,
        currentMatch.value?.category,
    ]
        .filter(Boolean)
        .join(' ')
        .toUpperCase(),
);

const categoryLabel = computed(() =>
    (currentMatch.value?.category || '-').toString().toUpperCase(),
);

const arenaDisplayName = computed(
    () => props.arena?.arena_name ?? props.arena?.gelanggang_id ?? '-',
);

const partaiLabel = computed(() => currentMatch.value?.match_code ?? '-');
</script>

<template>
    <Head title="Fight Streaming Online - Tapak Suci" />

    <div
        class="relative h-dvh w-screen overflow-hidden bg-[#00ff00] text-white"
    >
        <Transition name="broadcast-status" mode="out-in">
            <section
                v-if="hasActiveMatch && matchStatus === 'not_started'"
                key="not-started"
                class="absolute inset-x-0 bottom-[6vh] flex justify-center px-5"
            >
                <div
                    class="overlay-shell w-[min(84vw,1420px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/80 shadow-2xl backdrop-blur-sm"
                    :title="buttonTitle"
                    @click="triggerFullscreen"
                >
                    <div
                        class="overlay-topbar flex h-7 items-center justify-between gap-5 border-b border-white/15 bg-black/90 px-7 text-[10px] font-black tracking-widest text-zinc-200 uppercase"
                    >
                        <span class="min-w-0 break-words">
                            {{ matchTitle || 'Tanding' }}
                        </span>
                        <span class="shrink-0 text-yellow-300">
                            Next Match
                        </span>
                    </div>

                    <div
                        class="grid h-20 grid-cols-[minmax(0,1.4fr)_10rem_minmax(0,1.4fr)] items-stretch"
                    >
                        <div
                            class="overlay-blue flex min-w-0 flex-col items-end justify-center gap-0.5 bg-gradient-to-r from-blue-700 to-blue-600 px-6 text-right text-white"
                        >
                            <h2
                                class="max-w-full text-[clamp(1.15rem,1.65vw,1.85rem)] leading-tight font-black break-words uppercase drop-shadow-sm"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="max-w-full text-sm leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                        </div>

                        <div
                            class="overlay-center relative z-10 flex flex-col items-center justify-center gap-0.5 border-x border-white/15 bg-zinc-950/95 px-3 text-center shadow-[0_0_35px_rgba(0,0,0,0.55)]"
                        >
                            <div
                                class="text-sm leading-tight font-black tracking-widest text-yellow-300 uppercase"
                            >
                                Partai {{ partaiLabel }}
                            </div>
                            <div
                                class="text-[10px] font-black tracking-widest text-zinc-400 uppercase"
                            >
                                Gelanggang
                            </div>
                            <div
                                class="max-w-full text-xs leading-tight font-black break-words text-zinc-100 uppercase"
                            >
                                {{ arenaDisplayName }}
                            </div>
                        </div>

                        <div
                            class="overlay-yellow flex min-w-0 flex-col items-start justify-center gap-0.5 bg-gradient-to-l from-yellow-400 to-yellow-300 px-6 text-left text-black"
                        >
                            <h2
                                class="max-w-full text-[clamp(1.15rem,1.65vw,1.85rem)] leading-tight font-black break-words uppercase drop-shadow-sm"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="max-w-full text-sm leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="overlay-footer flex h-7 items-center justify-center border-t border-white/15 bg-black/90 px-7 text-xs font-black tracking-widest uppercase"
                    >
                        juarasilat.com
                    </div>
                </div>
            </section>

            <section
                v-else-if="hasActiveMatch && matchStatus === 'ongoing'"
                key="ongoing"
                class="absolute inset-x-0 bottom-[5vh] flex flex-col items-center gap-2 px-5"
            >
                <div
                    class="overlay-topbar rounded-md border border-white/20 bg-black/90 px-5 py-1.5 text-xs font-black tracking-widest text-yellow-300 uppercase shadow-xl"
                >
                    {{ matchTitle || 'Tanding' }}
                </div>

                <div
                    class="overlay-shell w-[min(90vw,1560px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/80 shadow-2xl backdrop-blur-sm"
                    :title="buttonTitle"
                    @click="triggerFullscreen"
                >
                    <div
                        class="grid h-24 grid-cols-[minmax(0,1.45fr)_17rem_minmax(0,1.45fr)] items-stretch"
                    >
                        <div
                            class="overlay-blue flex min-w-0 items-center justify-end gap-5 bg-gradient-to-r from-blue-700 to-blue-600 px-5 text-right text-white"
                        >
                            <div class="min-w-0">
                                <h2
                                    class="max-w-full text-[clamp(1.05rem,1.45vw,1.6rem)] leading-tight font-black break-words uppercase"
                                >
                                    {{
                                        currentMatch?.atlete_blue ||
                                        currentMatch?.athlete_blue ||
                                        '-'
                                    }}
                                </h2>
                                <p
                                    class="max-w-full text-xs leading-tight font-bold break-words uppercase"
                                >
                                    {{ currentMatch?.contingent_blue || '-' }}
                                </p>
                            </div>
                            <div
                                class="overlay-score shrink-0 text-5xl font-black text-white tabular-nums"
                            >
                                {{ activeRoundRecap?.total_poin_blue || 0 }}
                            </div>
                        </div>

                        <div
                            class="overlay-center relative z-10 flex flex-col items-center justify-center gap-1 border-x border-white/15 bg-zinc-950/95 px-3 text-center shadow-[0_0_35px_rgba(0,0,0,0.55)]"
                        >
                            <div
                                class="text-xs leading-tight font-black tracking-widest text-yellow-300 uppercase"
                            >
                                Partai {{ partaiLabel }}
                            </div>
                            <div
                                v-if="isTimerDisplayed"
                                class="overlay-score font-mono text-[clamp(1.45rem,2.45vw,2.55rem)] leading-none font-black tracking-wider text-white tabular-nums"
                            >
                                {{ formattedTimer }}
                            </div>
                            <div
                                v-else
                                class="max-w-full text-[10px] leading-tight font-black break-words text-zinc-100 uppercase"
                            >
                                {{ arenaDisplayName }}
                            </div>
                            <div
                                class="text-xs leading-tight font-black tracking-widest text-zinc-400 uppercase"
                            >
                                Round {{ roundLabel }}
                            </div>
                        </div>

                        <div
                            class="overlay-yellow flex min-w-0 items-center justify-start gap-5 bg-gradient-to-l from-yellow-400 to-yellow-300 px-5 text-left text-black"
                        >
                            <div
                                class="overlay-score shrink-0 text-5xl font-black text-black tabular-nums"
                            >
                                {{ activeRoundRecap?.total_poin_yellow || 0 }}
                            </div>
                            <div class="min-w-0">
                                <h2
                                    class="max-w-full text-[clamp(1.05rem,1.45vw,1.6rem)] leading-tight font-black break-words uppercase"
                                >
                                    {{
                                        currentMatch?.atlete_yellow ||
                                        currentMatch?.athlete_yellow ||
                                        '-'
                                    }}
                                </h2>
                                <p
                                    class="max-w-full text-xs leading-tight font-bold break-words uppercase"
                                >
                                    {{ currentMatch?.contingent_yellow || '-' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="overlay-footer flex h-7 items-center justify-between gap-5 border-t border-white/15 bg-black/90 px-7 text-xs font-black tracking-widest uppercase"
                    >
                        <span>{{ matchTitle || 'Tanding' }}</span>
                        <span>juarasilat.com</span>
                    </div>
                </div>
            </section>

            <article
                v-else-if="hasActiveMatch && matchStatus === 'paused'"
                key="paused"
                class="overlay-shell absolute inset-x-0 bottom-[5vh] mx-auto max-h-[88vh] w-[min(96vw,1620px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/60 text-white shadow-2xl backdrop-blur-sm"
                :title="buttonTitle"
                @click="triggerFullscreen"
            >
                <header
                    class="grid min-h-32 grid-cols-[minmax(0,1fr)_16rem_minmax(0,1fr)] border-b border-white/15"
                >
                    <div
                        class="overlay-blue flex min-w-0 items-center justify-end gap-5 bg-blue-600/90 px-7 text-white"
                    >
                        <div class="min-w-0 text-right">
                            <h2
                                class="max-w-full text-3xl leading-tight font-black break-words uppercase"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="max-w-full text-lg leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                        </div>
                        <div
                            class="overlay-score shrink-0 text-6xl font-black text-white tabular-nums"
                        >
                            {{ displayedBlueScore }}
                        </div>
                    </div>

                    <div
                        class="overlay-center flex flex-col items-center justify-center gap-2 border-x border-white/15 bg-black/75 px-5 text-center"
                    >
                        <span
                            class="text-sm leading-tight font-black tracking-widest text-yellow-300 uppercase"
                        >
                            Partai {{ partaiLabel }}
                        </span>
                        <div
                            class="text-xs font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Gelanggang
                        </div>
                        <div
                            class="max-w-full text-sm font-black break-words text-zinc-100 uppercase"
                        >
                            {{ arenaDisplayName }}
                        </div>
                    </div>

                    <div
                        class="overlay-yellow flex min-w-0 items-center justify-start gap-5 bg-yellow-400/90 px-7 text-black"
                    >
                        <div
                            class="overlay-score shrink-0 text-6xl font-black text-black tabular-nums"
                        >
                            {{ displayedYellowScore }}
                        </div>
                        <div class="min-w-0 text-left">
                            <h2
                                class="max-w-full text-3xl leading-tight font-black break-words uppercase"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="max-w-full text-lg leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div class="grid gap-5 p-5">
                    <div class="flex w-full justify-center gap-5">
                        <div
                            v-for="(roundWinner, index) in roundWinnerCards"
                            :key="roundWinner.round"
                            class="overlay-stat-row relative flex w-44 flex-col items-center"
                            :style="{
                                '--row-delay': `${700 + index * 75}ms`,
                            }"
                        >
                            <div
                                class="absolute -top-3 z-10 rounded-full bg-black px-2 text-[10px] font-black tracking-widest text-white/65 uppercase"
                            >
                                {{ roundWinner.label }}
                            </div>
                            <div
                                :class="[
                                    'relative w-full overflow-hidden rounded-md border py-3 text-center text-base font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                    roundWinnerClass(
                                        getRoundWinner(roundWinner.round),
                                    ),
                                ]"
                            >
                                {{
                                    roundWinnerLabel(
                                        getRoundWinner(roundWinner.round),
                                    )
                                }}
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <div
                            v-for="(stat, index) in scoringStats"
                            :key="stat.score"
                            class="overlay-stat-row grid grid-cols-[6rem_minmax(0,1fr)_6rem] items-center gap-4 rounded-md border border-white/10 bg-black/40 px-5 py-2.5"
                            :style="{
                                '--row-delay': `${925 + index * 75}ms`,
                            }"
                        >
                            <div
                                class="text-center text-2xl font-black text-white tabular-nums"
                            >
                                {{ currentRoundStats.blueStats[stat.score] }}
                            </div>
                            <div
                                class="text-center text-base font-black tracking-widest text-white uppercase"
                            >
                                {{ stat.label }}
                                <span class="text-xs text-white/70"
                                    >({{ stat.score }})</span
                                >
                            </div>
                            <div
                                class="text-center text-2xl font-black text-white tabular-nums"
                            >
                                {{ currentRoundStats.yellowStats[stat.score] }}
                            </div>
                        </div>

                        <div
                            class="overlay-stat-row grid grid-cols-[6rem_minmax(0,1fr)_6rem] items-center gap-4 rounded-md border border-white/10 bg-black/40 px-5 py-2.5"
                            :style="{
                                '--row-delay': `${925 + scoringStats.length * 75}ms`,
                            }"
                        >
                            <div
                                class="text-center text-2xl font-black text-white tabular-nums"
                            >
                                {{
                                    currentRoundStats.blueStats.punishmentPoints
                                }}
                            </div>
                            <div
                                class="text-center text-base font-black tracking-widest text-white uppercase"
                            >
                                Hukuman
                            </div>
                            <div
                                class="text-center text-2xl font-black text-white tabular-nums"
                            >
                                {{
                                    currentRoundStats.yellowStats
                                        .punishmentPoints
                                }}
                            </div>
                        </div>
                    </div>
                </div>

                <footer
                    class="overlay-footer flex h-8 items-center justify-between gap-5 border-t border-white/15 bg-black/60 px-8 text-sm font-black tracking-widest uppercase"
                >
                    <span>{{ categoryLabel }}</span>
                    <span>juarasilat.com</span>
                </footer>
            </article>

            <article
                v-else-if="hasActiveMatch"
                key="done"
                class="overlay-shell absolute inset-x-0 bottom-[5vh] mx-auto max-h-[88vh] w-[min(96vw,1620px)] cursor-pointer overflow-hidden rounded-md border border-white/25 bg-black/60 text-white shadow-2xl backdrop-blur-sm"
                :title="buttonTitle"
                @click="triggerFullscreen"
            >
                <header
                    class="grid min-h-32 grid-cols-[minmax(0,1fr)_15rem_minmax(0,1fr)] border-b border-white/15"
                >
                    <div
                        class="overlay-blue flex min-w-0 items-center justify-end gap-5 bg-blue-600/90 px-7 text-white"
                    >
                        <div class="min-w-0 text-right">
                            <h2
                                class="max-w-full text-3xl leading-tight font-black break-words uppercase"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="max-w-full text-lg leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                        </div>
                        <div
                            class="overlay-score shrink-0 text-6xl font-black tabular-nums drop-shadow-md"
                        >
                            {{ displayedBlueScore }}
                        </div>
                    </div>

                    <div
                        class="overlay-center flex flex-col items-center justify-center gap-2 border-x border-white/15 bg-black/75 px-5 text-center"
                    >
                        <span
                            class="text-xs font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Skor Ronde
                        </span>
                        <div
                            class="flex items-center gap-3 text-5xl leading-none font-black"
                        >
                            <span class="text-blue-300">
                                {{ matchStats.scoreRound.split(' - ')[0] }}
                            </span>
                            <span class="text-3xl text-zinc-500">-</span>
                            <span class="text-yellow-300">
                                {{ matchStats.scoreRound.split(' - ')[1] }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="overlay-yellow flex min-w-0 items-center justify-start gap-5 bg-yellow-400/90 px-7 text-black"
                    >
                        <div
                            class="overlay-score shrink-0 text-6xl font-black tabular-nums drop-shadow-md"
                        >
                            {{ displayedYellowScore }}
                        </div>
                        <div class="min-w-0 text-left">
                            <h2
                                class="max-w-full text-3xl leading-tight font-black break-words uppercase"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="max-w-full text-lg leading-tight font-bold break-words uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div
                    class="overlay-footer flex h-8 items-center justify-between gap-5 border-b border-white/15 bg-black/75 px-8 text-sm font-black tracking-widest uppercase"
                >
                    <span>{{ matchTitle || 'Tanding' }}</span>
                    <span>juarasilat.com</span>
                </div>

                <div class="grid gap-2 p-5">
                    <div
                        v-for="(stat, index) in scoringStats"
                        :key="stat.score"
                        class="overlay-stat-row grid grid-cols-[6rem_minmax(0,1fr)_6rem] items-center gap-4 rounded-md border border-white/10 bg-black/40 px-5 py-3"
                        :style="{ '--row-delay': `${700 + index * 75}ms` }"
                    >
                        <div
                            class="text-center text-3xl font-black text-blue-200 tabular-nums"
                        >
                            {{ displayedBlueStats[stat.score] }}
                        </div>
                        <div
                            class="text-center text-lg font-black tracking-widest text-white uppercase"
                        >
                            {{ stat.label }}
                            <span class="text-sm text-zinc-400"
                                >({{ stat.score }})</span
                            >
                        </div>
                        <div
                            class="text-center text-3xl font-black text-yellow-300 tabular-nums"
                        >
                            {{ displayedYellowStats[stat.score] }}
                        </div>
                    </div>

                    <div
                        class="overlay-stat-row grid grid-cols-[6rem_minmax(0,1fr)_6rem] items-center gap-4 rounded-md border border-red-400/25 bg-red-950/30 px-5 py-3"
                        :style="{
                            '--row-delay': `${700 + scoringStats.length * 75}ms`,
                        }"
                    >
                        <div
                            class="text-center text-3xl font-black text-red-300 tabular-nums"
                        >
                            {{ displayedBlueStats.punishmentPoints }}
                        </div>
                        <div
                            class="text-center text-lg font-black tracking-widest text-red-200 uppercase"
                        >
                            Hukuman
                        </div>
                        <div
                            class="text-center text-3xl font-black text-red-300 tabular-nums"
                        >
                            {{ displayedYellowStats.punishmentPoints }}
                        </div>
                    </div>

                    <div
                        v-if="currentMatch?.winner_corner"
                        class="overlay-winner mt-3 rounded-md border border-white/15 bg-black/40 px-6 py-4 text-center"
                    >
                        <div
                            class="mb-1 text-xs font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Pemenang Pertandingan
                        </div>
                        <div
                            :class="[
                                'text-4xl font-black tracking-widest uppercase',
                                currentMatch?.winner_corner === 'blue'
                                    ? 'text-blue-300'
                                    : currentMatch?.winner_corner === 'yellow'
                                      ? 'text-yellow-300'
                                      : 'text-zinc-200',
                            ]"
                        >
                            {{
                                currentMatch?.winner_corner === 'blue'
                                    ? 'Biru'
                                    : currentMatch?.winner_corner === 'yellow'
                                      ? 'Kuning'
                                      : currentMatch?.winner_corner === 'draw'
                                        ? 'Seri'
                                        : 'Ditunda'
                            }}
                        </div>
                    </div>
                </div>

                <footer
                    class="overlay-footer flex h-8 items-center justify-between gap-5 border-t border-white/15 bg-black/60 px-8 text-sm font-black tracking-widest uppercase"
                >
                    <span>{{ categoryLabel }}</span>
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
