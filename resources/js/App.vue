<template>
  <div class="app-container">
    <header class="app-header">
      <div class="header-left">
        <div class="logo">
          <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <h1>Spi</h1>
        </div>
      </div>
      <div class="header-right" v-if="!authStore.isAuthenticated">
        <router-link to="/login" class="btn btn-login">Sign In</router-link>
        <router-link to="/register" class="btn btn-register">Get Started</router-link>
      </div>
      <div class="header-right" v-else>
        <router-link v-if="authStore.user.is_admin" to="/admin" class="admin-link">Admin</router-link>
      </div>
    </header>
    
    <!-- User Menu Sidebar -->
    <div v-if="authStore.isAuthenticated" class="user-sidebar">
      <router-link to="/profile" class="sidebar-item">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
        <span>{{ authStore.user.name }}</span>
      </router-link>
      <button @click="handleLogout" class="sidebar-item logout">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span>Logout</span>
      </button>
    </div>

    <router-view class="router-content"></router-view>
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
  flex-shrink: 0;
  z-index: 100;
}

.header-left {
  display: flex;
  align-items: center;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
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

.router-content {
  flex: 1;
  overflow-y: auto;
}

/* User Sidebar */
.user-sidebar {
  position: fixed;
  bottom: 24px;
  left: 24px;
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 8px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  z-index: 50;
}

.sidebar-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 8px;
  color: var(--text-primary);
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.2s;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  width: 100%;
}

.sidebar-item:hover {
  background: var(--border-color);
}

.sidebar-item svg {
  color: var(--text-secondary);
  flex-shrink: 0;
}

.sidebar-item.logout:hover {
  background: rgba(248, 81, 73, 0.1);
  color: #ff7b72;
}

.sidebar-item.logout:hover svg {
  color: #ff7b72;
}

/* Buttons */
.btn-login {
  background: none;
  border: 1px solid var(--border-color);
  color: var(--text-primary);
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition: all 0.2s;
}
.btn-login:hover {
  background: var(--border-color);
}

.btn-register {
  background: var(--accent-color);
  border: 1px solid var(--accent-color);
  color: #fff;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s;
}
.btn-register:hover {
  background: #4a9eff;
  border-color: #4a9eff;
}

.admin-link {
  color: #d29922;
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  padding: 8px 16px;
  border-radius: 8px;
  border: 1px solid rgba(210, 153, 34, 0.3);
  background: rgba(210, 153, 34, 0.1);
  transition: all 0.2s;
}
.admin-link:hover {
  background: rgba(210, 153, 34, 0.2);
  border-color: #d29922;
}
</style>