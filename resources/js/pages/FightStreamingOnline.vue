<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useFullscreenLock } from '@/composables/useFullscreenLock';
import { useSyncedTimer } from '@/composables/useSyncedTimer';
import type { SyncedTimerState } from '@/composables/useSyncedTimer';

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
const { formattedTimer, localTimer, syncTimer } = useSyncedTimer(
    props.timer ?? defaultTimer,
);
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
        const pointsArray = cornerPoints.filter((point: any) =>
            isSameRound(point.round_number, roundNumber),
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
                              ] ??
                                  0) ||
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
        return 'border-zinc-600 bg-zinc-800 text-white';
    }

    return 'border-zinc-700 bg-zinc-900 text-zinc-500 shadow-none';
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

const categoryLabel = computed(() =>
    (currentMatch.value?.category || '-').toString().toUpperCase(),
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
                class="absolute inset-x-0 bottom-[7vh] flex justify-center px-5"
            >
                <div
                    class="overlay-shell w-[min(76vw,1180px)] cursor-pointer overflow-hidden rounded-none bg-[#20236f] text-white shadow-[0_18px_45px_rgba(0,0,0,0.38)] ring-1 ring-indigo-200"
                    :title="buttonTitle"
                    @click="triggerFullscreen"
                >
                    <div
                        class="overlay-topbar flex h-9 items-center justify-between gap-5 bg-black px-5 text-xs font-black tracking-widest uppercase"
                    >
                        <div class="flex items-center gap-2">
                            <img
                                src="/assets/images/ts_logo.png"
                                alt=""
                                class="size-6 object-contain"
                            />
                            <img
                                src="/assets/images/js_logo.png"
                                alt=""
                                class="size-6 object-contain"
                            />
                        </div>
                        <span class="text-white">Juarasilat.com</span>
                        <span class="text-yellow-300"> Next Match </span>
                    </div>

                    <div
                        class="grid h-14 grid-cols-[minmax(0,1fr)_10rem_minmax(0,1fr)] items-stretch bg-[#252987]"
                    >
                        <div
                            class="overlay-blue flex min-w-0 flex-col items-end justify-center bg-[#1f4fd8] px-5 text-right text-white"
                        >
                            <h2
                                class="max-w-full truncate text-xl leading-tight font-black uppercase"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-blue-100 uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                        </div>

                        <div
                            class="overlay-center relative z-10 flex flex-col items-center justify-center bg-[#1c1f62] px-3 text-center"
                        >
                            <div
                                class="text-sm leading-tight font-black tracking-widest text-yellow-300 uppercase"
                            >
                                Partai
                            </div>
                            <div
                                class="text-2xl leading-none font-black tracking-widest text-white uppercase"
                            >
                                {{ partaiLabel }}
                            </div>
                        </div>

                        <div
                            class="overlay-yellow flex min-w-0 flex-col items-start justify-center bg-yellow-400 px-5 text-left text-black"
                        >
                            <h2
                                class="max-w-full truncate text-xl leading-tight font-black uppercase"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-yellow-900 uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-else-if="hasActiveMatch && matchStatus === 'ongoing'"
                key="ongoing"
                class="absolute inset-x-0 bottom-[7vh] flex flex-col items-center px-5"
            >
                <div
                    class="overlay-shell w-[min(76vw,1180px)] cursor-pointer overflow-hidden rounded-none bg-[#20236f] text-white shadow-[0_18px_45px_rgba(0,0,0,0.38)] ring-1 ring-indigo-200"
                    :title="buttonTitle"
                    @click="triggerFullscreen"
                >
                    <div
                        class="overlay-topbar flex h-9 items-center justify-center bg-black px-6 text-xs font-black tracking-widest text-white uppercase"
                    >
                        Juarasilat.com
                    </div>

                    <div
                        class="grid h-14 grid-cols-[minmax(0,1fr)_5rem_7rem_5rem_minmax(0,1fr)] items-stretch bg-[#252987]"
                    >
                        <div
                            class="overlay-blue flex min-w-0 items-center justify-end gap-3 overflow-hidden bg-[#1f4fd8] px-5 text-right text-white"
                        >
                            <div class="min-w-0 leading-none">
                                <h2
                                    class="max-w-full truncate text-xl font-black uppercase"
                                >
                                    {{
                                        currentMatch?.atlete_blue ||
                                        currentMatch?.athlete_blue ||
                                        '-'
                                    }}
                                </h2>
                                <p
                                    class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-blue-100 uppercase"
                                >
                                    {{ currentMatch?.contingent_blue || '-' }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="overlay-score-card overlay-score-card-blue flex items-center justify-center bg-[#f4f1e8] text-4xl font-black text-[#20236f] tabular-nums"
                        >
                            {{ activeRoundRecap?.total_poin_blue || 0 }}
                        </div>

                        <div
                            class="overlay-center relative z-10 flex flex-col items-center justify-center bg-[#1c1f62] px-3 text-center"
                        >
                            <div
                                v-if="isTimerDisplayed"
                                class="font-mono text-xl leading-none font-black tracking-wider text-white tabular-nums"
                            >
                                {{ formattedTimer }}
                            </div>
                            <div
                                v-else
                                class="text-xl leading-none font-black tracking-widest text-white uppercase"
                            >
                                {{ partaiLabel }}
                            </div>
                            <div
                                class="mt-1 text-[10px] leading-none font-black tracking-widest text-indigo-300 uppercase"
                            >
                                {{ isTimerDisplayed ? 'Waktu' : 'Partai' }}
                            </div>
                        </div>

                        <div
                            class="overlay-score-card overlay-score-card-yellow flex items-center justify-center bg-[#f4f1e8] text-4xl font-black text-[#20236f] tabular-nums"
                        >
                            {{ activeRoundRecap?.total_poin_yellow || 0 }}
                        </div>

                        <div
                            class="overlay-yellow flex min-w-0 items-center justify-start gap-3 overflow-hidden bg-yellow-400 px-5 text-left text-black"
                        >
                            <div class="min-w-0 leading-none">
                                <h2
                                    class="max-w-full truncate text-xl font-black uppercase"
                                >
                                    {{
                                        currentMatch?.atlete_yellow ||
                                        currentMatch?.athlete_yellow ||
                                        '-'
                                    }}
                                </h2>
                                <p
                                    class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-yellow-900 uppercase"
                                >
                                    {{ currentMatch?.contingent_yellow || '-' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <article
                v-else-if="hasActiveMatch && matchStatus === 'paused'"
                key="paused"
                class="overlay-shell absolute inset-x-0 bottom-[7vh] mx-auto max-h-[86vh] w-[min(82vw,1220px)] cursor-pointer overflow-hidden rounded-none bg-black text-white shadow-[0_18px_45px_rgba(0,0,0,0.46)] ring-1 ring-zinc-800"
                :title="buttonTitle"
                @click="triggerFullscreen"
            >
                <div
                    class="overlay-topbar flex h-9 items-center justify-between gap-5 bg-black px-5 text-xs font-black tracking-widest uppercase"
                >
                    <div class="flex items-center gap-2">
                        <img
                            src="/assets/images/ts_logo.png"
                            alt=""
                            class="size-6 object-contain"
                        />
                        <img
                            src="/assets/images/js_logo.png"
                            alt=""
                            class="size-6 object-contain"
                        />
                    </div>
                    <span>Juarasilat.com</span>
                    <span class="text-yellow-300">Jeda</span>
                </div>
                <header
                    class="grid h-14 grid-cols-[minmax(0,1fr)_5rem_7rem_5rem_minmax(0,1fr)] items-stretch bg-zinc-950"
                >
                    <div
                        class="overlay-blue flex min-w-0 items-center justify-end gap-3 overflow-hidden bg-[#1f4fd8] px-5 text-right text-white"
                    >
                        <div class="min-w-0 leading-none">
                            <h2
                                class="max-w-full truncate text-xl font-black uppercase"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-blue-100 uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="overlay-score-card overlay-score-card-blue flex items-center justify-center bg-[#f4f1e8] text-4xl font-black text-[#20236f] tabular-nums"
                    >
                        {{ displayedBlueScore }}
                    </div>

                    <div
                        class="overlay-center flex flex-col items-center justify-center bg-black px-3 text-center"
                    >
                        <span
                            class="text-[10px] leading-none font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Partai
                        </span>
                        <div
                            class="text-xl leading-none font-black tracking-widest text-white uppercase"
                        >
                            {{ partaiLabel }}
                        </div>
                        <div
                            class="mt-1 max-w-full text-[10px] leading-none font-black tracking-widest text-yellow-300 uppercase"
                        >
                            Jeda {{ roundLabel }}
                        </div>
                    </div>

                    <div
                        class="overlay-score-card overlay-score-card-yellow flex items-center justify-center bg-[#f4f1e8] text-4xl font-black text-[#20236f] tabular-nums"
                    >
                        {{ displayedYellowScore }}
                    </div>

                    <div
                        class="overlay-yellow flex min-w-0 items-center justify-start gap-3 overflow-hidden bg-yellow-400 px-5 text-left text-black"
                    >
                        <div class="min-w-0 leading-none">
                            <h2
                                class="max-w-full truncate text-xl font-black uppercase"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-yellow-900 uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div class="grid gap-3 p-4">
                    <div class="grid w-full grid-cols-3 gap-1.5">
                        <div
                            v-for="(roundWinner, index) in roundWinnerCards"
                            :key="roundWinner.round"
                            class="overlay-stat-row grid min-h-14 grid-rows-[1rem_1fr] overflow-hidden rounded-none bg-zinc-800 text-center"
                            :style="{
                                '--row-delay': `${700 + index * 75}ms`,
                            }"
                        >
                            <div
                                class="flex items-center justify-center bg-black px-2 text-[10px] font-black tracking-widest text-zinc-400 uppercase"
                            >
                                {{ roundWinner.label }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center rounded-none border-t py-2 text-sm font-black tracking-wider uppercase transition-all duration-300',
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

                    <div class="grid gap-1.5">
                        <div
                            v-for="(stat, index) in scoringStats"
                            :key="stat.score"
                            class="overlay-stat-row grid grid-cols-[4.5rem_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-none bg-zinc-800 px-4 py-2"
                            :style="{
                                '--row-delay': `${925 + index * 75}ms`,
                            }"
                        >
                            <div
                                class="text-center text-xl font-black text-white tabular-nums"
                            >
                                {{ currentRoundStats.blueStats[stat.score] }}
                            </div>
                            <div
                                class="text-center text-sm font-black tracking-widest text-white uppercase"
                            >
                                {{ stat.label }}
                                <span class="text-xs text-zinc-300"
                                    >({{ stat.score }})</span
                                >
                            </div>
                            <div
                                class="text-center text-xl font-black text-white tabular-nums"
                            >
                                {{ currentRoundStats.yellowStats[stat.score] }}
                            </div>
                        </div>

                        <div
                            class="overlay-stat-row grid grid-cols-[4.5rem_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-none bg-zinc-800 px-4 py-2"
                            :style="{
                                '--row-delay': `${925 + scoringStats.length * 75}ms`,
                            }"
                        >
                            <div
                                class="text-center text-xl font-black text-red-200 tabular-nums"
                            >
                                {{
                                    currentRoundStats.blueStats.punishmentPoints
                                }}
                            </div>
                            <div
                                class="text-center text-sm font-black tracking-widest text-red-200 uppercase"
                            >
                                Hukuman
                            </div>
                            <div
                                class="text-center text-xl font-black text-red-200 tabular-nums"
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
                    class="overlay-footer flex h-7 items-center justify-between gap-5 bg-black px-6 text-xs font-black tracking-widest uppercase"
                >
                    <span>{{ categoryLabel }}</span>
                    <span>juarasilat.com</span>
                </footer>
            </article>

            <article
                v-else-if="hasActiveMatch"
                key="done"
                class="overlay-shell absolute inset-x-0 bottom-[7vh] mx-auto max-h-[86vh] w-[min(82vw,1220px)] cursor-pointer overflow-hidden rounded-none bg-black text-white shadow-[0_18px_45px_rgba(0,0,0,0.46)] ring-1 ring-zinc-800"
                :title="buttonTitle"
                @click="triggerFullscreen"
            >
                <div
                    class="overlay-topbar flex h-9 items-center justify-between gap-5 bg-black px-5 text-xs font-black tracking-widest uppercase"
                >
                    <div class="flex items-center gap-2">
                        <img
                            src="/assets/images/ts_logo.png"
                            alt=""
                            class="size-6 object-contain"
                        />
                        <img
                            src="/assets/images/js_logo.png"
                            alt=""
                            class="size-6 object-contain"
                        />
                    </div>
                    <span>Juarasilat.com</span>
                    <span class="text-yellow-300">Selesai</span>
                </div>
                <header
                    class="grid h-14 grid-cols-[minmax(0,1fr)_5rem_7rem_5rem_minmax(0,1fr)] items-stretch bg-zinc-950"
                >
                    <div
                        class="overlay-blue flex min-w-0 items-center justify-end gap-3 overflow-hidden bg-[#1f4fd8] px-5 text-right text-white"
                    >
                        <div class="min-w-0 leading-none">
                            <h2
                                class="max-w-full truncate text-xl font-black uppercase"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-blue-100 uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="overlay-score-card overlay-score-card-blue flex items-center justify-center bg-[#f4f1e8] text-4xl font-black text-[#20236f] tabular-nums"
                    >
                        {{ displayedBlueScore }}
                    </div>

                    <div
                        class="overlay-center flex flex-col items-center justify-center bg-black px-3 text-center"
                    >
                        <span
                            class="text-[10px] leading-none font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Partai
                        </span>
                        <div
                            class="text-xl leading-none font-black tracking-widest text-white uppercase"
                        >
                            {{ partaiLabel }}
                        </div>
                        <div
                            class="mt-1 text-[10px] leading-none font-black tracking-widest text-yellow-300 uppercase"
                        >
                            Selesai
                        </div>
                    </div>

                    <div
                        class="overlay-score-card overlay-score-card-yellow flex items-center justify-center bg-[#f4f1e8] text-4xl font-black text-[#20236f] tabular-nums"
                    >
                        {{ displayedYellowScore }}
                    </div>

                    <div
                        class="overlay-yellow flex min-w-0 items-center justify-start gap-3 overflow-hidden bg-yellow-400 px-5 text-left text-black"
                    >
                        <div class="min-w-0 leading-none">
                            <h2
                                class="max-w-full truncate text-xl font-black uppercase"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                class="mt-1 max-w-full truncate text-[10px] font-bold tracking-widest text-yellow-900 uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div class="grid gap-1.5 p-4">
                    <div
                        v-for="(stat, index) in scoringStats"
                        :key="stat.score"
                        class="overlay-stat-row grid grid-cols-[4.5rem_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-none bg-zinc-800 px-4 py-2"
                        :style="{ '--row-delay': `${700 + index * 75}ms` }"
                    >
                        <div
                            class="text-center text-xl font-black text-blue-200 tabular-nums"
                        >
                            {{ displayedBlueStats[stat.score] }}
                        </div>
                        <div
                            class="text-center text-sm font-black tracking-widest text-white uppercase"
                        >
                            {{ stat.label }}
                            <span class="text-sm text-zinc-400"
                                >({{ stat.score }})</span
                            >
                        </div>
                        <div
                            class="text-center text-xl font-black text-yellow-300 tabular-nums"
                        >
                            {{ displayedYellowStats[stat.score] }}
                        </div>
                    </div>

                    <div
                        class="overlay-stat-row grid grid-cols-[4.5rem_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-none bg-zinc-800 px-4 py-2"
                        :style="{
                            '--row-delay': `${700 + scoringStats.length * 75}ms`,
                        }"
                    >
                        <div
                            class="text-center text-xl font-black text-red-300 tabular-nums"
                        >
                            {{ displayedBlueStats.punishmentPoints }}
                        </div>
                        <div
                            class="text-center text-sm font-black tracking-widest text-red-200 uppercase"
                        >
                            Hukuman
                        </div>
                        <div
                            class="text-center text-xl font-black text-red-300 tabular-nums"
                        >
                            {{ displayedYellowStats.punishmentPoints }}
                        </div>
                    </div>

                    <div
                        v-if="currentMatch?.winner_corner"
                        class="overlay-winner mt-2 rounded-none bg-zinc-800 px-6 py-3 text-center"
                    >
                        <div
                            class="mb-1 text-xs font-black tracking-widest text-zinc-400 uppercase"
                        >
                            Pemenang Pertandingan
                        </div>
                        <div
                            :class="[
                                'text-3xl font-black tracking-widest uppercase',
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
                    class="overlay-footer flex h-7 items-center justify-between gap-5 bg-black px-6 text-xs font-black tracking-widest uppercase"
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
    transform-origin: center bottom;
}

