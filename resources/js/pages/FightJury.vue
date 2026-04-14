<script setup lang="ts">
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Bell, Delete } from 'lucide-vue-next';
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

// Reactive match state — starts from server-side props, updated via Echo
const currentMatch = ref<any>(props.activeMatch ?? null);
const localYellowPoints = ref<any[]>([...(props.yellowPoints || [])]);
const localBluePoints = ref<any[]>([...(props.bluePoints || [])]);
const localRecapPoints = ref<any[]>([...(props.recapPoints || [])]);

// Sync with Inertia props changes
watch(() => props.activeMatch, (newVal) => { currentMatch.value = newVal; }, { deep: true });
watch(() => props.yellowPoints, (newVal) => { localYellowPoints.value = [...(newVal || [])]; }, { deep: true });
watch(() => props.bluePoints, (newVal) => { localBluePoints.value = [...(newVal || [])]; }, { deep: true });
watch(() => props.recapPoints, (newVal) => { localRecapPoints.value = [...(newVal || [])]; }, { deep: true });

// Show scoring UI only when status is 'ongoing'
const isLoading = computed(() => {
    return !currentMatch.value || currentMatch.value.status !== 'ongoing';
});

const juryNumber = computed(() => {
    const match = userName.value.match(/\d+/);
    return match ? parseInt(match[0], 10) : 1;
});

const roundsData = computed(() => {
    return [1, 2, 3].map(round => {
        // Yellow Details
        const yDetails = localYellowPoints.value.filter(
            p => p.jury_number === juryNumber.value && p.round_number === round
        );
        
        // Blue Details
        const bDetails = localBluePoints.value.filter(
            p => p.jury_number === juryNumber.value && p.round_number === round
        );

        // Recap
        const recap = localRecapPoints.value.find(r => r.round_number === round);

        let yTotal = 0;
        let bTotal = 0;

        if (recap) {
            const jNumMap: Record<number, string> = { 1: 'one', 2: 'two', 3: 'three', 4: 'four' };
            const word = jNumMap[juryNumber.value] || 'one';
            yTotal = recap[`jury_${word}_total_poin_yellow`] ?? 0;
            bTotal = recap[`jury_${word}_total_poin_blue`] ?? 0;
        }

        return {
            round_number: round,
            yellow_details: yDetails,
            yellow_total: yTotal,
            blue_details: bDetails,
            blue_total: bTotal
        };
    });
});

