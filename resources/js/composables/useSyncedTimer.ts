import { computed, onMounted, onUnmounted, ref, type Ref } from 'vue';

export type SyncedTimerState = {
    started_at: string | null;
    started_at_milliseconds?: number | null;
    status: 'running' | 'paused' | 'stopped';
    is_countdown: boolean;
    second: number;
    is_autostop: boolean;
    elapsed_seconds: number;
    elapsed_milliseconds?: number | null;
    display_seconds: number;
    display_milliseconds?: number | null;
    server_now?: string | null;
};

const monotonicNow = () =>
    typeof performance === 'undefined' ? Date.now() : performance.now();

const snapshotElapsedMilliseconds = (timer: SyncedTimerState) =>
    timer.elapsed_milliseconds ??
    (Number(timer.elapsed_seconds) || 0) * 1000;

export const formatTimerDuration = (millisecondsValue: number) => {
    const safeMilliseconds = Math.max(0, Math.floor(millisecondsValue));
    const minutes = Math.floor(safeMilliseconds / 60000);
    const seconds = Math.floor((safeMilliseconds % 60000) / 1000);
    const milliseconds = safeMilliseconds % 1000;

    return `${minutes.toString().padStart(2, '0')}:${seconds
        .toString()
        .padStart(2, '0')}:${milliseconds.toString().padStart(3, '0')}`;
};

export const useSyncedTimer = <T extends SyncedTimerState>(initialTimer: T) => {
    const localTimer = ref<T>({ ...initialTimer }) as Ref<T>;
    const syncedElapsedMilliseconds = ref(
        snapshotElapsedMilliseconds(initialTimer),
    );
    const syncedAt = ref(monotonicNow());
    const tick = ref(syncedAt.value);
    let tickInterval: ReturnType<typeof setInterval> | null = null;

    const syncTimer = (nextTimer: T) => {
        localTimer.value = { ...localTimer.value, ...nextTimer };
        syncedElapsedMilliseconds.value =
            snapshotElapsedMilliseconds(nextTimer);
        syncedAt.value = monotonicNow();
        tick.value = syncedAt.value;
    };

    const elapsedMilliseconds = computed(() => {
        if (localTimer.value.status !== 'running') {
            return syncedElapsedMilliseconds.value;
        }

        return (
            syncedElapsedMilliseconds.value +
            Math.max(0, tick.value - syncedAt.value)
        );
    });

    const displayMilliseconds = computed(() => {
        if (localTimer.value.is_countdown) {
            return Math.max(
                0,
                localTimer.value.second * 1000 - elapsedMilliseconds.value,
            );
        }

        if (localTimer.value.is_autostop) {
            return Math.min(
                elapsedMilliseconds.value,
                localTimer.value.second * 1000,
            );
        }

        return elapsedMilliseconds.value;
    });

    const formattedTimer = computed(() =>
        formatTimerDuration(displayMilliseconds.value),
    );

    onMounted(() => {
        tickInterval = setInterval(() => {
            tick.value = monotonicNow();
        }, 25);
    });

    onUnmounted(() => {
        if (tickInterval) {
            clearInterval(tickInterval);
        }
    });

    return {
        displayMilliseconds,
        elapsedMilliseconds,
        formatTimerDuration,
        formattedTimer,
        localTimer,
        syncTimer,
    };
};
