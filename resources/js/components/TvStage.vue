<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useTvStage } from '@/composables/useTvStage';

/**
 * Scaled design canvas for the streaming boards.
 *
 * Children are laid out against a fixed pixel canvas (`width` x `height`) and
 * the whole canvas is scaled to fit the real screen, so one layout serves a
 * tablet, a monitor, a projector and a TV identically. The operator can trim
 * that automatic fit - long press anywhere, press `S`, or hit an arrow key - to
 * work around a TV that overscans or crops its input. The trim is stored per
 * physical screen, so it only has to be done once per device.
 */

type Props = {
    /** Design canvas width in CSS pixels. */
    width?: number;
    /** Design canvas height in CSS pixels. */
    height?: number;
    /** Letterbox colour around the canvas. Keep it green for chroma keying. */
    background?: string;
    /** Classes for the canvas itself, i.e. what the board's root div carried. */
    stageClass?: string;
    /** Board-supplied fullscreen toggle, so the page keeps its own exit guard. */
    onFullscreen?: () => void | Promise<void>;
};

const props = withDefaults(defineProps<Props>(), {
    width: 1920,
    height: 1080,
    background: '#000',
    stageClass: '',
    onFullscreen: undefined,
});

const IDLE_MS = 4000;
const LONG_PRESS_MS = 800;
const LONG_PRESS_MOVE_TOLERANCE = 10;
const CLICK_SUPPRESSION_MS = 700;

const {
    NUDGE_STEP,
    ZOOM_STEP,
    fit,
    nudge,
    offsetX,
    offsetY,
    profileLabel,
    reset,
    scale,
    viewportHeight,
    viewportWidth,
    zoom,
    zoomBy,
} = useTvStage({
    design: () => ({ width: props.width, height: props.height }),
});

const isPanelOpen = ref(false);
const areControlsVisible = ref(true);
const viewport = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const zoomOutButton = ref<HTMLButtonElement | null>(null);

let idleTimer: ReturnType<typeof setTimeout> | null = null;
let longPressTimer: ReturnType<typeof setTimeout> | null = null;
let clickSuppressionTimer: ReturnType<typeof setTimeout> | null = null;
let suppressNextClick = false;

const stageStyle = computed(() => ({
    width: `${props.width}px`,
    height: `${props.height}px`,
    transform: `translate(${offsetX.value}px, ${offsetY.value}px) scale(${scale.value})`,
}));

const zoomLabel = computed(() => `${Math.round(zoom.value * 100)}%`);
const offsetLabel = computed(() => `${offsetX.value} , ${offsetY.value} px`);
const metaLabel = computed(
    () =>
        `Layar ${viewportWidth.value}×${viewportHeight.value}` +
        ` · skala ${Math.round(scale.value * 1000) / 10}%` +
        ` · profil ${profileLabel.value}`,
);

const showControls = () => {
    areControlsVisible.value = true;

    if (idleTimer !== null) {
        clearTimeout(idleTimer);
    }

    idleTimer = setTimeout(() => {
        areControlsVisible.value = false;
    }, IDLE_MS);
};

const openPanel = async () => {
    if (isPanelOpen.value) {
        return;
    }

    isPanelOpen.value = true;
    fit();
    showControls();

    await Promise.resolve();
    zoomOutButton.value?.focus();
};

const closePanel = () => {
    isPanelOpen.value = false;
};

const toggleFullscreen = async () => {
    if (props.onFullscreen) {
        await props.onFullscreen();

        return;
    }

    try {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await document.documentElement.requestFullscreen();
        }
    } catch {
        // Several TV browsers expose no usable Fullscreen API.
    }
};

const disarmClickSuppression = () => {
    suppressNextClick = false;

    if (clickSuppressionTimer !== null) {
        clearTimeout(clickSuppressionTimer);
        clickSuppressionTimer = null;
    }
};

const armClickSuppression = () => {
    suppressNextClick = true;

    if (clickSuppressionTimer !== null) {
        clearTimeout(clickSuppressionTimer);
    }

    // The release click lands within a frame or two; anything later is a fresh
    // interaction and must not be swallowed.
    clickSuppressionTimer = setTimeout(
        disarmClickSuppression,
        CLICK_SUPPRESSION_MS,
    );
};

const cancelLongPress = () => {
    if (longPressTimer !== null) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
    }

    document.removeEventListener('pointerup', cancelLongPress);
    document.removeEventListener('pointercancel', cancelLongPress);
    document.removeEventListener('pointermove', handleLongPressMove);
};

let longPressStartX = 0;
let longPressStartY = 0;

