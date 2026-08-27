<template>
  <!-- Authenticated: top navbar + collapsible sidebar -->
  <div v-if="authStore.isAuthenticated" class="shell" :class="{ collapsed: !sidebarOpen }">

    <!-- Full-width top navbar -->
    <header class="topbar">
      <div class="topbar-left">
        <button class="icon-btn" @click="sidebarOpen = !sidebarOpen" aria-label="Toggle menu">
          <Icon name="menu" :size="20" />
        </button>
        <router-link to="/dashboard" class="brand">
          <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="brand-mark"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <span class="brand-name">Spi</span>
          <span class="brand-sub">apispi.com</span>
        </router-link>
      </div>

      <!-- Profile dropdown (top right) -->
      <div ref="acctRoot" class="acct">
        <button type="button" class="acct-trigger" :class="{ open: acctOpen }" @click="acctOpen = !acctOpen" aria-haspopup="true" :aria-expanded="acctOpen">
          <span class="avatar">{{ initial }}</span>
          <span class="acct-name">{{ authStore.user.name }}</span>
          <Icon name="chevronDown" :size="16" class="acct-caret" />
        </button>

        <transition name="fade-pop">
          <div v-if="acctOpen" class="acct-menu" @click.stop>
            <div class="acct-head">
              <div class="acct-label">Signed in as</div>
              <div class="acct-fullname">{{ authStore.user.name }}</div>
              <div class="acct-email">{{ authStore.user.email }}</div>
            </div>
            <div class="acct-divider"></div>
            <router-link to="/dashboard" class="acct-item" @click="onAcctNav">
              <Icon name="home" :size="16" /> Dashboard
            </router-link>
            <router-link to="/profile" class="acct-item" @click="onAcctNav">
              <Icon name="user" :size="16" /> Manage profile
            </router-link>
            <router-link v-if="authStore.user.is_admin" to="/admin" class="acct-item" @click="onAcctNav">
              <Icon name="shield" :size="16" /> Admin panel
            </router-link>
            <div class="acct-divider"></div>
            <button type="button" class="acct-item acct-signout" @click="handleLogout">
              <Icon name="logout" :size="16" /> Sign out
            </button>
          </div>
        </transition>
      </div>
    </header>

    <div class="shell-body">
      <!-- Mobile scrim -->
      <div class="scrim" @click="sidebarOpen = false"></div>

      <!-- Sidebar -->
      <aside class="sidebar">
        <!-- The admin area replaces the workspace nav rather than adding to
             it: inside admin you see admin sections only, with one link back
             so you are never stranded. -->
        <nav class="nav" v-if="inAdminArea">
          <span class="nav-label">Admin</span>
          <router-link v-for="item in adminNav" :key="item.to" :to="item.to" class="nav-link" @click="closeOnMobile">
            <Icon :name="item.icon" :size="18" />
            <span>{{ item.label }}</span>
          </router-link>

          <router-link to="/dashboard" class="nav-link nav-leave" @click="closeOnMobile">
            <Icon name="arrowRight" :size="18" class="nav-leave-icon" />
            <span>Leave admin</span>
          </router-link>
        </nav>

        <nav class="nav" v-else>
          <span class="nav-label">Workspace</span>
          <router-link v-for="item in workspaceNav" :key="item.to" :to="item.to" class="nav-link" @click="closeOnMobile">
            <Icon :name="item.icon" :size="18" />
            <span>{{ item.label }}</span>
          </router-link>
        </nav>

        <a href="https://modelcontextprotocol.io" target="_blank" rel="noopener" class="nav-foot">
          <Icon name="plug" :size="16" /> MCP reference
        </a>
      </aside>

      <!-- Main -->
      <main class="main">
        <router-view />
      </main>
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
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from './store/auth';
import { useRouter, useRoute } from 'vue-router';
import Icon from './components/Icon.vue';

const authStore = useAuthStore();
const router = useRouter();

