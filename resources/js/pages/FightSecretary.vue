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
                if (e.scoreDetail) {
                    if (e.scoreDetail.deleted) {
                        const targetId = Number(e.scoreDetail.id);
                        if (e.corner === 'yellow') {
                            localYellowPoints.value = localYellowPoints.value.filter(p => p.id !== targetId);
                        } else {
                            localBluePoints.value = localBluePoints.value.filter(p => p.id !== targetId);
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
                    const idx = localRecapPoints.value.findIndex(r => r.round_number === e.recap.round_number);
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
    const roundDetails = localRecapPoints.value.find(r => r.round_number === roundNumber);
    const jNumMap: Record<number, string> = { 1: 'one', 2: 'two', 3: 'three', 4: 'four' };
    
    return [1, 2, 3, 4].map(juryNumber => {
        const yDetails = localYellowPoints.value.filter(
            p => p.jury_number === juryNumber && p.round_number === roundNumber
        );
        const bDetails = localBluePoints.value.filter(
            p => p.jury_number === juryNumber && p.round_number === roundNumber
        );
        
        let yTotal = 0;
        let bTotal = 0;
        if (roundDetails) {
            const word = jNumMap[juryNumber];
            yTotal = roundDetails[`jury_${word}_total_poin_yellow`] ?? 0;
            bTotal = roundDetails[`jury_${word}_total_poin_blue`] ?? 0;
        }
        
        return {
            jury_name: `PW ${juryNumber}`,
            yellow_details: yDetails,
            yellow_total: yTotal,
            blue_details: bDetails,
            blue_total: bTotal
        };
    });
});

const activeRoundRecap = computed(() => {
    if (!currentMatch.value) return null;
    return localRecapPoints.value.find(r => r.round_number === currentMatch.value.round_number);
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
                <div class="h-32 shrink-0 w-full flex z-10 shadow-xl border-b border-stone-800">
                    <!-- Blue Section -->
                    <div class="flex-1 bg-gradient-to-r from-blue-700 to-blue-600 flex items-center justify-between px-10 relative overflow-hidden shadow-[inset_0_0_50px_rgba(0,0,0,0.2)]">
                        <div class="flex flex-col items-start text-left max-w-[70%]">
                            <h2 class="text-3xl font-black text-white uppercase tracking-wider mb-2 drop-shadow-sm truncate w-full">
                                {{ currentMatch?.atlete_blue || currentMatch?.athlete_blue || '-' }}
                            </h2>
                            <div class="flex items-center gap-2 flex-wrap">
                                <Badge class="bg-blue-950 text-blue-100 hover:bg-blue-950 uppercase font-bold tracking-widest text-[10px]">
                                    {{ currentMatch?.contingent_blue || '-' }}
                                </Badge>
                                <Badge class="bg-black text-blue-400 uppercase font-black tracking-widest text-[10px] pointer-events-none px-2 py-0.5 shadow-md border-none">
                                    {{ currentMatch?.weight_status_blue || '-' }} {{ currentMatch?.weight_blue ? `- ${currentMatch.weight_blue} KG` : '' }}
                                </Badge>
                            </div>
                        </div>
                        <div class="text-[5.5rem] font-black text-white leading-none drop-shadow-md tabular-nums pt-1">
                            {{ activeRoundRecap?.total_poin_blue || 0 }}
                        </div>
                    </div>

                    <!-- Center Round / VS -->
                    <div class="w-32 bg-zinc-950 flex flex-col shadow-[0_0_30px_rgba(0,0,0,0.5)] items-center justify-center shrink-0 border-x border-stone-800 z-20 relative text-stone-600 font-black italic skew-x-[-10deg]">
                        <div class="skew-x-[10deg] flex flex-col items-center justify-center">
                            <template v-if="matchStatus === 'ongoing_paused'">
                                <span class="text-[10px] text-stone-500 font-black uppercase tracking-widest mb-1 not-italic">Ronde</span>
                                <span class="text-5xl font-black text-white leading-none not-italic">{{ currentMatch?.round_number }}</span>
                            </template>
                            <template v-else>
                                <span class="text-3xl">VS</span>
                            </template>
                        </div>
                    </div>

                    <!-- Yellow Section -->
                    <div class="flex-1 bg-gradient-to-l from-yellow-500 to-yellow-400 flex items-center justify-between px-10 relative overflow-hidden shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]">
                        <div class="text-[5.5rem] font-black text-black leading-none drop-shadow-md tabular-nums pt-1">
                            {{ activeRoundRecap?.total_poin_yellow || 0 }}
                        </div>
                        <div class="flex flex-col items-end text-right max-w-[70%]">
                            <h2 class="text-3xl font-black text-black uppercase tracking-wider mb-2 drop-shadow-sm truncate w-full">
                                {{ currentMatch?.atlete_yellow || currentMatch?.athlete_yellow || '-' }}
                            </h2>
                            <div class="flex items-center justify-end gap-2 flex-wrap">
                                <Badge class="bg-black text-yellow-500 uppercase font-black tracking-widest text-[10px] pointer-events-none px-2 py-0.5 shadow-md border-none">
                                    {{ currentMatch?.weight_yellow ? `${currentMatch.weight_yellow} KG -` : '' }} {{ currentMatch?.weight_status_yellow || '-' }}
                                </Badge>
                                <Badge class="bg-black text-yellow-400 hover:bg-black uppercase font-bold tracking-widest text-[10px]">
                                    {{ currentMatch?.contingent_yellow || '-' }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Section -->
                <div class="flex-1 flex flex-col p-6 overflow-hidden bg-zinc-950 px-10">
                    
                    <template v-if="matchStatus === 'ongoing_paused'">
                        <!-- ONGOING SCORING LAYOUT -->
                        <div class="flex flex-col gap-6 flex-1 w-full relative z-10 pb-6 mb-2">

                            <!-- Real-time Valid Sequences -->
                            <div class="flex-1 flex flex-col">
                                <div class="grid grid-cols-[1fr_80px_100px_80px_1fr] gap-6 mb-4 text-center text-[11px] font-black uppercase tracking-widest text-muted-foreground w-full shrink-0">
                                    <div class="text-left pl-2">Detail Nilai</div>
                                    <div>Total</div>
                                    <div>Ronde {{ currentMatch?.round_number }}</div>
                                    <div>Total</div>
                                    <div class="text-right pr-2">Detail Nilai</div>
                                </div>
                                
                                <div class="flex flex-col gap-4 overflow-y-auto custom-scrollbar pr-2 pb-2 flex-1">
                                    <div v-for="(jury, idx) in activeRoundJuriesData" :key="idx" 
                                         class="grid grid-cols-[1fr_80px_100px_80px_1fr] gap-6 items-stretch min-h-[4.5rem]">
                                        
                                        <!-- Blue Nilai -->
                                        <div class="bg-zinc-800 border-[1.5px] border-blue-600/40 rounded-md px-4 py-2 flex flex-wrap content-center gap-1.5 overflow-hidden">
                                            <template v-for="(p, i) in jury.blue_details" :key="'bn'+i">
                                                <span v-if="p.ref_score_id" class="text-white text-[1.1rem] font-bold leading-none">{{ p.score?.name }},</span>
                                                <span v-else-if="p.ref_punishment_id" class="text-red-500 text-[1.1rem] font-bold leading-none">{{ p.punishment?.name }},</span>
                                            </template>
                                        </div>
                                        
                                        <!-- Blue Total -->
                                        <div :class="[
                                            'flex items-center justify-center rounded-md border-[1.5px] font-black text-2xl tabular-nums tracking-tighter', 
                                            jury.blue_total > 200 ? 'bg-red-600 border-red-500 text-white' : 'bg-zinc-800/80 border-blue-600/40 text-white'
                                        ]">
                                            {{ jury.blue_total }}
                                        </div>
                                        
                                        <!-- PW Name -->
                                        <div class="flex items-center justify-center font-black text-sm rounded-md bg-zinc-800 border-[1px] border-stone-700 text-stone-300 drop-shadow-sm uppercase tracking-wider">
                                            {{ jury.jury_name }}
                                        </div>
                                        
                                        <!-- Yellow Total -->
                                        <div :class="[
                                            'flex items-center justify-center rounded-md border-[1.5px] font-black text-2xl tabular-nums tracking-tighter', 
                                            jury.yellow_total > 200 ? 'bg-red-600 border-red-500 text-white' : 'bg-zinc-800/80 border-yellow-500/40 text-white'
                                        ]">
                                            {{ jury.yellow_total }}
                                        </div>
                                        
                                        <!-- Yellow Nilai -->
                                        <div class="bg-zinc-800 border-[1.5px] border-yellow-500/40 rounded-md px-4 py-2 flex flex-wrap content-center gap-1.5 justify-end overflow-hidden">
                                            <template v-for="(p, i) in jury.yellow_details" :key="'yn'+i">
                                                <span v-if="p.ref_score_id" class="text-white text-[1.1rem] font-bold leading-none">{{ p.score?.name }},</span>
                                                <span v-else-if="p.ref_punishment_id" class="text-red-500 text-[1.1rem] font-bold leading-none">{{ p.punishment?.name }},</span>
                                            </template>
                                        </div>
                                        
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
                                    <h3 class="text-5xl font-black text-blue-500 mb-2">{{ activeRoundRecap?.total_poin_blue || 0 }}</h3>
                                    <span class="uppercase tracking-widest text-sm text-muted-foreground font-bold">{{ currentMatch?.atlete_blue }}</span>
                                </div>
                                <div class="text-bold text-stone-600 italic text-2xl skew-x-[-10deg]">VS</div>
                                <div class="flex-1 text-left">
                                    <h3 class="text-5xl font-black text-yellow-500 mb-2">{{ activeRoundRecap?.total_poin_yellow || 0 }}</h3>
                                    <span class="uppercase tracking-widest text-sm text-muted-foreground font-bold">{{ currentMatch?.atlete_yellow }}</span>
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