.broadcast-status-enter-active {
    animation: broadcast-scorebug-enter 1650ms cubic-bezier(0.16, 1, 0.3, 1)
        both;
}

.broadcast-status-leave-active {
    animation: broadcast-scorebug-exit 1180ms cubic-bezier(0.45, 0, 0.2, 1) both;
    pointer-events: none;
}

.broadcast-status-enter-from,
.broadcast-status-enter-to,
.broadcast-status-leave-from,
.broadcast-status-leave-to {
    transform-origin: center bottom;
}

.broadcast-status-leave-active .overlay-shell {
    animation: overlay-shell-out 1180ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-topbar,
.broadcast-status-leave-active .overlay-footer {
    animation: overlay-strip-out 940ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-blue {
    animation: overlay-blue-out 1020ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-yellow {
    animation: overlay-yellow-out 1020ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-center {
    animation: overlay-center-out 940ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-score {
    animation: overlay-score-out 820ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-score-card {
    animation: overlay-score-card-out 780ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.broadcast-status-leave-active .overlay-stat-row,
.broadcast-status-leave-active .overlay-winner {
    animation: overlay-stat-row-out 820ms cubic-bezier(0.45, 0, 0.2, 1) both;
}

.overlay-shell {
    animation: overlay-shell-in 1650ms cubic-bezier(0.16, 1, 0.3, 1) both;
    transform-origin: center bottom;
}

.overlay-topbar {
    animation: overlay-topbar-in 1250ms cubic-bezier(0.16, 1, 0.3, 1) 140ms both;
}

.overlay-yellow {
    animation: overlay-yellow-in 1450ms cubic-bezier(0.16, 1, 0.3, 1) 420ms both;
    transform-origin: right center;
}

.overlay-blue {
    animation: overlay-blue-in 1450ms cubic-bezier(0.16, 1, 0.3, 1) 340ms both;
    transform-origin: left center;
}

.overlay-center {
    animation: overlay-center-in 1300ms cubic-bezier(0.16, 1, 0.3, 1) 560ms both;
}

.overlay-score {
    animation: overlay-score-pop 1180ms cubic-bezier(0.16, 1, 0.3, 1) 780ms both;
}

.overlay-score-card {
    animation: overlay-score-card-in 1050ms cubic-bezier(0.16, 1, 0.3, 1) 1120ms
        both;
    transform-origin: center center;
}

.overlay-score-card-blue {
    --score-card-start: 5rem;
    --score-card-overshoot: -0.35rem;
}

.overlay-score-card-yellow {
    --score-card-start: -5rem;
    --score-card-overshoot: 0.35rem;
}

.overlay-footer {
    animation: overlay-footer-in 1100ms cubic-bezier(0.16, 1, 0.3, 1) 860ms both;
}

.overlay-stat-row {
    animation: overlay-stat-row-in 1100ms cubic-bezier(0.16, 1, 0.3, 1)
        var(--row-delay, 700ms) both;
}

.overlay-winner {
    animation: overlay-score-pop 1180ms cubic-bezier(0.16, 1, 0.3, 1) 1320ms
        both;
}

@keyframes broadcast-scorebug-enter {
    0% {
        clip-path: inset(0 50% 0 50%);
        transform: translateY(16px) scaleX(0.96);
    }

    42% {
        clip-path: inset(0 18% 0 18%);
        transform: translateY(0) scaleX(1.03);
    }

    72% {
        clip-path: inset(0 0 0 0);
        transform: translateY(0) scaleX(1.01);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateY(0) scaleX(1);
    }
}

@keyframes broadcast-scorebug-exit {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateY(0) scaleX(1);
    }

    38% {
        clip-path: inset(0 8% 0 8%);
        transform: translateY(-4px) scaleX(1.02);
    }

    100% {
        clip-path: inset(0 50% 0 50%);
        transform: translateY(16px) scaleX(0.94);
    }
}

@keyframes overlay-shell-in {
    0% {
        clip-path: inset(0 48% 0 48%);
        transform: translateY(20px) scaleY(0.86);
    }

    44% {
        clip-path: inset(0 12% 0 12%);
        transform: translateY(0) scaleY(1.08);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateY(0) scaleY(1);
    }
}

@keyframes overlay-shell-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateY(0) scaleY(1);
    }

    100% {
        clip-path: inset(0 52% 0 52%);
        transform: translateY(16px) scaleY(0.9);
    }
}

@keyframes overlay-topbar-in {
    0% {
        clip-path: inset(0 100% 0 0);
        transform: translateX(-18px);
    }

    55% {
        clip-path: inset(0 0 0 0);
        transform: translateX(8px);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }
}

@keyframes overlay-footer-in {
    0% {
        clip-path: inset(0 0 0 100%);
        transform: translateX(18px);
    }

    55% {
        clip-path: inset(0 0 0 0);
        transform: translateX(-8px);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }
}

@keyframes overlay-strip-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }

    100% {
        clip-path: inset(0 50% 0 50%);
        transform: translateX(12px);
    }
}

@keyframes overlay-blue-in {
    0% {
        clip-path: inset(0 100% 0 0);
        transform: translateX(-16px);
    }

    68% {
        clip-path: inset(0 0 0 0);
        transform: translateX(4px);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }
}

@keyframes overlay-blue-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }

    100% {
        clip-path: inset(0 100% 0 0);
        transform: translateX(-16px);
    }
}

