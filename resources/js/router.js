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
import Developers from './views/Developers.vue';
import AiLab from './views/AiLab.vue';
import Explore from './views/Explore.vue';
import Reports from './views/Reports.vue';
import SharedReport from './views/SharedReport.vue';
import StatusPage from './views/StatusPage.vue';
import Tester from './views/Tester.vue';
import Monitors from './views/Monitors.vue';
import Webhooks from './views/Webhooks.vue';
import Recorder from './views/Recorder.vue';
import Collections from './views/Collections.vue';
import AdminOrganisations from './views/AdminOrganisations.vue';
import AdminMonitoring from './views/AdminMonitoring.vue';
import AdminUserDetail from './views/AdminUserDetail.vue';
import AdminUsers from './views/AdminUsers.vue';
import Terms from './views/Terms.vue';
import Privacy from './views/Privacy.vue';

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
        path: '/tester',
        name: 'tester',
        component: Tester,
        meta: { requiresAuth: true }
    },
    {
        path: '/collections',
        name: 'collections',
        component: Collections,
        meta: { requiresAuth: true }
    },
    {
        path: '/recorder',
        name: 'recorder',
        component: Recorder,
        meta: { requiresAuth: true }
    },
    {
        path: '/webhooks',
        name: 'webhooks',
        component: Webhooks,
        meta: { requiresAuth: true }
    },
    {
        path: '/monitors',
        name: 'monitors',
        component: Monitors,
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
        path: '/ai-lab',
        name: 'ai-lab',
        component: AiLab,
        meta: { requiresAuth: true }
    },
    {
        path: '/explore',
        name: 'explore',
        component: Explore,
        meta: { requiresAuth: true }
    },
    {
        path: '/reports',
        name: 'reports',
        component: Reports,
        meta: { requiresAuth: true }
    },
    {
        path: '/r/:token',
        name: 'shared-report',
        component: SharedReport
    },
    {
        path: '/status/:token',
        name: 'status-page',
        component: StatusPage
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
        path: '/developers',
        name: 'developers',
        component: Developers
    },
    {
        path: '/terms',
        name: 'terms',
        component: Terms
    },
    {
        path: '/privacy',
        name: 'privacy',
        component: Privacy
    },
    {
        path: '/admin',
        name: 'admin',
        component: Admin,
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/admin/users',
        name: 'admin-users',
        component: AdminUsers,
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/admin/users/:id',
        name: 'admin-user',
        component: AdminUserDetail,
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/admin/organisations',
        name: 'admin-organisations',
        component: AdminOrganisations,
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/admin/monitoring',
        name: 'admin-monitoring',
        component: AdminMonitoring,
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
