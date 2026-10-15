import { onMounted, onUnmounted } from 'vue';

/**
 * Close the topmost open overlay in a view when Escape is pressed.
 *
 * The shared dialog components each handled this themselves; the modals
 * declared inline in views did not, so they could only be dismissed by
 * clicking the scrim or hunting for the X. That is fine with a mouse and a
 * dead end without one.
 *
 * Pass the view's overlay state in the order it stacks — innermost first — as
 * pairs of [isOpen, close]. Only the first open one is closed per press, so
 * Escape unwinds a stack one layer at a time rather than clearing everything
 * at once.
 *
 * @param {() => Array<[unknown, () => void]>} layers
 */
export function useEscape(layers) {
    const onKey = (event) => {
        if (event.key !== 'Escape') return;

        for (const [isOpen, close] of layers()) {
            if (isOpen) {
                event.preventDefault();
                close();

                return;
            }
        }
    };

    onMounted(() => window.addEventListener('keydown', onKey));
    onUnmounted(() => window.removeEventListener('keydown', onKey));
}
