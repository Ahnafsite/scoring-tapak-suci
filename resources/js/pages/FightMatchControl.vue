<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';
import FightFullscreenButton from '@/components/fight/FightFullscreenButton.vue';
import FightWaitingState from '@/components/fight/FightWaitingState.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { RefreshCw } from 'lucide-vue-next';
import { Toaster } from '@/components/ui/sonner';
import { useFullscreenLock } from '@/composables/useFullscreenLock';
import { toast } from 'vue-sonner';

const props = defineProps<{
    schedules: any[];
    arena: any;
    activeMatch?: any;
    recapJuryPoint?: any;
}>();

const isSetupDialogOpen = ref(false);
const isLoading = ref(false);
const isRefreshing = ref(false);
const {
    buttonTitle,
    exitClickCount,
    isFullscreen,
    remainingExitClicks,
    requiredExitClicks,
    triggerFullscreen,
} = useFullscreenLock();

const isConfirmDialogOpen = ref(false);
const selectedMatch = ref<any>(null);
const currentMatchDetail = ref<any>(props.activeMatch || null);
const currentRecapDetail = ref<any>(props.recapJuryPoint || null);
const isSyncing = ref(false);
const isResetDialogOpen = ref(false);
const isResetting = ref(false);

const isWinnerDialogOpen = ref(false);
const suggestedWinner = ref('');
const selectedRoundWinner = ref('');
const isSavingWinner = ref(false);

const isMatchWinnerDialogOpen = ref(false);
const suggestedMatchWinner = ref('');
const selectedMatchWinner = ref('');
const selectedWinnerStatus = ref('menang_angka');
const isSavingMatchWinner = ref(false);

const isDisqualificationDialogOpen = ref(false);
const selectedDisqualifiedCorner = ref('');
const isSavingDisqualification = ref(false);

const gelanggangList = ref<any[]>([]);
const sesiList = ref<any[]>([]);

const selectedGelanggang = ref('');
const selectedSesi = ref('');

const fetchGelanggang = async () => {
    try {
        const response = await axios.get('/api/source/gelanggang');
        if (response.data?.data) {
            gelanggangList.value = response.data.data;
        }
    } catch (e) {
        console.error('Failed to fetch gelanggang', e);
    }
};

const handleGelanggangChange = async (val: any, retainSesi = false) => {
    if (!val) return;
    selectedGelanggang.value = val.toString();
    if (!retainSesi) {
        selectedSesi.value = '';
    }
    sesiList.value = [];
    try {
        const response = await axios.get(`/api/source/sesi/${val}`);
        if (response.data?.data) {
            sesiList.value = response.data.data;
            if (retainSesi && props.arena?.sesi_tanding_id) {
                selectedSesi.value = props.arena.sesi_tanding_id.toString();
            }
        }
    } catch (e) {
        console.error('Failed to fetch sesi', e);
    }
};

const openSetup = async () => {
    isSetupDialogOpen.value = true;
    await fetchGelanggang();
    if (props.arena?.gelanggang_id) {
        await handleGelanggangChange(
            props.arena.gelanggang_id.toString(),
            true,
        );
    }
};

const saveSetup = async () => {
    if (!selectedGelanggang.value || !selectedSesi.value) return;
    isLoading.value = true;
    try {
        const g = gelanggangList.value.find(
            (i: any) => i.id.toString() === selectedGelanggang.value,
        );

        await axios.post('/api/arena/setup', {
            gelanggang_id: selectedGelanggang.value,
            sesi_tanding_id: selectedSesi.value,
            arena_name: g ? g.nama_gelanggang : null,
        });
        isSetupDialogOpen.value = false;
        router.reload({ only: ['schedules', 'arena'] });
    } catch (e) {
        console.error('Failed to setup arena', e);
    } finally {
        isLoading.value = false;
    }
};

const refreshSchedule = async () => {
    if (!props.arena?.gelanggang_id || !props.arena?.sesi_tanding_id) return;
    isRefreshing.value = true;
    try {
        await axios.post('/api/arena/setup', {
            gelanggang_id: props.arena.gelanggang_id,
            sesi_tanding_id: props.arena.sesi_tanding_id,
            arena_name: props.arena.arena_name ?? null,
        });
        router.reload({ only: ['schedules', 'arena'] });
    } catch (e) {
        console.error('Failed to refresh schedule', e);
    } finally {
        isRefreshing.value = false;
    }
};

const refreshArenaSchedules = async () => {
    if (!props.arena?.gelanggang_id || !props.arena?.sesi_tanding_id) return;

    await axios.post('/api/arena/setup', {
        gelanggang_id: props.arena.gelanggang_id,
        sesi_tanding_id: props.arena.sesi_tanding_id,
        arena_name: props.arena.arena_name ?? null,
    });
};

const statusConfig: Record<string, { label: string; class: string }> = {
    not_started: {
        label: 'Belum Mulai',
        class: 'bg-red-500/15 text-red-400 border-red-500/25',
    },
    ongoing: {
        label: 'Berlangsung',
        class: 'bg-yellow-500/15 text-yellow-400 border-yellow-500/25',
    },
    paused: {
        label: 'Dijeda',
        class: 'bg-blue-500/15 text-blue-400 border-blue-500/25',
    },
    done: {
        label: 'Selesai',
        class: 'bg-green-500/15 text-green-400 border-green-500/25',
    },
};

const getStatus = (status: string) => {
    return statusConfig[status] || statusConfig['not_started'];
};

const isActiveMatch = (match: any) => {
    if (!currentMatchDetail.value) return false;
    return (
        currentMatchDetail.value.fight_schedule_id == match.id ||
        currentMatchDetail.value.match_code == match.match_code
    );
};

const openConfirm = (match: any) => {
    const yellowIsPemenang =
        match.athlete_yellow &&
        match.athlete_yellow.toLowerCase().includes('pemenang partai');
    const blueIsPemenang =
        match.athlete_blue &&
        match.athlete_blue.toLowerCase().includes('pemenang partai');

    if (yellowIsPemenang && !match.winner_corner) {
        toast.error(
            `${match.athlete_yellow} belum ada. Silakan kembali ke ${match.athlete_yellow} terlebih dahulu.`,
        );
        return;
    }
    if (blueIsPemenang && !match.winner_corner) {
        toast.error(
            `${match.athlete_blue} belum ada. Silakan kembali ke ${match.athlete_blue} terlebih dahulu.`,
        );
        return;
    }

    if (isActiveMatch(match)) {
        return; // Prevent clicking on the currently active match again
    }
    if (
        currentMatchDetail.value &&
        ['ongoing', 'paused'].includes(currentMatchDetail.value.status)
    ) {
        return; // disabled click if active match is not purely done/not_started
    }
    selectedMatch.value = match;
    isConfirmDialogOpen.value = true;
};

const currentMatchIndex = computed(() => {
    if (!currentMatchDetail.value || !props.schedules) return -1;
    return props.schedules.findIndex(
        (s: any) =>
            s.id == currentMatchDetail.value.fight_schedule_id ||
            s.match_code == currentMatchDetail.value.match_code,
    );
});

const prevMatch = computed(() => {
    if (currentMatchIndex.value > 0) {
        return props.schedules[currentMatchIndex.value - 1];
    }
    return null;
});

const nextMatch = computed(() => {
    if (
        currentMatchIndex.value >= 0 &&
        currentMatchIndex.value < props.schedules.length - 1
    ) {
        return props.schedules[currentMatchIndex.value + 1];
    }
    return null;
});

const setRound = async (roundNum: number) => {
    if (
        currentMatchDetail.value &&
        currentMatchDetail.value.status === 'paused'
    ) {
        const oldVal = currentMatchDetail.value.round_number;
        currentMatchDetail.value.round_number = roundNum;
        try {
            await axios.post('/api/partai/update-round', {
                id: currentMatchDetail.value.id,
                round_number: roundNum,
            });
        } catch (e) {
            console.error(e);
            currentMatchDetail.value.round_number = oldVal;
        }
    }
};

const setStatus = async (newStatus: string) => {
    if (currentMatchDetail.value) {
        const oldVal = currentMatchDetail.value.status;
        currentMatchDetail.value.status = newStatus;
        try {
            const response = await axios.post('/api/partai/update-status', {
                id: currentMatchDetail.value.id,
                status: newStatus,
            });
            if (response.data?.data) {
                currentMatchDetail.value = response.data.data;
            }
            if (
                response.data?.recap &&
                currentRecapDetail.value &&
                Array.isArray(currentRecapDetail.value)
            ) {
                const idx = currentRecapDetail.value.findIndex(
                    (r: any) =>
                        r.round_number === response.data.recap.round_number,
                );
                if (idx !== -1) {
                    currentRecapDetail.value.splice(
                        idx,
                        1,
                        response.data.recap,
                    );
                }
            }
            // Reload schedules to reflect status change in sidebar
            router.reload({ only: ['schedules'] });
        } catch (e) {
            console.error(e);
            currentMatchDetail.value.status = oldVal;
        }
    }
};

