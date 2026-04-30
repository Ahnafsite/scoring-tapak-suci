import { computed, onMounted, onUnmounted, ref } from 'vue';

type UseFullscreenLockOptions = {
    requiredExitClicks?: number;
};

const FULLSCREEN_LOCK_KEY = '__fightFullscreenLock';

export function useFullscreenLock(options: UseFullscreenLockOptions = {}) {
    const requiredExitClicks = options.requiredExitClicks ?? 5;
    const isFullscreen = ref(false);
    const exitClickCount = ref(0);
    const historyToken = `${Date.now()}-${Math.random().toString(36).slice(2)}`;

    const remainingExitClicks = computed(() =>
        Math.max(requiredExitClicks - exitClickCount.value, 0),
    );

    const buttonTitle = computed(() =>
        isFullscreen.value
            ? `Klik ${remainingExitClicks.value} kali lagi untuk keluar fullscreen`
            : 'Masuk fullscreen',
    );

    const pushFullscreenHistoryState = () => {
        if (typeof window === 'undefined' || !isFullscreen.value) {
            return;
        }

        const currentState =
            typeof history.state === 'object' && history.state !== null
                ? history.state
                : {};

        if (currentState[FULLSCREEN_LOCK_KEY] === historyToken) {
            return;
        }

        history.pushState(
            { ...currentState, [FULLSCREEN_LOCK_KEY]: historyToken },
            '',
            window.location.href,
        );
    };

    const preventBackWhileFullscreen = () => {
        if (!isFullscreen.value) {
            return;
        }

        pushFullscreenHistoryState();
    };

    const isEditableTarget = (eventTarget: EventTarget | null) => {
        if (!(eventTarget instanceof HTMLElement)) {
            return false;
        }

        return Boolean(
            eventTarget.closest(
                'input, textarea, select, [contenteditable="true"]',
            ),
        );
    };

    const preventBackShortcutWhileFullscreen = (event: KeyboardEvent) => {
        if (!isFullscreen.value) {
            return;
        }

        const isBrowserBackShortcut =
            event.key === 'BrowserBack' ||
            event.key === 'GoBack' ||
            (event.altKey && event.key === 'ArrowLeft') ||
            (event.metaKey && event.key === '[');

        if (isBrowserBackShortcut) {
            event.preventDefault();
            event.stopPropagation();
            pushFullscreenHistoryState();
            return;
        }

        if (event.key === 'Backspace' && !isEditableTarget(event.target)) {
            event.preventDefault();
            event.stopPropagation();
        }
    };

    const syncFullscreenState = () => {
        isFullscreen.value = Boolean(document.fullscreenElement);

        if (isFullscreen.value) {
            pushFullscreenHistoryState();
            return;
        }

        exitClickCount.value = 0;
    };

    const requestFullscreen = async () => {
        if (typeof document === 'undefined') {
            return;
        }

        await document.documentElement.requestFullscreen();
        exitClickCount.value = 0;
    };

    const exitFullscreen = async () => {
        if (typeof document === 'undefined' || !document.fullscreenElement) {
            return;
        }

        await document.exitFullscreen();
        exitClickCount.value = 0;
    };

    const triggerFullscreen = async () => {
        if (!isFullscreen.value) {
            await requestFullscreen();
            return;
        }

        exitClickCount.value += 1;

        if (exitClickCount.value >= requiredExitClicks) {
            await exitFullscreen();
        }
    };

    onMounted(() => {
        syncFullscreenState();
        document.addEventListener('fullscreenchange', syncFullscreenState);
        window.addEventListener('popstate', preventBackWhileFullscreen);
        window.addEventListener('keydown', preventBackShortcutWhileFullscreen);
    });

    onUnmounted(() => {
        document.removeEventListener('fullscreenchange', syncFullscreenState);
        window.removeEventListener('popstate', preventBackWhileFullscreen);
        window.removeEventListener(
            'keydown',
            preventBackShortcutWhileFullscreen,
        );
    });

    return {
        buttonTitle,
        exitClickCount,
        isFullscreen,
        remainingExitClicks,
        requiredExitClicks,
        triggerFullscreen,
    };
}
