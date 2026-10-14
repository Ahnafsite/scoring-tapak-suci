import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Open/closed state for a control panel's schedule sidebar.
 *
 * The sidebar costs 368px, which a desktop can spare and a tablet cannot - on
 * a 1024px screen it leaves the operator 656px to run a match in. So below the
 * breakpoint the sidebar starts closed and opens as an overlay, and at or above
 * it the sidebar starts open and sits in the layout. Either way the operator
 * can toggle it; crossing the breakpoint (a tablet being rotated, a window
 * being resized) re-applies the default for the new size.
 */

const WIDE_SCREEN_QUERY = '(min-width: 1280px)';

export function useControlSidebar(query: string = WIDE_SCREEN_QUERY) {
    const matchesWideScreen = () =>
        typeof window === 'undefined' ? true : window.matchMedia(query).matches;

    const isWideScreen = ref(matchesWideScreen());
    const isOpen = ref(isWideScreen.value);

    let mediaQuery: MediaQueryList | null = null;

    const applyDefault = () => {
        isWideScreen.value = matchesWideScreen();
        isOpen.value = isWideScreen.value;
    };

    const open = () => {
        isOpen.value = true;
    };

    const close = () => {
        isOpen.value = false;
    };

    const toggle = () => {
        isOpen.value = !isOpen.value;
    };

    /** Closes an overlaying sidebar once the operator has picked something. */
    const closeOnNarrowScreen = () => {
        if (!isWideScreen.value) {
            close();
        }
    };

    onMounted(() => {
        applyDefault();

        mediaQuery = window.matchMedia(query);
        mediaQuery.addEventListener('change', applyDefault);
    });

    onBeforeUnmount(() => {
        mediaQuery?.removeEventListener('change', applyDefault);
        mediaQuery = null;
    });

    return {
        close,
        closeOnNarrowScreen,
        isOpen,
        isWideScreen,
        open,
        toggle,
    };
}
