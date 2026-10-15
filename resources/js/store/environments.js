import { defineStore } from 'pinia';
import axios from 'axios';

const SELECTED_KEY = 'spi.selectedEnvironmentId';

// Storage access can throw outright, not just come back empty — a browser with
// site data blocked, or a privacy mode that disallows it. theme.js and
// onboarding.js both guard for that; this store did not, and it read storage
// while building its initial state, so the throw would take the store (and
// with it the tester) down at startup. Remembering the selected environment is
// a convenience and must never be load-bearing.
const remembered = () => {
    try {
        return Number(localStorage.getItem(SELECTED_KEY)) || null;
    } catch {
        return null;
    }
};

const remember = (id) => {
    try {
        if (id) {
            localStorage.setItem(SELECTED_KEY, String(id));
        } else {
            localStorage.removeItem(SELECTED_KEY);
        }
    } catch {
        // Nothing to do: the selection still applies for this session.
    }
};

export const useEnvironmentsStore = defineStore('environments', {
    state: () => ({
        environments: [],
        selectedId: remembered(),
        isLoading: false,
        // Set when the list fetch fails, so a view can say so instead of
        // rendering its empty state — \"you have none\" and \"it did not
        // load\" look identical otherwise.
        loadError: null,
        loaded: false,
    }),
    getters: {
        selected: (state) => state.environments.find((e) => e.id === state.selectedId) || null,
        // Every variable name the current environment defines, for hinting in
        // the editor.
        selectedKeys: (state) => {
            const env = state.environments.find((e) => e.id === state.selectedId);
            return env ? env.variables.map((v) => v.key) : [];
        },
    },
    actions: {
        async fetch() {
            this.isLoading = true;
            this.loadError = null;
            try {
                const res = await axios.get('/api/environments');
                this.environments = res.data;

                // Fall back to the default environment when nothing is
                // remembered, or when the remembered one was deleted.
                if (!this.environments.some((e) => e.id === this.selectedId)) {
                    const fallback = this.environments.find((e) => e.is_default);
                    this.select(fallback ? fallback.id : null);
                }
                this.loaded = true;
            } catch (error) {
                console.error('Failed to fetch environments', error);
                this.loadError = 'Could not load your environments.';
            } finally {
                this.isLoading = false;
            }
        },
        select(id) {
            this.selectedId = id;
            remember(id);
        },
        async create(payload) {
            const res = await axios.post('/api/environments', payload);
            this.environments.push(res.data);
            this.sort();
            this.select(res.data.id);
            return res.data;
        },
        async update(id, payload) {
            const res = await axios.put(`/api/environments/${id}`, payload);
            const i = this.environments.findIndex((e) => e.id === id);
            if (i !== -1) this.environments[i] = res.data;
            if (res.data.is_default) {
                this.environments.forEach((e) => {
                    if (e.id !== id) e.is_default = false;
                });
            }
            this.sort();
            return res.data;
        },
        async remove(id) {
            await axios.delete(`/api/environments/${id}`);
            this.environments = this.environments.filter((e) => e.id !== id);
            if (this.selectedId === id) {
                this.select(null);
            }
        },
        sort() {
            this.environments.sort((a, b) => a.name.localeCompare(b.name));
        },
    },
});
