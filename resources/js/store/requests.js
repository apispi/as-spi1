import { defineStore } from 'pinia';
import axios from 'axios';

export const useRequestsStore = defineStore('requests', {
    state: () => ({
        savedRequests: [],
        isLoading: false,
        // Handed to the tester when a request is opened from another view.
        // Consumed once, so returning to the tester later starts clean.
        pendingLoad: null,
    }),
    actions: {
        async fetchSavedRequests() {
            this.isLoading = true;
            try {
                const response = await axios.get('/api/saved-requests');
                this.savedRequests = response.data;
            } catch (error) {
                console.error("Failed to fetch saved requests", error);
            } finally {
                this.isLoading = false;
            }
        },
        openInTester(request) {
            this.pendingLoad = { ...request };
        },
        takePendingLoad() {
            const pending = this.pendingLoad;
            this.pendingLoad = null;
            return pending;
        },
        async saveRequest(requestData) {
            try {
                const response = await axios.post('/api/saved-requests', requestData);
                this.savedRequests.unshift(response.data);
                return response.data;
            } catch (error) {
                console.error("Failed to save request", error);
                throw error;
            }
        },
        async deleteRequest(id) {
            try {
                await axios.delete(`/api/saved-requests/${id}`);
                this.savedRequests = this.savedRequests.filter(req => req.id !== id);
            } catch (error) {
                console.error("Failed to delete request", error);
                throw error;
            }
        }
    }
});
