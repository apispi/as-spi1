import { defineStore } from 'pinia';
import axios from 'axios';

export const useCollectionsStore = defineStore('collections', {
    state: () => ({
        collections: [],
        isLoading: false,
        loaded: false,
        lastRun: null,
    }),
    actions: {
        async fetch() {
            this.isLoading = true;
            try {
                const res = await axios.get('/api/collections');
                this.collections = res.data;
                this.loaded = true;
            } catch (error) {
                console.error('Failed to fetch collections', error);
            } finally {
                this.isLoading = false;
            }
        },
        async save(payload, id = null) {
            const res = id
                ? await axios.put(`/api/collections/${id}`, payload)
                : await axios.post('/api/collections', payload);

            const i = this.collections.findIndex((c) => c.id === res.data.id);
            if (i === -1) this.collections.push(res.data);
            else this.collections[i] = res.data;

            this.collections.sort((a, b) => a.name.localeCompare(b.name));
            return res.data;
        },
        async remove(id) {
            await axios.delete(`/api/collections/${id}`);
            this.collections = this.collections.filter((c) => c.id !== id);
        },
        /**
         * A run answers 200 when every step passed and 422 when any failed, so
         * a failed run is a normal result here rather than an error.
         */
        async run(id, environmentId) {
            this.lastRun = null;
            try {
                const res = await axios.post(`/api/collections/${id}/run`, {
                    environment_id: environmentId || null,
                });
                this.lastRun = res.data;
            } catch (e) {
                if (e.response?.status === 422 && e.response.data?.steps) {
                    this.lastRun = e.response.data;
                } else {
                    throw e;
                }
            }
            return this.lastRun;
        },
    },
});
