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
    if (currentMatch.value.status === 'ongoing') return 'ongoing';
    if (['paused', 'done'].includes(currentMatch.value.status)) return 'done_paused';
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
    
    // Evaluate cross-jury validity mathematically (similar to backend recap tally)
    const evaluateValidity = (pointsArray: any[]) => {
        const juryPointCounts: Record<number, Record<string, number>> = { 1: {}, 2: {}, 3: {}, 4: {} };
        const validCounts: Record<string, number> = {};

        pointsArray.forEach(p => {
             const jn = p.jury_number;
             if (jn >= 1 && jn <= 4) {
                 const id = p.ref_score_id ? `s:${p.ref_score_id}` : `p:${p.ref_punishment_id}`;
                 if (!juryPointCounts[jn][id]) juryPointCounts[jn][id] = 0;
                 juryPointCounts[jn][id]++;
             }
        });

        const allIds = new Set<string>();
        [1,2,3,4].forEach(j => Object.keys(juryPointCounts[j]).forEach(k => allIds.add(k)));
        
        allIds.forEach(id => {
            let maxOccurrences = 0;
            for(let j=1; j<=4; j++) {
                const count = juryPointCounts[j][id] || 0;
                if(count > maxOccurrences) maxOccurrences = count;
            }

            validCounts[id] = 0;
            for(let k=1; k<=maxOccurrences; k++) {
                let jCount = 0;
                for(let j=1; j<=4; j++) {
                     if((juryPointCounts[j][id] || 0) >= k) jCount++;
                }
                if (jCount >= 3) {
                    validCounts[id]++;
                }
            }
        });

        const markedCounts: Record<number, Record<string, number>> = { 1: {}, 2: {}, 3: {}, 4: {} };
        return pointsArray.map(p => {
             const jn = p.jury_number;
             if (jn >= 1 && jn <= 4) {
                 const id = p.ref_score_id ? `s:${p.ref_score_id}` : `p:${p.ref_punishment_id}`;
                 if (!markedCounts[jn][id]) markedCounts[jn][id] = 0;
                 markedCounts[jn][id]++;
                 return { ...p, is_valid: markedCounts[jn][id] <= (validCounts[id] || 0) };
             }
             return { ...p, is_valid: false };
        });
    };

    const evalYellow = evaluateValidity(localYellowPoints.value.filter(p => p.round_number === roundNumber));
    const evalBlue = evaluateValidity(localBluePoints.value.filter(p => p.round_number === roundNumber));
    
    return [1, 2, 3, 4].map(juryNumber => {
        const yDetails = evalYellow.filter(p => p.jury_number === juryNumber);
        const bDetails = evalBlue.filter(p => p.jury_number === juryNumber);
        
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
            jury_winner: juryWinner
        };
    });
});



const activeRoundRecap = computed(() => {
    if (!currentMatch.value) return null;
    return localRecapPoints.value.find(r => r.round_number === currentMatch.value.round_number);
});

const getRoundWinner = (roundNum: number) => {
    if (!localRecapPoints.value || !Array.isArray(localRecapPoints.value)) return null;
    const r = localRecapPoints.value.find((x: any) => x.round_number == roundNum);
    return r ? r.winner : null;
};

