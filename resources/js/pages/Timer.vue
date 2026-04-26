<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Eye,
    EyeOff,
    LogOut,
    Pause,
    Play,
    RotateCcw,
    Square,
    TimerReset,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    control as controlTimer,
    update as updateTimer,
} from '@/actions/App/Http/Controllers/TimerController';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { logout } from '@/routes';

type TimerState = {
    id: number | null;
    is_display: boolean;
    started_at: string | null;
    started_at_milliseconds?: number | null;
    status: 'running' | 'paused' | 'stopped';
    stored_status?: 'running' | 'paused' | 'stopped';
    is_countdown: boolean;
    second: number;
    is_autostop: boolean;
    elapsed_seconds: number;
    elapsed_milliseconds?: number;
    display_seconds: number;
    display_milliseconds?: number;
    server_now?: string | null;
};

const props = defineProps<{
    timer: TimerState;
}>();

const timer = ref<TimerState>({ ...props.timer });
const nowTick = ref(Date.now());
const isSaving = ref(false);
const customMinutes = ref(Math.floor((props.timer.second ?? 120) / 60));
const customSeconds = ref((props.timer.second ?? 120) % 60);
let tickInterval: ReturnType<typeof setInterval> | null = null;
let echoTimerChannel: any = null;

const durationOptions = [
    { label: '00.30', seconds: 30 },
    { label: '01.00', seconds: 60 },
    { label: '01.30', seconds: 90 },
    { label: '02.00', seconds: 120 },
];

const handleLogout = () => {
    router.flushAll();
};

const elapsedMilliseconds = computed(() => {
    let elapsed =
        timer.value.elapsed_milliseconds ??
        (Number(timer.value.elapsed_seconds) || 0) * 1000;

    if (timer.value.status === 'running' && timer.value.started_at) {
        elapsed += Math.max(
            0,
            nowTick.value -
                (timer.value.started_at_milliseconds ??
                    Date.parse(timer.value.started_at)),
        );
    }

    return elapsed;
});

const displayMilliseconds = computed(() => {
    if (timer.value.is_countdown) {
        return Math.max(
            0,
            timer.value.second * 1000 - elapsedMilliseconds.value,
        );
    }

    if (timer.value.is_autostop) {
        return Math.min(elapsedMilliseconds.value, timer.value.second * 1000);
    }

    return elapsedMilliseconds.value;
});

const displayStatus = computed(() => {
    if (timer.value.status !== 'running') {
        return timer.value.status;
    }

    if (timer.value.is_countdown && displayMilliseconds.value === 0) {
        return 'stopped';
    }

    if (
        !timer.value.is_countdown &&
        timer.value.is_autostop &&
        elapsedMilliseconds.value >= timer.value.second * 1000
    ) {
        return 'stopped';
    }

    return timer.value.status;
});

const selectedDuration = computed(() =>
    durationOptions.some((option) => option.seconds === timer.value.second)
        ? timer.value.second
        : 'custom',
);

const statusLabel = computed(() => {
    const labels = {
        running: 'Berjalan',
        paused: 'Jeda',
        stopped: 'Berhenti',
    };

    return labels[displayStatus.value];
});

const formatDuration = (millisecondsValue: number) => {
    const safeMilliseconds = Math.max(0, Math.floor(millisecondsValue));
    const minutes = Math.floor(safeMilliseconds / 60000);
    const remainingSeconds = Math.floor((safeMilliseconds % 60000) / 1000);
    const milliseconds = safeMilliseconds % 1000;

    return `${minutes.toString().padStart(2, '0')}:${remainingSeconds
        .toString()
        .padStart(2, '0')}:${milliseconds.toString().padStart(3, '0')}`;
};

const syncTimer = (nextTimer: TimerState) => {
    timer.value = { ...timer.value, ...nextTimer };
};

const saveConfig = async (payload: Partial<TimerState>) => {
    isSaving.value = true;

    try {
        const response = await axios.post(updateTimer().url, payload);
        syncTimer(response.data.timer);
    } catch (error) {
        console.error('Failed to update timer', error);
        toast.error('Gagal menyimpan timer.');
    } finally {
        isSaving.value = false;
    }
};

const sendControl = async (action: 'start' | 'pause' | 'stop' | 'reset') => {
    isSaving.value = true;

    try {
        const response = await axios.post(controlTimer().url, { action });
        syncTimer(response.data.timer);
    } catch (error) {
        console.error('Failed to control timer', error);
        toast.error('Gagal mengontrol timer.');
    } finally {
        isSaving.value = false;
    }
};

const applyCustomDuration = async () => {
    const seconds =
        Math.max(0, Number(customMinutes.value) || 0) * 60 +
        Math.max(0, Number(customSeconds.value) || 0);

    if (seconds < 1) {
        toast.error('Durasi minimal 1 detik.');

        return;
    }

    await saveConfig({ second: seconds });
};

