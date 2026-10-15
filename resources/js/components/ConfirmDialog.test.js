import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ConfirmDialog from './ConfirmDialog.vue';
import { confirmDialog, confirmState, resolveConfirm } from '../confirm';

/**
 * The dialog that stands in for native confirm(), and is therefore the last
 * thing between a click and a deletion.
 *
 * It used to focus Cancel and bind Enter to confirm, with preventDefault
 * stopping the focused button activating — so on "Delete X?" it showed Cancel
 * focused while Enter deleted. That was found by reading, not by a test. This
 * is the test.
 */
describe('ConfirmDialog', () => {
    afterEach(() => {
        if (confirmState.open) resolveConfirm(false);
    });

    const press = (key) =>
        window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));

    async function open(message = 'Delete this?') {
        const wrapper = mount(ConfirmDialog, { attachTo: document.body });
        const answer = confirmDialog(message);
        await nextTick();
        await nextTick();

        return { wrapper, answer };
    }

    it('focuses the safe option, not the destructive one', async () => {
        const { wrapper, answer } = await open();

        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');

        resolveConfirm(false);
        await answer;
        wrapper.unmount();
    });

    it('does not confirm on Enter — the focused control decides', async () => {
        // The regression this file exists for. Enter must not be a one-key
        // path to the destructive action while Cancel is the focused button.
        const { wrapper, answer } = await open();

        press('Enter');
        await nextTick();

        expect(confirmState.open).toBe(true);

        resolveConfirm(false);
        expect(await answer).toBe(false);
        wrapper.unmount();
    });

    it('cancels on Escape', async () => {
        const { wrapper, answer } = await open();

        press('Escape');

        expect(await answer).toBe(false);
        expect(confirmState.open).toBe(false);
        wrapper.unmount();
    });

    it('resolves true only when the confirm button is activated', async () => {
        const { wrapper, answer } = await open();

        await wrapper.find('.cd-btn.confirm').trigger('click');

        expect(await answer).toBe(true);
        wrapper.unmount();
    });

    it('keeps Tab inside the dialog', async () => {
        // Focus walking out of an aria-modal dialog is both confusing and a
        // way to reach controls that are supposed to be blocked.
        const { wrapper, answer } = await open();

        const buttons = wrapper.findAll('.cd-btn');
        const last = buttons[buttons.length - 1].element;
        last.focus();

        const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        window.dispatchEvent(event);
        await nextTick();

        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(buttons[0].element);

        resolveConfirm(false);
        await answer;
        wrapper.unmount();
    });
});
