<script setup lang="ts">
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import FightFullscreenButton from '@/components/fight/FightFullscreenButton.vue';
import FightWaitingState from '@/components/fight/FightWaitingState.vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useFullscreenLock } from '@/composables/useFullscreenLock';
import { Delete } from 'lucide-vue-next';
import axios from 'axios';

const props = defineProps<{
    arena: any;
    activeMatch?: any;
    recapPoints?: any[];
    yellowPoints?: any[];
    bluePoints?: any[];
}>();

const page = usePage<any>();
const userName = computed(() => page.props.auth?.user?.name || 'Juri 1');
const pwName = computed(() => userName.value.replace('Juri', 'Pembantu Wasit'));
const {
    buttonTitle,
    exitClickCount,
    isFullscreen,
    remainingExitClicks,
    requiredExitClicks,
    triggerFullscreen,
} = useFullscreenLock();

// Reactive match state — starts from server-side props, updated via Echo
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

// Show scoring UI only when status is 'ongoing'
const isLoading = computed(() => {
    return !currentMatch.value || currentMatch.value.status !== 'ongoing';
});

const juryNumber = computed(() => {
    const match = userName.value.match(/\d+/);
    return match ? parseInt(match[0], 10) : 1;
});

const roundsData = computed(() => {
    return [1, 2, 3].map((round) => {
        // Yellow Details
        const yDetails = localYellowPoints.value.filter(
            (p) =>
                p.jury_number === juryNumber.value && p.round_number === round,
        );

        // Blue Details
        const bDetails = localBluePoints.value.filter(
            (p) =>
                p.jury_number === juryNumber.value && p.round_number === round,
        );

        // Recap
        const recap = localRecapPoints.value.find(
            (r) => r.round_number === round,
        );

        let yTotal = 0;
        let bTotal = 0;

        if (recap) {
            const jNumMap: Record<number, string> = {
                1: 'one',
                2: 'two',
                3: 'three',
                4: 'four',
            };
            const word = jNumMap[juryNumber.value] || 'one';
            yTotal = recap[`jury_${word}_total_poin_yellow`] ?? 0;
            bTotal = recap[`jury_${word}_total_poin_blue`] ?? 0;
        }

        return {
            round_number: round,
            yellow_details: yDetails,
            yellow_total: yTotal,
            blue_details: bDetails,
            blue_total: bTotal,
        };
    });
});

const computedWinner = computed(() => {
    if (!currentMatch.value) return { status: 'draw', text: 'Seri' };

    const roundNumber = currentMatch.value.round_number;
    const recap = localRecapPoints.value.find(
        (r) => r.round_number === roundNumber,
    );
    if (!recap) return { status: 'draw', text: 'Seri' };

    const jNumMap: Record<number, string> = {
        1: 'one',
        2: 'two',
        3: 'three',
        4: 'four',
    };
    const word = jNumMap[juryNumber.value] || 'one';

    const statusWinner = recap[`jury_${word}_winner`] || 'draw';

    if (statusWinner === 'yellow') {
        return { status: 'yellow', text: 'Pemenang Sudut Kuning Ronde Ini' };
    } else if (statusWinner === 'blue') {
        return { status: 'blue', text: 'Pemenang Sudut Biru Ronde Ini' };
    } else {
        return { status: 'draw', text: 'Seri' };
    }
});

// Scoring Submission Logic
const submitScore = async (
    corner: 'yellow' | 'blue',
    type: 'score' | 'punishment',
    ref_id: number,
) => {
    if (!currentMatch.value || currentMatch.value.status !== 'ongoing') return;

    try {
        const response = await axios.post('/api/jury/score', {
            partai_id: currentMatch.value.id,
            corner,
            round_number: currentMatch.value.round_number,
            jury_number: juryNumber.value,
            type,
            ref_id,
        });

        // Instant local optimistic update
        const data = response.data;
        if (data.success) {
            if (corner === 'yellow') localYellowPoints.value.push(data.detail);
            if (corner === 'blue') localBluePoints.value.push(data.detail);

            if (data.recap) {
                const idx = localRecapPoints.value.findIndex(
                    (r) => r.round_number === data.recap.round_number,
                );
                if (idx !== -1) localRecapPoints.value[idx] = data.recap;
                else localRecapPoints.value.push(data.recap);
            }
        }
    } catch (e) {
        console.error('Failed to submit score:', e);
    }
};