const canShowKeputusan = computed(() => {
    if (!currentMatchDetail.value || !currentRecapDetail.value) return false;
    const validRecaps = currentRecapDetail.value.filter(
        (r: any) => r.winner && r.winner !== '',
    );

    if (validRecaps.length === 2) {
        const r1 = validRecaps.find((r: any) => r.round_number == 1);
        const r2 = validRecaps.find((r: any) => r.round_number == 2);

        if (r1 && r2) {
            if (
                (r1.winner === 'yellow' && r2.winner === 'blue') ||
                (r1.winner === 'blue' && r2.winner === 'yellow') ||
                (r1.winner === 'draw' && r2.winner === 'draw')
            ) {
                return false;
            }
        }
    }

    return validRecaps.length >= 2;
});

const calculateMatchWinner = () => {
    if (!currentRecapDetail.value) return '';

    let yellowWins = 0;
    let blueWins = 0;
    let totalYellow = 0;
    let totalBlue = 0;

    currentRecapDetail.value.forEach((r: any) => {
        if (r.winner === 'yellow') yellowWins++;
        else if (r.winner === 'blue') blueWins++;

        totalYellow += r.total_poin_yellow || 0;
        totalBlue += r.total_poin_blue || 0;
    });

    if (yellowWins > blueWins) return 'yellow';
    if (blueWins > yellowWins) return 'blue';

    if (totalYellow > totalBlue) return 'yellow';
    if (totalBlue > totalYellow) return 'blue';

    const weightYellow =
        parseFloat(currentMatchDetail.value.weight_yellow) || 0;
    const weightBlue = parseFloat(currentMatchDetail.value.weight_blue) || 0;

    if (weightYellow < weightBlue) return 'yellow';
    if (weightBlue < weightYellow) return 'blue';

    return 'draw';
};

const triggerKeputusan = () => {
    const sug = calculateMatchWinner();
    suggestedMatchWinner.value = sug;
    selectedMatchWinner.value = sug;
    selectedWinnerStatus.value = 'menang_angka';

    if (currentMatchDetail.value) {
        localStorage.setItem(
            'pending_keputusan_match_code',
            currentMatchDetail.value.match_code,
        );
    }

    isMatchWinnerDialogOpen.value = true;
};

const saveMatchWinner = async () => {
    if (
        !currentMatchDetail.value ||
        !selectedMatchWinner.value ||
        !selectedWinnerStatus.value
    )
        return;
    isSavingMatchWinner.value = true;
    try {
        const response = await axios.post(
            `/api/partai/save-partai-data-ts/${currentMatchDetail.value.partai_id}`,
            {
                winner_corner: selectedMatchWinner.value,
                winner_status: selectedWinnerStatus.value,
            },
        );

        currentMatchDetail.value = response.data.data;
        isMatchWinnerDialogOpen.value = false;
        localStorage.removeItem('pending_keputusan_match_code');
        await refreshArenaSchedules();
        router.reload({ only: ['schedules', 'arena', 'activeMatch'] });
    } catch (e) {
        console.error('Failed to save match winner', e);
    } finally {
        isSavingMatchWinner.value = false;
    }
};

const cancelMatchWinner = () => {
    isMatchWinnerDialogOpen.value = false;
    if (currentMatchDetail.value) {
        currentMatchDetail.value.status = 'paused';
        localStorage.removeItem('pending_keputusan_match_code');
    }
};

const triggerReset = () => {
    isResetDialogOpen.value = true;
};

const confirmReset = async () => {
    if (!currentMatchDetail.value) return;

    isResetting.value = true;

    try {
        const matchId = currentMatchDetail.value.id;
        const partaiId = currentMatchDetail.value.partai_id;

        await axios.post(`/api/partai/save-partai-data-ts/${partaiId}`, {
            status: 'not_started',
            winner_corner: null,
            winner_status: null,
        });

        const response = await axios.post('/api/partai/update-status', {
            id: matchId,
            status: 'not_started',
            sync_server: true,
        });

        if (response.data?.data) {
            currentMatchDetail.value = response.data.data;
        } else {
            currentMatchDetail.value.status = 'not_started';
            currentMatchDetail.value.round_number = 1;
            currentMatchDetail.value.winner_corner = null;
            currentMatchDetail.value.winner_status = null;
        }

        currentRecapDetail.value = [];
        localStorage.removeItem('pending_keputusan_match_code');
        localStorage.removeItem(
            `pending_round_decision_${currentMatchDetail.value.match_code}`,
        );

        await refreshArenaSchedules();
        router.reload({
            only: ['schedules', 'arena', 'activeMatch', 'recapJuryPoint'],
        });

        isResetDialogOpen.value = false;
        toast.success('Pertandingan berhasil direset.');
    } catch (e) {
        console.error('Failed to reset match', e);
        toast.error('Gagal reset pertandingan.');
    } finally {
        isResetting.value = false;
    }
};

const triggerDiskualifikasi = () => {
    if (!currentMatchDetail.value) {
        toast.error('Pilih partai terlebih dahulu.');
        return;
    }

    selectedDisqualifiedCorner.value = '';
    isDisqualificationDialogOpen.value = true;
};

const saveDisqualification = async () => {
    if (!currentMatchDetail.value || !selectedDisqualifiedCorner.value) return;

    isSavingDisqualification.value = true;

    try {
        const winnerCorner =
            selectedDisqualifiedCorner.value === 'yellow' ? 'blue' : 'yellow';
        const response = await axios.post(
            `/api/partai/save-partai-data-ts/${currentMatchDetail.value.partai_id}`,
            {
                winner_corner: winnerCorner,
                winner_status: 'menang_diskualifikasi',
            },
        );

        currentMatchDetail.value = response.data.data;
        localStorage.removeItem('pending_keputusan_match_code');
        localStorage.removeItem(
            `pending_round_decision_${currentMatchDetail.value.match_code}`,
        );

        await refreshArenaSchedules();
        router.reload({ only: ['schedules', 'arena', 'activeMatch'] });

        isDisqualificationDialogOpen.value = false;
        selectedDisqualifiedCorner.value = '';
        toast.success('Diskualifikasi berhasil disimpan.');
    } catch (e) {
        console.error('Failed to save disqualification', e);
        toast.error('Gagal menyimpan diskualifikasi.');
    } finally {
        isSavingDisqualification.value = false;
    }
};

const openMatch = (match: any) => {
    if (match) {
        openConfirm(match);
    }
};

const syncMatch = async () => {
    if (!selectedMatch.value) return;
    isSyncing.value = true;
    try {
        const response = await axios.post(
            `/api/partai/sync/${selectedMatch.value.partai_id}`,
            {
                fight_schedule_id: selectedMatch.value.id,
            },
        );
        // If successful, the local API returns the updated match details
        currentMatchDetail.value = response.data.data;
        if (response.data.recap) currentRecapDetail.value = response.data.recap;
        isConfirmDialogOpen.value = false;
        setTimeout(checkUnfinishedDecision, 100);
        router.reload({ only: ['schedules', 'activeMatch', 'recapJuryPoint'] });
    } catch (e) {
        console.error('Failed to sync match', e);
    } finally {
        isSyncing.value = false;
    }
};

const getRoundWinner = (roundNum: number) => {
    if (!currentRecapDetail.value || !Array.isArray(currentRecapDetail.value))
        return null;
    const r = currentRecapDetail.value.find(
        (x: any) => x.round_number == roundNum,
    );
    return r ? r.winner : null;
};

const activeRoundRecap = computed(() => {
    if (!currentRecapDetail.value || !Array.isArray(currentRecapDetail.value))
        return null;
    return currentRecapDetail.value.find(
        (x: any) =>
            x.round_number == (currentMatchDetail.value?.round_number ?? 1),
    );
});

const calculateSuggestedWinner = () => {
    if (!activeRoundRecap.value) return 'draw';
    const votes = { yellow: 0, blue: 0, draw: 0 };
    const juries = [
        activeRoundRecap.value.jury_one_winner,
        activeRoundRecap.value.jury_two_winner,
        activeRoundRecap.value.jury_three_winner,
        activeRoundRecap.value.jury_four_winner,
    ];
    juries.forEach((v) => {
        if (v === 'yellow') votes.yellow++;
        else if (v === 'blue') votes.blue++;
        else votes.draw++;
    });

    if (votes.yellow >= 3) return 'yellow';
    if (votes.blue >= 3) return 'blue';
    if (votes.draw >= 3) return 'draw';

    if (votes.yellow === 2 && votes.blue === 2) return 'draw';
    if (votes.yellow === 2) return 'yellow';
    if (votes.blue === 2) return 'blue';
    if (votes.draw === 2) return 'draw';

    return 'draw';
};

