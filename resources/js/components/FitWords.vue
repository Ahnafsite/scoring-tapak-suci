<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Shows as many whole words of `text` as fit on one line, and drops the rest.
 *
 * A clipped name reads worse than a short one: "ARROYYAN MUHAMMA…" is not a
 * name anybody can read off a board, while "ARROYYAN MUHAMMAD" is. So this
 * measures the real rendered width and removes trailing words until the line
 * fits - no ellipsis, no half words.
 *
 * Width comes from the parent, so the host must be able to fill it; the text
 * never feeds back into the measurement.
 */

type Props = {
    text?: string | null;
    /** Never drop below this many words, even when they still overflow. */
    minWords?: number;
    /** Rendered when `text` is empty. */
    placeholder?: string;
};

const props = withDefaults(defineProps<Props>(), {
    text: '',
    minWords: 1,
    placeholder: '-',
});

const host = ref<HTMLElement | null>(null);
const probe = ref<HTMLElement | null>(null);
const wordLimit = ref(Number.POSITIVE_INFINITY);

let resizeObserver: ResizeObserver | null = null;

const words = computed(() =>
    String(props.text ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean),
);

const visibleText = computed(() => {
    if (!words.value.length) {
        return props.placeholder;
    }

    const limit = Math.min(words.value.length, wordLimit.value);

    return words.value.slice(0, Math.max(props.minWords, limit)).join(' ');
});

const measure = () => {
    const hostElement = host.value;
    const probeElement = probe.value;

    if (!hostElement || !probeElement) {
        return;
    }

    if (words.value.length <= props.minWords) {
        wordLimit.value = words.value.length;

        return;
    }

    const available = hostElement.clientWidth;

    // A board that has not been laid out yet reports 0; keep every word rather
    // than collapsing the name to a single one.
    if (!available) {
        return;
    }

    let count = words.value.length;

    while (count > props.minWords) {
        probeElement.textContent = words.value.slice(0, count).join(' ');

        if (probeElement.scrollWidth <= available) {
            break;
        }

        count -= 1;
    }

    probeElement.textContent = '';
    wordLimit.value = count;
};

watch(words, measure, { flush: 'post' });

onMounted(() => {
    measure();

    if (window.ResizeObserver && host.value) {
        resizeObserver = new ResizeObserver(measure);
        resizeObserver.observe(host.value);
    }

    // Web fonts land after the first paint and change every width.
    document.fonts?.ready.then(measure).catch(() => undefined);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
});
</script>

<template>
    <span
        ref="host"
        class="relative block w-full overflow-hidden whitespace-nowrap"
        :title="String(text ?? '')"
    >
        {{ visibleText }}
        <span
            ref="probe"
            aria-hidden="true"
            class="pointer-events-none invisible absolute top-0 left-0 whitespace-pre"
        ></span>
    </span>
</template>
