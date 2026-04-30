<script setup lang="ts">
import { Expand, Minimize } from 'lucide-vue-next';

type Props = {
    exitClickCount?: number;
    isFullscreen: boolean;
    remainingExitClicks: number;
    requiredExitClicks?: number;
    title?: string;
    onTrigger?: () => void | Promise<void>;
};

withDefaults(defineProps<Props>(), {
    exitClickCount: 0,
    requiredExitClicks: 5,
    title: 'Masuk fullscreen',
    onTrigger: undefined,
});
</script>

<template>
    <button
        type="button"
        class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-stone-700/30 bg-zinc-950/20 text-stone-200/45 transition hover:border-stone-500/50 hover:bg-zinc-950/35 hover:text-white/80"
        :title="title"
        :aria-label="title"
        @click="onTrigger?.()"
    >
        <component :is="isFullscreen ? Minimize : Expand" class="h-4 w-4" />
    </button>
</template>