const triggerPause = async () => {
    // Prevent multiple clicks if already paused
    if (
        currentMatchDetail.value &&
        currentMatchDetail.value.status === 'paused'
    ) {
        isWinnerDialogOpen.value = true;
        return;
    }

    const sug = calculateSuggestedWinner();
    suggestedWinner.value = sug;
    selectedRoundWinner.value = sug;

    if (currentMatchDetail.value) {
        localStorage.setItem(
            `pending_round_decision_${currentMatchDetail.value.match_code}`,
            currentMatchDetail.value.round_number.toString(),
        );
    }

    isWinnerDialogOpen.value = true;
    await setStatus('paused');
};

const saveRoundWinner = async () => {
    if (!currentMatchDetail.value || !selectedRoundWinner.value) return;
    isSavingWinner.value = true;
    try {
        const response = await axios.post('/api/partai/update-round-winner', {
            round_number: currentMatchDetail.value.round_number,
            winner: selectedRoundWinner.value,
        });

        if (response.data?.data && currentRecapDetail.value) {
            const idx = currentRecapDetail.value.findIndex(
                (r: any) => r.round_number === response.data.data.round_number,
            );
            if (idx !== -1) {
                currentRecapDetail.value.splice(idx, 1, response.data.data);
            } else {
                currentRecapDetail.value.push(response.data.data);
            }
        }
        localStorage.removeItem(
            `pending_round_decision_${currentMatchDetail.value.match_code}`,
        );
        isWinnerDialogOpen.value = false;
    } catch (e) {
        console.error(e);
    } finally {
        isSavingWinner.value = false;
    }
};

const cancelPause = async () => {
    isWinnerDialogOpen.value = false;
    if (currentMatchDetail.value) {
        localStorage.removeItem(
            `pending_round_decision_${currentMatchDetail.value.match_code}`,
        );
    }
    await setStatus('ongoing');
};

const triggerResume = async () => {
    if (!currentMatchDetail.value) return;

    const currentRoundWinner = getRoundWinner(
        currentMatchDetail.value.round_number,
    );
    if (currentRoundWinner) {
        try {
            const response = await axios.post(
                '/api/partai/update-round-winner',
                {
                    round_number: currentMatchDetail.value.round_number,
                    winner: null,
                },
            );
            if (response.data?.data && currentRecapDetail.value) {
                const idx = currentRecapDetail.value.findIndex(
                    (r: any) =>
                        r.round_number === response.data.data.round_number,
                );
                if (idx !== -1) {
                    currentRecapDetail.value.splice(idx, 1, response.data.data);
                }
            }
        } catch (e) {
            console.error('Failed to clear round winner on resume', e);
        }
    }

    await setStatus('ongoing');
};

// Real-time listeners
let echoStatusChannel: any = null;
let echoScoreChannel: any = null;

const checkUnfinishedDecision = () => {
    if (!currentMatchDetail.value) return;

    if (currentMatchDetail.value.status === 'paused') {
        const roundNum = currentMatchDetail.value.round_number;
        const currentRoundWinner = getRoundWinner(roundNum);
        const pendingRound = localStorage.getItem(
            `pending_round_decision_${currentMatchDetail.value.match_code}`,
        );

        if (!currentRoundWinner && pendingRound == roundNum.toString()) {
            const sug = calculateSuggestedWinner();
            suggestedWinner.value = sug;
            selectedRoundWinner.value = sug;
            isWinnerDialogOpen.value = true;
            return;
        }
    }

    const pendingKeputusan = localStorage.getItem(
        'pending_keputusan_match_code',
    );
    if (
        pendingKeputusan &&
        pendingKeputusan == currentMatchDetail.value.match_code &&
        !currentMatchDetail.value.winner_corner
    ) {
        const sug = calculateMatchWinner();
        suggestedMatchWinner.value = sug;
        selectedMatchWinner.value = sug;
        selectedWinnerStatus.value = 'menang_angka';
        isMatchWinnerDialogOpen.value = true;
    }
};

const activeMainCorner = computed(() => {
    if (!activeRoundRecap.value) return 'draw';

    const yellowScore = activeRoundRecap.value.total_poin_yellow || 0;
    const blueScore = activeRoundRecap.value.total_poin_blue || 0;

    if (yellowScore > blueScore) return 'yellow';
    if (blueScore > yellowScore) return 'blue';

    // Tie-breaker: Jury winner majority
    const juries = [
        activeRoundRecap.value.jury_one_winner,
        activeRoundRecap.value.jury_two_winner,
        activeRoundRecap.value.jury_three_winner,
        activeRoundRecap.value.jury_four_winner,
    ];

    let yellowJuryCount = 0;
    let blueJuryCount = 0;

    juries.forEach((w) => {
        if (w === 'yellow') yellowJuryCount++;
        else if (w === 'blue') blueJuryCount++;
    });

    if (yellowJuryCount > blueJuryCount) return 'yellow';
    if (blueJuryCount > yellowJuryCount) return 'blue';

    return 'draw';
});

const isBuzzerActive = computed(() => {
    if (currentMatchDetail.value?.status !== 'ongoing') return false;
    if (!activeRoundRecap.value) return false;

    const r = activeRoundRecap.value;
    let yellow200Count = 0;
    let blue200Count = 0;

    const yTotals = [
        r.jury_one_total_poin_yellow,
        r.jury_two_total_poin_yellow,
        r.jury_three_total_poin_yellow,
        r.jury_four_total_poin_yellow,
    ];

    const bTotals = [
        r.jury_one_total_poin_blue,
        r.jury_two_total_poin_blue,
        r.jury_three_total_poin_blue,
        r.jury_four_total_poin_blue,
    ];

    yTotals.forEach((val) => {
        if (Number(val) > 200) yellow200Count++;
    });
    bTotals.forEach((val) => {
        if (Number(val) > 200) blue200Count++;
    });

    return yellow200Count >= 3 || blue200Count >= 3;
});

const buzzerAudio = ref<HTMLAudioElement | null>(null);

watch(isBuzzerActive, (active) => {
    if (!buzzerAudio.value) return;
    if (active) {
        buzzerAudio.value
            .play()
            .catch((e) => console.error('Audio play failed:', e));
    } else {
        buzzerAudio.value.pause();
        buzzerAudio.value.currentTime = 0;
    }
});

onMounted(() => {
    buzzerAudio.value = new Audio('/assets/audio/buzzer.mp3');
    buzzerAudio.value.loop = true;

    if (isBuzzerActive.value) {
        buzzerAudio.value
            .play()
            .catch((e) => console.error('Audio play failed:', e));
    }

    const echo = (window as any).Echo;
    if (!echo) return;

    // Listen for match status/round changes from Operator
    echoStatusChannel = echo
        .channel('match.status')
        .listen('.ActiveMatchUpdated', (e: any) => {
            if (e.match) {
                currentMatchDetail.value = e.match;
                setTimeout(checkUnfinishedDecision, 100);
            }
        });

    // Listen for score updates from any Jury → update Pembantu Wasit recap table
    echoScoreChannel = echo
        .channel('match.score')
        .listen('.JuryScoreUpdated', (e: any) => {
            if (
                e.recap &&
                currentRecapDetail.value &&
                Array.isArray(currentRecapDetail.value)
            ) {
                const idx = currentRecapDetail.value.findIndex(
                    (r: any) => r.round_number === e.recap.round_number,
                );
                if (idx !== -1) {
                    currentRecapDetail.value[idx] = e.recap;
                } else {
                    currentRecapDetail.value.push(e.recap);
                }
            }
        });

    setTimeout(checkUnfinishedDecision, 500);
});

onUnmounted(() => {
    if (buzzerAudio.value) {
        buzzerAudio.value.pause();
        buzzerAudio.value.currentTime = 0;
    }

    const echo = (window as any).Echo;
    if (!echo) return;
    if (echoStatusChannel) {
        echoStatusChannel.stopListening('.ActiveMatchUpdated');
        echo.leaveChannel('match.status');
    }
    if (echoScoreChannel) {
        echoScoreChannel.stopListening('.JuryScoreUpdated');
        echo.leaveChannel('match.score');
    }
});
</script>

