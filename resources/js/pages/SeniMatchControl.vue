<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import FightFullscreenButton from '@/components/fight/FightFullscreenButton.vue';
import FightWaitingState from '@/components/fight/FightWaitingState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import { useFullscreenLock } from '@/composables/useFullscreenLock';

type SeniPool = {
    id: number;
    no_pool_babak_id: number;
    round_match: string | null;
    group: string | null;
    category: string | null;
    no_pool: string | null;
};

type SeniMatch = {
    id: number;
    bkp_id: number | null;
    matches_code: string | null;
    atletes: string | null;
    contingent: string | null;
    type: string | null;
    category: string | null;
    group: string | null;
    status: string;
    is_active: boolean;
    is_disqualified: boolean;
    is_passed: boolean;
    round_match: string | null;
    no_order: number | null;
    total_score: string | null;
    total_wiraga: string | null;
    total_wirasa: string | null;
    total_wirama: string | null;
    total_kualitas_teknik: string | null;
    total_kuantitas_teknik: string | null;
    total_ketangkasan: string | null;
    total_stamina: string | null;
    total_kemantapan: string | null;
    total_musik: string | null;
    total_punishment: string | null;
    time: number | null;
    rank: number | null;
};

const props = defineProps<{
    pools: SeniPool[];
    matches: SeniMatch[];
    activePool: SeniPool | null;
    arena: any;
}>();

const pools = ref<SeniPool[]>(props.pools);
const selectedPool = ref<SeniPool | null>(props.activePool);
const pendingPool = ref<SeniPool | null>(null);
const pendingMatch = ref<SeniMatch | null>(null);
const currentMatches = ref<SeniMatch[]>(props.matches ?? []);

const isSetupDialogOpen = ref(false);
const isLoading = ref(false);
const isRefreshing = ref(false);
const isConfirmDialogOpen = ref(false);
const isMatchConfirmDialogOpen = ref(false);
const isSyncing = ref(false);
const isActivatingMatch = ref(false);
const isUpdatingStatus = ref(false);

const gelanggangList = ref<any[]>([]);
const sesiList = ref<any[]>([]);
const selectedGelanggang = ref('');
const selectedSesi = ref('');

const {
    buttonTitle,
    exitClickCount,
    isFullscreen,
    remainingExitClicks,
    requiredExitClicks,
    triggerFullscreen,
} = useFullscreenLock();

const activePoolTitle = computed(() => {
    if (!selectedPool.value) {
        return 'Pilih Pool Seni';
    }

    return [
        `Pool ${selectedPool.value.no_pool ?? selectedPool.value.no_pool_babak_id}`,
        selectedPool.value.round_match,
        selectedPool.value.group,
        selectedPool.value.category,
    ]
        .filter(Boolean)
        .join(' ');
});

const pendingPoolTitle = computed(() => {
    if (!pendingPool.value) {
        return activePoolTitle.value;
    }

    return [
        `Pool ${pendingPool.value.no_pool ?? pendingPool.value.no_pool_babak_id}`,
        pendingPool.value.round_match,
        pendingPool.value.group,
        pendingPool.value.category,
    ]
        .filter(Boolean)
        .join(' ');
});

const activeMatch = computed(() => {
    return currentMatches.value.find((match) => match.is_active) ?? null;
});

const hasLockedMatch = computed(() => {
    return currentMatches.value.some((match) =>
        ['ongoing', 'paused'].includes(match.status),
    );
});

const pendingMatchTitle = computed(() => {
    if (!pendingMatch.value) {
        return '-';
    }

    return [
        `Partai ${pendingMatch.value.matches_code ?? pendingMatch.value.bkp_id}`,
        pendingMatch.value.atletes,
        pendingMatch.value.contingent,
    ]
        .filter(Boolean)
        .join(' - ');
});

