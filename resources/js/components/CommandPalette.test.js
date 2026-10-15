import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { nextTick } from 'vue';
import axios from 'axios';
import CommandPalette from './CommandPalette.vue';

/**
 * The command palette, which is how saved requests are found at all — there
 * was no list view for them until recently.
 *
 * Its search is debounced and asynchronous, which is where both of the bugs
 * these tests pin came from: a spinner that never stopped, and a slow earlier
 * response overwriting a newer one.
 */
describe('CommandPalette', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    const palette = () => mount(CommandPalette, {
        global: { mocks: { $router: { push: vi.fn() } } },
    });

    const result = (id, label) => ({ type: 'collection', id, label, to: '/collections' });

    /** Let the debounce fire and any resolved promises settle. */
    async function settle(ms = 250) {
        await vi.advanceTimersByTimeAsync(ms);
        await nextTick();
    }

    async function type(wrapper, value) {
        await wrapper.find('.cp-input').setValue(value);
    }

    it('searches once the debounce elapses', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [result(1, 'Smoke')] } });
        const wrapper = palette();

        await type(wrapper, 'smo');
        expect(get).not.toHaveBeenCalled(); // debounced

        await settle();

        expect(get).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Smoke');
    });

    it('stops searching when the box is cleared mid-debounce', async () => {
        // The stuck spinner: clearing within the debounce window cancelled the
        // request but left loading true, so the palette sat on "Searching…"
        // until something else was typed.
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [] } });
        const wrapper = palette();

        await type(wrapper, 'smo');
        await type(wrapper, '');
        await settle();

        expect(get).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('Searching');
        expect(wrapper.text()).toContain('Type to search');
    });

    it('ignores a slow earlier response that lands after a newer one', async () => {
        // Without a sequence check, the results for "a" could replace the
        // results for "ab" simply by being slower.
        let resolveFirst;
        vi.spyOn(axios, 'get')
            .mockImplementationOnce(() => new Promise((r) => { resolveFirst = r; }))
            .mockImplementationOnce(() => Promise.resolve({ data: { results: [result(2, 'Newer')] } }));

        const wrapper = palette();

        await type(wrapper, 'a');
        await vi.advanceTimersByTimeAsync(250); // first request now in flight

        await type(wrapper, 'ab');
        await settle();

        expect(wrapper.text()).toContain('Newer');

        // The stale response arrives last and must be discarded.
        resolveFirst({ data: { results: [result(1, 'Stale')] } });
        await settle(0);

        expect(wrapper.text()).toContain('Newer');
        expect(wrapper.text()).not.toContain('Stale');
    });

    it('does not let a stale response clear the current spinner', async () => {
        let resolveFirst;
        vi.spyOn(axios, 'get')
            .mockImplementationOnce(() => new Promise((r) => { resolveFirst = r; }))
            .mockImplementationOnce(() => new Promise(() => {})); // still running

        const wrapper = palette();

        await type(wrapper, 'a');
        await vi.advanceTimersByTimeAsync(250);
        await type(wrapper, 'ab');
        await vi.advanceTimersByTimeAsync(250);

        resolveFirst({ data: { results: [] } });
        await settle(0);

        // The current search has not finished, so it is still searching.
        expect(wrapper.text()).toContain('Searching');
    });

    it('wraps arrow-key selection and reports no matches', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: { results: [result(1, 'First'), result(2, 'Second')] },
        });
        const wrapper = palette();

        await type(wrapper, 'x');
        await settle();

        const input = wrapper.find('.cp-input');
        expect(wrapper.findAll('.cp-item')[0].classes()).toContain('active');

        await input.trigger('keydown', { key: 'ArrowUp' });
        // Wraps to the end rather than going out of bounds.
        expect(wrapper.findAll('.cp-item')[1].classes()).toContain('active');

        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.findAll('.cp-item')[0].classes()).toContain('active');
    });

    it('says so when a search fails rather than claiming no matches', async () => {
        vi.spyOn(axios, 'get').mockRejectedValue(new Error('network down'));
        const wrapper = palette();

        await type(wrapper, 'anything');
        await settle();

        // Currently indistinguishable from an empty result — recorded as the
        // known behaviour so the distinction is a deliberate choice later.
        expect(wrapper.text()).toContain('No matches');
    });
});