watch(
    () => timer.value.second,
    (seconds) => {
        customMinutes.value = Math.floor(seconds / 60);
        customSeconds.value = seconds % 60;
    },
);

onMounted(() => {
    tickInterval = setInterval(() => {
        nowTick.value = Date.now();
    }, 25);

    const echo = (window as any).Echo;

    if (!echo) {
        return;
    }

    echoTimerChannel = echo
        .channel('timer')
        .listen('.TimerUpdated', (event: any) => {
            if (event.timer) {
                syncTimer(event.timer);
            }
        });
});

onUnmounted(() => {
    if (tickInterval) {
        clearInterval(tickInterval);
    }

    const echo = (window as any).Echo;

    if (!echo || !echoTimerChannel) {
        return;
    }

    echoTimerChannel.stopListening('.TimerUpdated');
    echo.leaveChannel('timer');
});
</script>

<template>
    <Head title="Timer" />
    <Toaster rich-colors />

    <div class="min-h-dvh bg-zinc-950 text-white">
        <main
            class="mx-auto flex min-h-dvh w-full max-w-6xl flex-col gap-6 px-6 py-7"
        >
            <header class="flex items-center justify-between gap-4">
                <div>
                    <p
                        class="text-sm font-black tracking-widest text-yellow-400 uppercase"
                    >
                        Panel Timer
                    </p>
                    <h1 class="mt-1 text-3xl font-black tracking-tight">
                        Timer Pertandingan
                    </h1>
                </div>

                <Link :href="logout()" @click="handleLogout" as="button">
                    <Button variant="secondary" size="icon" class="size-10">
                        <LogOut class="size-4" />
                    </Button>
                </Link>
            </header>

            <section class="rounded-lg border border-stone-800 bg-zinc-900 p-7">
                <div
                    class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <div
                            class="font-mono text-7xl font-black tabular-nums sm:text-8xl"
                        >
                            {{ formatDuration(displayMilliseconds) }}
                        </div>
                        <div
                            class="mt-3 flex flex-wrap items-center gap-3 text-sm font-bold uppercase"
                        >
                            <span
                                class="rounded bg-black px-3 py-1 text-stone-300"
                            >
                                {{ statusLabel }}
                            </span>
                            <span
                                class="rounded bg-black px-3 py-1 text-stone-300"
                            >
                                {{
                                    timer.is_countdown ? 'Countdown' : 'Countup'
                                }}
                            </span>
                            <span
                                v-if="timer.is_display"
                                class="rounded bg-green-500/15 px-3 py-1 text-green-300"
                            >
                                Tampil di Streaming
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Button
                            class="h-14 gap-2"
                            :disabled="isSaving || displayStatus === 'running'"
                            @click="sendControl('start')"
                        >
                            <Play class="size-5" />
                            Start
                        </Button>
                        <Button
                            variant="secondary"
                            class="h-14 gap-2"
                            :disabled="isSaving || displayStatus !== 'running'"
                            @click="sendControl('pause')"
                        >
                            <Pause class="size-5" />
                            Pause
                        </Button>
                        <Button
                            variant="secondary"
                            class="h-14 gap-2"
                            :disabled="isSaving || displayStatus === 'stopped'"
                            @click="sendControl('stop')"
                        >
                            <Square class="size-5" />
                            Stop
                        </Button>
                        <Button
                            variant="outline"
                            class="h-14 gap-2 border-stone-700 bg-transparent"
                            :disabled="isSaving"
                            @click="sendControl('reset')"
                        >
                            <RotateCcw class="size-5" />
                            Reset
                        </Button>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-[1fr_1.2fr]">
                <div class="rounded-lg border border-stone-800 bg-zinc-900 p-6">
                    <h2 class="text-lg font-black tracking-wide">
                        Konfigurasi
                    </h2>

                    <div class="mt-6 grid gap-4">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="timer.is_display"
                            class="flex items-center justify-between rounded-lg border border-stone-800 bg-black px-4 py-4 text-left transition hover:border-stone-600"
                            @click="
                                saveConfig({ is_display: !timer.is_display })
                            "
                        >
                            <span>
                                <span class="block font-bold"
                                    >Tampilkan Timer</span
                                >
                                <span class="text-sm text-stone-400"
                                    >Kontrol tampilan di streaming</span
                                >
                            </span>
                            <span
                                :class="[
                                    'flex h-8 w-14 items-center rounded-full p-1 transition-colors',
                                    timer.is_display
                                        ? 'bg-green-500'
                                        : 'bg-stone-700',
                                ]"
                            >
                                <span
                                    :class="[
                                        'flex size-6 items-center justify-center rounded-full bg-white text-zinc-950 transition-transform',
                                        timer.is_display
                                            ? 'translate-x-6'
                                            : 'translate-x-0',
                                    ]"
                                >
                                    <Eye
                                        v-if="timer.is_display"
                                        class="size-3.5"
                                    />
                                    <EyeOff v-else class="size-3.5" />
                                </span>
                            </span>
                        </button>

                        <button
                            type="button"
                            role="switch"
                            :aria-checked="timer.is_countdown"
                            class="flex items-center justify-between rounded-lg border border-stone-800 bg-black px-4 py-4 text-left transition hover:border-stone-600"
                            @click="
                                saveConfig({
                                    is_countdown: !timer.is_countdown,
                                })
                            "
                        >
                            <span>
                                <span class="block font-bold">Mode Timer</span>
                                <span class="text-sm text-stone-400">
                                    {{
                                        timer.is_countdown
                                            ? 'Countdown'
                                            : 'Countup'
                                    }}
                                </span>
                            </span>
                            <span
                                :class="[
                                    'flex h-8 w-14 items-center rounded-full p-1 transition-colors',
                                    timer.is_countdown
                                        ? 'bg-yellow-400'
                                        : 'bg-blue-500',
                                ]"
                            >
                                <span
                                    :class="[
                                        'size-6 rounded-full bg-white transition-transform',
                                        timer.is_countdown
                                            ? 'translate-x-6'
                                            : 'translate-x-0',
                                    ]"
                                ></span>
                            </span>
                        </button>

                        <button
                            type="button"
                            role="switch"
                            :aria-checked="timer.is_autostop"
                            class="flex items-center justify-between rounded-lg border border-stone-800 bg-black px-4 py-4 text-left transition hover:border-stone-600"
                            :class="{ 'opacity-55': timer.is_countdown }"
                            :disabled="timer.is_countdown"
                            @click="
                                saveConfig({ is_autostop: !timer.is_autostop })
                            "
                        >
                            <span>
                                <span class="block font-bold"
                                    >Auto Stop Countup</span
                                >
                                <span class="text-sm text-stone-400"
                                    >Berhenti otomatis di durasi terpilih</span
                                >
                            </span>
                            <span
                                :class="[
                                    'flex h-8 w-14 items-center rounded-full p-1 transition-colors',
                                    timer.is_autostop
                                        ? 'bg-green-500'
                                        : 'bg-stone-700',
                                ]"
                            >
                                <span
                                    :class="[
                                        'size-6 rounded-full bg-white transition-transform',
                                        timer.is_autostop
                                            ? 'translate-x-6'
                                            : 'translate-x-0',
                                    ]"
                                ></span>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="rounded-lg border border-stone-800 bg-zinc-900 p-6">
                    <div class="flex items-center gap-3">
                        <TimerReset class="size-5 text-yellow-400" />
                        <h2 class="text-lg font-black tracking-wide">
                            Durasi Timer
                        </h2>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <Button
                            v-for="option in durationOptions"
                            :key="option.seconds"
                            variant="secondary"
                            class="h-12 font-mono text-lg"
                            :class="{
                                'bg-yellow-400 text-black hover:bg-yellow-300':
                                    selectedDuration === option.seconds,
                            }"
                            :disabled="isSaving"
                            @click="saveConfig({ second: option.seconds })"
                        >
                            {{ option.label }}
                        </Button>
                        <Button
                            variant="outline"
                            class="h-12 border-stone-700 bg-transparent"
                            :class="{
                                'border-yellow-400 text-yellow-300':
                                    selectedDuration === 'custom',
                            }"
                        >
                            Custom
                        </Button>
                    </div>

                    <div
                        class="mt-6 grid gap-4 rounded-lg border border-stone-800 bg-black p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    >
                        <label class="grid gap-2">
                            <span class="text-sm font-bold text-stone-300"
                                >Menit</span
                            >
                            <input
                                v-model.number="customMinutes"
                                type="number"
                                min="0"
                                max="599"
                                class="h-11 rounded-md border border-stone-700 bg-zinc-950 px-3 font-mono text-lg outline-none focus:border-yellow-400"
                            />
                        </label>
                        <label class="grid gap-2">
                            <span class="text-sm font-bold text-stone-300"
                                >Detik</span
                            >
                            <input
                                v-model.number="customSeconds"
                                type="number"
                                min="0"
                                max="59"
                                class="h-11 rounded-md border border-stone-700 bg-zinc-950 px-3 font-mono text-lg outline-none focus:border-yellow-400"
                            />
                        </label>
                        <Button
                            class="h-11"
                            :disabled="isSaving"
                            @click="applyCustomDuration"
                        >
                            Terapkan
                        </Button>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