const deleteLastScore = async (corner: 'yellow' | 'blue') => {
    if (!currentMatch.value || currentMatch.value.status !== 'ongoing') return;

    const list =
        corner === 'yellow' ? localYellowPoints.value : localBluePoints.value;
    const items = list.filter(
        (p) =>
            p.jury_number === juryNumber.value &&
            p.round_number === currentMatch.value.round_number,
    );
    if (!items.length) return;

    const lastItem = items[items.length - 1];

    try {
        const response = await axios.delete(`/api/jury/score/${lastItem.id}`, {
            data: {
                partai_id: currentMatch.value.id,
                corner,
                round_number: currentMatch.value.round_number,
                jury_number: juryNumber.value,
            },
        });

        if (response.data.success) {
            if (corner === 'yellow') {
                localYellowPoints.value = localYellowPoints.value.filter(
                    (p) => p.id !== lastItem.id,
                );
            } else {
                localBluePoints.value = localBluePoints.value.filter(
                    (p) => p.id !== lastItem.id,
                );
            }

            if (response.data.recap) {
                const idx = localRecapPoints.value.findIndex(
                    (r) => r.round_number === response.data.recap.round_number,
                );
                if (idx !== -1)
                    localRecapPoints.value[idx] = response.data.recap;
            }
        }
    } catch (e) {
        console.error('Failed to delete score:', e);
    }
};

// Real-time Echo listener
// FightJury only listens for match STATE changes (status/round/match switch).
// Score updates are NOT listened here — the jury that submitted already updated
// locally via axios response. match.score channel is for other observers (e.g. Operator).
let echoStatusChannel: any = null;

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
                        // Match changed entirely — reload scoring data from server
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
                        // Status/round update only
                        currentMatch.value = e.match;
                    }
                }
            });
    }
});

onUnmounted(() => {
    if (echoStatusChannel) {
        echoStatusChannel.stopListening('.ActiveMatchUpdated');
        const echo = (window as any).Echo;
        if (echo) {
            echo.leaveChannel('match.status');
        }
    }
});
</script>

