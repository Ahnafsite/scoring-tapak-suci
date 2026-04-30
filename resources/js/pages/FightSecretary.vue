<script setup lang="ts">
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import FightFullscreenButton from '@/components/fight/FightFullscreenButton.vue';
import FightWaitingState from '@/components/fight/FightWaitingState.vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useFullscreenLock } from '@/composables/useFullscreenLock';

const props = defineProps<{
    arena: any;
    activeMatch?: any;
    recapPoints?: any[];
    yellowPoints?: any[];
    bluePoints?: any[];
}>();

const page = usePage<any>();
const userName = computed(() => page.props.auth?.user?.name || 'Sekretaris');
const {
    buttonTitle,
    exitClickCount,
    isFullscreen,
    remainingExitClicks,
    requiredExitClicks,
    triggerFullscreen,
} = useFullscreenLock();

// Reactive match state
const currentMatch = ref<any>(props.activeMatch ?? null);
const localYellowPoints = ref<any[]>([...(props.yellowPoints || [])]);
const localBluePoints = ref<any[]>([...(props.bluePoints || [])]);
const localRecapPoints = ref<any[]>([...(props.recapPoints || [])]);

// Sync with Inertia props changes
watch(
    () => props.activeMatch,
    (newVal) => {
        currentMatch.value = newVal;
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
watch(
    () => props.recapPoints,
    (newVal) => {
        localRecapPoints.value = [...(newVal || [])];
    },
    { deep: true },
);

const matchStatus = computed(() => {
    if (!currentMatch.value) return 'not started';
    if (currentMatch.value.status === 'ongoing') return 'ongoing';
    if (['paused', 'done'].includes(currentMatch.value.status))
        return 'done_paused';
    return 'not started';
});

// Real-time Echo listener
let echoStatusChannel: any = null;
let echoScoreChannel: any = null;

onMounted(() => {
    const echo = (window as any).Echo;

    if (echo) {
        echoStatusChannel = echo
            .channel('match.status')
            .listen('.ActiveMatchUpdated', (e: any) => {
                if (e.match) {
                    if (
                        !currentMatch.value ||
                        currentMatch.value.id !== e.match.id
                    ) {
                        currentMatch.value = e.match;
                        router.reload({
                            only: [
                                'activeMatch',
                                'recapPoints',
                                'yellowPoints',
                                'bluePoints',
                            ],
                        });
                    } else {
                        currentMatch.value = e.match;
                    }
                }
            });

        echoScoreChannel = echo
            .channel('match.score')
            .listen('.JuryScoreUpdated', (e: any) => {
                if (e.scoreDetail) {
                    if (e.scoreDetail.deleted) {
                        const targetId = Number(e.scoreDetail.id);
                        if (e.corner === 'yellow') {
                            localYellowPoints.value =
                                localYellowPoints.value.filter(
                                    (p) => p.id !== targetId,
                                );
                        } else {
                            localBluePoints.value =
                                localBluePoints.value.filter(
                                    (p) => p.id !== targetId,
                                );
                        }
                    } else {
                        if (e.corner === 'yellow') {
                            localYellowPoints.value.push(e.scoreDetail);
                        } else {
                            localBluePoints.value.push(e.scoreDetail);
                        }
                    }
                }

                if (e.recap) {
                    const idx = localRecapPoints.value.findIndex(
                        (r) => r.round_number === e.recap.round_number,
                    );
                    if (idx !== -1) {
                        localRecapPoints.value.splice(idx, 1, e.recap);
                    } else {
                        localRecapPoints.value.push(e.recap);
                    }
                }
            });
    }
});

onUnmounted(() => {
    const echo = (window as any).Echo;
    if (echoStatusChannel && echo) {
        echoStatusChannel.stopListening('.ActiveMatchUpdated');
        echo.leaveChannel('match.status');
    }
    if (echoScoreChannel && echo) {
        echoScoreChannel.stopListening('.JuryScoreUpdated');
        echo.leaveChannel('match.score');
    }
});

const activeRoundJuriesData = computed(() => {
    if (!currentMatch.value) return [];

    const roundNumber = currentMatch.value.round_number;
    const roundDetails = localRecapPoints.value.find(
        (r) => r.round_number === roundNumber,
    );
    const jNumMap: Record<number, string> = {
        1: 'one',
        2: 'two',
        3: 'three',
        4: 'four',
    };

    // Evaluate cross-jury validity mathematically (similar to backend recap tally)
    const evaluateValidity = (pointsArray: any[]) => {
        const juryPointCounts: Record<number, Record<string, number>> = {
            1: {},
            2: {},
            3: {},
            4: {},
        };
        const validCounts: Record<string, number> = {};

        pointsArray.forEach((p) => {
            const jn = p.jury_number;
            if (jn >= 1 && jn <= 4) {
                const id = p.ref_score_id
                    ? `s:${p.ref_score_id}`
                    : `p:${p.ref_punishment_id}`;
                if (!juryPointCounts[jn][id]) juryPointCounts[jn][id] = 0;
                juryPointCounts[jn][id]++;
            }
        });

        const allIds = new Set<string>();
        [1, 2, 3, 4].forEach((j) =>
            Object.keys(juryPointCounts[j]).forEach((k) => allIds.add(k)),
        );

        allIds.forEach((id) => {
            let maxOccurrences = 0;
            for (let j = 1; j <= 4; j++) {
                const count = juryPointCounts[j][id] || 0;
                if (count > maxOccurrences) maxOccurrences = count;
            }

            validCounts[id] = 0;
            for (let k = 1; k <= maxOccurrences; k++) {
                let jCount = 0;
                for (let j = 1; j <= 4; j++) {
                    if ((juryPointCounts[j][id] || 0) >= k) jCount++;
                }
                if (jCount >= 3) {
                    validCounts[id]++;
                }
            }
        });

        const markedCounts: Record<number, Record<string, number>> = {
            1: {},
            2: {},
            3: {},
            4: {},
        };
        return pointsArray.map((p) => {
            const jn = p.jury_number;
            if (jn >= 1 && jn <= 4) {
                const id = p.ref_score_id
                    ? `s:${p.ref_score_id}`
                    : `p:${p.ref_punishment_id}`;
                if (!markedCounts[jn][id]) markedCounts[jn][id] = 0;
                markedCounts[jn][id]++;
                return {
                    ...p,
                    is_valid: markedCounts[jn][id] <= (validCounts[id] || 0),
                };
            }
            return { ...p, is_valid: false };
        });
    };

    const evalYellow = evaluateValidity(
        localYellowPoints.value.filter((p) => p.round_number === roundNumber),
    );
    const evalBlue = evaluateValidity(
        localBluePoints.value.filter((p) => p.round_number === roundNumber),
    );

    return [1, 2, 3, 4].map((juryNumber) => {
        const yDetails = evalYellow.filter((p) => p.jury_number === juryNumber);
        const bDetails = evalBlue.filter((p) => p.jury_number === juryNumber);

        let yTotal = 0;
        let bTotal = 0;
        let juryWinner = 'draw';
        if (roundDetails) {
            const word = jNumMap[juryNumber];
            yTotal = roundDetails[`jury_${word}_total_poin_yellow`] ?? 0;
            bTotal = roundDetails[`jury_${word}_total_poin_blue`] ?? 0;
            juryWinner = roundDetails[`jury_${word}_winner`] || 'draw';
        }

        return {
            jury_name: `PW ${juryNumber}`,
            yellow_details: yDetails,
            yellow_total: yTotal,
            blue_details: bDetails,
            blue_total: bTotal,
            jury_winner: juryWinner,
        };
    });
});

const activeRoundRecap = computed(() => {
    if (!currentMatch.value) return null;
    return localRecapPoints.value.find(
        (r) => r.round_number === currentMatch.value.round_number,
    );
});

const getRoundWinner = (roundNum: number) => {
    if (!localRecapPoints.value || !Array.isArray(localRecapPoints.value))
        return null;
    const r = localRecapPoints.value.find(
        (x: any) => x.round_number == roundNum,
    );
    return r ? r.winner : null;
};

const matchStats = computed(() => {
    let roundWinBlue = 0;
    let roundWinYellow = 0;
    let totalPoinBlue = 0;
    let totalPoinYellow = 0;

    if (localRecapPoints.value) {
        localRecapPoints.value.forEach((r) => {
            if (r.winner === 'blue') roundWinBlue++;
            if (r.winner === 'yellow') roundWinYellow++;
            totalPoinBlue += Number(r.total_poin_blue) || 0;
            totalPoinYellow += Number(r.total_poin_yellow) || 0;
        });
    }

    const getCornerStats = (cornerPoints: any[]) => {
        const stats: any = {
            '20': 0,
            '10+20': 0,
            '30': 0,
            '10+30': 0,
            '40': 0,
            '10+40': 0,
            '50': 0,
            punishmentPoints: 0,
        };

        const rounds = [1, 2, 3];
        rounds.forEach((roundNumber) => {
            const pointsArray = cornerPoints.filter(
                (p) => p.round_number === roundNumber,
            );
            const juryPointCounts: Record<number, Record<string, number>> = {
                1: {},
                2: {},
                3: {},
                4: {},
            };
            const idToScore: Record<string, any> = {};

            pointsArray.forEach((p) => {
                const jn = p.jury_number;
                if (jn >= 1 && jn <= 4) {
                    const id = p.ref_score_id
                        ? `s:${p.ref_score_id}`
                        : `p:${p.ref_punishment_id}`;
                    if (!juryPointCounts[jn][id]) juryPointCounts[jn][id] = 0;
                    juryPointCounts[jn][id]++;
                    idToScore[id] = p;
                }
            });

            const allIds = new Set<string>();
            [1, 2, 3, 4].forEach((j) =>
                Object.keys(juryPointCounts[j]).forEach((k) => allIds.add(k)),
            );

            allIds.forEach((id) => {
                let maxOccurrences = 0;
                for (let j = 1; j <= 4; j++) {
                    const count = juryPointCounts[j][id] || 0;
                    if (count > maxOccurrences) maxOccurrences = count;
                }

                let validCount = 0;
                for (let k = 1; k <= maxOccurrences; k++) {
                    let jCount = 0;
                    for (let j = 1; j <= 4; j++) {
                        if ((juryPointCounts[j][id] || 0) >= k) jCount++;
                    }
                    if (jCount >= 3) validCount++;
                }

                if (validCount > 0) {
                    const p = idToScore[id];
                    if (id.startsWith('s:')) {
                        const name = p.score?.name;
                        if (stats[name] !== undefined) {
                            stats[name] += validCount;
                        } else if (name === '50') {
                            stats['50'] += validCount;
                        }
                    } else if (id.startsWith('p:')) {
                        let punishmentValue = 0;
                        if (p.punishment?.score !== undefined) {
                            punishmentValue = Math.abs(
                                Number(p.punishment.score),
                            );
                        } else if (p.punishment?.name) {
                            punishmentValue = Math.abs(
                                parseInt(p.punishment.name) || 0,
                            );
                        }
                        stats.punishmentPoints += punishmentValue * validCount;
                    }
                }
            });
        });

        return stats;
    };

    return {
        scoreRound: `${roundWinBlue} - ${roundWinYellow}`,
        totalPoinBlue,
        totalPoinYellow,
        blueStats: getCornerStats(localBluePoints.value),
        yellowStats: getCornerStats(localYellowPoints.value),
    };
});
</script>

<template>
    <Head title="Sekretaris Pertandingan - Tapak Suci" />
    <div class="flex h-screen overflow-hidden bg-zinc-950 text-foreground">
        <!-- CONDITION 2: Not Started (Loading) -->
        <template v-if="matchStatus === 'not started'">
            <FightWaitingState clickable :on-logo-click="triggerFullscreen" />
        </template>

        <!-- CONDITION 3: Done or Paused -->
        <template v-else-if="matchStatus === 'done_paused'">
            <div class="relative z-10 flex h-full w-full flex-col bg-zinc-950">
                <!-- Small Header Section -->
                <div
                    class="flex h-12 w-full shrink-0 items-center justify-between border-b border-stone-800 bg-zinc-900 px-6 text-[11px] font-bold tracking-widest text-muted-foreground uppercase shadow-sm"
                >
                    <div class="flex items-center gap-4">
                        <FightFullscreenButton
                            :exit-click-count="exitClickCount"
                            :is-fullscreen="isFullscreen"
                            :remaining-exit-clicks="remainingExitClicks"
                            :required-exit-clicks="requiredExitClicks"
                            :title="buttonTitle"
                            :on-trigger="triggerFullscreen"
                        />
                        <span class="font-black text-yellow-500">{{
                            userName
                        }}</span>
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span
                            >Gelanggang
                            {{
                                props.arena?.arena_name ??
                                props.arena?.gelanggang_id ??
                                '-'
                            }}</span
                        >
                    </div>

                    <div class="flex items-center gap-4">
                        <span class="text-white">STATISTIK PERTANDINGAN</span>
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span
                            >{{ currentMatch?.group }}
                            {{ currentMatch?.category }}</span
                        >
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span
                            >Partai
                            <span
                                class="ml-1 text-sm text-white tabular-nums"
                                >{{ currentMatch?.match_code }}</span
                            ></span
                        >
                    </div>
                </div>

                <!-- Main Header (Athlete VS Athlete) -->
                <div
                    class="z-10 flex h-32 w-full shrink-0 border-b border-stone-800 shadow-xl"
                >
                    <!-- Blue Section -->
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
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    class="bg-blue-950 text-[10px] font-bold tracking-widest text-blue-100 uppercase hover:bg-blue-950"
                                >
                                    {{ currentMatch?.contingent_blue || '-' }}
                                </Badge>
                                <Badge
                                    class="pointer-events-none border-none bg-black px-2 py-0.5 text-[10px] font-black tracking-widest text-blue-400 uppercase shadow-md"
                                >
                                    {{
                                        currentMatch?.weight_status_blue || '-'
                                    }}
                                    {{
                                        currentMatch?.weight_blue
                                            ? `- ${currentMatch.weight_blue} KG`
                                            : ''
                                    }}
                                </Badge>
                            </div>
                        </div>
                        <div
                            class="pt-1 text-[5.5rem] leading-none font-black text-white tabular-nums drop-shadow-md"
                        >
                            {{ matchStats.totalPoinBlue || 0 }}
                        </div>
                    </div>

                    <!-- Center Round / VS -->
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

                    <!-- Yellow Section -->
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
                            <div
                                class="flex flex-wrap items-center justify-end gap-2"
                            >
                                <Badge
                                    class="pointer-events-none border-none bg-black px-2 py-0.5 text-[10px] font-black tracking-widest text-yellow-500 uppercase shadow-md"
                                >
                                    {{
                                        currentMatch?.weight_yellow
                                            ? `${currentMatch.weight_yellow} KG -`
                                            : ''
                                    }}
                                    {{
                                        currentMatch?.weight_status_yellow ||
                                        '-'
                                    }}
                                </Badge>
                                <Badge
                                    class="bg-black text-[10px] font-bold tracking-widest text-yellow-400 uppercase hover:bg-black"
                                >
                                    {{ currentMatch?.contingent_yellow || '-' }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Stats Table -->
                <div
                    class="custom-scrollbar flex-1 overflow-y-auto bg-zinc-950 p-10"
                >
                    <div class="max-w-8xl mx-auto flex flex-col gap-3">
                        <!-- Technique Rows -->
                        <template
                            v-for="(stat, idx) in [
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
                            :key="idx"
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
                                            :style="`width: ${(parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0) > 0 ? (parseInt(matchStats.blueStats[stat.score]) / ((parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0))) * 100 : 0}%`"
                                        ></div>
                                    </div>
                                    <div
                                        class="flex h-full w-1/2 justify-start"
                                    >
                                        <div
                                            class="h-full bg-yellow-500 transition-all duration-1000 ease-out"
                                            :style="`width: ${(parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0) > 0 ? (parseInt(matchStats.yellowStats[stat.score]) / ((parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0))) * 100 : 0}%`"
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

                        <!-- Hukuman -->
                        <div
                            class="relative grid grid-cols-[100px_1fr_100px] items-center gap-8 overflow-hidden rounded-xl border border-stone-800 bg-zinc-900 px-8 py-4 shadow-md"
                        >
                            <div
                                class="pointer-events-none absolute inset-0 flex opacity-20"
                            >
                                <div class="flex h-full w-1/2 justify-end">
                                    <div
                                        class="h-full bg-red-500 transition-all duration-1000 ease-out"
                                        :style="`width: ${(parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0) > 0 ? (parseInt(matchStats.blueStats.punishmentPoints) / ((parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0))) * 100 : 0}%`"
                                    ></div>
                                </div>
                                <div class="flex h-full w-1/2 justify-start">
                                    <div
                                        class="h-full bg-red-500 transition-all duration-1000 ease-out"
                                        :style="`width: ${(parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0) > 0 ? (parseInt(matchStats.yellowStats.punishmentPoints) / ((parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0))) * 100 : 0}%`"
                                    ></div>
                                </div>
                            </div>
                            <div
                                class="z-10 text-center text-3xl font-black text-red-500 tabular-nums"
                            >
                                {{ matchStats.blueStats.punishmentPoints }}
                            </div>
                            <div
                                class="z-10 text-center text-xl font-black tracking-widest text-red-500/80 uppercase"
                            >
                                Hukuman
                            </div>
                            <div
                                class="z-10 text-center text-3xl font-black text-red-500 tabular-nums"
                            >
                                {{ matchStats.yellowStats.punishmentPoints }}
                            </div>
                        </div>

                        <!-- Total Nilai / Pemenang Pertandingan -->
                        <div
                            v-if="currentMatch?.winner_corner"
                            class="relative mt-6 flex flex-col items-center justify-center overflow-hidden rounded-2xl border-4 border-stone-800 bg-zinc-900 py-6 shadow-2xl"
                        >
                            <div
                                class="z-10 mb-2 text-lg font-black tracking-widest text-white uppercase"
                            >
                                Pemenang Pertandingan
                            </div>
                            <div
                                :class="[
                                    'z-10 text-5xl font-black tracking-widest uppercase drop-shadow-md',
                                    currentMatch?.winner_corner === 'blue'
                                        ? 'text-blue-500'
                                        : currentMatch?.winner_corner ===
                                            'yellow'
                                          ? 'text-yellow-500'
                                          : 'text-white',
                                ]"
                            >
                                {{
                                    currentMatch?.winner_corner === 'blue'
                                        ? 'SUDUT BIRU'
                                        : currentMatch?.winner_corner ===
                                            'yellow'
                                          ? 'SUDUT KUNING'
                                          : 'SERI'
                                }}
                            </div>
                            <div
                                class="z-10 mt-2 text-xl font-bold tracking-wider text-white uppercase"
                            >
                                {{
                                    currentMatch?.winner_status
                                        ? currentMatch.winner_status.replace(
                                              /_/g,
                                              ' ',
                                          )
                                        : '-'
                                }}
                            </div>
                            <div
                                class="pointer-events-none absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent"
                            ></div>
                        </div>
                        <div
                            v-else
                            class="relative mt-6 flex flex-col items-center justify-center overflow-hidden rounded-2xl border-4 border-stone-800 bg-zinc-900 py-6 shadow-2xl"
                        >
                            <div
                                class="z-10 mb-2 text-sm font-black tracking-widest text-stone-500 uppercase"
                            >
                                Status Pertandingan
                            </div>
                            <div
                                class="z-10 text-3xl font-black tracking-widest text-stone-300 uppercase drop-shadow-md"
                            >
                                DITUNDA
                            </div>
                            <div
                                class="pointer-events-none absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- CONDITION 1: Ongoing -->
        <template v-else>
            <div class="relative z-10 flex h-full w-full flex-col">
                <!-- Small Header Section -->
                <div
                    class="flex h-12 w-full shrink-0 items-center justify-between border-b border-stone-800 bg-zinc-900 px-6 text-[11px] font-bold tracking-widest text-muted-foreground uppercase shadow-sm"
                >
                    <div class="flex items-center gap-4">
                        <FightFullscreenButton
                            :exit-click-count="exitClickCount"
                            :is-fullscreen="isFullscreen"
                            :remaining-exit-clicks="remainingExitClicks"
                            :required-exit-clicks="requiredExitClicks"
                            :title="buttonTitle"
                            :on-trigger="triggerFullscreen"
                        />
                        <span class="font-black text-yellow-500">{{
                            userName
                        }}</span>
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span
                            >Gelanggang
                            {{
                                props.arena?.arena_name ??
                                props.arena?.gelanggang_id ??
                                '-'
                            }}</span
                        >
                    </div>

                    <div class="flex items-center gap-4">
                        <span
                            >{{ currentMatch?.match_round }} -
                            {{ currentMatch?.group }}
                            {{ currentMatch?.category }}</span
                        >
                        <div class="h-4 w-px bg-stone-800"></div>
                        <span
                            >Partai
                            <span
                                class="ml-1 text-sm text-white tabular-nums"
                                >{{ currentMatch?.match_code }}</span
                            ></span
                        >
                    </div>
                </div>

                <!-- Main Header (Athlete VS Athlete) -->
                <div
                    class="z-10 flex h-32 w-full shrink-0 border-b border-stone-800 shadow-xl"
                >
                    <!-- Blue Section -->
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
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    class="bg-blue-950 text-[10px] font-bold tracking-widest text-blue-100 uppercase hover:bg-blue-950"
                                >
                                    {{ currentMatch?.contingent_blue || '-' }}
                                </Badge>
                                <Badge
                                    class="pointer-events-none border-none bg-black px-2 py-0.5 text-[10px] font-black tracking-widest text-blue-400 uppercase shadow-md"
                                >
                                    {{
                                        currentMatch?.weight_status_blue || '-'
                                    }}
                                    {{
                                        currentMatch?.weight_blue
                                            ? `- ${currentMatch.weight_blue} KG`
                                            : ''
                                    }}
                                </Badge>
                            </div>
                        </div>
                        <div
                            class="pt-1 text-[5.5rem] leading-none font-black text-white tabular-nums drop-shadow-md"
                        >
                            {{ activeRoundRecap?.total_poin_blue || 0 }}
                        </div>
                    </div>

                    <!-- Center Round / VS -->
                    <div
                        class="relative z-20 flex w-32 shrink-0 skew-x-[-10deg] flex-col items-center justify-center border-x border-stone-800 bg-zinc-950 font-black text-stone-600 italic shadow-[0_0_30px_rgba(0,0,0,0.5)]"
                    >
                        <div
                            class="flex skew-x-[10deg] flex-col items-center justify-center"
                        >
                            <template v-if="matchStatus === 'ongoing'">
                                <span
                                    class="mb-1 text-[10px] font-black tracking-widest text-stone-500 uppercase not-italic"
                                    >{{
                                        currentMatch?.round_number === 3
                                            ? 'Ronde'
                                            : 'Ronde'
                                    }}</span
                                >
                                <span
                                    :class="[
                                        'leading-none font-black text-white not-italic',
                                        currentMatch?.round_number === 3
                                            ? 'text-4xl'
                                            : 'text-5xl',
                                    ]"
                                    >{{
                                        currentMatch?.round_number === 3
                                            ? 'TBH'
                                            : currentMatch?.round_number
                                    }}</span
                                >
                            </template>
                            <template v-else>
                                <span class="text-3xl">VS</span>
                            </template>
                        </div>
                    </div>

                    <!-- Yellow Section -->
                    <div
                        class="relative flex flex-1 items-center justify-between overflow-hidden bg-gradient-to-l from-yellow-500 to-yellow-400 px-10 shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]"
                    >
                        <div
                            class="pt-1 text-[5.5rem] leading-none font-black text-black tabular-nums drop-shadow-md"
                        >
                            {{ activeRoundRecap?.total_poin_yellow || 0 }}
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
                            <div
                                class="flex flex-wrap items-center justify-end gap-2"
                            >
                                <Badge
                                    class="pointer-events-none border-none bg-black px-2 py-0.5 text-[10px] font-black tracking-widest text-yellow-500 uppercase shadow-md"
                                >
                                    {{
                                        currentMatch?.weight_yellow
                                            ? `${currentMatch.weight_yellow} KG -`
                                            : ''
                                    }}
                                    {{
                                        currentMatch?.weight_status_yellow ||
                                        '-'
                                    }}
                                </Badge>
                                <Badge
                                    class="bg-black text-[10px] font-bold tracking-widest text-yellow-400 uppercase hover:bg-black"
                                >
                                    {{ currentMatch?.contingent_yellow || '-' }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Section -->
                <div
                    class="flex flex-1 flex-col overflow-hidden bg-zinc-950 p-6 px-10"
                >
                    <!-- ONGOING SCORING LAYOUT -->
                    <div
                        class="relative z-10 mb-2 flex w-full flex-1 flex-col gap-6 pb-6"
                    >
                        <!-- Round Winners -->
                        <div class="mt-2 flex w-full justify-center gap-6">
                            <!-- R1 -->
                            <div
                                class="group relative flex w-48 flex-col items-center"
                            >
                                <div
                                    class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                >
                                    Ronde 1
                                </div>
                                <div
                                    :class="[
                                        'relative w-full overflow-hidden rounded-xl border py-4 text-center text-lg font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                        getRoundWinner(1) === 'yellow'
                                            ? 'border-yellow-500 bg-yellow-400 text-black'
                                            : getRoundWinner(1) === 'blue'
                                              ? 'border-blue-500 bg-blue-600 text-white'
                                              : getRoundWinner(1) === 'draw'
                                                ? 'border-stone-400 bg-stone-500 text-white'
                                                : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                    ]"
                                >
                                    <span v-if="getRoundWinner(1) === 'yellow'"
                                        >Kuning</span
                                    >
                                    <span
                                        v-else-if="getRoundWinner(1) === 'blue'"
                                        >Biru</span
                                    >
                                    <span
                                        v-else-if="getRoundWinner(1) === 'draw'"
                                        >Seri</span
                                    >
                                    <span v-else>-</span>
                                </div>
                            </div>

                            <!-- R2 -->
                            <div
                                class="group relative flex w-48 flex-col items-center"
                            >
                                <div
                                    class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                >
                                    Ronde 2
                                </div>
                                <div
                                    :class="[
                                        'relative w-full overflow-hidden rounded-xl border py-4 text-center text-lg font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                        getRoundWinner(2) === 'yellow'
                                            ? 'border-yellow-500 bg-yellow-400 text-black'
                                            : getRoundWinner(2) === 'blue'
                                              ? 'border-blue-500 bg-blue-600 text-white'
                                              : getRoundWinner(2) === 'draw'
                                                ? 'border-stone-400 bg-stone-500 text-white'
                                                : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                    ]"
                                >
                                    <span v-if="getRoundWinner(2) === 'yellow'"
                                        >Kuning</span
                                    >
                                    <span
                                        v-else-if="getRoundWinner(2) === 'blue'"
                                        >Biru</span
                                    >
                                    <span
                                        v-else-if="getRoundWinner(2) === 'draw'"
                                        >Seri</span
                                    >
                                    <span v-else>-</span>
                                </div>
                            </div>

                            <!-- R3 / TBH -->
                            <div
                                class="group relative flex w-48 flex-col items-center"
                            >
                                <div
                                    class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                >
                                    Ronde TBH
                                </div>
                                <div
                                    :class="[
                                        'relative w-full overflow-hidden rounded-xl border py-4 text-center text-lg font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                        getRoundWinner(3) === 'yellow'
                                            ? 'border-yellow-500 bg-yellow-400 text-black'
                                            : getRoundWinner(3) === 'blue'
                                              ? 'border-blue-500 bg-blue-600 text-white'
                                              : getRoundWinner(3) === 'draw'
                                                ? 'border-stone-400 bg-stone-500 text-white'
                                                : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                    ]"
                                >
                                    <span v-if="getRoundWinner(3) === 'yellow'"
                                        >Kuning</span
                                    >
                                    <span
                                        v-else-if="getRoundWinner(3) === 'blue'"
                                        >Biru</span
                                    >
                                    <span
                                        v-else-if="getRoundWinner(3) === 'draw'"
                                        >Seri</span
                                    >
                                    <span v-else>-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Real-time Valid Sequences -->
                        <div class="mt-2 flex flex-1 flex-col">
                            <div
                                class="mb-4 grid w-full shrink-0 grid-cols-[1fr_80px_100px_80px_1fr] gap-6 text-center text-[11px] font-black tracking-widest text-muted-foreground uppercase"
                            >
                                <div class="pl-2 text-left">Detail Nilai</div>
                                <div>Total</div>
                                <div>
                                    Ronde {{ currentMatch?.round_number }}
                                </div>
                                <div>Total</div>
                                <div class="pr-2 text-right">Detail Nilai</div>
                            </div>

                            <div
                                class="custom-scrollbar flex flex-1 flex-col gap-4 overflow-y-auto pr-2 pb-2"
                            >
                                <div
                                    v-for="(jury, idx) in activeRoundJuriesData"
                                    :key="idx"
                                    class="grid min-h-[4.5rem] grid-cols-[1fr_80px_100px_80px_1fr] items-stretch gap-6"
                                >
                                    <!-- Blue Nilai -->
                                    <div
                                        class="flex flex-wrap content-center gap-1.5 overflow-hidden rounded-md border-[1.5px] border-blue-600/40 bg-zinc-800 px-4 py-2"
                                    >
                                        <template
                                            v-for="(p, i) in jury.blue_details"
                                            :key="'bn' + i"
                                        >
                                            <span
                                                v-if="p.ref_score_id"
                                                :class="[
                                                    p.is_valid
                                                        ? 'text-green-500'
                                                        : 'text-white',
                                                    'text-[1.1rem] leading-none font-bold',
                                                ]"
                                                >{{ p.score?.name }},</span
                                            >
                                            <span
                                                v-else-if="p.ref_punishment_id"
                                                :class="[
                                                    p.is_valid
                                                        ? 'text-red-500 underline decoration-green-500 decoration-2 underline-offset-4'
                                                        : 'text-red-500',
                                                    'text-[1.1rem] leading-none font-bold',
                                                ]"
                                                >{{ p.punishment?.name }},</span
                                            >
                                        </template>
                                    </div>

                                    <!-- Blue Total -->
                                    <div
                                        :class="[
                                            'flex items-center justify-center rounded-md border-[1.5px] text-2xl font-black tracking-tighter tabular-nums transition-colors',
                                            jury.blue_total > 200
                                                ? 'border-red-500 bg-red-600 text-white'
                                                : jury.jury_winner === 'blue'
                                                  ? 'border-blue-500 bg-blue-600 text-white shadow-[0_0_15px_rgba(37,99,235,0.4)]'
                                                  : 'border-blue-600/40 bg-zinc-800/80 text-blue-100/70',
                                        ]"
                                    >
                                        {{ jury.blue_total }}
                                    </div>

                                    <!-- PW Name -->
                                    <div
                                        class="flex items-center justify-center rounded-md border-[1px] border-stone-700 bg-zinc-800 text-sm font-black tracking-wider text-stone-300 uppercase drop-shadow-sm"
                                    >
                                        {{ jury.jury_name }}
                                    </div>

                                    <!-- Yellow Total -->
                                    <div
                                        :class="[
                                            'flex items-center justify-center rounded-md border-[1.5px] text-2xl font-black tracking-tighter tabular-nums transition-colors',
                                            jury.yellow_total > 200
                                                ? 'border-red-500 bg-red-600 text-white'
                                                : jury.jury_winner === 'yellow'
                                                  ? 'border-yellow-400 bg-yellow-500 text-black shadow-[0_0_15px_rgba(234,179,8,0.4)]'
                                                  : 'border-yellow-500/40 bg-zinc-800/80 text-yellow-100/70',
                                        ]"
                                    >
                                        {{ jury.yellow_total }}
                                    </div>

                                    <!-- Yellow Nilai -->
                                    <div
                                        class="flex flex-wrap content-center justify-end gap-1.5 overflow-hidden rounded-md border-[1.5px] border-yellow-500/40 bg-zinc-800 px-4 py-2"
                                    >
                                        <template
                                            v-for="(
                                                p, i
                                            ) in jury.yellow_details"
                                            :key="'yn' + i"
                                        >
                                            <span
                                                v-if="p.ref_score_id"
                                                :class="[
                                                    p.is_valid
                                                        ? 'text-green-500'
                                                        : 'text-white',
                                                    'text-[1.1rem] leading-none font-bold',
                                                ]"
                                                >{{ p.score?.name }},</span
                                            >
                                            <span
                                                v-else-if="p.ref_punishment_id"
                                                :class="[
                                                    p.is_valid
                                                        ? 'text-red-500 underline decoration-green-500 decoration-2 underline-offset-4'
                                                        : 'text-red-500',
                                                    'text-[1.1rem] leading-none font-bold',
                                                ]"
                                                >{{ p.punishment?.name }},</span
                                            >
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped></style>
