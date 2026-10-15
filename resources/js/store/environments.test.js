import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

/**
 * The environments store remembers the selected environment in localStorage.
 *
 * Storage access can throw outright rather than just come back empty — a
 * browser with site data blocked, or a privacy mode that disallows it. This
 * store read storage while building its initial state, so that throw took the
 * store, and with it the tester, down at startup. theme.js and onboarding.js
 * had both already guarded for it; this one had not.
 */
describe('environments store storage', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.resetModules();
    });

    afterEach(() => vi.unstubAllGlobals());

    /** Replace localStorage with one that throws, as a locked-down browser does. */
    function denyStorage() {
        vi.stubGlobal('localStorage', {
            get getItem() { throw new Error('SecurityError: storage is not available'); },
            get setItem() { throw new Error('SecurityError: storage is not available'); },
            get removeItem() { throw new Error('SecurityError: storage is not available'); },
        });
    }

    /**
     * A plain working store. Stubbed rather than using jsdom's, so the test
     * does not depend on how jsdom attaches localStorage to the window.
     */
    function allowStorage(initial = {}) {
        const data = { ...initial };

        vi.stubGlobal('localStorage', {
            getItem: (k) => (k in data ? data[k] : null),
            setItem: (k, v) => { data[k] = String(v); },
            removeItem: (k) => { delete data[k]; },
        });

        return data;
    }

    it('still initialises when storage is unavailable', async () => {
        denyStorage();

        const { useEnvironmentsStore } = await import('./environments');
        const store = useEnvironmentsStore();

        expect(store.selectedId).toBe(null);
        expect(store.environments).toEqual([]);
    });

    it('still applies a selection for the session when it cannot be persisted', async () => {
        denyStorage();

        const { useEnvironmentsStore } = await import('./environments');
        const store = useEnvironmentsStore();

        expect(() => store.select(7)).not.toThrow();
        // Remembering it is a convenience; using it is not.
        expect(store.selectedId).toBe(7);
    });

    it('reads a remembered selection back when storage works', async () => {
        const data = allowStorage({ 'spi.selectedEnvironmentId': '3' });

        const { useEnvironmentsStore } = await import('./environments');
        const store = useEnvironmentsStore();

        expect(store.selectedId).toBe(3);

        store.select(9);
        expect(data['spi.selectedEnvironmentId']).toBe('9');

        store.select(null);
        expect('spi.selectedEnvironmentId' in data).toBe(false);
    });
});
