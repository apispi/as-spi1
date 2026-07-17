import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './store/auth';

import Home from './views/Home.vue';
import Dashboard from './views/Dashboard.vue';
import Login from './views/Login.vue';
import Register from './views/Register.vue';
import Admin from './views/Admin.vue';
import Profile from './views/Profile.vue';
import Chat from './views/Chat.vue';
import CatalogSection from './views/CatalogSection.vue';
import CompleteRegistration from './views/CompleteRegistration.vue';

const routes = [
    {
        path: '/',
        name: 'home',
        component: Home,
        meta: { guestOnly: true }
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: Dashboard,
        meta: { requiresAuth: true }
    },
    {
        path: '/profile',
        name: 'profile',
        component: Profile,
        meta: { requiresAuth: true }
    },
    {
        path: '/chat',
        name: 'chat',
        component: Chat,
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
        path: '/complete-registration',
        name: 'complete-registration',
        component: CompleteRegistration,
        meta: { guestOnly: true }
    },
    {
        path: '/admin',
        name: 'admin',
        component: Admin,
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/catalog',
        name: 'catalog',
        component: CatalogSection,
        meta: { requiresAuth: true, requiresAdmin: true, section: 'catalog' }
    },
    {
        path: '/active',
        name: 'active',
        component: CatalogSection,
        meta: { requiresAuth: true, requiresAdmin: true, section: 'active' }
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
        next('/dashboard');
    } else if (to.meta.requiresAdmin && (!authStore.user || !authStore.user.is_admin)) {
        next('/dashboard');
    } else {
        next();
    }
});

export default router;