<template>
    <Head title="Tanding Olahraga - Tapak Suci" />
    <div class="flex h-screen overflow-hidden bg-zinc-950 text-foreground">
        <template v-if="isLoading">
            <FightWaitingState clickable :on-logo-click="triggerFullscreen" />
        </template>

        <template v-else>
            <!-- Scoring State -->
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
                            pwName
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
                    class="z-10 flex h-28 w-full shrink-0 border-b border-stone-800 shadow-xl"
                >
                    <!-- Yellow Section -->
                    <div
                        class="relative flex flex-1 flex-col items-end justify-center overflow-hidden bg-gradient-to-r from-yellow-500 to-yellow-400 px-10 text-right shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]"
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
                        <Badge
                            class="bg-black text-[10px] font-bold tracking-widest text-yellow-400 uppercase hover:bg-black"
                        >
                            {{ currentMatch?.contingent_yellow || '-' }}
                        </Badge>
                    </div>

                    <!-- Netral Section (VS) -->
                    <div
                        class="relative z-20 flex w-24 shrink-0 skew-x-[-10deg] items-center justify-center border-x border-stone-800 bg-zinc-950 text-2xl font-black text-stone-600 italic shadow-[0_0_30px_rgba(0,0,0,0.5)]"
                    >
                        <span class="skew-x-[10deg]">VS</span>
                    </div>

                    <!-- Blue Section -->
                    <div
                        class="relative flex flex-1 flex-col items-start justify-center overflow-hidden bg-gradient-to-l from-blue-700 to-blue-600 px-10 text-left shadow-[inset_0_0_50px_rgba(0,0,0,0.2)]"
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
                        <Badge
                            class="bg-blue-950 text-[10px] font-bold tracking-widest text-blue-100 uppercase hover:bg-blue-950"
                        >
                            {{ currentMatch?.contingent_blue || '-' }}
                        </Badge>
                    </div>
                </div>

                <!-- Winner Alert Banner -->
                <div
                    :class="[
                        'relative z-20 flex w-full shrink-0 items-center justify-center border-b border-white/10 py-3 text-lg font-bold tracking-[0.1em] uppercase shadow-2xl transition-all duration-500',
                        computedWinner.status.startsWith('yellow')
                            ? 'bg-yellow-500 text-black shadow-yellow-500/30'
                            : computedWinner.status.startsWith('blue')
                              ? 'bg-blue-600 text-white shadow-blue-600/30'
                              : 'bg-zinc-900 text-zinc-500',
                    ]"
                >
                    <span class="drop-shadow-lg">{{
                        computedWinner.text
                    }}</span>
                </div>

                <!-- Main Section -->
                <div
                    class="flex flex-1 flex-col overflow-hidden bg-zinc-950 p-6 px-10"
                >
                    <!-- Column Titles -->
                    <div
                        class="mb-4 grid w-full grid-cols-[1fr_80px_80px_80px_1fr] gap-6 text-center text-[11px] font-black tracking-widest text-muted-foreground uppercase"
                    >
                        <div class="pl-2 text-left">Nilai</div>
                        <div>Jumlah</div>
                        <div></div>
                        <div>Jumlah</div>
                        <div class="pr-2 text-right">Nilai</div>
                    </div>

                    <div class="flex flex-1 flex-col gap-5">
                        <div
                            v-for="round in roundsData"
                            :key="round.round_number"
                            :class="[
                                'grid min-h-[4.5rem] grid-cols-[1fr_80px_80px_80px_1fr] items-stretch gap-6 transition-all duration-500',
                                currentMatch?.round_number ===
                                round.round_number
                                    ? 'scale-100 opacity-100 drop-shadow-md'
                                    : 'pointer-events-none scale-[0.98] opacity-[0.55] grayscale-[50%]',
                            ]"
                        >
                            <!-- Yellow Nilai -->
                            <div
                                class="flex flex-wrap content-center gap-1.5 overflow-hidden rounded-md border-[1.5px] border-yellow-500/80 bg-zinc-800/80 px-4 py-2"
                            >
                                <template
                                    v-for="(p, i) in round.yellow_details"
                                    :key="i"
                                >
                                    <span
                                        v-if="p.ref_score_id"
                                        class="text-[1.1rem] leading-none font-bold tracking-tight text-white"
                                        >{{ p.score?.name }},</span
                                    >
                                    <span
                                        v-else-if="p.ref_punishment_id"
                                        class="text-[1.1rem] leading-none font-bold tracking-tight text-red-500"
                                        >{{ p.punishment?.name }},</span
                                    >
                                </template>
                            </div>

                            <!-- Yellow Total -->
                            <div
                                :class="[
                                    'flex items-center justify-center rounded-md border-[1.5px] text-2xl font-black tracking-tighter tabular-nums',
                                    round.yellow_total > 200
                                        ? 'border-red-500 bg-red-600 text-white'
                                        : 'border-yellow-500/80 bg-zinc-800/80 text-white',
                                ]"
                            >
                                {{ round.yellow_total }}
                            </div>

                            <!-- Ronde Indicator -->
                            <div
                                :class="[
                                    'flex items-center justify-center rounded-md text-xl font-black transition-colors',
                                    currentMatch?.round_number ===
                                    round.round_number
                                        ? 'bg-green-500 text-white shadow-lg'
                                        : 'bg-green-500/40 text-white/50',
                                ]"
                            >
                                {{
                                    round.round_number === 3
                                        ? 'TBH'
                                        : round.round_number
                                }}
                            </div>

                            <!-- Blue Total -->
                            <div
                                :class="[
                                    'flex items-center justify-center rounded-md border-[1.5px] text-2xl font-black tracking-tighter tabular-nums',
                                    round.blue_total > 200
                                        ? 'border-red-500 bg-red-600 text-white'
                                        : 'border-blue-600/80 bg-zinc-800/80 text-white',
                                ]"
                            >
                                {{ round.blue_total }}
                            </div>

                            <!-- Blue Nilai -->
                            <div
                                class="flex flex-wrap content-center gap-1.5 overflow-hidden rounded-md border-[1.5px] border-blue-600/80 bg-zinc-800/80 px-4 py-2"
                            >
                                <template
                                    v-for="(p, i) in round.blue_details"
                                    :key="i"
                                >
                                    <span
                                        v-if="p.ref_score_id"
                                        class="text-[1.1rem] leading-none font-bold tracking-tight text-white"
                                        >{{ p.score?.name }},</span
                                    >
                                    <span
                                        v-else-if="p.ref_punishment_id"
                                        class="text-[1.1rem] leading-none font-bold tracking-tight text-red-500"
                                        >{{ p.punishment?.name }},</span
                                    >
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Button Section -->
                <div
                    class="flex h-[260px] shrink-0 flex-row items-center justify-between gap-8 bg-zinc-950/80 p-6"
                >
                    <!-- Yellow Corner Buttons -->
                    <div
                        class="grid h-full w-full flex-1 grid-cols-4 grid-rows-3 gap-3"
                    >
                        <button
                            @click="submitScore('yellow', 'score', 1)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            20
                        </button>
                        <button
                            @click="submitScore('yellow', 'score', 3)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            30
                        </button>
                        <button
                            @click="submitScore('yellow', 'score', 5)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            40
                        </button>
                        <button
                            @click="deleteLastScore('yellow')"
                            class="row-span-2 flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            <Delete class="h-10 w-10 stroke-[3]" />
                        </button>

                        <button
                            @click="submitScore('yellow', 'score', 2)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            10+20
                        </button>
                        <button
                            @click="submitScore('yellow', 'score', 4)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            10+30
                        </button>
                        <button
                            @click="submitScore('yellow', 'score', 6)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            10+40
                        </button>

                        <button
                            @click="submitScore('yellow', 'punishment', 1)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            -10
                        </button>
                        <button
                            @click="submitScore('yellow', 'punishment', 2)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            -20
                        </button>
                        <button
                            @click="submitScore('yellow', 'punishment', 3)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            -30
                        </button>
                        <button
                            @click="submitScore('yellow', 'punishment', 4)"
                            class="flex items-center justify-center rounded-md bg-yellow-400 text-2xl font-black text-black transition-colors hover:bg-yellow-500"
                        >
                            -40
                        </button>
                    </div>

                    <!-- Neutral Logo Section (Center) -->
                    <div
                        class="flex h-full w-32 shrink-0 items-center justify-center"
                    >
                        <img
                            src="/assets/images/ts_logo.png"
                            alt="TS Logo"
                            class="h-28 w-28 object-contain opacity-90 mix-blend-screen drop-shadow-[0_0_15px_rgba(255,255,255,0.1)]"
                        />
                    </div>

                    <!-- Blue Corner Buttons -->
                    <div
                        class="grid h-full w-full flex-1 grid-cols-4 grid-rows-3 gap-3"
                    >
                        <button
                            @click="submitScore('blue', 'score', 1)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            20
                        </button>
                        <button
                            @click="submitScore('blue', 'score', 3)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            30
                        </button>
                        <button
                            @click="submitScore('blue', 'score', 5)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            40
                        </button>
                        <button
                            @click="deleteLastScore('blue')"
                            class="row-span-2 flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            <Delete class="h-10 w-10 stroke-[3]" />
                        </button>

                        <button
                            @click="submitScore('blue', 'score', 2)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            10+20
                        </button>
                        <button
                            @click="submitScore('blue', 'score', 4)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            10+30
                        </button>
                        <button
                            @click="submitScore('blue', 'score', 6)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            10+40
                        </button>

                        <button
                            @click="submitScore('blue', 'punishment', 1)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            -10
                        </button>
                        <button
                            @click="submitScore('blue', 'punishment', 2)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            -20
                        </button>
                        <button
                            @click="submitScore('blue', 'punishment', 3)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            -30
                        </button>
                        <button
                            @click="submitScore('blue', 'punishment', 4)"
                            class="flex items-center justify-center rounded-md bg-blue-600 text-2xl font-black text-white transition-colors hover:bg-blue-500"
                        >
                            -40
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped></style>