const isTechniqueMatch = (match: SeniMatch | null | undefined) => {
    const matchText = [
        match?.type,
        match?.category,
        match?.group,
        selectedPool.value?.category,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return matchText.includes('ganda') || matchText.includes('trio');
};

const scoringMode = computed<'tgr' | 'technique'>(() => {
    const poolText = [
        selectedPool.value?.category,
        selectedPool.value?.group,
        currentMatches.value[0]?.type,
        currentMatches.value[0]?.category,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    if (poolText.includes('ganda') || poolText.includes('trio')) {
        return 'technique';
    }

    return 'tgr';
});

const activeScoreRows = computed(() => {
    const match = activeMatch.value;

    if (!match) {
        return [];
    }

    if (isTechniqueMatch(match)) {
        return [
            { label: 'Kualitas Teknik', value: match.total_kualitas_teknik },
            { label: 'Kuantitas Teknik', value: match.total_kuantitas_teknik },
            { label: 'Ketangkasan', value: match.total_ketangkasan },
            { label: 'Stamina', value: match.total_stamina },
            { label: 'Kemantapan', value: match.total_kemantapan },
            { label: 'Musik', value: match.total_musik },
        ];
    }

    return [
        { label: 'Wiraga', value: match.total_wiraga },
        { label: 'Wirasa', value: match.total_wirasa },
        { label: 'Wirama', value: match.total_wirama },
    ];
});

const fetchGelanggang = async () => {
    try {
        const response = await axios.get('/api/source/gelanggang');

        if (response.data?.data) {
            gelanggangList.value = response.data.data;
        }
    } catch (e) {
        console.error('Failed to fetch gelanggang', e);
        toast.error('Gagal memuat gelanggang.');
    }
};

const handleGelanggangChange = async (val: any, retainSesi = false) => {
    if (!val) {
        return;
    }

    selectedGelanggang.value = val.toString();

    if (!retainSesi) {
        selectedSesi.value = '';
    }

    sesiList.value = [];

    try {
        const response = await axios.get(`/api/seni/source/sesi/${val}`);

        if (response.data?.data) {
            sesiList.value = response.data.data;

            if (retainSesi && props.arena?.sesi_seni_id) {
                selectedSesi.value = props.arena.sesi_seni_id.toString();
            }
        }
    } catch (e) {
        console.error('Failed to fetch sesi seni', e);
        toast.error('Gagal memuat sesi seni.');
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
    if (!selectedGelanggang.value || !selectedSesi.value) {
        return;
    }

    isLoading.value = true;

    try {
        const selectedArena = gelanggangList.value.find(
            (item: any) => item.id.toString() === selectedGelanggang.value,
        );

        const response = await axios.post('/api/seni/arena/setup', {
            gelanggang_id: selectedGelanggang.value,
            sesi_seni_id: selectedSesi.value,
            arena_name: selectedArena ? selectedArena.nama_gelanggang : null,
        });

        pools.value = response.data?.data ?? [];
        selectedPool.value = null;
        currentMatches.value = [];
        isSetupDialogOpen.value = false;
        router.reload({ only: ['pools', 'arena'] });
        toast.success('Pool seni berhasil dimuat.');
    } catch (e) {
        console.error('Failed to setup seni arena', e);
        toast.error('Gagal setup gelanggang seni.');
    } finally {
        isLoading.value = false;
    }
};

const refreshPools = async () => {
    if (!props.arena?.gelanggang_id || !props.arena?.sesi_seni_id) {
        return;
    }

    isRefreshing.value = true;

    try {
        const response = await axios.post('/api/seni/arena/setup', {
            gelanggang_id: props.arena.gelanggang_id,
            sesi_seni_id: props.arena.sesi_seni_id,
            arena_name: props.arena.arena_name ?? null,
        });

        pools.value = response.data?.data ?? [];
        selectedPool.value = null;
        currentMatches.value = [];
        router.reload({ only: ['pools', 'arena'] });
        toast.success('Pool seni berhasil diperbarui.');
    } catch (e) {
        console.error('Failed to refresh seni pools', e);
        toast.error('Gagal refresh pool seni.');
    } finally {
        isRefreshing.value = false;
    }
};

const openPoolConfirm = (pool: SeniPool) => {
    if (hasLockedMatch.value) {
        toast.error('Selesaikan partai aktif sebelum mengganti pool.');

        return;
    }

    pendingPool.value = pool;
    isConfirmDialogOpen.value = true;
};

const openReloadConfirm = () => {
    if (hasLockedMatch.value) {
        toast.error('Selesaikan partai aktif sebelum memuat ulang pool.');

        return;
    }

    pendingPool.value = selectedPool.value;
    isConfirmDialogOpen.value = true;
};

const setConfirmDialogOpen = (isOpen: boolean) => {
    isConfirmDialogOpen.value = isOpen;

    if (!isOpen && !isSyncing.value) {
        pendingPool.value = null;
    }
};

const syncPoolMatches = async () => {
    if (!pendingPool.value) {
        return;
    }

    isSyncing.value = true;

    try {
        const response = await axios.post(
            `/api/seni/pools/${pendingPool.value.id}/sync-matches`,
        );

        selectedPool.value = pendingPool.value;
        currentMatches.value = response.data?.data ?? [];
        isConfirmDialogOpen.value = false;
        pendingPool.value = null;
        toast.success('Data partai pool berhasil dimuat.');
    } catch (e) {
        console.error('Failed to sync seni pool matches', e);
        toast.error('Gagal mengambil data partai pool.');
    } finally {
        isSyncing.value = false;
    }
};

const openMatchConfirm = (match: SeniMatch) => {
    if (hasLockedMatch.value && !match.is_active) {
        toast.error(
            'Selesaikan partai yang berlangsung sebelum mengganti partai.',
        );

        return;
    }

    pendingMatch.value = match;
    isMatchConfirmDialogOpen.value = true;
};

const setMatchConfirmDialogOpen = (isOpen: boolean) => {
    isMatchConfirmDialogOpen.value = isOpen;

    if (!isOpen && !isActivatingMatch.value) {
        pendingMatch.value = null;
    }
};

const activatePendingMatch = async () => {
    if (!pendingMatch.value) {
        return;
    }

    isActivatingMatch.value = true;

    try {
        const response = await axios.post(
            `/api/seni/matches/${pendingMatch.value.id}/activate`,
        );

        selectedPool.value = response.data?.pool ?? selectedPool.value;
        currentMatches.value = response.data?.matches ?? currentMatches.value;
        isMatchConfirmDialogOpen.value = false;
        pendingMatch.value = null;
        toast.success('Data partai seni berhasil dimuat.');
    } catch (e) {
        console.error('Failed to activate seni match', e);
        toast.error('Gagal memuat detail partai seni.');
    } finally {
        isActivatingMatch.value = false;
    }
};

const updateActiveMatchStatus = async (status: string) => {
    if (!activeMatch.value) {
        return;
    }

    isUpdatingStatus.value = true;

    try {
        const response = await axios.post(
            `/api/seni/matches/${activeMatch.value.id}/status`,
            { status },
        );

        selectedPool.value = response.data?.pool ?? selectedPool.value;
        currentMatches.value = response.data?.matches ?? currentMatches.value;
        toast.success('Status partai seni berhasil diperbarui.');
    } catch (e) {
        console.error('Failed to update seni match status', e);
        toast.error('Gagal memperbarui status partai seni.');
    } finally {
        isUpdatingStatus.value = false;
    }
};

const scoreValue = (value: string | number | null | undefined) => {
    return value ?? '-';
};

const formatTime = (value: string | number | null | undefined) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    const seconds = Number(value);

    if (Number.isNaN(seconds)) {
        return value;
    }

    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = Math.max(0, Math.floor(seconds % 60));

    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
};
</script>

<template>
    <Head title="Seni Match Control - Tapak Suci" />
    <Toaster rich-colors />

    <div class="flex h-screen overflow-hidden bg-background text-foreground">
        <div
            class="z-10 flex h-full w-92 shrink-0 flex-col border-r border-border bg-zinc-900 shadow-xl"
        >
            <div class="flex flex-col border-b border-stone-700 p-6">
                <Button @click="openSetup" class="w-full">
                    Pilih Gelanggang Seni
                </Button>
                <h2
                    v-if="props.arena?.gelanggang_id"
                    class="mt-2 text-center text-sm font-bold tracking-tight text-yellow-500"
                >
                    Gelanggang
                    {{
                        props.arena.arena_name ??
                        'Gelanggang ' + props.arena.gelanggang_id
                    }}
                </h2>
                <h2
                    v-if="props.arena?.sesi_seni_id"
                    class="text-center text-sm font-bold tracking-tight text-green-500"
                >
                    Sesi Seni ID {{ props.arena.sesi_seni_id }}
                </h2>
            </div>

            <div
                v-if="props.arena?.gelanggang_id"
                class="flex items-center justify-between border-b border-stone-700 px-4 py-3"
            >
                <div>
                    <h3 class="text-sm font-semibold tracking-tight">
                        Pool Seni
                    </h3>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        Jumlah Pool:
                        <span class="font-bold text-foreground">{{
                            pools.length
                        }}</span>
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="refreshPools"
                    :disabled="isRefreshing"
                    class="text-muted-foreground hover:text-foreground"
                >
                    <RefreshCw
                        :class="['h-4 w-4', isRefreshing ? 'animate-spin' : '']"
                    />
                    Refresh
                </Button>
            </div>

            <div class="custom-scrollbar flex-1 space-y-3 overflow-y-auto p-4">
                <div
                    v-if="pools.length === 0"
                    class="mt-10 text-center text-sm text-muted-foreground"
                >
                    <p>Belum ada pool seni.</p>
                    <p class="mt-1 text-xs">
                        Silakan set gelanggang dan sesi seni terlebih dahulu.
                    </p>
                </div>

                <Card
                    v-for="pool in pools"
                    :key="pool.id"
                    @click="openPoolConfirm(pool)"
                    :class="[
                        'group gap-0 overflow-hidden py-0 transition-all hover:border-yellow-500/30 hover:bg-zinc-800/50',
                        hasLockedMatch
                            ? 'cursor-not-allowed opacity-70'
                            : 'cursor-pointer',
                        selectedPool?.id === pool.id
                            ? 'border-yellow-500/50 bg-yellow-500/10 shadow-[0_0_15px_rgba(234,179,8,0.15)]'
                            : '',
                    ]"
                >
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-2 px-4 pt-3"
                    >
                        <Badge
                            class="mb-2 shrink-0 border-gray-400/25 bg-gray-400/15 text-[11px] font-bold tracking-wider text-gray-400 uppercase hover:bg-gray-400/15"
                        >
                            Pool {{ pool.no_pool ?? pool.no_pool_babak_id }}
                        </Badge>
                        <Badge
                            class="shrink-0 border-yellow-500/25 bg-yellow-500/15 text-[11px] font-semibold tracking-wider text-yellow-400 uppercase"
                        >
                            {{ pool.round_match ?? '-' }}
                        </Badge>
                    </CardHeader>
                    <CardContent class="px-4 pt-0 pb-3">
                        <p
                            class="w-full text-center text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                        >
                            {{ pool.group ?? '-' }} - {{ pool.category ?? '-' }}
                        </p>
                        <p
                            class="mt-2 text-center text-xs text-muted-foreground"
                        >
                            No Pool Babak {{ pool.no_pool_babak_id }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div
            class="relative flex h-full flex-1 flex-col overflow-hidden bg-zinc-950"
        >
            <div
                class="z-10 flex h-16 w-full shrink-0 items-center justify-between border-b border-stone-800 bg-zinc-900 px-6"
            >
                <div class="h-9 w-9 shrink-0"></div>
                <h1 class="text-center text-xl font-black text-white uppercase">
                    {{ activePoolTitle }}
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

            <template v-if="selectedPool">
                <div class="z-10 flex flex-1 flex-col overflow-hidden">
                    <div
                        class="grid min-h-0 flex-1 grid-cols-[320px_minmax(0,1fr)] gap-6 overflow-hidden p-6"
                    >
                        <div class="min-h-0">
                            <Card
                                class="h-full gap-0 overflow-hidden border-stone-800 bg-zinc-900 py-0 shadow-lg"
                            >
                                <CardHeader
                                    class="border-b border-stone-800 bg-black/30 px-5 py-4"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="text-xs font-black tracking-widest text-green-400 uppercase"
                                            >
                                                Partai Aktif
                                            </p>
                                            <p
                                                class="mt-1 truncate text-sm font-bold text-white"
                                            >
                                                {{
                                                    activeMatch?.matches_code
                                                        ? 'Partai ' +
                                                          activeMatch.matches_code
                                                        : '-'
                                                }}
                                            </p>
                                        </div>
                                        <Badge
                                            v-if="activeMatch"
                                            class="shrink-0 border-green-500/25 bg-green-500/15 text-green-400 uppercase"
                                        >
                                            {{
                                                activeMatch.status.replace(
                                                    '_',
                                                    ' ',
                                                )
                                            }}
                                        </Badge>
                                    </div>
                                </CardHeader>

                                <CardContent
                                    v-if="activeMatch"
                                    class="custom-scrollbar flex h-full flex-col overflow-y-auto px-5 py-5"
                                >
                                    <div class="space-y-1">
                                        <p
                                            class="text-[11px] font-black tracking-widest text-muted-foreground uppercase"
                                        >
                                            Nama Atlet
                                        </p>
                                        <p
                                            class="text-lg leading-tight font-black text-white uppercase"
                                        >
                                            {{ activeMatch.atletes ?? '-' }}
                                        </p>
                                    </div>

                                    <div class="mt-5 space-y-1">
                                        <p
                                            class="text-[11px] font-black tracking-widest text-muted-foreground uppercase"
                                        >
                                            Kontingen
                                        </p>
                                        <p
                                            class="text-sm font-bold text-white uppercase"
                                        >
                                            {{ activeMatch.contingent ?? '-' }}
                                        </p>
                                    </div>

                                    <div
                                        class="mt-6 rounded-md border border-yellow-500/20 bg-yellow-500/10 p-4 text-center"
                                    >
                                        <p
                                            class="text-[11px] font-black tracking-widest text-yellow-400 uppercase"
                                        >
                                            Total Nilai
                                        </p>
                                        <p
                                            class="mt-1 text-5xl font-black text-yellow-400 tabular-nums"
                                        >
                                            {{
                                                scoreValue(
                                                    activeMatch.total_score,
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div class="mt-4 grid grid-cols-2 gap-3">
                                        <div
                                            class="rounded-md border border-stone-800 bg-black/30 p-3"
                                        >
                                            <p
                                                class="text-[10px] font-black tracking-widest text-muted-foreground uppercase"
                                            >
                                                Waktu
                                            </p>
                                            <p
                                                class="mt-1 text-xl font-black text-white tabular-nums"
                                            >
                                                {{
                                                    formatTime(activeMatch.time)
                                                }}
                                            </p>
                                        </div>
                                        <div
                                            class="rounded-md border border-stone-800 bg-black/30 p-3"
                                        >
                                            <p
                                                class="text-[10px] font-black tracking-widest text-muted-foreground uppercase"
                                            >
                                                Rank
                                            </p>
                                            <p
                                                class="mt-1 text-xl font-black text-white tabular-nums"
                                            >
                                                {{
                                                    scoreValue(activeMatch.rank)
                                                }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-2">
                                        <div
                                            v-for="row in activeScoreRows"
                                            :key="row.label"
                                            class="flex items-center justify-between gap-3 rounded-md border border-stone-800 bg-black/30 px-3 py-2"
                                        >
                                            <span
                                                class="text-[11px] font-black tracking-wider text-muted-foreground uppercase"
                                            >
                                                {{ row.label }}
                                            </span>
                                            <span
                                                class="font-black text-white tabular-nums"
                                            >
                                                {{ scoreValue(row.value) }}
                                            </span>
                                        </div>
                                    </div>
                                </CardContent>

                                <CardContent
                                    v-else
                                    class="flex h-full flex-col items-center justify-center px-5 py-8 text-center text-sm text-muted-foreground"
                                >
                                    <p>Belum ada partai aktif.</p>
                                    <p class="mt-1 text-xs">
                                        Pilih baris partai lalu muat data.
                                    </p>
                                </CardContent>
                            </Card>
                        </div>

                        <div class="flex min-h-0 flex-col overflow-hidden">
                            <div
                                v-if="currentMatches.length === 0"
                                class="flex flex-1 items-center justify-center text-center text-sm text-muted-foreground"
                            >
                                <div>
                                    <p>Data partai pool belum dimuat.</p>
                                    <Button
                                        class="mt-4"
                                        @click="openReloadConfirm"
                                    >
                                        Muat Data Pool
                                    </Button>
                                </div>
                            </div>

                            <div
                                v-else
                                class="custom-scrollbar flex-1 overflow-auto rounded-md border border-stone-800"
                            >
                                <table
                                    class="min-w-full border-collapse text-sm"
                                >
                                    <thead
                                        class="sticky top-0 z-10 border-b border-stone-800 bg-zinc-900 text-[11px] font-black tracking-wider text-muted-foreground uppercase"
                                    >
                                        <tr>
                                            <th class="px-3 py-3 text-left">
                                                No Urut
                                            </th>
                                            <th class="px-3 py-3 text-left">
                                                No Partai
                                            </th>
                                            <th
                                                class="min-w-44 px-3 py-3 text-left"
                                            >
                                                Kontingen
                                            </th>
                                            <th
                                                class="min-w-56 px-3 py-3 text-left"
                                            >
                                                Nama Atlet
                                            </th>
                                            <th class="px-3 py-3 text-right">
                                                Waktu
                                            </th>
                                            <th class="px-3 py-3 text-right">
                                                Rank
                                            </th>
                                            <th class="px-3 py-3 text-right">
                                                Total Nilai
                                            </th>
                                            <template
                                                v-if="scoringMode === 'tgr'"
                                            >
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Wiraga
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Wirasa
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Wirama
                                                </th>
                                            </template>
                                            <template v-else>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Kualitas Teknik
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Kuantitas Teknik
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Ketangkasan
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Stamina
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Kemantapan
                                                </th>
                                                <th
                                                    class="px-3 py-3 text-right"
                                                >
                                                    Musik
                                                </th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-800">
                                        <tr
                                            v-for="match in currentMatches"
                                            :key="match.id"
                                            @click="openMatchConfirm(match)"
                                            :class="[
                                                'text-white transition-colors',
                                                match.is_active
                                                    ? 'bg-green-500/15 ring-1 ring-green-500/40 ring-inset hover:bg-green-500/20'
                                                    : 'bg-zinc-950/60 hover:bg-zinc-900',
                                                hasLockedMatch &&
                                                !match.is_active
                                                    ? 'cursor-not-allowed opacity-70'
                                                    : 'cursor-pointer',
                                            ]"
                                        >
                                            <td class="px-3 py-3 font-bold">
                                                {{ scoreValue(match.no_order) }}
                                            </td>
                                            <td class="px-3 py-3 font-bold">
                                                {{
                                                    scoreValue(
                                                        match.matches_code,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-3 py-3 font-semibold uppercase"
                                            >
                                                {{
                                                    scoreValue(match.contingent)
                                                }}
                                            </td>
                                            <td class="px-3 py-3 uppercase">
                                                {{ scoreValue(match.atletes) }}
                                            </td>
                                            <td class="px-3 py-3 text-right">
                                                {{ formatTime(match.time) }}
                                            </td>
                                            <td class="px-3 py-3 text-right">
                                                {{ scoreValue(match.rank) }}
                                            </td>
                                            <td
                                                class="px-3 py-3 text-right font-black text-yellow-400"
                                            >
                                                {{
                                                    scoreValue(
                                                        match.total_score,
                                                    )
                                                }}
                                            </td>
                                            <template
                                                v-if="scoringMode === 'tgr'"
                                            >
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_wiraga,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_wirasa,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_wirama,
                                                        )
                                                    }}
                                                </td>
                                            </template>
                                            <template v-else>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_kualitas_teknik,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_kuantitas_teknik,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_ketangkasan,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_stamina,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_kemantapan,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 text-right"
                                                >
                                                    {{
                                                        scoreValue(
                                                            match.total_musik,
                                                        )
                                                    }}
                                                </td>
                                            </template>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div
                        class="z-20 flex h-[72px] w-full shrink-0 items-center justify-center border-t border-stone-800 bg-zinc-900 px-6"
                    >
                        <div
                            class="flex flex-1 items-center justify-center gap-3"
                        >
                            <Button
                                v-if="
                                    activeMatch &&
                                    ['not_started', 'done'].includes(
                                        activeMatch.status,
                                    )
                                "
                                class="bg-blue-600 px-8 font-bold tracking-widest text-white hover:bg-blue-700"
                                @click="updateActiveMatchStatus('ongoing')"
                                :disabled="isUpdatingStatus"
                            >
                                START
                            </Button>
                            <Button
                                v-if="activeMatch?.status === 'ongoing'"
                                class="bg-yellow-500 px-8 font-bold tracking-widest text-black hover:bg-yellow-600"
                                @click="updateActiveMatchStatus('paused')"
                                :disabled="isUpdatingStatus"
                            >
                                PAUSE
                            </Button>
                            <Button
                                v-if="activeMatch?.status === 'paused'"
                                class="bg-blue-600 px-8 font-bold tracking-widest text-white hover:bg-blue-700"
                                @click="updateActiveMatchStatus('ongoing')"
                                :disabled="isUpdatingStatus"
                            >
                                RESUME
                            </Button>
                            <Button
                                v-if="activeMatch?.status === 'paused'"
                                class="bg-green-500 font-bold tracking-wider text-white hover:bg-green-600"
                                @click="updateActiveMatchStatus('done')"
                                :disabled="isUpdatingStatus"
                            >
                                SAVE
                            </Button>
                            <Button
                                v-if="
                                    activeMatch &&
                                    ['paused', 'done'].includes(
                                        activeMatch.status,
                                    )
                                "
                                variant="destructive"
                                class="font-bold tracking-wider"
                                disabled
                            >
                                RESET
                            </Button>
                        </div>
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

        <Dialog
            :open="isSetupDialogOpen"
            @update:open="isSetupDialogOpen = $event"
        >
            <DialogContent class="overflow-hidden sm:max-w-[480px]">
                <DialogHeader class="pb-4">
                    <DialogTitle class="text-xl font-bold text-primary">
                        Setup Gelanggang Seni
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        Pilih gelanggang dan sesi seni yang akan digunakan.
                    </DialogDescription>
                </DialogHeader>

                <Separator />

                <div class="grid gap-6 py-2">
                    <div class="grid gap-2">
                        <Label
                            class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                        >
                            Pilih Gelanggang
                        </Label>
                        <Select
                            v-model="selectedGelanggang"
                            @update:modelValue="handleGelanggangChange"
                        >
                            <SelectTrigger class="h-10 w-full">
                                <SelectValue
                                    v-if="gelanggangList.length === 0"
                                    placeholder="Memuat gelanggang..."
                                />
                                <SelectValue
                                    v-else
                                    placeholder="Pilih Gelanggang"
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
                        >
                            Pilih Sesi Seni
                        </Label>
                        <Select
                            v-model="selectedSesi"
                            :disabled="!selectedGelanggang"
                        >
                            <SelectTrigger class="h-10 w-full">
                                <SelectValue
                                    v-if="!selectedGelanggang"
                                    placeholder="Silakan pilih gelanggang"
                                />
                                <SelectValue v-else placeholder="Pilih Sesi" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="s in sesiList"
                                    :key="s.id"
                                    :value="s.id.toString()"
                                    class="cursor-pointer"
                                >
                                    {{
                                        s.sesi ?? s.nama_sesi ?? 'Sesi ' + s.id
                                    }}
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

        <Dialog :open="isConfirmDialogOpen" @update:open="setConfirmDialogOpen">
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Muat Data Pool</DialogTitle>
                    <DialogDescription>
                        Ambil partai seni dari server untuk
                        {{ pendingPoolTitle }} dan simpan ke data lokal?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="setConfirmDialogOpen(false)"
                    >
                        Batal
                    </Button>
                    <Button @click="syncPoolMatches" :disabled="isSyncing">
                        {{ isSyncing ? 'Memuat...' : 'Ya, Muat Data' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="isMatchConfirmDialogOpen"
            @update:open="setMatchConfirmDialogOpen"
        >
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Muat Data Partai</DialogTitle>
                    <DialogDescription>
                        Ambil detail nilai juri dari server untuk
                        {{ pendingMatchTitle }} dan jadikan partai aktif?
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="setMatchConfirmDialogOpen(false)"
                    >
                        Batal
                    </Button>
                    <Button
                        @click="activatePendingMatch"
                        :disabled="isActivatingMatch"
                    >
                        {{ isActivatingMatch ? 'Memuat...' : 'Ya, Muat Data' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
