<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    arena: any;
    activeMatch?: any;
    recapPoints?: any[];
    yellowPoints?: any[];
    bluePoints?: any[];
}>();

const currentMatch = ref<any>(props.activeMatch ?? null);
const localRecapPoints = ref<any[]>([...(props.recapPoints || [])]);
const localYellowPoints = ref<any[]>([...(props.yellowPoints || [])]);
const localBluePoints = ref<any[]>([...(props.bluePoints || [])]);
const buzzerAudio = ref<HTMLAudioElement | null>(null);

watch(
    () => props.activeMatch,
    (newVal) => {
        currentMatch.value = newVal;
    },
    { deep: true },
);
watch(
    () => props.recapPoints,
    (newVal) => {
        localRecapPoints.value = [...(newVal || [])];
    },
    { deep: true },
);
watch(
    () => props.yellowPoints,
    (newVal) => {
        localYellowPoints.value = [...(newVal || [])];
    },
    { deep: true },
);
watch(
    () => props.bluePoints,
    (newVal) => {
        localBluePoints.value = [...(newVal || [])];
    },
    { deep: true },
);

const matchStatus = computed(() => {
    if (!currentMatch.value || currentMatch.value.status === 'not_started') {
        return 'not_started';
    }

    if (currentMatch.value.status === 'ongoing') {
        return 'ongoing';
    }

    if (['paused', 'done'].includes(currentMatch.value.status)) {
        return 'done_paused';
    }

    return 'not_started';
});

const activeRoundRecap = computed(() => {
    if (!currentMatch.value) {
        return null;
    }

    return (
        localRecapPoints.value.find(
            (recap: any) =>
                recap.round_number == currentMatch.value.round_number,
        ) ?? null
    );
});

const getRoundWinner = (roundNumber: number) => {
    const recap = localRecapPoints.value.find(
        (item: any) => item.round_number == roundNumber,
    );

    return recap ? recap.winner : null;
};

const activeMainCorner = computed(() => {
    if (!activeRoundRecap.value) {
        return 'draw';
    }

    const yellowScore = Number(activeRoundRecap.value.total_poin_yellow) || 0;
    const blueScore = Number(activeRoundRecap.value.total_poin_blue) || 0;

    if (yellowScore > blueScore) {
        return 'yellow';
    }

    if (blueScore > yellowScore) {
        return 'blue';
    }

    const juries = [
        activeRoundRecap.value.jury_one_winner,
        activeRoundRecap.value.jury_two_winner,
        activeRoundRecap.value.jury_three_winner,
        activeRoundRecap.value.jury_four_winner,
    ];

    const yellowJuryCount = juries.filter(
        (winner) => winner === 'yellow',
    ).length;
    const blueJuryCount = juries.filter((winner) => winner === 'blue').length;

    if (yellowJuryCount > blueJuryCount) {
        return 'yellow';
    }

    if (blueJuryCount > yellowJuryCount) {
        return 'blue';
    }

    return 'draw';
});

const scoreNumber = (value: any) => Number(value) || 0;
const isScoreOverLimit = (value: any) => scoreNumber(value) > 200;
const isCornerActive = (corner: 'yellow' | 'blue') =>
    activeMainCorner.value === corner || activeMainCorner.value === 'draw';

const cornerPanelClass = (corner: 'yellow' | 'blue', score: any) => {
    if (isScoreOverLimit(score)) {
        return 'bg-red-600';
    }

    if (isCornerActive(corner)) {
        return corner === 'yellow' ? 'bg-yellow-400' : 'bg-blue-600';
    }

    return 'bg-zinc-900';
};

const cornerTextClass = (corner: 'yellow' | 'blue', score: any) => {
    if (isScoreOverLimit(score)) {
        return 'text-white';
    }

    if (corner === 'yellow') {
        return isCornerActive(corner) ? 'text-black' : 'text-yellow-400';
    }

    return isCornerActive(corner) ? 'text-white' : 'text-blue-600';
};