<template>
    <Head title="Control Panel - Tapak Suci" />
    <Toaster rich-colors />
    <div class="flex h-screen overflow-hidden bg-background text-foreground">
        <!-- Sidebar -->
        <div
            class="z-10 flex h-full w-92 shrink-0 flex-col border-r border-border bg-zinc-900 shadow-xl"
        >
            <!-- Sidebar Header -->
            <div class="flex flex-col border-b border-stone-700 p-6">
                <!-- Setup Button -->
                <Button
                    v-if="
                        !(
                            currentMatchDetail &&
                            ['ongoing', 'paused'].includes(
                                currentMatchDetail.status,
                            )
                        )
                    "
                    @click="openSetup"
                    class="w-full"
                >
                    Pilih Gelanggang
                </Button>
                <h2
                    class="mt-2 text-center text-sm font-bold tracking-tight text-yellow-500"
                    v-if="props.arena?.gelanggang_id"
                >
                    Gelanggang
                    {{
                        props.arena.arena_name ??
                        'Gelanggang ' + props.arena.gelanggang_id
                    }}
                </h2>
                <h2
                    class="text-center text-sm font-bold tracking-tight text-green-500"
                    v-if="props.arena?.gelanggang_id"
                >
                    Sesi Tanding ID {{ props.arena.sesi_tanding_id }}
                </h2>
            </div>

            <!-- Schedule Info Bar -->
            <div
                class="flex items-center justify-between border-b border-stone-700 px-4 py-3"
                v-if="props.arena?.gelanggang_id"
            >
                <div>
                    <h3 class="text-sm font-semibold tracking-tight">
                        Jadwal Pertandingan
                    </h3>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        Jumlah Partai:
                        <span class="font-bold text-foreground">{{
                            props.schedules.length
                        }}</span>
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="refreshSchedule"
                    :disabled="isRefreshing"
                    class="text-muted-foreground hover:text-foreground"
                >
                    <RefreshCw
                        :class="['h-4 w-4', isRefreshing ? 'animate-spin' : '']"
                    />
                    Refresh
                </Button>
            </div>

            <!-- Match Schedules List -->
            <div class="custom-scrollbar flex-1 space-y-3 overflow-y-auto p-4">
                <div
                    v-if="props.schedules.length === 0"
                    class="mt-10 text-center text-sm text-muted-foreground"
                >
                    <div class="mb-4 text-4xl opacity-20">🏟️</div>
                    <p>Belum ada jadwal partai.</p>
                    <p class="mt-1 text-xs">
                        Silakan set gelanggang dan sesi terlebih dahulu.
                    </p>
                </div>

                <Card
                    v-for="match in props.schedules"
                    :key="match.id"
                    @click="openConfirm(match)"
                    :class="[
                        'group gap-0 overflow-hidden py-0 transition-all',
                        isActiveMatch(match)
                            ? 'cursor-default border-yellow-500/50 bg-yellow-500/10 shadow-[0_0_15px_rgba(234,179,8,0.15)]'
                            : '',
                        !isActiveMatch(match) &&
                        currentMatchDetail &&
                        ['ongoing', 'paused'].includes(
                            currentMatchDetail.status,
                        )
                            ? 'cursor-not-allowed'
                            : '',
                        !isActiveMatch(match) &&
                        (!currentMatchDetail ||
                            !['ongoing', 'paused'].includes(
                                currentMatchDetail.status,
                            ))
                            ? 'cursor-pointer hover:border-yellow-500/30 hover:bg-zinc-800/50'
                            : '',
                    ]"
                >
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-2 px-4 pt-3"
                    >
                        <div class="flex flex-col">
                            <Badge
                                class="mb-2 shrink-0 border-gray-400/25 bg-gray-400/15 text-[11px] font-bold tracking-wider text-gray-400 uppercase hover:bg-gray-400/15"
                            >
                                Partai {{ match.match_code }}
                            </Badge>
                        </div>
                        <Badge
                            :class="[
                                'shrink-0 text-[11px] font-semibold tracking-wider uppercase',
                                getStatus(match.status).class,
                            ]"
                        >
                            {{ getStatus(match.status).label }}
                        </Badge>
                    </CardHeader>

                    <CardContent class="px-4 pt-0 pb-3">
                        <p
                            class="w-full text-center text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                        >
                            {{ match.match_round }}
                        </p>
                        <p
                            class="mb-2 w-full text-center text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                        >
                            {{ match.group }} - {{ match.category }}
                        </p>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1 text-right">
                                <p
                                    :class="[
                                        'line-clamp-2 text-sm leading-relaxed font-semibold uppercase transition-all',
                                        match.winner_corner === 'blue' &&
                                        match.status === 'done'
                                            ? 'text-yellow-400/50 line-through'
                                            : 'text-yellow-400',
                                    ]"
                                >
                                    {{ match.athlete_yellow || '-' }}
                                </p>
                                <p
                                    :class="[
                                        'mt-0.5 line-clamp-2 text-[12px] uppercase transition-all',
                                        match.winner_corner === 'blue' &&
                                        match.status === 'done'
                                            ? 'text-white/50 line-through'
                                            : 'text-white',
                                    ]"
                                >
                                    {{ match.contingent_yellow || 'Kontingen' }}
                                </p>
                            </div>
                            <div
                                class="shrink-0 px-1.5 pt-0.5 text-[10px] font-bold text-muted-foreground/50 italic"
                            >
                                VS
                            </div>
                            <div class="min-w-0 flex-1 text-left">
                                <p
                                    :class="[
                                        'line-clamp-2 text-sm leading-relaxed font-semibold uppercase transition-all',
                                        match.winner_corner === 'yellow' &&
                                        match.status === 'done'
                                            ? 'text-blue-400/50 line-through'
                                            : 'text-blue-400',
                                    ]"
                                >
                                    {{ match.athlete_blue || '-' }}
                                </p>
                                <p
                                    :class="[
                                        'mt-0.5 line-clamp-2 text-[12px] uppercase transition-all',
                                        match.winner_corner === 'yellow' &&
                                        match.status === 'done'
                                            ? 'text-white/50 line-through'
                                            : 'text-white',
                                    ]"
                                >
                                    {{ match.contingent_blue || 'Kontingen' }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Separator />

            <div
                class="flex h-[72px] shrink-0 items-center border-t border-stone-800 p-4"
            >
                <Button
                    @click="triggerDiskualifikasi()"
                    variant="destructive"
                    class="w-full text-xs font-bold tracking-wider uppercase"
                    :disabled="!currentMatchDetail || isSavingDisqualification"
                >
                    {{
                        isSavingDisqualification
                            ? 'MENYIMPAN...'
                            : 'DISKUALIFIKASI'
                    }}
                </Button>
            </div>
        </div>

        <!-- Main Content Area -->
        <div
            class="relative flex h-full flex-1 flex-col overflow-hidden bg-zinc-950"
        >
            <template v-if="currentMatchDetail">
                <!-- Match Information Header -->
                <div
                    class="z-10 flex h-16 w-full shrink-0 items-center justify-between border-b border-stone-800 bg-zinc-900 px-6"
                >
                    <div class="h-9 w-9 shrink-0"></div>
                    <h1
                        class="text-center text-2xl font-black tracking-widest text-white uppercase"
                    >
                        {{ currentMatchDetail.match_round }}
                        {{ currentMatchDetail.group }}
                        {{ currentMatchDetail.category }}
                    </h1>
                    <FightFullscreenButton
                        :exit-click-count="exitClickCount"
                        :is-fullscreen="isFullscreen"
                        :remaining-exit-clicks="remainingExitClicks"
                        :required-exit-clicks="requiredExitClicks"
                        :title="buttonTitle"
                        :on-trigger="triggerFullscreen"
                    />
                </div>

                <!-- Main Content Body -->
                <div
                    class="z-10 flex flex-1 flex-col space-y-6 overflow-hidden p-6"
                >
                    <!-- Score Cards -->
                    <div
                        class="flex flex-1 overflow-hidden rounded-2xl border border-stone-800 shadow-2xl"
                    >
                        <!-- Yellow Corner Card -->
                        <div
                            :class="[
                                'relative flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center transition-colors duration-500',
                                activeMainCorner === 'yellow' ||
                                activeMainCorner === 'draw'
                                    ? 'bg-yellow-400'
                                    : 'bg-zinc-900 opacity-80 grayscale',
                            ]"
                        >
                            <h2
                                :class="[
                                    'mb-1 text-4xl font-black tracking-wider uppercase drop-shadow-sm',
                                    activeMainCorner === 'yellow' ||
                                    activeMainCorner === 'draw'
                                        ? 'text-black'
                                        : 'text-yellow-400',
                                ]"
                            >
                                {{ currentMatchDetail.atlete_yellow || '-' }}
                            </h2>
                            <p
                                :class="[
                                    'text-xl font-bold uppercase',
                                    activeMainCorner === 'yellow' ||
                                    activeMainCorner === 'draw'
                                        ? 'text-black/80'
                                        : 'text-white',
                                ]"
                            >
                                {{
                                    currentMatchDetail.contingent_yellow || '-'
                                }}
                            </p>

                            <!-- Weight and Status Badge -->
                            <div class="mt-2 flex flex-col items-center gap-2">
                                <span
                                    :class="[
                                        'text-4xl font-black tabular-nums',
                                        activeMainCorner === 'yellow' ||
                                        activeMainCorner === 'draw'
                                            ? 'text-black'
                                            : 'text-yellow-100',
                                    ]"
                                >
                                    {{ currentMatchDetail.weight_yellow }} KG
                                </span>
                                <Badge
                                    :class="[
                                        activeMainCorner === 'yellow' ||
                                        activeMainCorner === 'draw'
                                            ? 'bg-black text-yellow-400'
                                            : 'bg-yellow-500 text-black',
                                        'pointer-events-none font-bold tracking-widest uppercase hover:bg-black',
                                    ]"
                                >
                                    {{
                                        currentMatchDetail.weight_status_yellow
                                    }}
                                </Badge>
                            </div>

                            <div class="mt-auto flex w-full justify-center">
                                <div
                                    :class="[
                                        'text-[12rem] font-black drop-shadow-sm',
                                        activeMainCorner === 'yellow' ||
                                        activeMainCorner === 'draw'
                                            ? 'text-black'
                                            : 'text-yellow-500',
                                    ]"
                                >
                                    {{
                                        activeRoundRecap?.total_poin_yellow || 0
                                    }}
                                </div>
                            </div>
                        </div>

                        <!-- Middle Neutral Column -->
                        <div
                            class="relative z-20 flex w-64 shrink-0 flex-col items-center justify-start border-x border-stone-800 bg-zinc-950 py-6 shadow-2xl"
                        >
                            <div
                                class="mb-8 w-full border-b border-stone-800 pb-4 text-center text-xs font-black tracking-widest text-stone-200 uppercase"
                            >
                                PARTAI
                                {{ currentMatchDetail.match_code || '-' }}
                            </div>

                            <div
                                class="relative -mt-4 flex w-full flex-1 flex-col justify-center gap-8 px-6"
                            >
                                <!-- R1 -->
                                <div
                                    class="group relative flex flex-col items-center"
                                >
                                    <div
                                        class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                    >
                                        Ronde 1
                                    </div>
                                    <div
                                        :class="[
                                            'relative w-full overflow-hidden rounded-xl border py-5 text-center text-xl font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                            getRoundWinner(1) === 'yellow'
                                                ? 'border-yellow-500 bg-yellow-400 text-black'
                                                : getRoundWinner(1) === 'blue'
                                                  ? 'border-blue-500 bg-blue-600 text-white'
                                                  : getRoundWinner(1) === 'draw'
                                                    ? 'border-stone-400 bg-stone-500 text-white'
                                                    : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                        ]"
                                    >
                                        <span
                                            v-if="
                                                getRoundWinner(1) === 'yellow'
                                            "
                                            >Kuning</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(1) === 'blue'
                                            "
                                            >Biru</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(1) === 'draw'
                                            "
                                            >Seri</span
                                        >
                                        <span v-else>-</span>
                                    </div>
                                </div>

                                <!-- R2 -->
                                <div
                                    class="group relative flex flex-col items-center"
                                >
                                    <div
                                        class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                    >
                                        Ronde 2
                                    </div>
                                    <div
                                        :class="[
                                            'relative w-full overflow-hidden rounded-xl border py-5 text-center text-xl font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                            getRoundWinner(2) === 'yellow'
                                                ? 'border-yellow-500 bg-yellow-400 text-black'
                                                : getRoundWinner(2) === 'blue'
                                                  ? 'border-blue-500 bg-blue-600 text-white'
                                                  : getRoundWinner(2) === 'draw'
                                                    ? 'border-stone-400 bg-stone-500 text-white'
                                                    : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                        ]"
                                    >
                                        <span
                                            v-if="
                                                getRoundWinner(2) === 'yellow'
                                            "
                                            >Kuning</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(2) === 'blue'
                                            "
                                            >Biru</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(2) === 'draw'
                                            "
                                            >Seri</span
                                        >
                                        <span v-else>-</span>
                                    </div>
                                </div>

                                <!-- R3 -->
                                <div
                                    class="group relative flex flex-col items-center"
                                >
                                    <div
                                        class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                    >
                                        Ronde Tambahan
                                    </div>
                                    <div
                                        :class="[
                                            'relative w-full overflow-hidden rounded-xl border py-5 text-center text-xl font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                            getRoundWinner(3) === 'yellow'
                                                ? 'border-yellow-500 bg-yellow-400 text-black'
                                                : getRoundWinner(3) === 'blue'
                                                  ? 'border-blue-500 bg-blue-600 text-white'
                                                  : getRoundWinner(3) === 'draw'
                                                    ? 'border-stone-400 bg-stone-500 text-white'
                                                    : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                        ]"
                                    >
                                        <span
                                            v-if="
                                                getRoundWinner(3) === 'yellow'
                                            "
                                            >Kuning</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(3) === 'blue'
                                            "
                                            >Biru</span
                                        >
                                        <span
                                            v-else-if="
                                                getRoundWinner(3) === 'draw'
                                            "
                                            >Seri</span
                                        >
                                        <span v-else>-</span>
                                    </div>
                                </div>

                                <div
                                    class="group relative flex flex-col items-center"
                                >
                                    <div
                                        class="absolute -top-3 z-10 rounded-full bg-zinc-950 px-2 text-[10px] font-black tracking-widest text-stone-500 uppercase transition-colors"
                                    >
                                        Pemenang
                                    </div>
                                    <div
                                        :class="[
                                            'relative w-full overflow-hidden rounded-xl border py-5 text-center text-xl font-black tracking-wider uppercase shadow-lg transition-all duration-300',
                                            currentMatchDetail.winner_corner ===
                                            'yellow'
                                                ? 'border-yellow-500 bg-yellow-400 text-black'
                                                : currentMatchDetail.winner_corner ===
                                                    'blue'
                                                  ? 'border-blue-500 bg-blue-600 text-white'
                                                  : currentMatchDetail.winner_corner ===
                                                      'draw'
                                                    ? 'border-stone-400 bg-stone-500 text-white'
                                                    : 'border-stone-800 bg-zinc-900 text-stone-700 shadow-none',
                                        ]"
                                    >
                                        <span
                                            v-if="
                                                currentMatchDetail.winner_corner ===
                                                'yellow'
                                            "
                                            >Kuning</span
                                        >
                                        <span
                                            v-else-if="
                                                currentMatchDetail.winner_corner ===
                                                'blue'
                                            "
                                            >Biru</span
                                        >
                                        <span
                                            v-else-if="
                                                currentMatchDetail.winner_corner ===
                                                'draw'
                                            "
                                            >Seri</span
                                        >
                                        <span v-else>-</span>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="mt-6 flex w-full flex-col items-center gap-3 border-t border-stone-800 px-6 pt-6"
                            >
                                <Badge
                                    :class="[
                                        'flex w-full items-center justify-center py-3 text-xs tracking-widest uppercase',
                                        getStatus(currentMatchDetail.status)
                                            .class,
                                    ]"
                                >
                                    {{
                                        getStatus(currentMatchDetail.status)
                                            .label
                                    }}
                                </Badge>
                            </div>
                        </div>

                        <!-- Blue Corner Card -->
                        <div
                            :class="[
                                'relative flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center transition-colors duration-500',
                                activeMainCorner === 'blue' ||
                                activeMainCorner === 'draw'
                                    ? 'bg-blue-600'
                                    : 'bg-zinc-900 opacity-80 grayscale',
                            ]"
                        >
                            <h2
                                :class="[
                                    'mb-1 text-4xl font-black tracking-wider uppercase drop-shadow-sm',
                                    activeMainCorner === 'blue' ||
                                    activeMainCorner === 'draw'
                                        ? 'text-white'
                                        : 'text-blue-400',
                                ]"
                            >
                                {{ currentMatchDetail.atlete_blue || '-' }}
                            </h2>
                            <p
                                :class="[
                                    'text-xl font-bold uppercase',
                                    activeMainCorner === 'blue' ||
                                    activeMainCorner === 'draw'
                                        ? 'text-blue-100'
                                        : 'text-white',
                                ]"
                            >
                                {{ currentMatchDetail.contingent_blue || '-' }}
                            </p>

                            <!-- Weight and Status Badge -->
                            <div class="mt-2 flex flex-col items-center gap-2">
                                <span
                                    :class="[
                                        'text-4xl font-black tabular-nums',
                                        activeMainCorner === 'blue' ||
                                        activeMainCorner === 'draw'
                                            ? 'text-white'
                                            : 'text-blue-100',
                                    ]"
                                >
                                    {{ currentMatchDetail.weight_blue }} KG
                                </span>
                                <Badge
                                    :class="[
                                        activeMainCorner === 'blue' ||
                                        activeMainCorner === 'draw'
                                            ? 'bg-black text-blue-400 hover:bg-black'
                                            : 'bg-blue-500 text-white hover:bg-blue-500',
                                        'pointer-events-none font-bold tracking-widest uppercase',
                                    ]"
                                >
                                    {{ currentMatchDetail.weight_status_blue }}
                                </Badge>
                            </div>

                            <div class="mt-auto flex w-full justify-center">
                                <div
                                    :class="[
                                        'text-[12rem] font-black drop-shadow-sm',
                                        activeMainCorner === 'blue' ||
                                        activeMainCorner === 'draw'
                                            ? 'text-white'
                                            : 'text-blue-500',
                                    ]"
                                >
                                    {{ activeRoundRecap?.total_poin_blue || 0 }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Jury Recap Table Area -->
                    <div
                        class="shrink-0 overflow-hidden rounded-xl border border-stone-800 bg-zinc-900 shadow-lg"
                    >
                        <div
                            class="grid grid-cols-4 border-b border-stone-800 bg-black/40 text-center text-xs font-black tracking-widest text-muted-foreground uppercase"
                        >
                            <div class="border-r border-stone-800 py-2">
                                Pembantu Wasit 1
                            </div>
                            <div class="border-r border-stone-800 py-2">
                                Pembantu Wasit 2
                            </div>
                            <div class="border-r border-stone-800 py-2">
                                Pembantu Wasit 3
                            </div>
                            <div class="py-2">Pembantu Wasit 4</div>
                        </div>
                        <div
                            class="grid grid-cols-8 border-b border-stone-800 text-center text-[10px] font-bold tracking-wider uppercase"
                        >
                            <!-- PW 1 -->
                            <div
                                class="border-r border-stone-800 bg-yellow-400/20 py-1.5 text-yellow-500"
                            >
                                Kuning
                            </div>
                            <div
                                class="border-r border-stone-800 bg-blue-600/20 py-1.5 text-blue-400"
                            >
                                Biru
                            </div>
                            <!-- PW 2 -->
                            <div
                                class="border-r border-stone-800 bg-yellow-400/20 py-1.5 text-yellow-500"
                            >
                                Kuning
                            </div>
                            <div
                                class="border-r border-stone-800 bg-blue-600/20 py-1.5 text-blue-400"
                            >
                                Biru
                            </div>
                            <!-- PW 3 -->
                            <div
                                class="border-r border-stone-800 bg-yellow-400/20 py-1.5 text-yellow-500"
                            >
                                Kuning
                            </div>
                            <div
                                class="border-r border-stone-800 bg-blue-600/20 py-1.5 text-blue-400"
                            >
                                Biru
                            </div>
                            <!-- PW 4 -->
                            <div
                                class="border-r border-stone-800 bg-yellow-400/20 py-1.5 text-yellow-500"
                            >
                                Kuning
                            </div>
                            <div class="bg-blue-600/20 py-1.5 text-blue-400">
                                Biru
                            </div>
                        </div>
                        <!-- Body Values -->
                        <div
                            class="relative grid grid-cols-8 text-center text-3xl font-black tabular-nums transition-colors duration-300"
                        >
                            <!-- PW 1 -->
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_one_winner ===
                                    'yellow'
                                        ? 'bg-yellow-400 text-black shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_one_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_one_winner === 'blue'
                                        ? 'bg-blue-600 text-white shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_one_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                            <!-- PW 2 -->
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_two_winner ===
                                    'yellow'
                                        ? 'bg-yellow-400 text-black shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_two_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_two_winner === 'blue'
                                        ? 'bg-blue-600 text-white shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_two_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                            <!-- PW 3 -->
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_three_winner ===
                                    'yellow'
                                        ? 'bg-yellow-400 text-black shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_three_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_three_winner ===
                                    'blue'
                                        ? 'bg-blue-600 text-white shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_three_total_poin_blue ??
                                    '0'
                                }}
                            </div>
                            <!-- PW 4 -->
                            <div
                                :class="[
                                    'border-r border-stone-800 py-3',
                                    activeRoundRecap?.jury_four_winner ===
                                    'yellow'
                                        ? 'bg-yellow-400 text-black shadow-inner'
                                        : 'bg-black text-white',
                                ]"
                            >
                                {{
                                    activeRoundRecap?.jury_four_total_poin_yellow ??
                                    '0'
                                }}
                            </div>
                            <div
                                :class="[
                                    'py-3',
                                    activeRoundRecap?.jury_four_winner ===
                                    'blue'
                                        ? 'bg-blue-600 text-white shadow-inner'
                                        : 'bg-black text-white',
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

                <!-- Control Bar (Bottom Fix) -->
                <div
                    class="z-20 flex h-[72px] w-full shrink-0 items-center border-t border-stone-800 bg-zinc-900 px-6"
                >
                    <!-- Left Column: Rounds -->
                    <div class="flex flex-1 items-center justify-start gap-2">
                        <Button
                            size="sm"
                            :variant="
                                currentMatchDetail.round_number == 1
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="currentMatchDetail.status !== 'paused'"
                            @click="setRound(1)"
                            :class="[
                                'h-10 w-12 font-black disabled:cursor-not-allowed disabled:opacity-100',
                                currentMatchDetail.round_number == 1
                                    ? 'bg-green-500 text-white hover:bg-green-600'
                                    : 'text-foreground hover:bg-zinc-800',
                            ]"
                            >1</Button
                        >
                        <Button
                            size="sm"
                            :variant="
                                currentMatchDetail.round_number == 2
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="currentMatchDetail.status !== 'paused'"
                            @click="setRound(2)"
                            :class="[
                                'h-10 w-12 font-black disabled:cursor-not-allowed disabled:opacity-100',
                                currentMatchDetail.round_number == 2
                                    ? 'bg-green-500 text-white hover:bg-green-600'
                                    : 'text-foreground hover:bg-zinc-800',
                            ]"
                            >2</Button
                        >
                        <Button
                            size="sm"
                            :variant="
                                currentMatchDetail.round_number == 3
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="currentMatchDetail.status !== 'paused'"
                            @click="setRound(3)"
                            :class="[
                                'h-10 w-12 font-black disabled:cursor-not-allowed disabled:opacity-100',
                                currentMatchDetail.round_number == 3
                                    ? 'bg-green-500 text-white hover:bg-green-600'
                                    : 'text-foreground hover:bg-zinc-800',
                            ]"
                            >TBH</Button
                        >
                    </div>

                    <!-- Center Column: Core Controls -->
                    <div class="flex flex-1 items-center justify-center gap-3">
                        <Button
                            v-if="
                                ['not_started', 'done'].includes(
                                    currentMatchDetail.status,
                                )
                            "
                            class="bg-blue-600 px-8 font-bold tracking-widest text-white hover:bg-blue-700"
                            @click="setStatus('ongoing')"
                        >
                            START
                        </Button>
                        <Button
                            v-if="currentMatchDetail.status === 'ongoing'"
                            class="bg-yellow-500 px-8 font-bold tracking-widest text-black hover:bg-yellow-600"
                            @click="triggerPause()"
                        >
                            PAUSE
                        </Button>
                        <Button
                            v-if="currentMatchDetail.status === 'paused'"
                            class="bg-blue-600 px-8 font-bold tracking-widest text-white hover:bg-blue-700"
                            @click="triggerResume()"
                        >
                            RESUME
                        </Button>

                        <!-- Keputusan (Only when paused AND can show keputusan logic valid) -->
                        <Button
                            v-if="
                                canShowKeputusan &&
                                currentMatchDetail.status === 'paused'
                            "
                            class="bg-green-500 font-bold tracking-wider text-white hover:bg-green-600"
                            @click="triggerKeputusan()"
                        >
                            KEPUTUSAN
                        </Button>

                        <!-- Reset (When paused or done) -->
                        <Button
                            v-if="
                                ['paused', 'done'].includes(
                                    currentMatchDetail.status,
                                )
                            "
                            variant="destructive"
                            class="font-bold tracking-wider"
                            @click="triggerReset()"
                            :disabled="isResetting"
                        >
                            {{ isResetting ? 'RESETTING...' : 'RESET' }}
                        </Button>
                    </div>

                    <!-- Right Column: Navigation -->
                    <div class="flex flex-1 items-center justify-end gap-2">
                        <Button
                            variant="outline"
                            :disabled="
                                !prevMatch ||
                                !['not_started', 'done'].includes(
                                    currentMatchDetail.status,
                                )
                            "
                            @click="openMatch(prevMatch)"
                            class="px-4 font-bold"
                        >
                            <span class="mr-2 text-lg leading-none"
                                >&larr;</span
                            >
                            Prev
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="
                                !nextMatch ||
                                !['not_started', 'done'].includes(
                                    currentMatchDetail.status,
                                )
                            "
                            @click="openMatch(nextMatch)"
                            class="px-4 font-bold"
                        >
                            Next
                            <span class="ml-2 text-lg leading-none"
                                >&rarr;</span
                            >
                        </Button>
                    </div>
                </div>
            </template>
            <template v-else>
                <FightWaitingState
                    clickable
                    :on-logo-click="triggerFullscreen"
                />
            </template>
        </div>

        <!-- Setup Dialog -->
        <Dialog
            :open="isSetupDialogOpen"
            @update:open="isSetupDialogOpen = $event"
        >
            <DialogContent class="overflow-hidden sm:max-w-[480px]">
                <DialogHeader class="pb-4">
                    <DialogTitle class="text-xl font-bold text-primary"
                        >Setup Gelanggang</DialogTitle
                    >
                    <DialogDescription class="text-sm text-muted-foreground">
                        Pilih gelanggang dan sesi tanding yang akan digunakan.
                    </DialogDescription>
                </DialogHeader>

                <Separator />

                <div class="grid gap-6 py-2">
                    <div class="grid gap-2">
                        <Label
                            class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                            >Pilih Gelanggang</Label
                        >
                        <Select
                            v-model="selectedGelanggang"
                            @update:modelValue="handleGelanggangChange"
                        >
                            <SelectTrigger class="h-10 w-full">
                                <SelectValue
                                    placeholder="Memuat gelanggang..."
                                    v-if="gelanggangList.length === 0"
                                />
                                <SelectValue
                                    placeholder="Pilih Gelanggang"
                                    v-else
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="g in gelanggangList"
                                    :key="g.id"
                                    :value="g.id.toString()"
                                    class="cursor-pointer"
                                >
                                    Gelanggang
                                    {{
                                        g.nama_gelanggang ??
                                        'Gelanggang ' + g.id
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label
                            class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                            >Pilih Sesi Tanding</Label
                        >
                        <Select
                            v-model="selectedSesi"
                            :disabled="!selectedGelanggang"
                        >
                            <SelectTrigger class="h-10 w-full">
                                <SelectValue
                                    placeholder="Silakan pilih gelanggang"
                                    v-if="!selectedGelanggang"
                                />
                                <SelectValue placeholder="Pilih Sesi" v-else />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="s in sesiList"
                                    :key="s.id"
                                    :value="s.id.toString()"
                                    class="cursor-pointer"
                                >
                                    {{ s.sesi ?? 'Sesi ' + s.id }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <Separator />

                <DialogFooter class="pt-2">
                    <Button variant="ghost" @click="isSetupDialogOpen = false">
                        Batal
                    </Button>
                    <Button
                        @click="saveSetup"
                        :disabled="!selectedSesi || isLoading"
                    >
                        {{ isLoading ? 'Menyimpan...' : 'Simpan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Sync Confirm Dialog -->
        <Dialog
            :open="isConfirmDialogOpen"
            @update:open="isConfirmDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-[400px]">
                <DialogHeader class="pb-2">
                    <DialogTitle class="text-xl font-bold text-primary"
                        >Muat Data Partai</DialogTitle
                    >
                    <DialogDescription
                        class="pt-2 text-sm text-muted-foreground"
                    >
                        Anda yakin ingin memuat Data Partai
                        <span class="font-bold text-foreground">{{
                            selectedMatch?.match_code
                        }}</span
                        >? Ini akan mengganti status pertandingan saat ini.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="pt-2">
                    <Button
                        variant="ghost"
                        @click="isConfirmDialogOpen = false"
                    >
                        Batal
                    </Button>
                    <Button @click="syncMatch" :disabled="isSyncing">
                        <RefreshCw
                            v-if="isSyncing"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        Ya, Muat Data
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Disqualification Dialog -->
        <Dialog
            :open="isDisqualificationDialogOpen"
            @update:open="isDisqualificationDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader class="pb-2">
                    <DialogTitle class="text-xl font-bold text-primary"
                        >Diskualifikasi Atlet</DialogTitle
                    >
                    <DialogDescription
                        class="pt-2 text-sm text-muted-foreground"
                    >
                        Pilih atlet yang akan didiskualifikasi.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-3 py-2">
                    <Label
                        class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                        >Pilih Atlet Diskualifikasi</Label
                    >
                    <div class="grid grid-cols-2 gap-3">
                        <div
                            @click="
                                !isSavingDisqualification &&
                                (selectedDisqualifiedCorner = 'yellow')
                            "
                            :class="[
                                'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 text-center transition-all',
                                selectedDisqualifiedCorner === 'yellow'
                                    ? 'border-yellow-500 bg-yellow-500/20'
                                    : 'border-stone-800 hover:border-yellow-500/50',
                                isSavingDisqualification
                                    ? 'pointer-events-none opacity-60'
                                    : '',
                            ]"
                        >
                            <div
                                :class="[
                                    'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                    selectedDisqualifiedCorner === 'yellow'
                                        ? 'border-yellow-500 bg-yellow-500/20'
                                        : 'border-stone-600',
                                ]"
                            >
                                <div
                                    v-if="
                                        selectedDisqualifiedCorner === 'yellow'
                                    "
                                    class="h-2 w-2 rounded-full bg-yellow-500"
                                ></div>
                            </div>
                            <span
                                class="text-xs font-black tracking-wider text-yellow-500 uppercase"
                                >Kuning</span
                            >
                            <span
                                class="mt-1 text-sm leading-snug font-bold text-foreground uppercase"
                                >{{
                                    currentMatchDetail?.atlete_yellow || '-'
                                }}</span
                            >
                        </div>

                        <div
                            @click="
                                !isSavingDisqualification &&
                                (selectedDisqualifiedCorner = 'blue')
                            "
                            :class="[
                                'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 text-center transition-all',
                                selectedDisqualifiedCorner === 'blue'
                                    ? 'border-blue-500 bg-blue-500/20'
                                    : 'border-stone-800 hover:border-blue-500/50',
                                isSavingDisqualification
                                    ? 'pointer-events-none opacity-60'
                                    : '',
                            ]"
                        >
                            <div
                                :class="[
                                    'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                    selectedDisqualifiedCorner === 'blue'
                                        ? 'border-blue-500 bg-blue-500/20'
                                        : 'border-stone-600',
                                ]"
                            >
                                <div
                                    v-if="selectedDisqualifiedCorner === 'blue'"
                                    class="h-2 w-2 rounded-full bg-blue-500"
                                ></div>
                            </div>
                            <span
                                class="text-xs font-black tracking-wider text-blue-400 uppercase"
                                >Biru</span
                            >
                            <span
                                class="mt-1 text-sm leading-snug font-bold text-foreground uppercase"
                                >{{
                                    currentMatchDetail?.atlete_blue || '-'
                                }}</span
                            >
                        </div>
                    </div>
                </div>

                <DialogFooter class="pt-2">
                    <Button
                        variant="ghost"
                        @click="isDisqualificationDialogOpen = false"
                        :disabled="isSavingDisqualification"
                    >
                        Batal
                    </Button>
                    <Button
                        variant="destructive"
                        @click="saveDisqualification"
                        :disabled="
                            !selectedDisqualifiedCorner ||
                            isSavingDisqualification
                        "
                    >
                        <RefreshCw
                            v-if="isSavingDisqualification"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{
                            isSavingDisqualification ? 'Menyimpan...' : 'Simpan'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Reset Confirm Dialog -->
        <Dialog
            :open="isResetDialogOpen"
            @update:open="isResetDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-[400px]">
                <DialogHeader class="pb-2">
                    <DialogTitle class="text-xl font-bold text-primary"
                        >Reset Pertandingan ?</DialogTitle
                    >
                    <DialogDescription
                        class="pt-2 text-sm text-muted-foreground"
                    >
                        Ini akan menghapus penilaian pada partai ini.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="pt-2">
                    <Button
                        variant="ghost"
                        @click="isResetDialogOpen = false"
                        :disabled="isResetting"
                    >
                        Batal
                    </Button>
                    <Button
                        variant="destructive"
                        @click="confirmReset"
                        :disabled="isResetting"
                    >
                        <RefreshCw
                            v-if="isResetting"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        {{ isResetting ? 'Mereset...' : 'Ya' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Winner Selection Dialog -->
        <Dialog
            :open="isWinnerDialogOpen"
            @update:open="
                (val) => {
                    if (val) isWinnerDialogOpen = val;
                }
            "
        >
            <DialogContent
                class="sm:max-w-[400px]"
                :showCloseButton="false"
                @interact-outside.prevent
                @escape-keydown.prevent
            >
                <DialogHeader
                    class="flex flex-col items-center pb-2 text-center"
                >
                    <DialogTitle
                        class="text-xl font-black tracking-widest text-primary uppercase"
                        >Pilih Pemenang Ronde</DialogTitle
                    >
                    <DialogDescription
                        class="pt-2 text-sm text-muted-foreground"
                    >
                        Berdasarkan keputusan Pembantu Wasit di ronde ini, kami
                        menyarankan:
                    </DialogDescription>
                    <Badge
                        class="mt-3 px-6 py-1.5 text-sm font-black tracking-widest uppercase"
                        :class="[
                            suggestedWinner === 'yellow'
                                ? 'bg-yellow-500 text-black'
                                : suggestedWinner === 'blue'
                                  ? 'bg-blue-600 text-white'
                                  : 'bg-stone-500 text-white',
                        ]"
                    >
                        {{
                            suggestedWinner === 'yellow'
                                ? 'Kuning'
                                : suggestedWinner === 'blue'
                                  ? 'Biru'
                                  : 'Seri'
                        }}
                    </Badge>
                </DialogHeader>

                <div class="grid grid-cols-3 gap-3 py-4">
                    <div
                        @click="selectedRoundWinner = 'yellow'"
                        :class="[
                            'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 transition-all',
                            selectedRoundWinner === 'yellow'
                                ? 'border-yellow-500 bg-yellow-500/20'
                                : 'border-stone-800 hover:border-yellow-500/50',
                        ]"
                    >
                        <div
                            :class="[
                                'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                selectedRoundWinner === 'yellow'
                                    ? 'border-yellow-500 bg-yellow-500/20'
                                    : 'border-stone-600',
                            ]"
                        >
                            <div
                                v-if="selectedRoundWinner === 'yellow'"
                                class="h-2 w-2 rounded-full bg-yellow-500"
                            ></div>
                        </div>
                        <span class="font-bold text-yellow-500">Kuning</span>
                    </div>

                    <div
                        @click="selectedRoundWinner = 'draw'"
                        :class="[
                            'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 transition-all',
                            selectedRoundWinner === 'draw'
                                ? 'border-stone-400 bg-stone-500/20'
                                : 'border-stone-800 hover:border-stone-500/50',
                        ]"
                    >
                        <div
                            :class="[
                                'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                selectedRoundWinner === 'draw'
                                    ? 'border-stone-400 bg-stone-500/20'
                                    : 'border-stone-600',
                            ]"
                        >
                            <div
                                v-if="selectedRoundWinner === 'draw'"
                                class="h-2 w-2 rounded-full bg-stone-400"
                            ></div>
                        </div>
                        <span class="font-bold text-stone-300">Seri</span>
                    </div>

                    <div
                        @click="selectedRoundWinner = 'blue'"
                        :class="[
                            'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 transition-all',
                            selectedRoundWinner === 'blue'
                                ? 'border-blue-500 bg-blue-600/20'
                                : 'border-stone-800 hover:border-blue-500/50',
                        ]"
                    >
                        <div
                            :class="[
                                'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                selectedRoundWinner === 'blue'
                                    ? 'border-blue-500 bg-blue-600/20'
                                    : 'border-stone-600',
                            ]"
                        >
                            <div
                                v-if="selectedRoundWinner === 'blue'"
                                class="h-2 w-2 rounded-full bg-blue-500"
                            ></div>
                        </div>
                        <span class="font-bold text-blue-400">Biru</span>
                    </div>
                </div>

                <DialogFooter class="flex w-full gap-2 pt-2">
                    <Button
                        variant="ghost"
                        @click="cancelPause"
                        class="flex-1 font-black tracking-widest uppercase"
                        :disabled="isSavingWinner"
                    >
                        Batal
                    </Button>
                    <Button
                        @click="saveRoundWinner"
                        class="flex-1 bg-green-500 font-black tracking-widest text-white uppercase hover:bg-green-600"
                        :disabled="isSavingWinner"
                    >
                        {{
                            isSavingWinner ? 'Menyimpan...' : 'Simpan Pemenang'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Match Winner Selection Dialog -->
        <Dialog
            :open="isMatchWinnerDialogOpen"
            @update:open="
                (val) => {
                    if (val) isMatchWinnerDialogOpen = val;
                }
            "
        >
            <DialogContent
                class="sm:max-w-[500px]"
                :showCloseButton="false"
                @interact-outside.prevent
                @escape-keydown.prevent
            >
                <DialogHeader
                    class="flex flex-col items-center pb-2 text-center"
                >
                    <DialogTitle
                        class="text-xl font-black tracking-widest text-primary uppercase"
                        >Keputusan Pemenang Partai</DialogTitle
                    >
                    <DialogDescription
                        class="pt-2 text-sm text-muted-foreground"
                    >
                        Berdasarkan poin dan aturan Tapak Suci, pemenang
                        pertandingan ini jatuh pada sudut:
                    </DialogDescription>
                    <Badge
                        class="mt-3 px-6 py-1.5 text-sm font-black tracking-widest uppercase"
                        :class="[
                            suggestedMatchWinner === 'yellow'
                                ? 'bg-yellow-500 text-black'
                                : suggestedMatchWinner === 'blue'
                                  ? 'bg-blue-600 text-white'
                                  : 'bg-stone-500 text-white',
                        ]"
                    >
                        {{
                            suggestedMatchWinner === 'yellow'
                                ? 'Kuning'
                                : suggestedMatchWinner === 'blue'
                                  ? 'Biru'
                                  : 'Seri'
                        }}
                    </Badge>
                </DialogHeader>

                <div class="space-y-4">
                    <div>
                        <h4 class="mb-2 text-sm font-bold">Pilih Pemenang</h4>
                        <div class="grid grid-cols-3 gap-3">
                            <div
                                @click="selectedMatchWinner = 'yellow'"
                                :class="[
                                    'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 transition-all',
                                    selectedMatchWinner === 'yellow'
                                        ? 'border-yellow-500 bg-yellow-500/20'
                                        : 'border-stone-800 hover:border-yellow-500/50',
                                ]"
                            >
                                <div
                                    :class="[
                                        'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                        selectedMatchWinner === 'yellow'
                                            ? 'border-yellow-500 bg-yellow-500/20'
                                            : 'border-stone-600',
                                    ]"
                                >
                                    <div
                                        v-if="selectedMatchWinner === 'yellow'"
                                        class="h-2 w-2 rounded-full bg-yellow-500"
                                    ></div>
                                </div>
                                <span class="font-bold text-yellow-500"
                                    >Kuning</span
                                >
                            </div>

                            <div
                                @click="selectedMatchWinner = 'draw'"
                                :class="[
                                    'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 transition-all',
                                    selectedMatchWinner === 'draw'
                                        ? 'border-stone-400 bg-stone-500/20'
                                        : 'border-stone-800 hover:border-stone-500/50',
                                ]"
                            >
                                <div
                                    :class="[
                                        'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                        selectedMatchWinner === 'draw'
                                            ? 'border-stone-400 bg-stone-500/20'
                                            : 'border-stone-600',
                                    ]"
                                >
                                    <div
                                        v-if="selectedMatchWinner === 'draw'"
                                        class="h-2 w-2 rounded-full bg-stone-400"
                                    ></div>
                                </div>
                                <span class="font-bold text-stone-400"
                                    >Seri</span
                                >
                            </div>

                            <div
                                @click="selectedMatchWinner = 'blue'"
                                :class="[
                                    'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-4 transition-all',
                                    selectedMatchWinner === 'blue'
                                        ? 'border-blue-500 bg-blue-500/20'
                                        : 'border-stone-800 hover:border-blue-500/50',
                                ]"
                            >
                                <div
                                    :class="[
                                        'mb-2 flex h-4 w-4 items-center justify-center rounded-full border',
                                        selectedMatchWinner === 'blue'
                                            ? 'border-blue-500 bg-blue-500/20'
                                            : 'border-stone-600',
                                    ]"
                                >
                                    <div
                                        v-if="selectedMatchWinner === 'blue'"
                                        class="h-2 w-2 rounded-full bg-blue-500"
                                    ></div>
                                </div>
                                <span class="font-bold text-blue-400"
                                    >Biru</span
                                >
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="mb-2 text-sm font-bold">
                            Status Kemenangan
                        </h4>
                        <div class="grid grid-cols-2 gap-2">
                            <div
                                v-for="status in [
                                    {
                                        val: 'menang_angka',
                                        label: 'Menang Angka',
                                    },
                                    {
                                        val: 'menang_teknik',
                                        label: 'Menang Teknik',
                                    },
                                    {
                                        val: 'menang_mutlak',
                                        label: 'Menang Mutlak',
                                    },
                                    { val: 'menang_wmp', label: 'Menang WMP' },
                                    {
                                        val: 'menang_undur_diri',
                                        label: 'Menang Undur Diri',
                                    },
                                    {
                                        val: 'menang_diskualifikasi',
                                        label: 'Menang Diskualifikasi',
                                    },
                                ]"
                                :key="status.val"
                                @click="selectedWinnerStatus = status.val"
                                :class="[
                                    'flex cursor-pointer items-center gap-3 rounded-lg border border-stone-800 p-3 transition-all',
                                    selectedWinnerStatus === status.val
                                        ? 'border-primary bg-primary/10'
                                        : 'hover:border-stone-600',
                                ]"
                            >
                                <div
                                    :class="[
                                        'flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full border',
                                        selectedWinnerStatus === status.val
                                            ? 'border-primary'
                                            : 'border-stone-600',
                                    ]"
                                >
                                    <div
                                        v-if="
                                            selectedWinnerStatus === status.val
                                        "
                                        class="h-2 w-2 rounded-full bg-primary"
                                    ></div>
                                </div>
                                <span
                                    :class="[
                                        'text-sm font-semibold',
                                        selectedWinnerStatus === status.val
                                            ? 'text-primary'
                                            : 'text-stone-300',
                                    ]"
                                    >{{ status.label }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <DialogFooter class="flex w-full gap-2 pt-4">
                    <Button
                        variant="ghost"
                        @click="cancelMatchWinner"
                        class="flex-1 font-black tracking-widest uppercase"
                        :disabled="isSavingMatchWinner"
                    >
                        Batal
                    </Button>
                    <Button
                        @click="saveMatchWinner"
                        class="flex-1 bg-green-500 font-black tracking-widest text-white uppercase hover:bg-green-600"
                        :disabled="isSavingMatchWinner"
                    >
                        {{
                            isSavingMatchWinner
                                ? 'Menyimpan & Mensinkronisasi...'
                                : 'Simpan & Akhiri Partai'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: hsl(var(--border));
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: hsl(var(--muted-foreground));
}
</style>