function handleLongPressMove(event: PointerEvent) {
    if (
        Math.abs(event.clientX - longPressStartX) < LONG_PRESS_MOVE_TOLERANCE &&
        Math.abs(event.clientY - longPressStartY) < LONG_PRESS_MOVE_TOLERANCE
    ) {
        return;
    }

    cancelLongPress();
}

/**
 * Touch / pointer entry point: press and hold anywhere for 800ms. Covers
 * Android tablets and the pointer-style TV remotes, neither of which has a
 * keyboard to hand.
 */
const handlePointerDown = (event: PointerEvent) => {
    showControls();

    if (isPanelOpen.value) {
        return;
    }

    longPressStartX = event.clientX;
    longPressStartY = event.clientY;

    longPressTimer = setTimeout(() => {
        armClickSuppression();
        void openPanel();
    }, LONG_PRESS_MS);

    document.addEventListener('pointerup', cancelLongPress);
    document.addEventListener('pointercancel', cancelLongPress);
    document.addEventListener('pointermove', handleLongPressMove);
};

/**
 * The board's own click handlers toggle fullscreen; a long press must not also
 * trigger them on release. Clicks inside the overlay are always let through -
 * several mobile browsers swallow the click that follows a long press, and a
 * stuck flag would otherwise eat the operator's first real tap on a control.
 */
const handleCapturedClick = (event: MouseEvent) => {
    if (!suppressNextClick) {
        return;
    }

    const target = event.target as Node | null;

    if (target && panel.value?.contains(target)) {
        return;
    }

    if (target && (target as HTMLElement).closest?.('.tvcal-gear')) {
        return;
    }

    disarmClickSuppression();
    event.stopPropagation();
    event.preventDefault();
};

const handleKeydown = (event: KeyboardEvent) => {
    const key = event.key || '';

    if (!isPanelOpen.value) {
        showControls();

        // Arrow keys are the only thing every TV remote reliably sends, and
        // nothing else on the board listens for them.
        const opensPanel =
            key === 's' ||
            key === 'S' ||
            key === 'F2' ||
            (key.startsWith('Arrow') &&
                !event.altKey &&
                !event.metaKey &&
                !event.ctrlKey);

        if (opensPanel) {
            void openPanel();
            event.preventDefault();
        }

        return;
    }

    switch (key) {
        case 'ArrowLeft':
            nudge(-NUDGE_STEP, 0);
            break;
        case 'ArrowRight':
            nudge(NUDGE_STEP, 0);
            break;
        case 'ArrowUp':
            nudge(0, -NUDGE_STEP);
            break;
        case 'ArrowDown':
            nudge(0, NUDGE_STEP);
            break;
        case '+':
        case '=':
            zoomBy(ZOOM_STEP);
            break;
        case '-':
        case '_':
            zoomBy(-ZOOM_STEP);
            break;
        case 'r':
        case 'R':
            reset();
            break;
        case 'Enter':
        case 'Escape':
        case 'Backspace':
            closePanel();
            break;
        default:
            return;
    }

    event.preventDefault();
};

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('pointermove', showControls);
    document.addEventListener('click', handleCapturedClick, true);
    showControls();
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    document.removeEventListener('pointerdown', handlePointerDown);
    document.removeEventListener('pointermove', showControls);
    document.removeEventListener('click', handleCapturedClick, true);
    cancelLongPress();
    disarmClickSuppression();

    if (idleTimer !== null) {
        clearTimeout(idleTimer);
        idleTimer = null;
    }
});

defineExpose({ openPanel, reset });
</script>

