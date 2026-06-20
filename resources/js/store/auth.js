import { defineStore } from 'pinia';
import axios from 'axios';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        isInitialized: false,
    }),
    getters: {
        isAuthenticated: (state) => !!state.user,
    },
    actions: {
        async fetchUser() {
            try {
                const response = await axios.get('/api/user');
                this.user = response.data;
            } catch (error) {
                this.user = null;
            } finally {
                this.isInitialized = true;
            }
        },
        async login(credentials) {
            await axios.post('/api/login', credentials);
            await this.fetchUser();
        },
        async register(details) {
            await axios.post('/api/register', details);
            await this.fetchUser();
        },
        async logout() {
            await axios.post('/api/logout');
            this.user = null;
        }
    }
});
