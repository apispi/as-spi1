<template>
  <!-- Authenticated: left sidebar shell (mirrors as-website1-laravel dashboard nav) -->
  <div v-if="authStore.isAuthenticated" class="db-shell" :class="{ 'sidebar-open': sidebarOpen }">

    <!-- Overlay (mobile) -->
    <div class="db-overlay" @click="sidebarOpen = false"></div>

    <!-- Sidebar -->
    <aside class="db-sidebar">
      <div class="db-sidebar-header">
        <router-link to="/dashboard" class="db-logo" @click="sidebarOpen = false">
          <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="db-logo-icon"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <span>Spi</span>
        </router-link>
        <button class="db-sidebar-close" @click="sidebarOpen = false" aria-label="Close menu">✕</button>
      </div>

      <nav class="db-nav">
        <span class="db-nav-label">Workspace</span>
        <router-link to="/dashboard" class="db-nav-link" @click="sidebarOpen = false">
          <span class="db-nav-icon">⬡</span> Dashboard
        </router-link>
        <router-link to="/chat" class="db-nav-link" @click="sidebarOpen = false">
          <span class="db-nav-icon">◇</span> Chat
        </router-link>
        <router-link to="/profile" class="db-nav-link" @click="sidebarOpen = false">
          <span class="db-nav-icon">◈</span> Profile
        </router-link>
        <router-link v-if="authStore.user.is_admin" to="/admin" class="db-nav-link db-nav-admin" @click="sidebarOpen = false">
          <span class="db-nav-icon">▣</span> Admin
        </router-link>
      </nav>

      <div class="db-sidebar-footer">
        <router-link to="/profile" class="db-user-row" @click="sidebarOpen = false">
          <div class="db-avatar">{{ initial }}</div>
          <div class="db-user-text">
            <div class="db-user-name">{{ authStore.user.name }}</div>
            <div class="db-user-email">{{ authStore.user.email }}</div>
          </div>
        </router-link>
        <button type="button" class="db-signout" @click="handleLogout">⏻ Sign Out</button>
      </div>
    </aside>

    <!-- Main -->
    <div class="db-main">
      <!-- Top bar (mobile) -->
      <header class="db-topbar">
        <div class="db-topbar-left">
          <button class="db-menu-btn" @click="sidebarOpen = true" aria-label="Open menu">
            <span></span><span></span><span></span>
          </button>
          <router-link to="/dashboard" class="db-topbar-logo">
            <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="db-logo-icon"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            <span>Spi</span>
          </router-link>
        </div>
        <div class="db-topbar-right">
          <router-link to="/profile" class="db-avatar db-avatar-sm" aria-label="My profile">{{ initial }}</router-link>
        </div>
      </header>

      <router-view class="db-content-area"></router-view>
    </div>
  </div>

  <!-- Guest: simple top header -->
  <div v-else class="app-container">
    <header class="app-header">
      <div class="header-left">
        <div class="logo">
          <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <h1>Spi</h1>
        </div>
      </div>
      <div class="header-right">
        <router-link to="/login" class="btn btn-login">Sign In</router-link>
        <router-link to="/register" class="btn btn-register">Get Started</router-link>
      </div>
    </header>

    <router-view class="router-content"></router-view>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useAuthStore } from './store/auth';
import { useRouter } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();

const sidebarOpen = ref(false);

const initial = computed(() => ((authStore.user && authStore.user.name) || 'U').charAt(0).toUpperCase());

const handleLogout = async () => {
  sidebarOpen.value = false;
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
/* ---------- Authenticated shell ---------- */
.db-shell {
  display: flex;
  height: 100vh;
  overflow: hidden;
  background-color: var(--bg-color);
  color: var(--text-primary);
}

/* Overlay (mobile) */
.db-overlay {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.6);
  z-index: 90;
}
.sidebar-open .db-overlay { display: block; }

/* Sidebar */
.db-sidebar {
  width: 240px;
  background-color: var(--panel-bg);
  border-right: 1px solid var(--border-color);
  position: fixed; top: 0; left: 0;
  height: 100vh;
  display: flex; flex-direction: column;
  z-index: 100;
  overflow-y: auto;
  transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}