const workspaceNav = [
  { to: '/dashboard', label: 'Home', icon: 'home' },
  { to: '/tester', label: 'Tester', icon: 'send' },
  { to: '/collections', label: 'Collections', icon: 'layers' },
  { to: '/ai-lab', label: 'AI Lab', icon: 'sparkles' },
  { to: '/explore', label: 'Explorer', icon: 'sparkles' },
  { to: '/monitors', label: 'Monitors', icon: 'activity' },
  { to: '/webhooks', label: 'Webhooks', icon: 'plug' },
  { to: '/recorder', label: 'Recorder', icon: 'activity' },
  { to: '/reports', label: 'Reports', icon: 'report' },
  { to: '/chat', label: 'Spi', icon: 'chat' },
];
const adminNav = [
  { to: '/admin', label: 'Overview', icon: 'home' },
  { to: '/admin/users', label: 'Users', icon: 'user' },
  { to: '/admin/organisations', label: 'Organisations', icon: 'shield' },
  { to: '/admin/monitoring', label: 'Monitoring', icon: 'activity' },
  { to: '/catalog', label: 'Catalog', icon: 'layers' },
  { to: '/active', label: 'Active', icon: 'sliders' },
];

// Admin routes form a separate area with their own nav, rather than sitting
// alongside the workspace sections.
const ADMIN_ROUTES = ['/admin', '/catalog', '/active'];
const route = useRoute();
const inAdminArea = computed(() =>
  !!authStore.user?.is_admin && ADMIN_ROUTES.some((path) => route.path.startsWith(path)));

// Open by default on desktop, collapsed on mobile.
const sidebarOpen = ref(typeof window !== 'undefined' ? window.innerWidth > 900 : true);

const closeOnMobile = () => {
  if (typeof window !== 'undefined' && window.innerWidth <= 900) sidebarOpen.value = false;
};

// Profile dropdown.
const acctRoot = ref(null);
const acctOpen = ref(false);

const onAcctNav = () => {
  acctOpen.value = false;
  closeOnMobile();
};

const onDocMousedown = (e) => {
  if (acctRoot.value && !acctRoot.value.contains(e.target)) acctOpen.value = false;
};
const onKeydown = (e) => { if (e.key === 'Escape') acctOpen.value = false; };

onMounted(() => {
  document.addEventListener('mousedown', onDocMousedown);
  document.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
  document.removeEventListener('mousedown', onDocMousedown);
  document.removeEventListener('keydown', onKeydown);
});

const initial = computed(() => ((authStore.user && authStore.user.name) || 'U').charAt(0).toUpperCase());

const handleLogout = async () => {
  acctOpen.value = false;
  sidebarOpen.value = false;
  await authStore.logout();
  router.push('/login');
};
</script>

<style scoped>
/* ---------------- Authenticated shell ---------------- */
.shell { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

/* Top navbar */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  height: 56px; flex-shrink: 0; padding: 0 14px;
  background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
  position: relative; z-index: var(--z-topbar);
}
.topbar-left { display: flex; align-items: center; gap: 10px; }
.icon-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border-radius: 8px; border: none;
  background: transparent; color: var(--text-secondary); cursor: pointer;
  transition: background 0.18s, color 0.18s;
}
.icon-btn:hover { background: var(--border-color); color: var(--text-primary); }

.brand { display: flex; align-items: baseline; gap: 8px; text-decoration: none; color: var(--text-primary); }
.brand-mark { color: var(--accent-color); align-self: center; }
.brand-name { font-size: 17px; font-weight: 700; letter-spacing: -0.01em; }
.brand-sub { font-size: 12px; color: var(--text-secondary); }
@media (max-width: 560px) { .brand-sub { display: none; } }

/* Profile dropdown */
.acct { position: relative; }
.acct-trigger {
  display: flex; align-items: center; gap: 8px; cursor: pointer;
  background: transparent; border: 1px solid transparent; border-radius: 999px;
  padding: 4px 8px 4px 4px; color: var(--text-primary);
  transition: background 0.18s, border-color 0.18s;
}
.acct-trigger:hover, .acct-trigger.open { background: var(--bg-elevated); border-color: var(--border-color); }
.avatar {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--accent-color), var(--accent-hover));
  color: #fff; font-size: 13px; font-weight: 700;
}
.acct-name { font-size: 14px; font-weight: 600; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 480px) { .acct-name { display: none; } }
.acct-caret { color: var(--text-secondary); }

