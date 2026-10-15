import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref, h } from 'vue';
import { useEscape } from './useEscape';

/**
 * Escape handling for the modals declared inline in views, which previously
 * had none — they could only be dismissed with a mouse.
 */
describe('useEscape', () => {
    /** A throwaway component so the composable has a lifecycle to bind to. */
    function harness(layers) {
        return mount({
            setup() {
                useEscape(layers);

                return () => h('div');
            },
        });
    }

    const press = (key = 'Escape') => {
        const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
        window.dispatchEvent(event);

        return event;
    };

    it('closes the innermost open layer only', () => {
        // A stack should unwind one press at a time, not collapse at once.
        const outer = ref(true);
        const inner = ref(true);

        const wrapper = harness(() => [
            [inner.value, () => { inner.value = false; }],
            [outer.value, () => { outer.value = false; }],
        ]);

        press();
        expect(inner.value).toBe(false);
        expect(outer.value).toBe(true);

        press();
        expect(outer.value).toBe(false);

        wrapper.unmount();
    });

    it('skips layers that are already closed', () => {
        const closed = ref(null);
        const open = ref({ id: 1 });

        const wrapper = harness(() => [
            [closed.value, () => { closed.value = 'should not run'; }],
            [open.value, () => { open.value = null; }],
        ]);

        press();

        expect(closed.value).toBe(null);
        expect(open.value).toBe(null);
        wrapper.unmount();
    });

    it('ignores other keys and does nothing when all layers are closed', () => {
        const state = ref(true);
        const wrapper = harness(() => [[state.value, () => { state.value = false; }]]);

        press('Enter');
        expect(state.value).toBe(true);

        state.value = false;
        expect(press().defaultPrevented).toBe(false);

        wrapper.unmount();
    });

    it('stops listening once the view is gone', () => {
        // Otherwise every visited view keeps handling Escape for the rest of
        // the session.
        const state = ref(true);
        harness(() => [[state.value, () => { state.value = false; }]]).unmount();

        press();

        expect(state.value).toBe(true);
    });
});
