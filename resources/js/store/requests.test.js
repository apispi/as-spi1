import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useRequestsStore } from './requests';

/**
 * The list store, which used to swallow a failed fetch into an empty array —
 * so the view rendered "Nothing saved yet" at a user whose requests were
 * sitting safely on the server.
 */
describe('requests store', () => {
    beforeEach(() => setActivePinia(createPinia()));
    afterEach(() => vi.restoreAllMocks());

    it('records that the list failed to load', async () => {
        vi.spyOn(axios, 'get').mockRejectedValue(new Error('network down'));
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const store = useRequestsStore();
        await store.fetchSavedRequests();

        // The empty array stays — the UI has to render something — but it is
        // now accompanied by the reason, so "none" and "broken" are distinct.
        expect(store.savedRequests).toEqual([]);
        expect(store.loadError).toBeTruthy();
        expect(store.isLoading).toBe(false);
    });

    it('clears the error once a later fetch succeeds', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const get = vi.spyOn(axios, 'get').mockRejectedValue(new Error('network down'));

        const store = useRequestsStore();
        await store.fetchSavedRequests();
        expect(store.loadError).toBeTruthy();

        get.mockResolvedValue({ data: [{ id: 1, name: 'List users' }] });
        await store.fetchSavedRequests();

        expect(store.loadError).toBe(null);
        expect(store.savedRequests).toHaveLength(1);
    });

    it('reports no error on a genuinely empty account', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: [] });

        const store = useRequestsStore();
        await store.fetchSavedRequests();

        expect(store.savedRequests).toEqual([]);
        expect(store.loadError).toBe(null);
    });

    it('keeps the delete response, so a caller can say what it removed', async () => {
        // Deleting a saved request cascades into collection steps; the caller
        // needs the count to tell the user which suites got shorter.
        vi.spyOn(axios, 'get').mockResolvedValue({ data: [{ id: 7, name: 'Doomed' }] });
        vi.spyOn(axios, 'delete').mockResolvedValue({
            data: { message: 'Deleted', removed_steps: 2, used_by: ['Smoke'] },
        });

        const store = useRequestsStore();
        await store.fetchSavedRequests();
        const result = await store.deleteRequest(7);

        expect(result.removed_steps).toBe(2);
        expect(store.savedRequests).toEqual([]);
    });
});
