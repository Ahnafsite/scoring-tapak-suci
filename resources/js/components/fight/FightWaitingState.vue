<script setup lang="ts">
type Props = {
    clickable?: boolean;
    onLogoClick?: () => void | Promise<void>;
};

withDefaults(defineProps<Props>(), {
    clickable: false,
    onLogoClick: undefined,
});
</script>

<template>
    <div class="flex flex-1 items-center justify-center bg-zinc-950">
        <button
            type="button"
            :disabled="!clickable"
            :class="[
                'flex items-center justify-center transition-transform outline-none',
                clickable
                    ? 'cursor-pointer focus-visible:scale-105'
                    : 'cursor-default',
            ]"
            :aria-label="
                clickable ? 'Masuk fullscreen' : 'Menunggu pertandingan'
            "
            @click="onLogoClick?.()"
        >
            <img
                src="/assets/images/js_logo.png"
                alt="Tapak Suci Logo"
                class="h-48 w-48 object-contain md:h-64 md:w-64"
            />
        </button>
    </div>
</template>