<template>
    <div
        ref="viewport"
        class="tv-stage-viewport"
        :class="{ 'tv-stage-viewport--idle': !areControlsVisible }"
        :style="{ background }"
    >
        <div class="tv-stage" :class="stageClass" :style="stageStyle">
            <slot />
        </div>

        <button
            v-show="areControlsVisible"
            class="tvcal-gear"
            type="button"
            title="Kalibrasi layar"
            aria-label="Kalibrasi layar"
            @click.stop="openPanel"
        >
            ⚙
        </button>

        <div v-if="isPanelOpen" class="tvcal" @click.self="closePanel">
            <div
                ref="panel"
                class="tvcal__box"
                role="dialog"
                aria-modal="true"
                aria-label="Kalibrasi layar"
            >
                <h2 class="tvcal__title">Kalibrasi Layar</h2>
                <p class="tvcal__meta">{{ metaLabel }}</p>

                <div class="tvcal__row">
                    <span class="tvcal__label">Ukuran</span>
                    <button
                        ref="zoomOutButton"
                        type="button"
                        aria-label="Perkecil"
                        @click="zoomBy(-ZOOM_STEP)"
                    >
                        &minus;
                    </button>
                    <button
                        type="button"
                        aria-label="Perbesar"
                        @click="zoomBy(ZOOM_STEP)"
                    >
                        +
                    </button>
                    <span class="tvcal__value">{{ zoomLabel }}</span>
                </div>

                <div class="tvcal__row">
                    <span class="tvcal__label">Geser</span>
                    <button
                        type="button"
                        aria-label="Geser kiri"
                        @click="nudge(-NUDGE_STEP, 0)"
                    >
                        &larr;
                    </button>
                    <button
                        type="button"
                        aria-label="Geser atas"
                        @click="nudge(0, -NUDGE_STEP)"
                    >
                        &uarr;
                    </button>
                    <button
                        type="button"
                        aria-label="Geser bawah"
                        @click="nudge(0, NUDGE_STEP)"
                    >
                        &darr;
                    </button>
                    <button
                        type="button"
                        aria-label="Geser kanan"
                        @click="nudge(NUDGE_STEP, 0)"
                    >
                        &rarr;
                    </button>
                    <span class="tvcal__value">{{ offsetLabel }}</span>
                </div>

                <div class="tvcal__actions">
                    <button type="button" @click="reset">Reset</button>
                    <button type="button" @click="toggleFullscreen">
                        Layar Penuh
                    </button>
                    <button type="button" @click="closePanel">Tutup</button>
                </div>

                <p class="tvcal__hint">
                    Remote / keyboard: panah = geser &middot; + dan &minus; =
                    ukuran &middot; R = reset &middot; Enter = tutup.<br />
                    Pengaturan tersimpan otomatis untuk layar ini.
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.tv-stage-viewport {
    position: fixed;
    inset: 0;
    overflow: hidden;
    /* Flex centering stays honest when the unscaled canvas is larger than the
       viewport. `inset: 0; margin: auto` does not: an over-constrained absolute
       box drops margin-left to 0 and hangs off the right edge instead. */
    display: flex;
    align-items: center;
    justify-content: center;
}

.tv-stage-viewport--idle {
    cursor: none;
}

.tv-stage {
    flex: 0 0 auto;
    transform-origin: center center;
    /* A transformed element is the containing block for its fixed descendants,
       so `fixed inset-0` children stay inside the canvas. */
    will-change: transform;
}

.tvcal-gear,
.tvcal {
    font-family:
        system-ui,
        -apple-system,
        'Segoe UI',
        Roboto,
        sans-serif;
}

.tvcal-gear {
    position: absolute;
    right: 16px;
    bottom: 16px;
    z-index: 2147483000;
    width: 56px;
    height: 56px;
    border: 2px solid rgb(255 255 255 / 35%);
    border-radius: 50%;
    background: rgb(0 0 0 / 65%);
    color: #fff;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 250ms ease;
}

.tvcal {
    position: absolute;
    inset: 0;
    z-index: 2147483001;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgb(0 0 0 / 72%);
    color: #fff;
}

.tvcal__box {
    width: min(560px, 92vw);
    max-height: 92vh;
    overflow: auto;
    padding: 28px 32px;
    border: 2px solid rgb(255 255 255 / 25%);
    border-radius: 16px;
    background: #10151f;
    box-shadow: 0 24px 80px rgb(0 0 0 / 75%);
}

.tvcal__title {
    margin: 0 0 4px;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: 0.02em;
}

.tvcal__meta {
    margin: 0 0 20px;
    font-size: 14px;
    line-height: 1.5;
    color: rgb(255 255 255 / 60%);
}

.tvcal__row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}

.tvcal__label {
    flex: 0 0 96px;
    font-size: 17px;
    font-weight: 700;
}

.tvcal__value {
    flex: 1 1 auto;
    text-align: right;
    font-size: 18px;
    font-variant-numeric: tabular-nums;
    color: #fbbf24;
}

.tvcal button {
    min-width: 52px;
    min-height: 52px;
    padding: 0 14px;
    border: 2px solid rgb(255 255 255 / 25%);
    border-radius: 10px;
    background: #1f2937;
    color: #fff;
    font-size: 20px;
    font-weight: 700;
    cursor: pointer;
}

.tvcal button:hover,
.tvcal button:focus-visible {
    background: #2563eb;
    border-color: #93c5fd;
    outline: none;
}

.tvcal__actions {
    display: flex;
    gap: 10px;
    margin-top: 22px;
}

.tvcal__actions button {
    flex: 1 1 0;
    font-size: 17px;
}

.tvcal__hint {
    margin: 18px 0 0;
    font-size: 13px;
    line-height: 1.6;
    color: rgb(255 255 255 / 50%);
}
</style>
