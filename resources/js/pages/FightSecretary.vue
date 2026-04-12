<script setup lang="ts">
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    arena: any;
    activeMatch?: any;
    recapPoints?: any[];
    yellowPoints?: any[];
    bluePoints?: any[];
}>();

const page = usePage<any>();
const userName = computed(() => page.props.auth?.user?.name || 'Sekretaris');

// Reactive match state
const currentMatch = ref<any>(props.activeMatch ?? null);
const localYellowPoints = ref<any[]>([...(props.yellowPoints || [])]);
const localBluePoints = ref<any[]>([...(props.bluePoints || [])]);
const localRecapPoints = ref<any[]>([...(props.recapPoints || [])]);

// Sync with Inertia props changes
watch(() => props.activeMatch, (newVal) => { currentMatch.value = newVal; }, { deep: true });
watch(() => props.yellowPoints, (newVal) => { localYellowPoints.value = [...(newVal || [])]; }, { deep: true });
watch(() => props.bluePoints, (newVal) => { localBluePoints.value = [...(newVal || [])]; }, { deep: true });
watch(() => props.recapPoints, (newVal) => { localRecapPoints.value = [...(newVal || [])]; }, { deep: true });

const matchStatus = computed(() => {
    if (!currentMatch.value) return 'not started';
    if (['ongoing', 'paused'].includes(currentMatch.value.status)) return 'ongoing_paused';
    if (currentMatch.value.status === 'done') return 'done';
    return 'not started';
});

// Real-time Echo listener
let echoStatusChannel: any = null;
let echoScoreChannel: any = null;

