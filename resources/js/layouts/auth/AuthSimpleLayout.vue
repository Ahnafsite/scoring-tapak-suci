<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh items-center justify-center overflow-hidden bg-[#0b0b0b] px-5 py-8 md:px-8"
    >
        <div
            class="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(234,179,8,0.12),transparent_38%),radial-gradient(circle_at_bottom,rgba(234,179,8,0.08),transparent_28%)]"
        ></div>
        <div
            class="absolute inset-0 bg-[linear-gradient(180deg,rgba(255,255,255,0.03),transparent_22%,transparent_78%,rgba(255,255,255,0.02))]"
        ></div>

        <Card
            class="relative w-full max-w-[29rem] overflow-hidden rounded-[1.6rem] border-yellow-500/10 bg-[#161616]/96 py-0 shadow-[0_24px_64px_rgba(0,0,0,0.5)]"
        >
            <div
                class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-yellow-400/50 to-transparent"
            ></div>

            <CardContent class="w-full px-5 py-5 sm:px-7 sm:py-6">
                <slot />
            </CardContent>
        </Card>
    </div>
</template>