.db-sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 16px;
  border-bottom: 1px solid var(--border-color);
  flex-shrink: 0;
}
.db-logo {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; color: var(--text-primary);
  font-size: 18px; font-weight: 600; letter-spacing: -0.5px;
}
.db-logo-icon { color: var(--accent-color); }
.db-sidebar-close {
  display: none;
  background: none; border: none; cursor: pointer;
  color: var(--text-secondary); font-size: 18px; padding: 4px;
}

.db-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 3px; }
.db-nav-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.1em; color: var(--text-secondary);
  padding: 12px 8px 4px;
}
.db-nav-link {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  text-decoration: none;
  color: var(--text-secondary); font-size: 14px; font-weight: 500;
  transition: all 0.18s;
}
.db-nav-link:hover { background: var(--border-color); color: var(--text-primary); }
.db-nav-link.router-link-active { background: rgba(88, 166, 255, 0.12); color: var(--accent-color); font-weight: 600; }
.db-nav-icon { font-size: 15px; width: 20px; text-align: center; flex-shrink: 0; }
.db-nav-admin { color: #d29922; }
.db-nav-admin:hover { background: rgba(210, 153, 34, 0.12); color: #d29922; }
.db-nav-admin.router-link-active { background: rgba(210, 153, 34, 0.15); color: #d29922; }

.db-sidebar-footer {
  padding: 12px;
  border-top: 1px solid var(--border-color);
  flex-shrink: 0;
}
.db-user-row {
  display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
  text-decoration: none; color: inherit;
  padding: 6px 8px; border-radius: 8px; transition: background 0.15s;
}
.db-user-row:hover { background: var(--border-color); }
.db-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--accent-color);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; color: #fff;
  flex-shrink: 0; text-decoration: none;
}
.db-avatar-sm { width: 32px; height: 32px; font-size: 13px; }
.db-user-text { min-width: 0; }
.db-user-name { font-size: 14px; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-user-email { font-size: 12px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-signout {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 10px 12px;
  background: none; border: none; cursor: pointer;
  color: var(--text-secondary); font-size: 14px; font-weight: 500; border-radius: 8px;
  transition: all 0.18s; font-family: inherit; text-align: left;
}
.db-signout:hover { background: rgba(248, 81, 73, 0.1); color: #ff7b72; }

/* Main */
.db-main {
  margin-left: 240px;
  flex: 1;
  height: 100vh;
  display: flex; flex-direction: column;
  min-width: 0;
}
.db-content-area { flex: 1; overflow-y: auto; min-height: 0; }

/* Topbar (mobile only) */
.db-topbar {
  display: none;
  background-color: var(--panel-bg);
  border-bottom: 1px solid var(--border-color);
  padding: 0 16px;
  height: 56px;
  align-items: center; justify-content: space-between;
  flex-shrink: 0;
}
.db-topbar-left { display: flex; align-items: center; gap: 14px; }
.db-topbar-logo {
  display: flex; align-items: center; gap: 8px;
  text-decoration: none; color: var(--text-primary);
  font-size: 16px; font-weight: 600;
}
.db-menu-btn {
  display: flex; flex-direction: column; justify-content: space-between;
  width: 24px; height: 18px;
  background: none; border: none; cursor: pointer; padding: 0;
}
.db-menu-btn span {
  display: block; width: 100%; height: 2px;
  background: var(--accent-color); border-radius: 2px;
}
.db-topbar-right { display: flex; align-items: center; }

/* ---------- Guest header ---------- */
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
.header-left { display: flex; align-items: center; }
.header-right { display: flex; align-items: center; gap: 12px; }
.logo { display: flex; align-items: center; gap: 12px; color: var(--accent-color); }
.logo h1 {
  font-size: 18px; font-weight: 600; margin: 0;
  color: var(--text-primary); letter-spacing: -0.5px;
}
.router-content { flex: 1; overflow-y: auto; }

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
.btn-login:hover { background: var(--border-color); }
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
.btn-register:hover { background: #4a9eff; border-color: #4a9eff; }

/* ---------- Mobile ---------- */
@media (max-width: 768px) {
  .db-sidebar { transform: translateX(-100%); }
  .sidebar-open .db-sidebar { transform: translateX(0); }
  .db-sidebar-close { display: block; }
  .db-main { margin-left: 0; }
  .db-topbar { display: flex; }
}
</style>
