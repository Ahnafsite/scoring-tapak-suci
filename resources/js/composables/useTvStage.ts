import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Fixed design canvas for TV / projector / tablet output.
 *
 * The board is laid out once at a fixed pixel size (1920x1080 by default) and
 * then uniformly scaled to fit whatever viewport it lands in. Every internal
 * measurement lives in that one coordinate system, so the result is
 * proportionally identical everywhere and unaffected by the browser zoom level
 * - which is what TV browsers give us almost no control over.
 *
 * On top of the automatic fit the operator gets a manual zoom and nudge, which
 * is what makes the board usable on a TV that overscans or crops its input.
 */

const STORAGE_PREFIX = 'tvStage.v1.';
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 1.5;
const ZOOM_STEP = 0.01;
const NUDGE_STEP = 4;
const FIT_SAFETY_NET_MS = 2000;

export type TvStageSettings = {
    zoom: number;
    offsetX: number;
    offsetY: number;
};

export type UseTvStageOptions = {
    /** Design canvas size, read on every fit so it may change at runtime. */
    design: () => { width: number; height: number };
};

const defaultSettings = (): TvStageSettings => ({
    zoom: 1,
    offsetX: 0,
    offsetY: 0,
});

const clampSettings = (raw: unknown): TvStageSettings => {
    const source = (raw ?? {}) as Partial<
        Record<keyof TvStageSettings, unknown>
    >;
    const zoom = Number.parseFloat(String(source.zoom));
    const offsetX = Number.parseFloat(String(source.offsetX));
    const offsetY = Number.parseFloat(String(source.offsetY));

    return {
        zoom: Number.isNaN(zoom)
            ? 1
            : Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom)),
        offsetX: Number.isNaN(offsetX) ? 0 : Math.round(offsetX),
        offsetY: Number.isNaN(offsetY) ? 0 : Math.round(offsetY),
    };
};

export function useTvStage({ design }: UseTvStageOptions) {
    const settings = ref<TvStageSettings>(defaultSettings());
    const scale = ref(1);
    const viewportWidth = ref(0);
    const viewportHeight = ref(0);

    let safetyNetTimer: ReturnType<typeof setInterval> | null = null;
    let resizeObserver: ResizeObserver | null = null;
    let fitPending = false;

    /**
     * Keyed by physical screen so a laptop docked to a 4K TV keeps a separate
     * calibration from its built-in panel. localStorage is already per-device
     * and per-browser, so this only has to separate resolutions.
     */
    const storageKey = () => {
        if (typeof window === 'undefined') {
            return STORAGE_PREFIX + 'ssr';
        }

        const screen = window.screen as Screen | undefined;
        const ratio = Math.round((window.devicePixelRatio || 1) * 100) / 100;

        return `${STORAGE_PREFIX}${screen?.width ?? 0}x${screen?.height ?? 0}@${ratio}`;
    };

    const profileLabel = computed(() =>
        storageKey().replace(STORAGE_PREFIX, ''),
    );

    const saveSettings = () => {
        try {
            window.localStorage.setItem(
                storageKey(),
                JSON.stringify(settings.value),
            );
        } catch {
            // Nothing to do; the calibration just will not survive a reload.
        }
    };

    const loadSettings = (): TvStageSettings => {
        try {
            const raw = window.localStorage.getItem(storageKey());

            if (raw) {
                return clampSettings(JSON.parse(raw));
            }
        } catch {
            // Private mode / storage disabled - fall back to defaults.
        }

        return defaultSettings();
    };

    /**
     * One-shot URL overrides so an operator can calibrate a remote-only TV from
     * the address bar. The value is persisted, so it is typed once.
     */
    const applyUrlOverrides = () => {
        const search = window.location.search;

        if (/[?&]stagereset=1/.test(search)) {
            settings.value = defaultSettings();
            saveSettings();

            return;
        }

        const zoom = /[?&]zoom=([^&]+)/.exec(search);

        if (zoom) {
            settings.value = clampSettings({
                ...settings.value,
                zoom: Number.parseFloat(decodeURIComponent(zoom[1])),
            });
            saveSettings();
        }
    };

    const fit = () => {
        const { width, height } = design();

        // clientWidth/clientHeight describe the initial containing block -
        // exactly the box the stage viewport fills. innerWidth would also count
        // the scrollbar gutter.
        const root = document.documentElement;
        const availableWidth = root.clientWidth || window.innerWidth;
        const availableHeight = root.clientHeight || window.innerHeight;

        // Some TV browsers report a 0-sized viewport before their first paint;
        // keep the previous scale instead of collapsing the board.
        if (!availableWidth || !availableHeight || !width || !height) {
            return;
        }

        viewportWidth.value = availableWidth;
        viewportHeight.value = availableHeight;
        scale.value =
            Math.min(availableWidth / width, availableHeight / height) *
            settings.value.zoom;
    };

    const scheduleFit = () => {
        if (fitPending) {
            return;
        }

        fitPending = true;
        window.requestAnimationFrame(() => {
            fitPending = false;
            fit();
        });
    };

    const zoomBy = (delta: number) => {
        settings.value = clampSettings({
            ...settings.value,
            zoom: Math.round((settings.value.zoom + delta) * 1000) / 1000,
        });
        saveSettings();
        fit();
    };

    const nudge = (deltaX: number, deltaY: number) => {
        settings.value = clampSettings({
            ...settings.value,
            offsetX: settings.value.offsetX + deltaX,
            offsetY: settings.value.offsetY + deltaY,
        });
        saveSettings();
        fit();
    };

    const reset = () => {
        settings.value = defaultSettings();
        saveSettings();
        fit();
    };

    onMounted(() => {
        settings.value = loadSettings();
        applyUrlOverrides();
        fit();

        window.addEventListener('resize', scheduleFit);
        window.addEventListener('orientationchange', scheduleFit);
        document.addEventListener('fullscreenchange', scheduleFit);
        document.addEventListener('webkitfullscreenchange', scheduleFit);
        window.visualViewport?.addEventListener('resize', scheduleFit);

        if (window.ResizeObserver) {
            resizeObserver = new ResizeObserver(scheduleFit);
            resizeObserver.observe(document.documentElement);
        }

        // Safety net: several TV browsers fire `resize` late, once, or not at
        // all after a source / resolution change.
        safetyNetTimer = setInterval(fit, FIT_SAFETY_NET_MS);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('resize', scheduleFit);
        window.removeEventListener('orientationchange', scheduleFit);
        document.removeEventListener('fullscreenchange', scheduleFit);
        document.removeEventListener('webkitfullscreenchange', scheduleFit);
        window.visualViewport?.removeEventListener('resize', scheduleFit);
        resizeObserver?.disconnect();
        resizeObserver = null;

        if (safetyNetTimer !== null) {
            clearInterval(safetyNetTimer);
            safetyNetTimer = null;
        }
    });

    return {
        NUDGE_STEP,
        ZOOM_STEP,
        fit,
        nudge,
        offsetX: computed(() => settings.value.offsetX),
        offsetY: computed(() => settings.value.offsetY),
        profileLabel,
        reset,
        scale: computed(() => scale.value),
        viewportHeight: computed(() => viewportHeight.value),
        viewportWidth: computed(() => viewportWidth.value),
        zoom: computed(() => settings.value.zoom),
        zoomBy,
    };
}