const computedWinner = computed(() => {
    if (!currentMatch.value) return { status: 'draw', text: 'Seri' };
    
    const roundNumber = currentMatch.value.round_number;
    const recap = localRecapPoints.value.find(r => r.round_number === roundNumber);
    if (!recap) return { status: 'draw', text: 'Seri' };

    const jNumMap: Record<number, string> = { 1: 'one', 2: 'two', 3: 'three', 4: 'four' };
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
const submitScore = async (corner: 'yellow' | 'blue', type: 'score' | 'punishment', ref_id: number) => {
    if (!currentMatch.value || currentMatch.value.status !== 'ongoing') return;
    
    try {
        const response = await axios.post('/api/jury/score', {
            partai_id: currentMatch.value.id,
            corner,
            round_number: currentMatch.value.round_number,
            jury_number: juryNumber.value,
            type,
            ref_id
        });

        // Instant local optimistic update
        const data = response.data;
        if (data.success) {
            if (corner === 'yellow') localYellowPoints.value.push(data.detail);
            if (corner === 'blue') localBluePoints.value.push(data.detail);

            if (data.recap) {
                const idx = localRecapPoints.value.findIndex(r => r.round_number === data.recap.round_number);
                if (idx !== -1) localRecapPoints.value[idx] = data.recap;
                else localRecapPoints.value.push(data.recap);
            }
        }
    } catch (e) {
        console.error("Failed to submit score:", e);
    }
};

const deleteLastScore = async (corner: 'yellow' | 'blue') => {
    if (!currentMatch.value || currentMatch.value.status !== 'ongoing') return;

    const list = corner === 'yellow' ? localYellowPoints.value : localBluePoints.value;
    const items = list.filter(p => p.jury_number === juryNumber.value && p.round_number === currentMatch.value.round_number);
    if (!items.length) return;
    
    const lastItem = items[items.length - 1];

    try {
        const response = await axios.delete(`/api/jury/score/${lastItem.id}`, {
            data: {
                partai_id: currentMatch.value.id,
                corner,
                round_number: currentMatch.value.round_number,
                jury_number: juryNumber.value
            }
        });

        if (response.data.success) {
            if (corner === 'yellow') {
                localYellowPoints.value = localYellowPoints.value.filter(p => p.id !== lastItem.id);
            } else {
                localBluePoints.value = localBluePoints.value.filter(p => p.id !== lastItem.id);
            }

            if (response.data.recap) {
                const idx = localRecapPoints.value.findIndex(r => r.round_number === response.data.recap.round_number);
                if (idx !== -1) localRecapPoints.value[idx] = response.data.recap;
            }
        }
    } catch (e) {
        console.error("Failed to delete score:", e);
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
        echoStatusChannel = echo.channel('match.status')
            .listen('.ActiveMatchUpdated', (e: any) => {
                if (e.match) {
                    if (!currentMatch.value || currentMatch.value.id !== e.match.id) {
                        // Match changed entirely — reload scoring data from server
                        currentMatch.value = e.match;
                        router.reload({ only: ['activeMatch', 'recapPoints', 'yellowPoints', 'bluePoints'] });
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
    <div class="flex h-screen bg-zinc-950 text-foreground overflow-hidden">
        
        <template v-if="isLoading">
            <!-- Loading State -->
            <div class="flex-1 flex flex-col items-center justify-center z-10 animate-pulse relative">
                <img src="/assets/images/ts_logo.png" alt="Tapak Suci Logo" class="w-64 h-64 object-contain drop-shadow-2xl z-10" />
                <p class="mt-8 text-muted-foreground/50 text-sm uppercase tracking-widest font-bold z-10">Menunggu Pertandingan...</p>
                <!-- Background Decal -->
                <div class="absolute inset-0 flex items-center justify-center opacity-[0.03] pointer-events-none">
                    <svg viewBox="0 0 100 100" class="w-[800px] h-[800px]" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="50" cy="50" r="40" stroke="currentColor" stroke-width="2" fill="none" />
                        <path d="M50 10 L50 90 M10 50 L90 50" stroke="currentColor" stroke-width="2" />
                        <circle cx="50" cy="50" r="20" stroke="currentColor" stroke-width="2" fill="none" />
                    </svg>
                </div>
            </div>
        </template>
        
        <template v-else>
            <!-- Scoring State -->
            <div class="w-full h-full flex flex-col z-10 relative">
                
                <!-- Small Header Section -->
                <div class="h-12 bg-zinc-900 border-b border-stone-800 shrink-0 w-full flex items-center justify-between px-6 shadow-sm text-[11px] font-bold uppercase tracking-widest text-muted-foreground">
                    <div class="flex items-center gap-4">
                        <span class="text-yellow-500 font-black">{{ pwName }}</span>
                        <div class="w-px h-4 bg-stone-800"></div>
                        <span>Gelanggang {{ props.arena?.arena_name ?? props.arena?.gelanggang_id ?? '-' }}</span>
                    </div>

                    <div class="flex items-center gap-4">
                        <span>{{ currentMatch?.match_round }} - {{ currentMatch?.group }} {{ currentMatch?.category }}</span>
                        <div class="w-px h-4 bg-stone-800"></div>
                        <span>Partai <span class="text-white ml-1 tabular-nums text-sm">{{ currentMatch?.match_code }}</span></span>
                    </div>
                </div>

                <!-- Main Header (Athlete VS Athlete) -->
                <div class="h-28 shrink-0 w-full flex z-10 shadow-xl border-b border-stone-800">
                    <!-- Yellow Section -->
                    <div class="flex-1 bg-gradient-to-r from-yellow-500 to-yellow-400 flex flex-col items-end justify-center px-10 relative overflow-hidden text-right shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]">
                        <h2 class="text-3xl font-black text-black uppercase tracking-wider mb-2 drop-shadow-sm truncate w-full">
                            {{ currentMatch?.atlete_yellow || currentMatch?.athlete_yellow || '-' }}
                        </h2>
                        <Badge class="bg-black text-yellow-400 hover:bg-black uppercase font-bold tracking-widest text-[10px]">
                            {{ currentMatch?.contingent_yellow || '-' }}
                        </Badge>
                    </div>

                    <!-- Netral Section (VS) -->
                    <div class="w-24 bg-zinc-950 flex shadow-[0_0_30px_rgba(0,0,0,0.5)] items-center justify-center shrink-0 border-x border-stone-800 z-20 relative text-stone-600 font-black italic text-2xl skew-x-[-10deg]">
                        <span class="skew-x-[10deg]">VS</span>
                    </div>

                    <!-- Blue Section -->
                    <div class="flex-1 bg-gradient-to-l from-blue-700 to-blue-600 flex flex-col items-start justify-center px-10 relative overflow-hidden text-left shadow-[inset_0_0_50px_rgba(0,0,0,0.2)]">
                        <h2 class="text-3xl font-black text-white uppercase tracking-wider mb-2 drop-shadow-sm truncate w-full">
                            {{ currentMatch?.atlete_blue || currentMatch?.athlete_blue || '-' }}
                        </h2>
                        <Badge class="bg-blue-950 text-blue-100 hover:bg-blue-950 uppercase font-bold tracking-widest text-[10px]">
                            {{ currentMatch?.contingent_blue || '-' }}
                        </Badge>
                    </div>
                </div>

                <!-- Winner Alert Banner -->
                <div :class="[
                    'shrink-0 w-full py-3 flex items-center justify-center font-bold uppercase tracking-[0.1em] text-lg transition-all duration-500 shadow-2xl z-20 relative border-b border-white/10',
                    computedWinner.status.startsWith('yellow') ? 'bg-yellow-500 text-black shadow-yellow-500/30' : 
                    computedWinner.status.startsWith('blue') ? 'bg-blue-600 text-white shadow-blue-600/30' : 
                    'bg-zinc-900 text-zinc-500'
                ]">
                    <span class="drop-shadow-lg">{{ computedWinner.text }}</span>
                </div>

                <!-- Main Section -->
                <div class="flex-1 flex flex-col p-6 overflow-hidden bg-zinc-950 px-10">
                    <!-- Column Titles -->
                    <div class="grid grid-cols-[1fr_80px_80px_80px_1fr] gap-6 mb-4 text-center text-[11px] font-black uppercase tracking-widest text-muted-foreground w-full">
                        <div class="text-left pl-2">Nilai</div>
                        <div>Jumlah</div>
                        <div></div>
                        <div>Jumlah</div>
                        <div class="text-right pr-2">Nilai</div>
                    </div>
                    
                    <div class="flex flex-col gap-5 flex-1">
                        <div v-for="round in roundsData" :key="round.round_number" :class="[
                            'grid grid-cols-[1fr_80px_80px_80px_1fr] gap-6 items-stretch min-h-[4.5rem] transition-all duration-500',
                            currentMatch?.round_number === round.round_number ? 'opacity-100 scale-100 drop-shadow-md' : 'opacity-[0.55] grayscale-[50%] scale-[0.98] pointer-events-none'
                        ]">
                            
                            <!-- Yellow Nilai -->
                            <div class="bg-zinc-800/80 border-[1.5px] border-yellow-500/80 rounded-md px-4 py-2 flex flex-wrap content-center gap-1.5 overflow-hidden">
                                <template v-for="(p, i) in round.yellow_details" :key="i">
                                    <span v-if="p.ref_score_id" class="text-white text-[1.1rem] font-bold leading-none tracking-tight">{{ p.score?.name }},</span>
                                    <span v-else-if="p.ref_punishment_id" class="text-red-500 text-[1.1rem] font-bold leading-none tracking-tight">{{ p.punishment?.name }},</span>
                                </template>
                            </div>
                            
                            <!-- Yellow Total -->
                            <div :class="[
                                'flex items-center justify-center rounded-md border-[1.5px] font-black text-2xl tabular-nums tracking-tighter', 
                                round.yellow_total > 200 ? 'bg-red-600 border-red-500 text-white' : 'bg-zinc-800/80 border-yellow-500/80 text-white'
                            ]">
                                {{ round.yellow_total }}
                            </div>
                            
                            <!-- Ronde Indicator -->
                            <div :class="[
                                'flex items-center justify-center font-black text-xl rounded-md transition-colors', 
                                currentMatch?.round_number === round.round_number ? 'bg-green-500 text-white shadow-lg' : 'bg-green-500/40 text-white/50'
                            ]">
                                {{ round.round_number === 3 ? 'TBH' : round.round_number }}
                            </div>
                            
                            <!-- Blue Total -->
                            <div :class="[
                                'flex items-center justify-center rounded-md border-[1.5px] font-black text-2xl tabular-nums tracking-tighter', 
                                round.blue_total > 200 ? 'bg-red-600 border-red-500 text-white' : 'bg-zinc-800/80 border-blue-600/80 text-white'
                            ]">
                                {{ round.blue_total }}
                            </div>
                            
                            <!-- Blue Nilai -->
                            <div class="bg-zinc-800/80 border-[1.5px] border-blue-600/80 rounded-md px-4 py-2 flex flex-wrap content-center gap-1.5 overflow-hidden">
                                <template v-for="(p, i) in round.blue_details" :key="i">
                                    <span v-if="p.ref_score_id" class="text-white text-[1.1rem] font-bold leading-none tracking-tight">{{ p.score?.name }},</span>
                                    <span v-else-if="p.ref_punishment_id" class="text-red-500 text-[1.1rem] font-bold leading-none tracking-tight">{{ p.punishment?.name }},</span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Button Section -->
                <div class="bg-zinc-950/80 shrink-0 p-6 flex flex-row items-center justify-between gap-8 h-[260px]">
                    
                    <!-- Yellow Corner Buttons -->
                    <div class="flex-1 grid grid-cols-4 grid-rows-3 gap-3 h-full w-full">
                        <button @click="submitScore('yellow', 'score', 1)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">20</button>
                        <button @click="submitScore('yellow', 'score', 3)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">30</button>
                        <button @click="submitScore('yellow', 'score', 5)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">40</button>
                        <button class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">
                            <Bell class="w-8 h-8 stroke-[3]" />
                        </button>
                        
                        <button @click="submitScore('yellow', 'score', 2)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">10+20</button>
                        <button @click="submitScore('yellow', 'score', 4)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">10+30</button>
                        <button @click="submitScore('yellow', 'score', 6)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">10+40</button>
                        <button @click="deleteLastScore('yellow')" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">
                            <Delete class="w-8 h-8 stroke-[3]" />
                        </button>

                        <button @click="submitScore('yellow', 'punishment', 1)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">-10</button>
                        <button @click="submitScore('yellow', 'punishment', 2)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">-20</button>
                        <button @click="submitScore('yellow', 'punishment', 3)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">-30</button>
                        <button @click="submitScore('yellow', 'punishment', 4)" class="bg-yellow-400 hover:bg-yellow-500 text-black font-black text-2xl rounded-md transition-colors flex items-center justify-center">-40</button>
                    </div>

                    <!-- Neutral Logo Section (Center) -->
                    <div class="w-32 shrink-0 flex items-center justify-center h-full">
                        <img src="/assets/images/ts_logo.png" alt="TS Logo" class="w-28 h-28 object-contain mix-blend-screen opacity-90 drop-shadow-[0_0_15px_rgba(255,255,255,0.1)]" />
                    </div>

                    <!-- Blue Corner Buttons -->
                    <div class="flex-1 grid grid-cols-4 grid-rows-3 gap-3 h-full w-full">
                        <button @click="submitScore('blue', 'score', 1)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">20</button>
                        <button @click="submitScore('blue', 'score', 3)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">30</button>
                        <button @click="submitScore('blue', 'score', 5)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">40</button>
                        <button class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">
                            <Bell class="w-8 h-8 stroke-[3]" />
                        </button>

                        <button @click="submitScore('blue', 'score', 2)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">10+20</button>
                        <button @click="submitScore('blue', 'score', 4)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">10+30</button>
                        <button @click="submitScore('blue', 'score', 6)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">10+40</button>
                        <button @click="deleteLastScore('blue')" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">
                            <Delete class="w-8 h-8 stroke-[3]" />
                        </button>

                        <button @click="submitScore('blue', 'punishment', 1)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">-10</button>
                        <button @click="submitScore('blue', 'punishment', 2)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">-20</button>
                        <button @click="submitScore('blue', 'punishment', 3)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">-30</button>
                        <button @click="submitScore('blue', 'punishment', 4)" class="bg-blue-600 hover:bg-blue-500 text-white font-black text-2xl rounded-md transition-colors flex items-center justify-center">-40</button>
                    </div>

                </div>
                
            </div>
        </template>
    </div>
</template>

<style scoped>
</style>
