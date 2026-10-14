<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * One-line tally of the deductions awarded in the current round.
 *
 * Wrapping is not an option here: the row is pinned to the bottom of a corner
 * panel, so a second line pushes straight out of the panel. Instead the row
 * stays on one line and shrinks to fit - the deductions are read as a group at
 * a glance, not individually, so a smaller line is far better than a broken
 * layout. Past `max` entries the tail collapses into a `+n` counter, which
 * keeps the count honest without turning the row into unreadable confetti.
 *
 * Every length here is relative to the row's own font size, so the fitted size
 * follows from a single measurement instead of a layout loop.
 */

type Props = {
    values: string[];
    /** Entries past this count collapse into a `+n` counter. */
    max?: number;
    /** Font size used when everything fits comfortably. */
    maxFontSize?: number;
    /** Font size below which entries are dropped rather than shrunk further. */
    minFontSize?: number;
};

const props = withDefaults(defineProps<Props>(), {
    max: 8,
    maxFontSize: 72,
    minFontSize: 28,
});

const PROBE_FONT_SIZE = 100;

const host = ref<HTMLElement | null>(null);
const probe = ref<HTMLElement | null>(null);
const fontSize = ref(props.maxFontSize);

let resizeObserver: ResizeObserver | null = null;

const visibleValues = computed(() =>
    props.values.length > props.max
        ? props.values.slice(0, props.max - 1)
        : props.values,
);

const overflowCount = computed(
    () => props.values.length - visibleValues.value.length,
);

const measure = () => {
    const hostElement = host.value;
    const probeElement = probe.value;

    if (!hostElement || !probeElement) {
        return;
    }

    const available = hostElement.clientWidth;
    const needed = probeElement.offsetWidth;

    // Before the first layout both read 0; keep the current size rather than
    // collapsing the row to its minimum.
    if (!available || !needed) {
        return;
    }

    const fitted = Math.floor((available * PROBE_FONT_SIZE) / needed);

    fontSize.value = Math.max(
        props.minFontSize,
        Math.min(props.maxFontSize, fitted),
    );
};

watch(() => props.values, measure, { deep: true, flush: 'post' });

onMounted(() => {
    measure();

    if (window.ResizeObserver && host.value) {
        resizeObserver = new ResizeObserver(measure);
        resizeObserver.observe(host.value);
    }

    document.fonts?.ready.then(measure).catch(() => undefined);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
});
</script>

<template>
    <!--
        No position utility here: the caller places this row, and a `relative`
        of our own would silently beat the `absolute` it passes, dropping the
        row back into flow where its width is its content and nothing ever
        shrinks.
    -->
    <div ref="host" class="flex items-center justify-center overflow-hidden">
        <div
            class="flex items-center justify-center gap-[0.22em] font-mono leading-none font-black tracking-widest tabular-nums"
            :style="{ fontSize: `${fontSize}px` }"
        >
            <span
                v-for="(value, index) in visibleValues"
                :key="`punishment-${value}-${index}`"
            >
                {{ value }}
            </span>
            <span v-if="overflowCount > 0" class="opacity-75">
                +{{ overflowCount }}
            </span>
        </div>

        <div
            ref="probe"
            aria-hidden="true"
            class="pointer-events-none invisible absolute top-0 left-0 flex w-max items-center gap-[0.22em] font-mono leading-none font-black tracking-widest tabular-nums"
            :style="{ fontSize: `${PROBE_FONT_SIZE}px` }"
        >
            <span
                v-for="(value, index) in visibleValues"
                :key="`probe-${value}-${index}`"
            >
                {{ value }}
            </span>
            <span v-if="overflowCount > 0">+{{ overflowCount }}</span>
        </div>
    </div>
</template>
