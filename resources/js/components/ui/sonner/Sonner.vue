<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "lucide-vue-next"
import { Toaster as Sonner } from "vue-sonner"
import "vue-sonner/style.css"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    close-button
    close-button-position="top-right"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--border-radius': 'var(--radius)',
    }"
    :toast-options="{
      classes: {
        toast:
          'group pointer-events-auto rounded-lg border border-border/80 border-l-4 border-l-primary bg-card/95 text-card-foreground shadow-[0_18px_45px_-24px_rgba(0,0,0,0.7)] backdrop-blur-md',
        content: 'gap-1.5',
        title: 'text-sm font-semibold tracking-tight',
        description: 'text-[13px] leading-5 text-muted-foreground',
        icon: 'mt-0.5 text-primary',
        closeButton:
          'border-border/70 bg-background/95 text-muted-foreground shadow-none transition-colors hover:border-primary/35 hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-0',
        success:
          'border-emerald-500/25 border-l-emerald-500 bg-emerald-500/10 text-card-foreground [&_[data-icon]]:text-emerald-500',
        error:
          'border-red-500/25 border-l-red-500 bg-red-500/10 text-card-foreground [&_[data-icon]]:text-red-500',
        warning:
          'border-amber-500/25 border-l-amber-500 bg-amber-500/12 text-card-foreground [&_[data-icon]]:text-amber-500',
        info: 'border-sky-500/25 border-l-sky-500 bg-sky-500/10 text-card-foreground [&_[data-icon]]:text-sky-500',
        loading:
          'border-primary/25 border-l-primary bg-primary/10 text-card-foreground [&_[data-icon]]:text-primary',
        default:
          'border-border/80 border-l-primary bg-card/95 text-card-foreground',
      },
    }"
    v-bind="$attrs"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