const cornerSecondaryTextClass = (corner: 'yellow' | 'blue', score: any) => {
    if (isScoreOverLimit(score)) {
        return 'text-white/90';
    }

    if (corner === 'yellow') {
        return isCornerActive(corner) ? 'text-black/80' : 'text-yellow-400';
    }

    return isCornerActive(corner) ? 'text-blue-100' : 'text-blue-600';
};

const juryScoreClass = (
    value: any,
    winner: string | null | undefined,
    corner: 'yellow' | 'blue',
) => {
    if (isScoreOverLimit(value)) {
        return 'bg-red-600 text-white shadow-inner';
    }

    if (winner === corner) {
        return corner === 'yellow'
            ? 'bg-yellow-400 text-black shadow-inner'
            : 'bg-blue-600 text-white shadow-inner';
    }

    return corner === 'yellow'
        ? 'bg-black text-yellow-400'
        : 'bg-black text-blue-600';
};

const isBuzzerActive = computed(() => {
    if (currentMatch.value?.status !== 'ongoing' || !activeRoundRecap.value) {
        return false;
    }

    const yellowTotals = [
        activeRoundRecap.value.jury_one_total_poin_yellow,
        activeRoundRecap.value.jury_two_total_poin_yellow,
        activeRoundRecap.value.jury_three_total_poin_yellow,
        activeRoundRecap.value.jury_four_total_poin_yellow,
    ];

    const blueTotals = [
        activeRoundRecap.value.jury_one_total_poin_blue,
        activeRoundRecap.value.jury_two_total_poin_blue,
        activeRoundRecap.value.jury_three_total_poin_blue,
        activeRoundRecap.value.jury_four_total_poin_blue,
    ];

    return (
        yellowTotals.filter((value) => Number(value) > 200).length >= 3 ||
        blueTotals.filter((value) => Number(value) > 200).length >= 3
    );
});

watch(isBuzzerActive, (active) => {
    if (!buzzerAudio.value) {
        return;
    }

    if (active) {
        buzzerAudio.value
            .play()
            .catch((error) => console.error('Audio play failed:', error));

        return;
    }

    buzzerAudio.value.pause();
    buzzerAudio.value.currentTime = 0;
});

const getCornerStats = (cornerPoints: any[]) => {
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

    [1, 2, 3].forEach((roundNumber) => {
        const pointsArray = cornerPoints.filter(
            (point: any) => point.round_number === roundNumber,
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
                    const scoreName = point.score?.name;

                    if (stats[scoreName] !== undefined) {
                        stats[scoreName] += validCount;
                    }
                } else if (id.startsWith('p:')) {
                    const punishmentValue =
                        point.punishment?.score !== undefined
                            ? Math.abs(Number(point.punishment.score))
                            : Math.abs(
                                  parseInt(point.punishment?.name || '0') || 0,
                              );

                    stats.punishmentPoints += punishmentValue * validCount;
                }
            }
        });
    });

    return stats;
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

const updateScoreDetail = (event: any) => {
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
        const index = localRecapPoints.value.findIndex(
            (recap: any) => recap.round_number === event.recap.round_number,
        );

        if (index !== -1) {
            localRecapPoints.value.splice(index, 1, event.recap);
        } else {
            localRecapPoints.value.push(event.recap);
        }
    }
};

let echoStatusChannel: any = null;
let echoScoreChannel: any = null;

