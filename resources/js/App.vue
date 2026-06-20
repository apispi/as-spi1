<template>
  <div class="app-container">
    <header class="app-header">
      <div class="logo">
        <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        <h1>ApiSpi Tester</h1>
      </div>
      <div class="auth-menu" v-if="authStore.isAuthenticated">
        <router-link v-if="authStore.user.is_admin" to="/admin" class="admin-link">Admin</router-link>
        <span class="user-name">{{ authStore.user.name }}</span>
        <button @click="handleLogout" class="btn btn-logout">Logout</button>
      </div>
    </header>
    <router-view></router-view>
  </div>
</template>

<script setup>
import { useAuthStore } from './store/auth';
import { useRouter } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
.app-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
  background-color: var(--bg-color);
}

.app-header {
  height: 60px;
  background-color: var(--panel-bg);
  border-bottom: 1px solid var(--border-color);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
}

.logo {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--accent-color);
}

.logo h1 {
  font-size: 18px;
  font-weight: 600;
  margin: 0;
  color: var(--text-primary);
  letter-spacing: -0.5px;
}

.auth-menu {
  display: flex;
  align-items: center;
  gap: 16px;
}

.user-name {
  color: var(--text-secondary);
  font-size: 14px;
  font-weight: 500;
}

.btn-logout {
  background: none;
  border: 1px solid var(--border-color);
  color: var(--text-primary);
  padding: 6px 12px;
  border-radius: 4px;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-logout:hover {
  background-color: rgba(248, 81, 73, 0.1);
  color: #ff7b72;
  border-color: #ff7b72;
}

.admin-link {
  color: #d29922;
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: 4px;
  border: 1px solid rgba(210, 153, 34, 0.3);
  background: rgba(210, 153, 34, 0.1);
  transition: all 0.2s;
}
.admin-link:hover {
  background: rgba(210, 153, 34, 0.2);
  border-color: #d29922;
}
</style>