.acct-menu {
  position: absolute; top: calc(100% + 8px); right: 0; width: 240px;
  background: var(--bg-elevated); border: 1px solid var(--border-color);
  border-radius: 12px; padding: 6px; z-index: var(--z-dropdown);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.4);
}
.acct-head { padding: 8px 10px 6px; }
.acct-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-secondary); }
.acct-fullname { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-top: 2px; }
.acct-email { font-size: 12px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.acct-divider { height: 1px; background: var(--border-color); margin: 6px 4px; }
.acct-item {
  display: flex; align-items: center; gap: 10px; width: 100%;
  padding: 9px 10px; border-radius: 8px; border: none; background: transparent;
  color: var(--text-primary); font-size: 14px; text-align: left; cursor: pointer;
  text-decoration: none; transition: background 0.18s;
}
.acct-item:hover { background: var(--accent-soft); color: var(--accent-color); }
.acct-signout { color: var(--error-color); }
.acct-signout:hover { background: rgba(248, 81, 73, 0.12); color: var(--error-color); }

.fade-pop-enter-active, .fade-pop-leave-active { transition: opacity 0.16s ease, transform 0.16s ease; }
.fade-pop-enter-from, .fade-pop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }

/* Body: sidebar + content */
.shell-body { display: flex; flex: 1; min-height: 0; position: relative; }

.sidebar {
  width: 236px; flex-shrink: 0; display: flex; flex-direction: column;
  background: var(--bg-secondary); border-right: 1px solid var(--border-color);
  transition: margin-left 0.22s ease; overflow-y: auto;
}
.shell.collapsed .sidebar { margin-left: -236px; }

.nav { flex: 1; padding: 14px 12px; display: flex; flex-direction: column; gap: 2px; }
.nav-label {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em;
  color: var(--text-secondary); padding: 12px 10px 6px;
}
.nav-link {
  display: flex; align-items: center; gap: 11px; padding: 9px 10px;
  border-radius: 8px; color: var(--text-secondary); text-decoration: none;
  font-size: 14px; font-weight: 500; transition: background 0.18s, color 0.18s;
}
.nav-link:hover { background: var(--border-color); color: var(--text-primary); }
.nav-link.router-link-active { background: var(--accent-soft); color: var(--accent-color); font-weight: 600; }
/* /admin is a prefix of every admin route, so without this the Overview link
   would stay highlighted on /admin/users etc. Only the exact match counts. */
.nav-link[href="/admin"].router-link-active:not(.router-link-exact-active) { background: none; color: var(--text-secondary); font-weight: 400; }
/* Leaving admin is a way out, not a section — set apart from the list above. */
.nav-leave { margin-top: 10px; border-top: 1px solid var(--border-color); padding-top: 14px; color: var(--text-secondary); }
.nav-leave.router-link-active { background: none; color: var(--text-secondary); font-weight: 400; }
.nav-leave-icon { transform: rotate(180deg); }
.nav-foot {
  display: flex; align-items: center; gap: 9px; margin: 8px 12px 14px;
  padding: 9px 10px; border-radius: 8px; font-size: 13px;
  color: var(--text-secondary); text-decoration: none; border: 1px solid var(--border-color);
  transition: border-color 0.18s, color 0.18s;
}
.nav-foot:hover { color: var(--accent-color); border-color: var(--accent-color); }

.main { flex: 1; min-width: 0; overflow-y: auto; background: var(--bg-color); }

.scrim { display: none; }

/* Mobile: sidebar becomes an overlay drawer */
@media (max-width: 900px) {
  .sidebar {
    position: fixed; top: 56px; bottom: 0; left: 0; z-index: var(--z-sidebar);
    margin-left: 0; transform: translateX(0); transition: transform 0.22s ease;
  }
  .shell.collapsed .sidebar { margin-left: 0; transform: translateX(-100%); }
  .shell:not(.collapsed) .scrim {
    display: block; position: fixed; top: 56px; inset: 56px 0 0 0;
    background: rgba(0, 0, 0, 0.5); z-index: var(--z-overlay);
  }
}

/* ---------------- Guest header (unchanged) ---------------- */
.app-container { min-height: 100vh; }
.app-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 24px; border-bottom: 1px solid var(--border-color);
}
.header-left .logo { display: flex; align-items: center; gap: 10px; }
.header-left .logo .icon { color: var(--accent-color); }
.header-left h1 { font-size: 20px; font-weight: 700; color: var(--text-primary); }
.header-right { display: flex; gap: 10px; }
.btn { padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; transition: background 0.18s, border-color 0.18s; }
.btn-login { color: var(--text-primary); border: 1px solid var(--border-color); }
.btn-login:hover { border-color: var(--text-secondary); }
.btn-register { background: var(--accent-color); color: #fff; }
.btn-register:hover { background: var(--accent-hover); }
</style>
