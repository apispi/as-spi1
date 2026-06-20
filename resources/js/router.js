import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './store/auth';

import Dashboard from './views/Dashboard.vue';
import Login from './views/Login.vue';
import Register from './views/Register.vue';
import Admin from './views/Admin.vue';

const routes = [
    {
        path: '/',
        name: 'dashboard',
        component: Dashboard,
        meta: { requiresAuth: true }
    },
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: { guestOnly: true }
    },
    {
        path: '/register',
        name: 'register',
        component: Register,
        meta: { guestOnly: true }
    },
    {
        path: '/admin',
        name: 'admin',
        component: Admin,
        meta: { requiresAuth: true, requiresAdmin: true }
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes
});

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();
    
    // Ensure we've checked for a logged-in user before initial navigation
    if (!authStore.isInitialized) {
        await authStore.fetchUser();
    }

    const isAuthenticated = authStore.isAuthenticated;

    if (to.meta.requiresAuth && !isAuthenticated) {
        next('/login');
    } else if (to.meta.guestOnly && isAuthenticated) {
        next('/');
    } else if (to.meta.requiresAdmin && (!authStore.user || !authStore.user.is_admin)) {
        next('/');
    } else {
        next();
    }
});

export default router;
