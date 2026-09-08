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
            const res = await axios.post('/api/login', credentials);
            if (res.data && res.data.two_factor_required) {
                return { twoFactorRequired: true };
            }
            await this.fetchUser();
            return { twoFactorRequired: false };
        },
        async loginTwoFactor(code) {
            await axios.post('/api/login/2fa', { code });
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