onMounted(() => {
    const echo = (window as any).Echo;
    
    if (echo) {
        echoStatusChannel = echo.channel('match.status')
            .listen('.ActiveMatchUpdated', (e: any) => {
                if (e.match) {
                    if (!currentMatch.value || currentMatch.value.id !== e.match.id) {
                        currentMatch.value = e.match;
                        router.reload({ only: ['activeMatch', 'recapPoints', 'yellowPoints', 'bluePoints'] });
                    } else {
                        currentMatch.value = e.match;
                    }
                }
            });

        echoScoreChannel = echo.channel('match.score')
            .listen('.JuryScoreUpdated', (e: any) => {
                if (e.detail) {
                    if (e.detail.deleted) {
                        if (e.corner === 'yellow') {
                            localYellowPoints.value = localYellowPoints.value.filter(p => p.id !== e.detail.id);
                        } else {
                            localBluePoints.value = localBluePoints.value.filter(p => p.id !== e.detail.id);
                        }
                    } else {
                        if (e.corner === 'yellow') {
                            localYellowPoints.value.push(e.detail);
                        } else {
                            localBluePoints.value.push(e.detail);
                        }
                    }
                }

                if (e.recap) {
                    const idx = localRecapPoints.value.findIndex(r => r.round_number === e.recap.round_number);
                    if (idx !== -1) {
                        localRecapPoints.value[idx] = e.recap;
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

// Front-end utility to visually derive valid matching items 
const getValidatedPoints = (detailsList: any[], roundNumber: number) => {
    const points = detailsList.filter((p: any) => p.round_number === roundNumber);
    const juries: any = { 1: [], 2: [], 3: [], 4: [] };
    points.forEach((p: any) => {
        if (p.jury_number >= 1 && p.jury_number <= 4) {
            juries[p.jury_number].push(p);
        }
    });

    const maxLen = Math.max(juries[1].length, juries[2].length, juries[3].length, juries[4].length);
    const validItems = [];

    for (let i = 0; i < maxLen; i++) {
        const freq: any = {};
        const itemMap: any = {};

        for (let j = 1; j <= 4; j++) {
            const item = juries[j][i];
            if (item) {
                const identifier = item.ref_score_id ? `s:${item.ref_score_id}` : `p:${item.ref_punishment_id}`;
                if (!freq[identifier]) {
                    freq[identifier] = 0;
                    itemMap[identifier] = item;
                }
                freq[identifier]++;
            }
        }

        for (const [id, count] of Object.entries(freq)) {
            if ((count as number) >= 3) {
                validItems.push(itemMap[id]);
                break;
            }
        }
    }
    return validItems;
};

const activeRoundValidatedYellow = computed(() => {
    if (!currentMatch.value) return [];
    return getValidatedPoints(localYellowPoints.value, currentMatch.value.round_number);
});

const activeRoundValidatedBlue = computed(() => {
    if (!currentMatch.value) return [];
    return getValidatedPoints(localBluePoints.value, currentMatch.value.round_number);
});
</script>

<template>
    <Head title="Sekretaris Pertandingan - Tapak Suci" />
    <div class="flex h-screen bg-zinc-950 text-foreground overflow-hidden">
        
        <!-- CONDITION 2: Not Started (Loading) -->
        <template v-if="matchStatus === 'not started'">
            <div class="flex-1 flex flex-col items-center justify-center z-10 animate-pulse relative">
                <img src="/assets/images/ts_logo.png" alt="Tapak Suci Logo" class="w-64 h-64 object-contain drop-shadow-2xl z-10" />
                <p class="mt-8 text-muted-foreground/50 text-sm uppercase tracking-widest font-bold z-10">Menunggu Pertandingan...</p>
                <div class="absolute inset-0 flex items-center justify-center opacity-[0.03] pointer-events-none">
                    <svg viewBox="0 0 100 100" class="w-[800px] h-[800px]" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="50" cy="50" r="40" stroke="currentColor" stroke-width="2" fill="none" />
                        <path d="M50 10 L50 90 M10 50 L90 50" stroke="currentColor" stroke-width="2" />
                        <circle cx="50" cy="50" r="20" stroke="currentColor" stroke-width="2" fill="none" />
                    </svg>
                </div>
            </div>
        </template>
        
        <!-- CONDITION 1 & 3: Ongoing, Paused, Done -->
        <template v-else>
            <div class="w-full h-full flex flex-col z-10 relative">
                
                <!-- Small Header Section -->
                <div class="h-12 bg-zinc-900 border-b border-stone-800 shrink-0 w-full flex items-center justify-between px-6 shadow-sm text-[11px] font-bold uppercase tracking-widest text-muted-foreground">
                    <div class="flex items-center gap-4">
                        <span class="text-yellow-500 font-black">{{ userName }}</span>
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
                    <div class="flex-1 bg-gradient-to-r from-yellow-500 to-yellow-400 flex flex-col items-end justify-center px-10 relative overflow-hidden text-right shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]">
                        <h2 class="text-3xl font-black text-black uppercase tracking-wider mb-2 drop-shadow-sm truncate w-full">
                            {{ currentMatch?.atlete_yellow || currentMatch?.athlete_yellow || '-' }}
                        </h2>
                        <Badge class="bg-black text-yellow-400 hover:bg-black uppercase font-bold tracking-widest text-[10px]">
                            {{ currentMatch?.contingent_yellow || '-' }}
                        </Badge>
                    </div>

                    <div class="w-24 bg-zinc-950 flex shadow-[0_0_30px_rgba(0,0,0,0.5)] items-center justify-center shrink-0 border-x border-stone-800 z-20 relative text-stone-600 font-black italic text-2xl skew-x-[-10deg]">
                        <span class="skew-x-[10deg]">VS</span>
                    </div>

                    <div class="flex-1 bg-gradient-to-l from-blue-700 to-blue-600 flex flex-col items-start justify-center px-10 relative overflow-hidden text-left shadow-[inset_0_0_50px_rgba(0,0,0,0.2)]">
                        <h2 class="text-3xl font-black text-white uppercase tracking-wider mb-2 drop-shadow-sm truncate w-full">
                            {{ currentMatch?.atlete_blue || currentMatch?.athlete_blue || '-' }}
                        </h2>
                        <Badge class="bg-blue-950 text-blue-100 hover:bg-blue-950 uppercase font-bold tracking-widest text-[10px]">
                            {{ currentMatch?.contingent_blue || '-' }}
                        </Badge>
                    </div>
                </div>

                <!-- Main Section -->
                <div class="flex-1 flex flex-col p-6 overflow-hidden bg-zinc-950 px-10">
                    
                    <template v-if="matchStatus === 'ongoing_paused'">
                        <!-- ONGOING SCORING LAYOUT -->
                        <div class="flex flex-1 rounded-2xl overflow-hidden shadow-2xl border border-stone-800 relative z-10 w-full mb-6">
                            
                            <!-- Yellow Corner Details -->
                            <div class="flex-[3] flex flex-col relative transition-colors duration-500 bg-yellow-400">
                                <div class="px-8 py-6 w-full flex items-center justify-between z-10">
                                     <span class="text-3xl font-black tabular-nums text-black drop-shadow-sm">
                                         {{ currentMatch?.weight_yellow }} KG
                                     </span>
                                     <Badge class="bg-black text-yellow-500 uppercase font-black tracking-widest text-lg pointer-events-none px-4 py-1">
                                         {{ currentMatch?.weight_status_yellow || '-' }}
                                     </Badge>
                                </div>

                                <div class="flex-1 flex flex-col items-center justify-center -mt-8 gap-1">
                                    <h3 class="text-black/50 font-bold uppercase tracking-widest text-sm mb-[-1.5rem] z-10">Total Nilai Otomatis</h3>
                                    <div class="text-[12rem] font-black text-black leading-none drop-shadow-md">
                                        {{ currentMatch?.total_poin_yellow || 0 }}
                                    </div>
                                </div>
                            </div>

                            <!-- Real-Time Valid Sequence -->
                            <div class="w-80 bg-zinc-950 border-x border-stone-800 shadow-2xl flex flex-col z-20 shrink-0">
                                <div class="shrink-0 pt-4 pb-2 text-center text-[10px] text-stone-500 font-black uppercase tracking-widest border-b border-stone-800">
                                    Ronde {{ currentMatch?.round_number }} - Rincian Tersahkan
                                </div>
                                <div class="flex-1 flex w-full">
                                    <!-- Yellow sequences -->
                                    <div class="w-1/2 h-full border-r border-stone-800 p-3 flex flex-col gap-2 overflow-y-auto custom-scrollbar">
                                        <div v-for="(p, i) in activeRoundValidatedYellow" :key="'y'+i" 
                                             class="bg-yellow-400/20 border border-yellow-500/50 text-yellow-400 text-center rounded-md py-1.5 font-bold text-sm tracking-wider">
                                             <span v-if="p.ref_score_id" class="">{{ p.score?.name }}</span>
                                             <span v-else-if="p.ref_punishment_id" class="text-red-400">{{ p.punishment?.name }}</span>
                                        </div>
                                    </div>
                                    <!-- Blue sequences -->
                                    <div class="w-1/2 h-full p-3 flex flex-col gap-2 overflow-y-auto custom-scrollbar">
                                        <div v-for="(p, i) in activeRoundValidatedBlue" :key="'b'+i" 
                                             class="bg-blue-600/20 border border-blue-500/50 text-blue-400 text-center rounded-md py-1.5 font-bold text-sm tracking-wider">
                                             <span v-if="p.ref_score_id" class="">{{ p.score?.name }}</span>
                                             <span v-else-if="p.ref_punishment_id" class="text-red-400">{{ p.punishment?.name }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Blue Corner Details -->
                            <div class="flex-[3] flex flex-col relative transition-colors duration-500 bg-blue-600">
                                <div class="px-8 py-6 w-full flex items-center justify-between z-10">
                                     <Badge class="bg-black text-blue-400 hover:bg-black uppercase font-black tracking-widest text-lg pointer-events-none px-4 py-1">
                                         {{ currentMatch?.weight_status_blue || '-' }}
                                     </Badge>
                                     <span class="text-3xl font-black tabular-nums text-white drop-shadow-sm">
                                         {{ currentMatch?.weight_blue }} KG
                                     </span>
                                </div>

                                <div class="flex-1 flex flex-col items-center justify-center -mt-8 gap-1">
                                    <h3 class="text-white/50 font-bold uppercase tracking-widest text-sm mb-[-1.5rem] z-10">Total Nilai Otomatis</h3>
                                    <div class="text-[12rem] font-black text-white leading-none drop-shadow-md">
                                        {{ currentMatch?.total_poin_blue || 0 }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                    
                    <template v-else-if="matchStatus === 'done'">
                        <!-- RECAP LAYOUT (match done) -->
                        <div class="w-full h-full flex flex-col items-center justify-center">
                            <h2 class="text-3xl text-zinc-500 font-bold uppercase mb-4 tracking-widest">Pertandingan Selesai</h2>
                            <div class="flex items-center gap-12 bg-zinc-900 border border-stone-800 p-8 rounded-2xl shadow-xl w-full max-w-4xl">
                                <div class="flex-1 text-right">
                                    <h3 class="text-5xl font-black text-yellow-500 mb-2">{{ currentMatch?.total_poin_yellow }}</h3>
                                    <span class="uppercase tracking-widest text-sm text-muted-foreground font-bold">{{ currentMatch?.atlete_yellow }}</span>
                                </div>
                                <div class="text-bold text-stone-600 italic text-2xl skew-x-[-10deg]">VS</div>
                                <div class="flex-1 text-left">
                                    <h3 class="text-5xl font-black text-blue-500 mb-2">{{ currentMatch?.total_poin_blue }}</h3>
                                    <span class="uppercase tracking-widest text-sm text-muted-foreground font-bold">{{ currentMatch?.atlete_blue }}</span>
                                </div>
                            </div>
                        </div>
                    </template>
                    
                </div>
                
            </div>
        </template>
    </div>
</template>

<style scoped>
</style>