@keyframes overlay-yellow-in {
    0% {
        clip-path: inset(0 0 0 100%);
        transform: translateX(16px);
    }

    68% {
        clip-path: inset(0 0 0 0);
        transform: translateX(-4px);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }
}

@keyframes overlay-yellow-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }

    100% {
        clip-path: inset(0 0 0 100%);
        transform: translateX(16px);
    }
}

@keyframes overlay-center-in {
    0% {
        clip-path: inset(50% 0 50% 0);
        transform: scaleX(0.72) rotateX(14deg);
    }

    58% {
        clip-path: inset(0 0 0 0);
        transform: scaleX(1.08) rotateX(0);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: scaleX(1) rotateX(0);
    }
}

@keyframes overlay-center-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: scaleX(1) rotateX(0);
    }

    100% {
        clip-path: inset(50% 0 50% 0);
        transform: scaleX(0.72) rotateX(12deg);
    }
}

@keyframes overlay-score-pop {
    0% {
        transform: scale(0.64) rotateX(18deg);
    }

    54% {
        transform: scale(1.18) rotateX(0);
    }

    76% {
        transform: scale(0.94);
    }

    100% {
        transform: scale(1);
    }
}

@keyframes overlay-score-out {
    0% {
        transform: scale(1) rotateX(0);
    }

    100% {
        transform: scale(0.72) rotateX(18deg);
    }
}

@keyframes overlay-score-card-in {
    0% {
        clip-path: inset(0 50% 0 50%);
        transform: translateX(var(--score-card-start)) scaleX(0.54);
    }

    64% {
        clip-path: inset(0 0 0 0);
        transform: translateX(var(--score-card-overshoot)) scaleX(1.04);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0) scaleX(1);
    }
}

@keyframes overlay-score-card-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0) scaleX(1);
    }

    100% {
        clip-path: inset(0 50% 0 50%);
        transform: translateX(var(--score-card-start)) scaleX(0.54);
    }
}

@keyframes overlay-stat-row-in {
    0% {
        clip-path: inset(0 100% 0 0);
        transform: translateX(-18px);
    }

    62% {
        clip-path: inset(0 0 0 0);
        transform: translateX(5px);
    }

    100% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }
}

@keyframes overlay-stat-row-out {
    0% {
        clip-path: inset(0 0 0 0);
        transform: translateX(0);
    }

    100% {
        clip-path: inset(0 0 0 100%);
        transform: translateX(14px);
    }
}
</style>