onMounted(() => {
    buzzerAudio.value = new Audio('/assets/audio/buzzer.mp3');
    buzzerAudio.value.loop = true;

    if (isBuzzerActive.value) {
        buzzerAudio.value
            .play()
            .catch((error) => console.error('Audio play failed:', error));
    }

    const echo = (window as any).Echo;

    if (!echo) {
        return;
    }

    echoStatusChannel = echo
        .channel('match.status')
        .listen('.ActiveMatchUpdated', (event: any) => {
            if (event.match) {
                const shouldReload =
                    !currentMatch.value ||
                    currentMatch.value.id !== event.match.id;
                currentMatch.value = event.match;

                if (shouldReload) {
                    router.reload({
                        only: [
                            'activeMatch',
                            'recapPoints',
                            'yellowPoints',
                            'bluePoints',
                        ],
                    });
                }
            }
        });

    echoScoreChannel = echo
        .channel('match.score')
        .listen('.JuryScoreUpdated', updateScoreDetail);
});

onUnmounted(() => {
    if (buzzerAudio.value) {
        buzzerAudio.value.pause();
        buzzerAudio.value.currentTime = 0;
    }

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

const partaiLabel = computed(() => currentMatch.value?.match_code ?? '-');
</script>

<template>
    <Head title="Fight Streaming - Tapak Suci" />

    <div
        class="flex h-dvh w-screen overflow-hidden bg-zinc-950 text-foreground"
    >
        <template v-if="matchStatus === 'not_started'">
            <div
                class="relative z-10 flex flex-1 animate-pulse flex-col items-center justify-center"
            >
                <img
                    src="/assets/images/ts_logo.png"
                    alt="Tapak Suci Logo"
                    class="z-10 h-64 w-64 object-contain drop-shadow-2xl"
                />
                <p
                    class="z-10 mt-8 text-sm font-bold tracking-widest text-muted-foreground/50 uppercase"
                >
                    Menunggu Pertandingan...
                </p>
                <div
                    class="pointer-events-none absolute inset-0 flex items-center justify-center opacity-[0.03]"
                >
                    <svg
                        viewBox="0 0 100 100"
                        class="h-[800px] w-[800px]"
                        fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <circle
                            cx="50"
                            cy="50"
                            r="40"
                            stroke="currentColor"
                            stroke-width="2"
                            fill="none"
                        />
                        <path
                            d="M50 10 L50 90 M10 50 L90 50"
                            stroke="currentColor"
                            stroke-width="2"
                        />
                        <circle
                            cx="50"
                            cy="50"
                            r="20"
                            stroke="currentColor"
                            stroke-width="2"
                            fill="none"
                        />
                    </svg>
                </div>
            </div>
        </template>

        <template v-else-if="matchStatus === 'done_paused'">
            <div class="relative z-10 flex h-full w-full flex-col bg-zinc-950">
                <div
                    class="flex h-32 w-full shrink-0 border-b border-stone-800 shadow-xl"
                >
                    <div
                        class="relative flex flex-1 items-center justify-between overflow-hidden bg-gradient-to-r from-blue-700 to-blue-600 px-10 shadow-[inset_0_0_50px_rgba(0,0,0,0.2)]"
                    >
                        <div
                            class="flex max-w-[70%] flex-col items-start text-left"
                        >
                            <h2
                                class="mb-2 w-full truncate text-3xl font-black tracking-wider text-white uppercase drop-shadow-sm"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <span
                                class="bg-blue-950 px-3 py-1 text-[10px] font-bold tracking-widest text-blue-100 uppercase"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </span>
                        </div>
                        <div
                            class="pt-1 text-[5.5rem] leading-none font-black text-white tabular-nums drop-shadow-md"
                        >
                            {{ matchStats.totalPoinBlue || 0 }}
                        </div>
                    </div>

                    <div
                        class="relative z-20 flex w-48 shrink-0 skew-x-[-10deg] flex-col items-center justify-center border-x border-stone-800 bg-zinc-950 font-black text-stone-600 italic shadow-[0_0_30px_rgba(0,0,0,0.5)]"
                    >
                        <div
                            class="flex skew-x-[10deg] flex-col items-center justify-center text-center"
                        >
                            <span
                                class="mb-1 text-[10px] font-black tracking-widest text-stone-500 uppercase not-italic"
                                >Skor Ronde</span
                            >
                            <div
                                class="flex items-center gap-3 text-5xl leading-none font-black text-white not-italic"
                            >
                                <span class="text-blue-500">{{
                                    matchStats.scoreRound.split(' - ')[0]
                                }}</span>
                                <span class="text-3xl text-stone-700">-</span>
                                <span class="text-yellow-500">{{
                                    matchStats.scoreRound.split(' - ')[1]
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <div
                        class="relative flex flex-1 items-center justify-between overflow-hidden bg-gradient-to-l from-yellow-500 to-yellow-400 px-10 shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]"
                    >
                        <div
                            class="pt-1 text-[5.5rem] leading-none font-black text-black tabular-nums drop-shadow-md"
                        >
                            {{ matchStats.totalPoinYellow || 0 }}
                        </div>
                        <div
                            class="flex max-w-[70%] flex-col items-end text-right"
                        >
                            <h2
                                class="mb-2 w-full truncate text-3xl font-black tracking-wider text-black uppercase drop-shadow-sm"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <span
                                class="bg-black px-3 py-1 text-[10px] font-bold tracking-widest text-yellow-400 uppercase"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    class="custom-scrollbar flex-1 overflow-y-auto bg-zinc-950 p-10"
                >
                    <div class="max-w-8xl mx-auto flex flex-col gap-3">
                        <template
                            v-for="stat in [
                                { label: 'Pukulan Katak', score: '20' },
                                {
                                    label: 'Mawar Lepas Katak Masuk',
                                    score: '10+20',
                                },
                                { label: 'Tendangan Harimau', score: '30' },
                                {
                                    label: 'Mawar Lepas Harimau Masuk',
                                    score: '10+30',
                                },
                                { label: 'Terkaman', score: '40' },
                                {
                                    label: 'Mawar Lepas Terkaman Masuk',
                                    score: '10+40',
                                },
                                { label: 'Jatuhan / Sapuan', score: '50' },
                            ]"
                            :key="stat.score"
                        >
                            <div
                                class="group relative grid grid-cols-[100px_1fr_100px] items-center gap-8 overflow-hidden rounded-xl border border-stone-800/60 bg-zinc-900/80 px-8 py-4 shadow-md transition-colors hover:bg-zinc-800"
                            >
                                <div
                                    class="pointer-events-none absolute inset-0 flex opacity-20 transition-opacity group-hover:opacity-30"
                                >
                                    <div class="flex h-full w-1/2 justify-end">
                                        <div
                                            class="h-full bg-blue-500 transition-all duration-1000 ease-out"
                                            :style="`width: ${(matchStats.blueStats[stat.score] || 0) + (matchStats.yellowStats[stat.score] || 0) > 0 ? ((matchStats.blueStats[stat.score] || 0) / ((matchStats.blueStats[stat.score] || 0) + (matchStats.yellowStats[stat.score] || 0))) * 100 : 0}%`"
                                        ></div>
                                    </div>
                                    <div
                                        class="flex h-full w-1/2 justify-start"
                                    >
                                        <div
                                            class="h-full bg-yellow-500 transition-all duration-1000 ease-out"
                                            :style="`width: ${(matchStats.blueStats[stat.score] || 0) + (matchStats.yellowStats[stat.score] || 0) > 0 ? ((matchStats.yellowStats[stat.score] || 0) / ((matchStats.blueStats[stat.score] || 0) + (matchStats.yellowStats[stat.score] || 0))) * 100 : 0}%`"
                                        ></div>
                                    </div>
                                </div>
                                <div
                                    class="z-10 text-center text-2xl font-black text-blue-400 tabular-nums"
                                >
                                    {{ matchStats.blueStats[stat.score] }}
                                </div>
                                <div
                                    class="z-10 text-center text-xl font-black tracking-widest text-white uppercase drop-shadow-md"
                                >
                                    {{ stat.label }}
                                    <span class="ml-2 text-sm text-stone-500"
                                        >({{ stat.score }})</span
                                    >
                                </div>
                                <div
                                    class="z-10 text-center text-2xl font-black text-yellow-400 tabular-nums"
                                >
                                    {{ matchStats.yellowStats[stat.score] }}
                                </div>
                            </div>
                        </template>

                        <div
                            class="relative grid grid-cols-[100px_1fr_100px] items-center gap-8 overflow-hidden rounded-xl border border-stone-800 bg-zinc-900 px-8 py-4 shadow-md"
                        >
                            <div
                                class="text-center text-3xl font-black text-red-500 tabular-nums"
                            >
                                {{ matchStats.blueStats.punishmentPoints }}
                            </div>
                            <div
                                class="text-center text-xl font-black tracking-widest text-red-500/80 uppercase"
                            >
                                Hukuman
                            </div>
                            <div
                                class="text-center text-3xl font-black text-red-500 tabular-nums"
                            >
                                {{ matchStats.yellowStats.punishmentPoints }}
                            </div>
                        </div>

                        <div
                            v-if="currentMatch?.winner_corner"
                            class="relative mt-6 flex flex-col items-center justify-center overflow-hidden rounded-2xl border-4 border-stone-800 bg-zinc-900 py-6 shadow-2xl"
                        >
                            <div
                                class="z-10 mb-2 text-sm font-black tracking-widest text-stone-500 uppercase"
                            >
                                Pemenang Pertandingan
                            </div>
                            <div
                                :class="[
                                    'z-10 text-4xl font-black tracking-widest uppercase drop-shadow-md',
                                    currentMatch?.winner_corner === 'blue'
                                        ? 'text-blue-500'
                                        : currentMatch?.winner_corner ===
                                            'yellow'
                                          ? 'text-yellow-500'
                                          : 'text-stone-300',
                                ]"
                            >
                                {{
                                    currentMatch?.winner_corner === 'blue'
                                        ? 'SUDUT BIRU'
                                        : currentMatch?.winner_corner ===
                                            'yellow'
                                          ? 'SUDUT KUNING'
                                          : currentMatch?.winner_corner ===
                                              'draw'
                                            ? 'SERI'
                                            : 'DITUNDA'
                                }}
                            </div>
                            <div
                                class="pointer-events-none absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template v-else>
            <div class="relative z-10 flex h-full w-full flex-col bg-zinc-950">
                <div
                    class="flex h-40 w-full shrink-0 bg-zinc-950 px-7 pt-5 text-center"
                >
                    <div
                        class="flex h-full w-full flex-col overflow-hidden rounded-2xl border border-stone-800 bg-zinc-900 shadow-2xl"
                    >
                        <div
                            class="flex h-14 shrink-0 items-center justify-center border-b border-stone-800 bg-black/40 px-8 text-3xl font-black text-white uppercase"
                        >
                            {{ matchTitle || '-' }}
                        </div>
                        <div
                            class="grid min-h-0 flex-1 grid-cols-[1fr_1.35fr_1fr] font-mono text-stone-100 uppercase tabular-nums"
                        >
                            <div
                                class="flex items-center justify-center border-r border-stone-800 bg-zinc-900"
                            >
                                <span class="text-7xl font-black"
                                    >PARTAI {{ partaiLabel }}</span
                                >
                            </div>
                            <div
                                class="flex items-center justify-center border-r border-stone-800 bg-zinc-900"
                            >
                                <span
                                    class="text-7xl font-black tracking-widest"
                                    >00:00:00</span
                                >
                            </div>
                            <div
                                class="flex items-center justify-center bg-zinc-900"
                            >
                                <span class="text-7xl font-black"
                                    >ROUND {{ roundLabel }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="flex min-h-0 flex-1 flex-col gap-5 overflow-hidden p-7"
                >
                    <div
                        class="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_16rem_minmax(0,1fr)] overflow-hidden rounded-2xl border border-stone-800 shadow-2xl"
                    >
                        <div
                            :class="[
                                'relative flex min-w-0 flex-col items-center justify-center gap-3 p-9 text-center transition-colors duration-500',
                                cornerPanelClass(
                                    'yellow',
                                    activeRoundRecap?.total_poin_yellow,
                                ),
                            ]"
                        >
                            <h2
                                :class="[
                                    'max-w-full text-5xl font-black tracking-wider uppercase drop-shadow-sm',
                                    cornerTextClass(
                                        'yellow',
                                        activeRoundRecap?.total_poin_yellow,
                                    ),
                                ]"
                            >
                                {{
                                    currentMatch?.atlete_yellow ||
                                    currentMatch?.athlete_yellow ||
                                    '-'
                                }}
                            </h2>
                            <p
                                :class="[
                                    'max-w-full text-3xl font-bold uppercase',
                                    cornerSecondaryTextClass(
                                        'yellow',
                                        activeRoundRecap?.total_poin_yellow,
                                    ),
                                ]"
                            >
                                {{ currentMatch?.contingent_yellow || '-' }}
                            </p>
                            <div class="mt-auto flex w-full justify-center">
                                <div
                                    :class="[
                                        'w-full text-center text-[min(27vh,20rem)] leading-none font-black drop-shadow-sm',
                                        cornerTextClass(
                                            'yellow',
                                            activeRoundRecap?.total_poin_yellow,
                                        ),
                                    ]"
                                >
                                    {{
                                        activeRoundRecap?.total_poin_yellow || 0
                                    }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="relative z-20 flex min-w-0 flex-col items-center justify-start border-x border-stone-800 bg-zinc-950 py-7 shadow-2xl"
                        >
                            <div
                                class="mb-7 w-full border-b border-stone-800 pb-5 text-center text-base font-black tracking-widest text-stone-200 uppercase"
                            >
                                Pemenang Ronde
                            </div>
                            <div
                                class="-mt-3 flex flex-1 flex-col justify-center gap-5 px-6"
                            >
                                <div
                                    v-for="roundNumber in [1, 2, 3]"
                                    :key="roundNumber"
                                    class="group relative flex w-full flex-col items-center"
                                >
                                    <div
                                        class="absolute -top-4 z-10 rounded-full bg-zinc-950 px-3 text-sm font-black tracking-widest text-stone-500 uppercase"
                                    >
                                        Ronde
                                        {{
                                            roundNumber === 3
                                                ? 'TBH'
                                                : roundNumber
                                        }}
                                    </div>
                                    <div
                                        :class="[
                                            'w-52 rounded-xl border py-5 text-center text-2xl font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                            getRoundWinner(roundNumber) ===
                                            'yellow'
                                                ? 'border-yellow-500 bg-yellow-400 text-black'
                                                : getRoundWinner(
                                                        roundNumber,
                                                    ) === 'blue'
                                                  ? 'border-blue-500 bg-blue-600 text-white'
                                                  : getRoundWinner(
                                                          roundNumber,
                                                      ) === 'draw'
                                                    ? 'border-stone-400 bg-stone-500 text-white'
                                                    : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                        ]"
                                    >
                                        <span
                                            v-if="
                                                getRoundWinner(roundNumber) ===
                                                'yellow'
                                            "
                                            >Kuning</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(roundNumber) ===
                                                'blue'
                                            "
                                            >Biru</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(roundNumber) ===
                                                'draw'
                                            "
                                            >Seri</span
                                        >
                                        <span v-else>-</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            :class="[
                                'relative flex min-w-0 flex-col items-center justify-center gap-3 p-9 text-center transition-colors duration-500',
                                cornerPanelClass(
                                    'blue',
                                    activeRoundRecap?.total_poin_blue,
                                ),
                            ]"
                        >
                            <h2
                                :class="[
                                    'max-w-full text-5xl font-black tracking-wider uppercase drop-shadow-sm',
                                    cornerTextClass(
                                        'blue',
                                        activeRoundRecap?.total_poin_blue,
                                    ),
                                ]"
                            >
                                {{
                                    currentMatch?.atlete_blue ||
                                    currentMatch?.athlete_blue ||
                                    '-'
                                }}
                            </h2>
                            <p
                                :class="[
                                    'max-w-full text-3xl font-bold uppercase',
                                    cornerSecondaryTextClass(
                                        'blue',
                                        activeRoundRecap?.total_poin_blue,
                                    ),
                                ]"
                            >
                                {{ currentMatch?.contingent_blue || '-' }}
                            </p>
                            <div class="mt-auto flex w-full justify-center">
                                <div
                                    :class="[
                                        'w-full text-center text-[min(27vh,20rem)] leading-none font-black drop-shadow-sm',
                                        cornerTextClass(
                                            'blue',
                                            activeRoundRecap?.total_poin_blue,
                                        ),
                                    ]"
                                >
                                    {{ activeRoundRecap?.total_poin_blue || 0 }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="h-48 shrink-0 overflow-hidden rounded-xl border border-stone-800 bg-zinc-900 shadow-lg"
                    >
                        <div
                            class="grid h-11 grid-cols-4 border-b border-stone-800 bg-black/40 text-center text-base font-black tracking-widest text-muted-foreground uppercase"
                        >
                            <div
                                class="flex items-center justify-center border-r border-stone-800"
                            >
                                Pembantu Wasit 1
                            </div>
                            <div
                                class="flex items-center justify-center border-r border-stone-800"
                            >
                                Pembantu Wasit 2
                            </div>
                            <div
                                class="flex items-center justify-center border-r border-stone-800"
                            >
                                Pembantu Wasit 3
                            </div>
                            <div class="flex items-center justify-center">
                                Pembantu Wasit 4
                            </div>
                        </div>
                        <div
                            class="grid h-10 grid-cols-8 border-b border-stone-800 text-center text-base font-black tracking-wider uppercase"
                        >
                            <template
                                v-for="juryNumber in [1, 2, 3, 4]"
                                :key="juryNumber"
                            >
                                <div
                                    class="flex items-center justify-center border-r border-stone-800 bg-yellow-400/20 text-yellow-500"
                                >
                                    Kuning
                                </div>
                                <div
                                    :class="[
                                        'flex items-center justify-center bg-blue-600/20 text-blue-400',
                                        juryNumber === 4
                                            ? ''
                                            : 'border-r border-stone-800',
                                    ]"
                                >
                                    Biru
                                </div>
                            </template>
                        </div>
                        <div
                            class="grid h-[calc(100%-5.25rem)] grid-cols-8 text-center text-6xl font-black tabular-nums transition-colors duration-300"
                        >
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_one_total_poin_yellow,
                                        activeRoundRecap?.jury_one_winner,
                                        'yellow',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_one_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_one_total_poin_blue,
                                        activeRoundRecap?.jury_one_winner,
                                        'blue',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_one_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_two_total_poin_yellow,
                                        activeRoundRecap?.jury_two_winner,
                                        'yellow',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_two_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_two_total_poin_blue,
                                        activeRoundRecap?.jury_two_winner,
                                        'blue',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_two_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_three_total_poin_yellow,
                                        activeRoundRecap?.jury_three_winner,
                                        'yellow',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_three_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_three_total_poin_blue,
                                        activeRoundRecap?.jury_three_winner,
                                        'blue',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_three_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center border-r border-stone-800',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_four_total_poin_yellow,
                                        activeRoundRecap?.jury_four_winner,
                                        'yellow',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_four_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'flex items-center justify-center',
                                    juryScoreClass(
                                        activeRoundRecap?.jury_four_total_poin_blue,
                                        activeRoundRecap?.jury_four_winner,
                                        'blue',
                                    ),
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_four_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
