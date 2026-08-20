import { defineStore } from 'pinia';
import axios from 'axios';

export const useMonitorsStore = defineStore('monitors', {
    state: () => ({
        monitors: [],
        isLoading: false,
        loaded: false,
    }),
    actions: {
        async fetch() {
            this.isLoading = true;
            try {
                const res = await axios.get('/api/monitors');
                this.monitors = res.data;
                this.loaded = true;
            } catch (error) {
                console.error('Failed to fetch monitors', error);
            } finally {
                this.isLoading = false;
            }
        },
        async show(id) {
            const res = await axios.get(`/api/monitors/${id}`);
            return res.data;
        },
        async save(payload, id = null) {
            const res = id
                ? await axios.put(`/api/monitors/${id}`, payload)
                : await axios.post('/api/monitors', payload);

            const i = this.monitors.findIndex((m) => m.id === res.data.id);
            if (i === -1) this.monitors.push(res.data);
            else this.monitors[i] = res.data;

            this.monitors.sort((a, b) => a.name.localeCompare(b.name));
            return res.data;
        },
        async remove(id) {
            await axios.delete(`/api/monitors/${id}`);
            this.monitors = this.monitors.filter((m) => m.id !== id);
        },
        /**
         * A run answers 422 when the collection failed — that is a result, not
         * an error, so unwrap it the same way a pass is unwrapped.
         */
        async run(id) {
            let data;
            try {
                data = (await axios.post(`/api/monitors/${id}/run`)).data;
            } catch (e) {
                if (e.response?.status === 422 && e.response.data?.last_status) {
                    data = e.response.data;
                } else {
                    throw e;
                }
            }
            const i = this.monitors.findIndex((m) => m.id === id);
            if (i !== -1) this.monitors[i] = { ...this.monitors[i], ...data };
            return data;
        },
    },
});