const matchStats = computed(() => {
    let roundWinBlue = 0;
    let roundWinYellow = 0;
    let totalPoinBlue = 0;
    let totalPoinYellow = 0;
    
    if (localRecapPoints.value) {
        localRecapPoints.value.forEach(r => {
            if (r.winner === 'blue') roundWinBlue++;
            if (r.winner === 'yellow') roundWinYellow++;
            totalPoinBlue += (Number(r.total_poin_blue) || 0);
            totalPoinYellow += (Number(r.total_poin_yellow) || 0);
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
            punishmentPoints: 0
        };

        const rounds = [1, 2, 3];
        rounds.forEach(roundNumber => {
            const pointsArray = cornerPoints.filter(p => p.round_number === roundNumber);
            const juryPointCounts: Record<number, Record<string, number>> = { 1: {}, 2: {}, 3: {}, 4: {} };
            const idToScore: Record<string, any> = {};

            pointsArray.forEach(p => {
                const jn = p.jury_number;
                if (jn >= 1 && jn <= 4) {
                    const id = p.ref_score_id ? `s:${p.ref_score_id}` : `p:${p.ref_punishment_id}`;
                    if (!juryPointCounts[jn][id]) juryPointCounts[jn][id] = 0;
                    juryPointCounts[jn][id]++;
                    idToScore[id] = p;
                }
            });

            const allIds = new Set<string>();
            [1,2,3,4].forEach(j => Object.keys(juryPointCounts[j]).forEach(k => allIds.add(k)));
            
            allIds.forEach(id => {
                let maxOccurrences = 0;
                for(let j=1; j<=4; j++) {
                    const count = juryPointCounts[j][id] || 0;
                    if(count > maxOccurrences) maxOccurrences = count;
                }

                let validCount = 0;
                for(let k=1; k<=maxOccurrences; k++) {
                    let jCount = 0;
                    for(let j=1; j<=4; j++) {
                        if((juryPointCounts[j][id] || 0) >= k) jCount++;
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
                            punishmentValue = Math.abs(Number(p.punishment.score));
                        } else if (p.punishment?.name) {
                            punishmentValue = Math.abs(parseInt(p.punishment.name) || 0);
                        }
                        stats.punishmentPoints += (punishmentValue * validCount);
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
        yellowStats: getCornerStats(localYellowPoints.value)
    };
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
        
        <!-- CONDITION 3: Done or Paused -->
        <template v-else-if="matchStatus === 'done_paused'">
            <div class="w-full h-full flex flex-col z-10 relative bg-zinc-950">
                
                <!-- Small Header Section -->
                <div class="h-12 bg-zinc-900 border-b border-stone-800 shrink-0 w-full flex items-center justify-between px-6 shadow-sm text-[11px] font-bold uppercase tracking-widest text-muted-foreground">
                    <div class="flex items-center gap-4">
                        <span class="text-yellow-500 font-black">{{ userName }}</span>
                        <div class="w-px h-4 bg-stone-800"></div>
                        <span>Gelanggang {{ props.arena?.arena_name ?? props.arena?.gelanggang_id ?? '-' }}</span>
                    </div>

                    <div class="flex items-center gap-4">
                        <span class="text-white">STATISTIK PERTANDINGAN</span>
                        <div class="w-px h-4 bg-stone-800"></div>
                        <span>{{ currentMatch?.group }} {{ currentMatch?.category }}</span>
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
                            {{ matchStats.totalPoinBlue || 0 }}
                        </div>
                    </div>

                    <!-- Center Round / VS -->
                    <div class="w-48 bg-zinc-950 flex flex-col shadow-[0_0_30px_rgba(0,0,0,0.5)] items-center justify-center shrink-0 border-x border-stone-800 z-20 relative text-stone-600 font-black italic skew-x-[-10deg]">
                        <div class="skew-x-[10deg] flex flex-col items-center justify-center text-center">
                            <span class="text-[10px] text-stone-500 font-black uppercase tracking-widest mb-1 not-italic">Skor Ronde</span>
                            <div class="font-black text-white leading-none not-italic text-5xl flex items-center gap-3">
                                <span class="text-blue-500">{{ matchStats.scoreRound.split(' - ')[0] }}</span>
                                <span class="text-stone-700 text-3xl">-</span>
                                <span class="text-yellow-500">{{ matchStats.scoreRound.split(' - ')[1] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Yellow Section -->
                    <div class="flex-1 bg-gradient-to-l from-yellow-500 to-yellow-400 flex items-center justify-between px-10 relative overflow-hidden shadow-[inset_0_0_50px_rgba(0,0,0,0.1)]">
                        <div class="text-[5.5rem] font-black text-black leading-none drop-shadow-md tabular-nums pt-1">
                            {{ matchStats.totalPoinYellow || 0 }}
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

                <!-- Detailed Stats Table -->
                <div class="flex-1 overflow-y-auto custom-scrollbar bg-zinc-950 p-10">
                    <div class="max-w-8xl mx-auto flex flex-col gap-3">
                        <!-- Technique Rows -->
                        <template v-for="(stat, idx) in [
                            { label: 'Pukulan Katak', score: '20' },
                            { label: 'Mawar Lepas Katak Masuk', score: '10+20' },
                            { label: 'Tendangan Harimau', score: '30' },
                            { label: 'Mawar Lepas Harimau Masuk', score: '10+30' },
                            { label: 'Terkaman', score: '40' },
                            { label: 'Mawar Lepas Terkaman Masuk', score: '10+40' },
                            { label: 'Jatuhan / Sapuan', score: '50' }
                        ]" :key="idx">
                            <div class="grid grid-cols-[100px_1fr_100px] gap-8 items-center py-4 bg-zinc-900/80 rounded-xl px-8 border border-stone-800/60 shadow-md hover:bg-zinc-800 transition-colors relative overflow-hidden group">
                                <div class="absolute inset-0 flex pointer-events-none opacity-20 group-hover:opacity-30 transition-opacity">
                                    <div class="w-1/2 h-full flex justify-end">
                                        <div class="h-full bg-blue-500 transition-all duration-1000 ease-out" :style="`width: ${ (parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0) > 0 ? (parseInt(matchStats.blueStats[stat.score]) / ((parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0)) * 100) : 0 }%`"></div>
                                    </div>
                                    <div class="w-1/2 h-full flex justify-start">
                                        <div class="h-full bg-yellow-500 transition-all duration-1000 ease-out" :style="`width: ${ (parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0) > 0 ? (parseInt(matchStats.yellowStats[stat.score]) / ((parseInt(matchStats.blueStats[stat.score]) || 0) + (parseInt(matchStats.yellowStats[stat.score]) || 0)) * 100) : 0 }%`"></div>
                                    </div>
                                </div>
                                <div class="text-2xl font-black text-blue-400 tabular-nums text-center z-10">{{ matchStats.blueStats[stat.score] }}</div>
                                <div class="text-center text-xl font-black uppercase tracking-widest text-white z-10 drop-shadow-md">{{ stat.label }} <span class="text-stone-500 ml-2 text-sm">({{ stat.score }})</span></div>
                                <div class="text-2xl font-black text-yellow-400 tabular-nums text-center z-10">{{ matchStats.yellowStats[stat.score] }}</div>
                            </div>
                        </template>
                        
                        <!-- Hukuman -->
                        <div class="grid grid-cols-[100px_1fr_100px] gap-8 items-center py-4 bg-zinc-900 rounded-xl px-8 border border-stone-800 shadow-md relative overflow-hidden">
                            <div class="absolute inset-0 flex pointer-events-none opacity-20">
                                <div class="w-1/2 h-full flex justify-end">
                                    <div class="h-full bg-red-500 transition-all duration-1000 ease-out" :style="`width: ${ (parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0) > 0 ? (parseInt(matchStats.blueStats.punishmentPoints) / ((parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0)) * 100) : 0 }%`"></div>
                                </div>
                                <div class="w-1/2 h-full flex justify-start">
                                    <div class="h-full bg-red-500 transition-all duration-1000 ease-out" :style="`width: ${ (parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0) > 0 ? (parseInt(matchStats.yellowStats.punishmentPoints) / ((parseInt(matchStats.blueStats.punishmentPoints) || 0) + (parseInt(matchStats.yellowStats.punishmentPoints) || 0)) * 100) : 0 }%`"></div>
                                </div>
                            </div>
                            <div class="text-3xl font-black text-red-500 tabular-nums text-center z-10">{{ matchStats.blueStats.punishmentPoints }}</div>
                            <div class="text-center text-xl font-black uppercase tracking-widest text-red-500/80 z-10">Hukuman</div>
                            <div class="text-3xl font-black text-red-500 tabular-nums text-center z-10">{{ matchStats.yellowStats.punishmentPoints }}</div>
                        </div>

                        <!-- Total Nilai / Pemenang Pertandingan -->
                        <div v-if="currentMatch?.winner_corner" class="flex flex-col items-center justify-center py-6 bg-zinc-900 rounded-2xl border-4 border-stone-800 shadow-2xl mt-6 relative overflow-hidden">
                            <div class="text-lg font-black uppercase tracking-widest text-white mb-2 z-10">Pemenang Pertandingan</div>
                            <div :class="[
                                'text-5xl font-black uppercase tracking-widest z-10 drop-shadow-md',
                                currentMatch?.winner_corner === 'blue' ? 'text-blue-500' :
                                currentMatch?.winner_corner === 'yellow' ? 'text-yellow-500' :
                                'text-white'
                            ]">
                                {{ currentMatch?.winner_corner === 'blue' ? 'SUDUT BIRU' : currentMatch?.winner_corner === 'yellow' ? 'SUDUT KUNING' : 'SERI' }}
                            </div>
                            <div class="text-xl font-bold uppercase tracking-wider text-white mt-2 z-10">
                                {{ currentMatch?.winner_status ? currentMatch.winner_status.replace(/_/g, ' ') : '-' }}
                            </div>
                            <div class="absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent pointer-events-none"></div>
                        </div>
                        <div v-else class="flex flex-col items-center justify-center py-6 bg-zinc-900 rounded-2xl border-4 border-stone-800 shadow-2xl mt-6 relative overflow-hidden">
                            <div class="text-sm font-black uppercase tracking-widest text-stone-500 mb-2 z-10">Status Pertandingan</div>
                            <div class="text-3xl font-black uppercase tracking-widest z-10 drop-shadow-md text-stone-300">
                                DITUNDA
                            </div>
                            <div class="absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent pointer-events-none"></div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        
        <!-- CONDITION 1: Ongoing -->
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
                            <template v-if="matchStatus === 'ongoing'">
                                <span class="text-[10px] text-stone-500 font-black uppercase tracking-widest mb-1 not-italic">{{ currentMatch?.round_number === 3 ? 'Ronde' : 'Ronde' }}</span>
                                <span :class="['font-black text-white leading-none not-italic', currentMatch?.round_number === 3 ? 'text-4xl' : 'text-5xl']">{{ currentMatch?.round_number === 3 ? 'TBH' : currentMatch?.round_number }}</span>
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
                    
                    <!-- ONGOING SCORING LAYOUT -->
                        <div class="flex flex-col gap-6 flex-1 w-full relative z-10 pb-6 mb-2">

                            <!-- Round Winners -->
                            <div class="flex justify-center gap-6 w-full mt-2">
                                <!-- R1 -->
                                <div class="flex flex-col items-center relative group w-48">
                                    <div class="text-[10px] text-stone-500 font-black uppercase tracking-widest absolute -top-3 bg-zinc-950 px-2 rounded-full z-10 transition-colors">
                                        Ronde 1
                                    </div>
                                    <div :class="[
                                        'w-full py-4 text-center text-lg font-black uppercase tracking-wider rounded-xl border transition-all duration-300 shadow-lg relative overflow-hidden',
                                        getRoundWinner(1) === 'yellow' ? 'bg-yellow-400 text-black border-yellow-500' :
                                        getRoundWinner(1) === 'blue' ? 'bg-blue-600 text-white border-blue-500' :
                                        getRoundWinner(1) === 'draw' ? 'bg-stone-500 text-white border-stone-400' :
                                        'bg-zinc-900 border-stone-800 text-stone-700 shadow-none'
                                    ]">
                                        <span v-if="getRoundWinner(1) === 'yellow'">Kuning</span>
                                        <span v-else-if="getRoundWinner(1) === 'blue'">Biru</span>
                                        <span v-else-if="getRoundWinner(1) === 'draw'">Seri</span>
                                        <span v-else>-</span>
                                    </div>
                                </div>
                                
                                <!-- R2 -->
                                <div class="flex flex-col items-center relative group w-48">
                                    <div class="text-[10px] text-stone-500 font-black uppercase tracking-widest absolute -top-3 bg-zinc-950 px-2 rounded-full z-10 transition-colors">
                                        Ronde 2
                                    </div>
                                    <div :class="[
                                        'w-full py-4 text-center text-lg font-black uppercase tracking-wider rounded-xl border transition-all duration-300 shadow-lg relative overflow-hidden',
                                        getRoundWinner(2) === 'yellow' ? 'bg-yellow-400 text-black border-yellow-500' :
                                        getRoundWinner(2) === 'blue' ? 'bg-blue-600 text-white border-blue-500' :
                                        getRoundWinner(2) === 'draw' ? 'bg-stone-500 text-white border-stone-400' :
                                        'bg-zinc-900 border-stone-800 text-stone-700 shadow-none'
                                    ]">
                                        <span v-if="getRoundWinner(2) === 'yellow'">Kuning</span>
                                        <span v-else-if="getRoundWinner(2) === 'blue'">Biru</span>
                                        <span v-else-if="getRoundWinner(2) === 'draw'">Seri</span>
                                        <span v-else>-</span>
                                    </div>
                                </div>

                                <!-- R3 / TBH -->
                                <div class="flex flex-col items-center relative group w-48">
                                    <div class="text-[10px] text-stone-500 font-black uppercase tracking-widest absolute -top-3 bg-zinc-950 px-2 rounded-full z-10 transition-colors">
                                        Ronde TBH
                                    </div>
                                    <div :class="[
                                        'w-full py-4 text-center text-lg font-black uppercase tracking-wider rounded-xl border transition-all duration-300 shadow-lg relative overflow-hidden',
                                        getRoundWinner(3) === 'yellow' ? 'bg-yellow-400 text-black border-yellow-500' :
                                        getRoundWinner(3) === 'blue' ? 'bg-blue-600 text-white border-blue-500' :
                                        getRoundWinner(3) === 'draw' ? 'bg-stone-500 text-white border-stone-400' :
                                        'bg-zinc-900 border-stone-800 text-stone-700 shadow-none'
                                    ]">
                                        <span v-if="getRoundWinner(3) === 'yellow'">Kuning</span>
                                        <span v-else-if="getRoundWinner(3) === 'blue'">Biru</span>
                                        <span v-else-if="getRoundWinner(3) === 'draw'">Seri</span>
                                        <span v-else>-</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Real-time Valid Sequences -->
                            <div class="flex-1 flex flex-col mt-2">
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
                                                <span v-if="p.ref_score_id" :class="[p.is_valid ? 'text-green-500' : 'text-white', 'text-[1.1rem] font-bold leading-none']">{{ p.score?.name }},</span>
                                                <span v-else-if="p.ref_punishment_id" :class="[p.is_valid ? 'text-red-500 underline decoration-green-500 underline-offset-4 decoration-2' : 'text-red-500', 'text-[1.1rem] font-bold leading-none']">{{ p.punishment?.name }},</span>
                                            </template>
                                        </div>
                                        
                                        <!-- Blue Total -->
                                        <div :class="[
                                            'flex items-center justify-center rounded-md border-[1.5px] font-black text-2xl tabular-nums tracking-tighter transition-colors', 
                                            jury.blue_total > 200 ? 'bg-red-600 border-red-500 text-white' : 
                                            (jury.jury_winner === 'blue' ? 'bg-blue-600 border-blue-500 text-white shadow-[0_0_15px_rgba(37,99,235,0.4)]' : 'bg-zinc-800/80 border-blue-600/40 text-blue-100/70')
                                        ]">
                                            {{ jury.blue_total }}
                                        </div>
                                        
                                        <!-- PW Name -->
                                        <div class="flex items-center justify-center font-black text-sm rounded-md bg-zinc-800 border-[1px] border-stone-700 text-stone-300 drop-shadow-sm uppercase tracking-wider">
                                            {{ jury.jury_name }}
                                        </div>
                                        
                                        <!-- Yellow Total -->
                                        <div :class="[
                                            'flex items-center justify-center rounded-md border-[1.5px] font-black text-2xl tabular-nums tracking-tighter transition-colors', 
                                            jury.yellow_total > 200 ? 'bg-red-600 border-red-500 text-white' : 
                                            (jury.jury_winner === 'yellow' ? 'bg-yellow-500 border-yellow-400 text-black shadow-[0_0_15px_rgba(234,179,8,0.4)]' : 'bg-zinc-800/80 border-yellow-500/40 text-yellow-100/70')
                                        ]">
                                            {{ jury.yellow_total }}
                                        </div>
                                        
                                        <!-- Yellow Nilai -->
                                        <div class="bg-zinc-800 border-[1.5px] border-yellow-500/40 rounded-md px-4 py-2 flex flex-wrap content-center gap-1.5 justify-end overflow-hidden">
                                            <template v-for="(p, i) in jury.yellow_details" :key="'yn'+i">
                                                <span v-if="p.ref_score_id" :class="[p.is_valid ? 'text-green-500' : 'text-white', 'text-[1.1rem] font-bold leading-none']">{{ p.score?.name }},</span>
                                                <span v-else-if="p.ref_punishment_id" :class="[p.is_valid ? 'text-red-500 underline decoration-green-500 underline-offset-4 decoration-2' : 'text-red-500', 'text-[1.1rem] font-bold leading-none']">{{ p.punishment?.name }},</span>
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

<style scoped>
</style>
