<!-- Copyright © 2026 ApiSpi -->
<template>
  <div class="up-shell" :class="{ 'sidebar-open': sidebarOpen, 'is-mobile': isMobile }">

    <div class="up-overlay" @click="sidebarOpen = false"></div>

    <aside class="up-sidebar">
      <div class="up-sidebar-header">
        <a href="/" class="up-logo">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 27" class="up-logo-icon">
            <defs>
              <linearGradient id="uplg" x1=".5" y1="0" x2=".5" y2="1">
                <stop offset="0%" stop-color="#60A5FA"/>
                <stop offset="100%" stop-color="#3B82F6"/>
              </linearGradient>
            </defs>
            <path d="M12,0.5 L13.4,3.3 L16,4.5 L13.4,5.7 L12,8.5 L10.6,5.7 L8,4.5 L10.6,3.3 Z" fill="url(#uplg)"/>
            <path d="M12,8.5 L24,26 L20,26 L15.5,18 L8.5,18 L4,26 L0,26 Z" fill="url(#uplg)"/>
          </svg>
          <span>ApiSpi</span>
        </a>
        <button class="up-sidebar-close" @click="sidebarOpen = false" aria-label="Close menu">✕</button>
      </div>

      <nav class="up-nav">
        <span class="up-nav-label">Navigation</span>
        <a href="/" class="up-nav-link">
          <span class="up-nav-icon">⬡</span> Home
        </a>
        <a href="/chat" class="up-nav-link">
          <span class="up-nav-icon">◈</span> Chat
        </a>
        <a href="/profile" class="up-nav-link active">
          <span class="up-nav-icon">◈</span> Profile
        </a>
      </nav>

      <div class="up-sidebar-footer">
        <div class="up-user-row up-user-row-active">
          <div v-if="authStore.user?.avatar" class="up-avatar">
            <img :src="authStore.user.avatar" :alt="authStore.user.name" class="up-avatar-photo" referrerpolicy="no-referrer">
          </div>
          <div v-else class="up-avatar">{{ userInitial }}</div>
          <div class="up-user-text">
            <div class="up-user-name">{{ authStore.user?.name || 'User' }}</div>
            <div class="up-user-email">{{ authStore.user?.email }}</div>
          </div>
        </div>
        <button @click="handleLogout" class="up-signout">⏻ Sign Out</button>
      </div>
    </aside>

    <!-- Main -->
    <div class="up-main">
      <header class="up-topbar">
        <div class="up-topbar-left">
          <a href="/" class="up-topbar-logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 27" class="up-logo-icon">
              <defs>
                <linearGradient id="uplg2" x1=".5" y1="0" x2=".5" y2="1">
                  <stop offset="0%" stop-color="#60A5FA"/>
                  <stop offset="100%" stop-color="#3B82F6"/>
                </linearGradient>
              </defs>
              <path d="M12,0.5 L13.4,3.3 L16,4.5 L13.4,5.7 L12,8.5 L10.6,5.7 L8,4.5 L10.6,3.3 Z" fill="url(#uplg2)"/>
              <path d="M12,8.5 L24,26 L20,26 L15.5,18 L8.5,18 L4,26 L0,26 Z" fill="url(#uplg2)"/>
            </svg>
            <span>ApiSpi</span>
          </a>
          <button class="up-menu-btn" @click="sidebarOpen = true" aria-label="Open menu">
            <span></span><span></span><span></span>
          </button>
        </div>
        <div class="up-topbar-right">
          <div v-if="authStore.user?.avatar" class="up-avatar up-avatar-sm">
            <img :src="authStore.user.avatar" :alt="authStore.user.name" class="up-avatar-photo" referrerpolicy="no-referrer">
          </div>
          <div v-else class="up-avatar up-avatar-sm">{{ userInitial }}</div>
        </div>
      </header>

      <main class="up-content">

        <!-- Flash messages -->
        <div v-if="flashSuccess" class="up-flash success">✓ {{ flashSuccess }}</div>
        <div v-if="flashError" class="up-flash error">{{ flashError }}</div>

        <!-- Profile hero -->
        <div class="up-hero">
          <div v-if="authStore.user?.avatar" class="up-hero-avatar">
            <img :src="authStore.user.avatar" :alt="authStore.user.name" class="up-hero-photo" referrerpolicy="no-referrer">
          </div>
          <div v-else class="up-hero-avatar">{{ userInitial }}</div>
          <div class="up-hero-info">
            <div class="up-hero-name">{{ authStore.user?.name || 'User' }}</div>
            <div class="up-hero-email">{{ authStore.user?.email }}</div>
            <div class="up-hero-date">Member since {{ formatMemberSince(authStore.user?.created_at) }}</div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="up-tabs">
          <button :class="['up-tab', { active: activeTab === 'account' }]" @click="activeTab = 'account'">Account</button>
          <button :class="['up-tab', { active: activeTab === 'api-keys' }]" @click="activeTab = 'api-keys'">API Keys</button>
          <button :class="['up-tab', { active: activeTab === 'usage' }]" @click="activeTab = 'usage'">Usage</button>
          <button :class="['up-tab', { active: activeTab === 'settings' }]" @click="activeTab = 'settings'">Settings</button>
          <button :class="['up-tab', { active: activeTab === 'danger' }]" @click="activeTab = 'danger'">Danger Zone</button>
        </div>

        <!-- ── Account tab ── -->
        <template v-if="activeTab === 'account'">
          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Account Details</h2>
              <p class="up-card-sub">Update your display name</p>
            </div>
            <form @submit.prevent="updateProfile">
              <div class="up-form-group">
                <label class="up-label" for="profile-name">Full Name</label>
                <input id="profile-name" type="text" v-model="form.name" required
                       class="up-input" placeholder="Your full name">
              </div>
              <div class="up-form-group">
                <label class="up-label">Email Address</label>
                <div class="up-input-static">
                  <span class="up-input-static-val">{{ authStore.user?.email }}</span>
                  <span class="up-input-lock">🔒 Read only</span>
                </div>
                <p class="up-hint">Email cannot be changed. Contact support if needed.</p>
              </div>
              <div class="up-form-footer">
                <button type="submit" class="up-btn-save" :disabled="saving">
                  {{ saving ? 'Saving...' : 'Save Changes' }}
                </button>
              </div>
            </form>
          </div>

          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Change Password</h2>
              <p class="up-card-sub">Choose a strong password of at least 8 characters</p>
            </div>
            <form @submit.prevent="updatePassword">
              <div class="up-form-group">
                <label class="up-label" for="current-password">Current Password</label>
                <input id="current-password" type="password" v-model="passwordForm.current_password" required
                       class="up-input" placeholder="Enter your current password">
              </div>
              <div class="up-form-row">
                <div class="up-form-group">
                  <label class="up-label" for="new-password">New Password</label>
                  <input id="new-password" type="password" v-model="passwordForm.password" required minlength="8"
                         class="up-input" placeholder="Min. 8 characters">
                </div>
                <div class="up-form-group">
                  <label class="up-label" for="confirm-password">Confirm New Password</label>
                  <input id="confirm-password" type="password" v-model="passwordForm.password_confirmation" required
                         class="up-input" placeholder="Repeat new password">
                </div>
              </div>
              <div class="up-form-footer">
                <button type="submit" class="up-btn-save" :disabled="changingPassword">
                  {{ changingPassword ? 'Updating...' : 'Update Password' }}
                </button>
              </div>
            </form>
          </div>
        </template>

        <!-- ── API Keys tab ── -->
        <template v-else-if="activeTab === 'api-keys'">
          <div v-if="newKey && showKeyBanner" class="up-key-banner">
            <div class="up-key-banner-header">
              <strong>{{ newKeyName }}</strong> — copy this key now. It won't be shown again.
            </div>
            <div class="up-key-banner-row">
              <code class="up-key-code">{{ newKey }}</code>
              <button type="button" class="up-btn-save" @click="copyNewKey">{{ copiedKey ? 'Copied!' : 'Copy' }}</button>
            </div>
          </div>

          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Your API Key</h2>
              <p class="up-card-sub">Use this key to authenticate your API requests</p>
            </div>
            <div class="up-api-key-row">
              <div class="up-api-key-display">
                <code>{{ apiKey || 'Generating...' }}</code>
                <button @click="copyApiKey" class="btn btn-icon" :title="copied ? 'Copied!' : 'Copy'">
                  <svg v-if="!copied" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                  </svg>
                  <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"></polyline>
                  </svg>
                </button>
              </div>
            </div>
            <div class="up-api-key-actions">
              <button @click="regenerateApiKey" class="up-btn-save" :disabled="regenerating">
                {{ regenerating ? 'Regenerating...' : 'Regenerate Key' }}
              </button>
              <p class="up-hint">Regenerating will invalidate your old key</p>
            </div>
          </div>
        </template>

        <!-- ── Usage tab ── -->
        <template v-else-if="activeTab === 'usage'">
          <div class="up-stats-grid">
            <div class="up-stat-card">
              <div class="up-stat-value">{{ stats.requests || 0 }}</div>
              <div class="up-stat-label">API Requests</div>
              <div class="up-stat-sub">All time</div>
            </div>
            <div class="up-stat-card">
              <div class="up-stat-value">{{ stats.saved || 0 }}</div>
              <div class="up-stat-label">Saved Requests</div>
              <div class="up-stat-sub">Your saved work</div>
            </div>
            <div class="up-stat-card">
              <div class="up-stat-value">{{ formatBytes(stats.bandwidth || 0) }}</div>
              <div class="up-stat-label">Data Transferred</div>
              <div class="up-stat-sub">Total bandwidth</div>
            </div>
            <div class="up-stat-card">
              <div class="up-stat-value">{{ stats.active_days || 0 }}</div>
              <div class="up-stat-label">Active Days</div>
              <div class="up-stat-sub">This month</div>
            </div>
          </div>

          <div class="up-card" style="margin-top: 1.5rem">
            <div class="up-card-header">
              <h2 class="up-card-title">Recent Activity</h2>
              <p class="up-card-sub">Your last 10 actions</p>
            </div>
            <div v-if="!recentActivity.length" class="up-empty">No activity recorded yet.</div>
            <div v-else class="up-activity-list">
              <div v-for="(item, i) in recentActivity" :key="i" class="up-activity-row">
                <div class="up-activity-dot" :class="activityColor(item.action)"></div>
                <div class="up-activity-body">
                  <div class="up-activity-desc">{{ item.description }}</div>
                  <div class="up-activity-meta">
                    <span class="up-activity-action">{{ item.action }}</span>
                    <span class="up-activity-time">{{ formatActivityDate(item.created_at) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- ── Settings tab ── -->
        <template v-else-if="activeTab === 'settings'">
          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Notification Settings</h2>
              <p class="up-card-sub">Choose which emails you receive from ApiSpi</p>
            </div>
            <form @submit.prevent="updateNotifications">
              <div class="up-toggle-row">
                <div class="up-toggle-info">
                  <div class="up-toggle-label">Subscription updates</div>
                  <div class="up-toggle-desc">Agent status changes, renewals, and expiry notices</div>
                </div>
                <label class="up-toggle">
                  <input type="checkbox" v-model="notificationsForm.subscription_updates">
                  <span class="up-toggle-track"><span class="up-toggle-thumb"></span></span>
                </label>
              </div>

              <div class="up-toggle-row">
                <div class="up-toggle-info">
                  <div class="up-toggle-label">New features & announcements</div>
                  <div class="up-toggle-desc">Product updates, new agents, and platform news</div>
                </div>
                <label class="up-toggle">
                  <input type="checkbox" v-model="notificationsForm.new_features">
                  <span class="up-toggle-track"><span class="up-toggle-thumb"></span></span>
                </label>
              </div>

              <div class="up-card" style="margin-top: 1.5rem"><div class="up-card-header"><h2 class="up-card-title">SCX AI Integration</h2><p class="up-card-sub">Connect your SCX AI account using an API key</p></div><form @submit.prevent="updateScxApiKey"><div class="up-form-group"><label class="up-label" for="scx-api-key">SCX API Key</label><span v-if="hasScxKey" class="scx-key-status">••••••••</span><input id="scx-api-key" type="password" v-model="scxApiKeyForm" class="up-input" placeholder="Enter your SCX API key"></div><p v-if="hasScxKey" class="up-hint">A key is saved. Enter a new value to replace it.</p><div class="up-form-group"><label class="up-label" for="scx-model">AI Model</label><select id="scx-model" v-model="scxModelForm" class="up-input"><option value="scx-ai">SCX AI (Default)</option><option value="gpt-4o-mini">GPT-4o Mini</option><option value="MAGPIE">MAGPIE</option><option value="coder">Coder</option><option value="MiniMax-M2.7">MiniMax-M2.7</option></select></div><div class="up-form-footer"><button type="submit" class="up-btn-save" :disabled="savingScx">Save SCX API Key</button></div></form></div><div class="up-toggle-row" style="border-bottom: none">
                <div class="up-toggle-info">
                  <div class="up-toggle-label">Tips & best practices</div>
                  <div class="up-toggle-desc">Occasional guides on getting more from your agents</div>
                </div>
                <label class="up-toggle">
                  <input type="checkbox" v-model="notificationsForm.tips">
                  <span class="up-toggle-track"><span class="up-toggle-thumb"></span></span>
                </label>
              </div>

              <div class="up-form-footer">
                <button type="submit" class="up-btn-save" :disabled="savingNotifications">
                  {{ savingNotifications ? 'Saving...' : 'Save Settings' }}
                </button>
              </div>
            </form>
          </div>
        </template>

        <!-- ── Danger Zone tab ── -->
        <template v-else-if="activeTab === 'danger'">
          <div class="up-card up-card-danger">
            <div class="up-card-header">
              <h2 class="up-card-title up-danger-title">Danger Zone</h2>
              <p class="up-card-sub">Irreversible actions — proceed with caution</p>
            </div>
            <div class="up-danger-row">
              <div class="up-danger-info">
                <div class="up-danger-label">Delete account</div>
                <div class="up-danger-desc">Permanently remove your account and all associated data. This cannot be undone.</div>
              </div>
              <button type="button" class="up-btn-danger" @click="showDeleteConfirm = !showDeleteConfirm">
                Delete Account
              </button>
            </div>
            <div v-if="showDeleteConfirm" class="up-delete-confirm">
              <p class="up-delete-warning">Type <strong>DELETE</strong> below to confirm account deletion.</p>
              <div class="up-delete-row">
                <input type="text" v-model="deleteConfirmText"
                       class="up-input" placeholder="Type DELETE to confirm" autocomplete="off">
                <button type="button" class="up-btn-danger-confirm"
                        :disabled="deleteConfirmText !== 'DELETE'" @click="deleteAccount">
                  Confirm Delete
                </button>
              </div>
            </div>
          </div>
        </template>

      </main>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '../store/auth';
import axios from 'axios';

const authStore = useAuthStore();

const sidebarOpen = ref(false);
const isMobile = ref(false);
let mobileMql = null;

function handleMobileChange(e) { isMobile.value = e.matches; }

onMounted(() => {
  mobileMql = window.matchMedia('(max-width: 768px)');
  isMobile.value = mobileMql.matches;
  mobileMql.addEventListener('change', handleMobileChange);
  
  form.name = authStore.user?.name || '';
  loadApiKey();
    loadScxKeyStatus();
  loadStats();
  loadRecentActivity();
});

onUnmounted(() => mobileMql?.removeEventListener('change', handleMobileChange));

const userInitial = computed(() => {
  const name = authStore.user?.name || 'U';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
});

const VALID_TABS = ['account', 'api-keys', 'usage', 'settings', 'danger'];

const activeTab = ref('account');
const flashSuccess = ref('');
const flashError = ref('');

const form = reactive({ name: '' });
const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: ''
});
const saving = ref(false);
const changingPassword = ref(false);

const apiKey = ref('');
const copied = ref(false);
const regenerating = ref(false);
const newKey = ref('');
const newKeyName = ref('');
const showKeyBanner = ref(false);
const copiedKey = ref(false);

const stats = ref({});
const recentActivity = ref([]);

const notificationsForm = reactive({
  subscription_updates: true,
  new_features: true,
  tips: false
});
const savingNotifications = ref(false);

const scxApiKeyForm = ref('');
const scxModelForm = ref('scx-ai');
const savingScx = ref(false);
const hasScxKey = ref(false);

const showDeleteConfirm = ref(false);
const deleteConfirmText = ref('');
const deleting = ref(false);

const loadScxKeyStatus = async () => {
  try {
    const res = await axios.get('/api/user/scx-api-key');
    hasScxKey.value = res.data.has_key;
  } catch (error) {
    hasScxKey.value = false;
  }
};

const loadApiKey = async () => {
  try {
    const res = await axios.get('/api/user/api-key');
    apiKey.value = res.data.api_key || 'No key generated';
  } catch (error) {
    apiKey.value = 'Error loading key';
  }
};

const loadStats = async () => {
  try {
    const res = await axios.get('/api/user/stats');
    stats.value = res.data;
  } catch (error) {
    stats.value = { requests: 0, saved: 0, bandwidth: 0, active_days: 0 };
  }
};

const loadRecentActivity = async () => {
  try {
    const res = await axios.get('/api/user/activity');
    recentActivity.value = res.data || [];
  } catch (error) {
    recentActivity.value = [];
  }
};

const updateProfile = async () => {
  saving.value = true;
  flashSuccess.value = '';
  flashError.value = '';
  try {
    const res = await axios.put('/api/user/profile', { name: form.name });
    authStore.user.name = res.data.name;
    flashSuccess.value = 'Profile updated successfully';
  } catch (error) {
    flashError.value = 'Failed to update profile. Please try again.';
  } finally {
    saving.value = false;
  }
};

const updatePassword = async () => {
  if (passwordForm.password !== passwordForm.password_confirmation) {
    flashError.value = 'Passwords do not match';
    return;
  }
  changingPassword.value = true;
  flashSuccess.value = '';
  flashError.value = '';
  try {
    await axios.put('/api/user/password', passwordForm);
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';
    flashSuccess.value = 'Password updated successfully';
  } catch (error) {
    flashError.value = error.response?.data?.message || 'Failed to update password';
  } finally {
    changingPassword.value = false;
  }
};

const copyApiKey = async () => {
  try {
    await navigator.clipboard.writeText(apiKey.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch (error) {
    console.error('Failed to copy');
  }
};

const copyNewKey = async () => {
  try {
    await navigator.clipboard.writeText(newKey.value);
    copiedKey.value = true;
    setTimeout(() => { copiedKey.value = false; }, 1500);
  } catch (error) {
    console.error('Failed to copy');
  }
};

const regenerateApiKey = async () => {
  if (!confirm('Are you sure? Your old API key will stop working.')) return;
  regenerating.value = true;
  try {
    const res = await axios.post('/api/user/api-key/regenerate');
    apiKey.value = res.data.api_key;
    newKey.value = res.data.api_key;
    newKeyName.value = 'API Key';
    showKeyBanner.value = true;
  } catch (error) {
    flashError.value = 'Failed to regenerate key';
  } finally {
    regenerating.value = false;
  }
};

const updateNotifications = async () => {
  savingNotifications.value = true;
  flashSuccess.value = '';
  flashError.value = '';
  try {
    await axios.put('/api/user/notifications', {
      subscription_updates: notificationsForm.subscription_updates,
      new_features: notificationsForm.new_features,
      tips: notificationsForm.tips
    });
    flashSuccess.value = 'Notification settings saved';
  } catch (error) {
    flashError.value = 'Failed to save settings';
  } finally {
    savingNotifications.value = false;
  }
};

const updateScxApiKey = async () => {
  savingScx.value = true;
    axios.put('/api/user/scx-model', { scx_model: scxModelForm.value });
  flashSuccess.value = '';
  flashError.value = '';
  try {
    await axios.put('/api/user/scx-api-key', {
      scx_api_key: scxApiKeyForm.value
    });
    flashSuccess.value = 'SCX API key saved';
    hasScxKey.value = true;
  } catch (error) {
    flashError.value = 'Failed to save SCX API key';
  } finally {
    savingScx.value = false;
  }
};

const formatBytes = (bytes) => {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const formatMemberSince = (d) => {
  if (!d) return 'Unknown';
  return new Date(d).toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
};

const formatActivityDate = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const activityColorMap = {
  login: 'green',
  logout: 'grey',
  register: 'amber',
  'password.change': 'amber',
  'profile.update': 'amber',
};

function activityColor(action) {
  return activityColorMap[action] ?? 'grey';
}

const handleLogout = async () => {
  try {
    await axios.post('/logout');
    await authStore.logout();
    window.location.href = '/';
  } catch (error) {
    window.location.href = '/';
  }
};

const deleteAccount = async () => {
  if (deleteConfirmText.value !== 'DELETE') return;
  deleting.value = true;
  try {
    await axios.delete('/api/user/account');
    await authStore.logout();
    window.location.href = '/';
  } catch (error) {
    flashError.value = 'Failed to delete account';
    deleting.value = false;
  }
};
</script>

<style scoped>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

.up-shell {
  display: flex; min-height: 100vh; background: #0a0805;
  font-family: 'Instrument Sans', system-ui, sans-serif;
}

/* Sidebar */
.up-sidebar {
  width: 240px; flex-shrink: 0;
  background: rgba(14,8,4,0.95);
  border-right: 1px solid rgba(59,130,246,0.1);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; height: 100vh; z-index: 40;
  transition: transform 0.25s ease;
}
.up-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 39;
}

.up-sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.25rem 1rem 1rem; border-bottom: 1px solid rgba(59,130,246,0.08);
}
.up-logo { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
.up-logo-icon { width: 22px; height: 25px; }
.up-logo span { font-size: 1.1rem; font-weight: 700; color: #60A5FA; letter-spacing: -0.01em; }
.up-sidebar-close {
  display: none; background: none; border: none; color: #6b7280;
  font-size: 1rem; cursor: pointer; padding: 0.25rem;
}

.up-nav {
  flex: 1; padding: 1rem 0.625rem; overflow-y: auto;
  display: flex; flex-direction: column; gap: 0.125rem;
}
.up-nav-label {
  font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.1em; color: #4b5563;
  padding: 0.75rem 0.5rem 0.25rem; display: block;
}
.up-nav-link {
  display: flex; align-items: center; gap: 0.625rem;
  padding: 0.5rem 0.75rem; border-radius: 0.5rem;
  color: #9ca3af; font-size: 0.875rem; font-weight: 500; text-decoration: none;
  transition: all 0.15s;
}
.up-nav-link:hover { background: rgba(59,130,246,0.06); color: #60A5FA; }
.up-nav-link.active { background: rgba(59,130,246,0.1); color: #60A5FA; }
.up-nav-icon { font-size: 0.9rem; width: 18px; text-align: center; flex-shrink: 0; }

.up-sidebar-footer {
  padding: 0.875rem; border-top: 1px solid rgba(59,130,246,0.08);
  display: flex; flex-direction: column; gap: 0.5rem;
}
.up-user-row {
  display: flex; align-items: center; gap: 0.625rem;
  padding: 0.5rem 0.625rem; border-radius: 0.5rem;
  text-decoration: none; color: inherit;
  transition: background 0.15s;
  cursor: pointer;
}
.up-user-row:hover { background: rgba(59,130,246,0.06); }
.up-user-row-active { background: rgba(59,130,246,0.06); }
.up-user-row-active:hover { background: rgba(59,130,246,0.1); }
.up-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: linear-gradient(135deg, #3B82F6, #60A5FA);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; color: #0a0805; flex-shrink: 0;
}
.up-avatar-sm { width: 28px; height: 28px; font-size: 0.72rem; }
.up-avatar-photo { object-fit: cover; background: none; width: 100%; height: 100%; border-radius: 50%; }
.up-user-text { overflow: hidden; }
.up-user-name { font-size: 0.8rem; font-weight: 600; color: #e5e7eb; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.up-user-email { font-size: 0.7rem; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.up-signout {
  width: 100%; padding: 0.5rem; border-radius: 0.4rem;
  background: none; border: 1px solid rgba(59,130,246,0.12);
  color: #6b7280; font-size: 0.78rem; cursor: pointer; font-family: inherit;
  transition: all 0.15s; text-align: center; min-height: 36px;
}
.up-signout:hover { border-color: rgba(59,130,246,0.3); color: #9ca3af; }

/* Main */
.up-main { flex: 1; margin-left: 240px; display: flex; flex-direction: column; min-height: 100vh; }

.up-topbar {
  display: none; align-items: center; justify-content: space-between;
  padding: 0.75rem 1rem; border-bottom: 1px solid rgba(59,130,246,0.08);
  background: rgba(14,8,4,0.9); position: sticky; top: 0; z-index: 30;
}
.up-menu-btn {
  display: flex; flex-direction: column; gap: 4px;
  background: none; border: none; cursor: pointer; padding: 4px;
}
.up-menu-btn span { display: block; width: 20px; height: 2px; background: #9ca3af; border-radius: 1px; }
.up-topbar-left { display: flex; align-items: center; gap: 1rem; }
.up-topbar-logo { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
.up-topbar-logo span { font-size: 1rem; font-weight: 700; color: #60A5FA; }
.up-topbar-right { display: flex; align-items: center; gap: 0.75rem; }

/* Mobile layout */
.is-mobile .up-sidebar { transform: translateX(-100%); }
.is-mobile.sidebar-open .up-sidebar { transform: translateX(0); }
.is-mobile.sidebar-open .up-overlay { display: block; }
.is-mobile .up-sidebar-close { display: block; }
.is-mobile .up-main { margin-left: 0; }
.is-mobile .up-topbar { display: flex; }

.up-content { padding: 2rem 2.5rem; max-width: none; }

/* Hero */
.up-hero {
  display: flex; align-items: center; gap: 1.25rem;
  background: rgba(8,14,28,0.8); border: 1px solid rgba(59,130,246,0.2);
  border-radius: 1.25rem; padding: 1.5rem 1.75rem; margin-bottom: 2rem;
}
.up-hero-avatar {
  width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, #3B82F6, #60A5FA);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; font-weight: 800; color: #0a0805;
  border: 2px solid rgba(59,130,246,0.4);
  overflow: hidden;
}
.up-hero-photo {
  width: 100%; height: 100%; object-fit: cover;
}
.up-hero-info { min-width: 0; }
.up-hero-name  { font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.25rem; }
.up-hero-email { font-size: 0.875rem; color: #9ca3af; }
.up-hero-date { font-size: 0.8rem; color: #6b7280; margin-top: 0.25rem; }

/* Tabs */
.up-tabs {
  display: flex; gap: 0.35rem; row-gap: 0.5rem; margin-bottom: 1.5rem;
  flex-wrap: wrap;
}
.up-tab {
  padding: 0.55rem 1.1rem; border-radius: 0.5rem;
  border: 1px solid rgba(59,130,246,0.18);
  background: rgba(59,130,246,0.04); cursor: pointer;
  font-family: inherit; font-size: 0.82rem; font-weight: 600;
  color: #6b7280; transition: all 0.15s;
  display: flex; align-items: center; gap: 0.4rem;
  white-space: nowrap; flex-shrink: 0;
}
.up-tab:hover { color: #d1d5db; background: rgba(59,130,246,0.1); border-color: rgba(59,130,246,0.35); }
.up-tab.active {
  background: rgba(16,24,40,0.7); color: #60A5FA;
  border-color: rgba(59,130,246,0.45);
}

/* Flash */
.up-flash {
  padding: 0.75rem 1rem; border-radius: 0.625rem;
  font-size: 0.875rem; margin-bottom: 1.5rem; font-weight: 500;
}
.up-flash.success { background: rgba(0,217,126,0.08); border: 1px solid rgba(0,217,126,0.3); color: #00d97e; }
.up-flash.error   { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.3); color: #ef4444; }

/* Cards */
.up-card {
  background: rgba(8,14,28,0.8); border: 1px solid rgba(59,130,246,0.15);
  border-radius: 1.25rem; padding: 1.75rem; margin-bottom: 1.5rem;
}
.up-card-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(59,130,246,0.1); }
.up-card-title { font-size: 1.05rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.25rem; }
.up-card-sub   { font-size: 0.82rem; color: #6b7280; }

/* Form */
.up-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.up-form-group { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem; }
.up-form-footer { padding-top: 0.5rem; border-top: 1px solid rgba(59,130,246,0.08); margin-top: 0.25rem; }

.up-label { font-size: 0.8rem; font-weight: 700; color: #d1d5db; letter-spacing: 0.02em; text-transform: uppercase; }

.up-input {
  padding: 0.75rem 1rem;
  background: rgba(10,8,5,0.9); border: 1px solid rgba(59,130,246,0.25);
  border-radius: 0.625rem; color: #f1f5f9; font-size: 0.95rem; font-family: inherit;
  transition: border-color 0.18s, box-shadow 0.18s; width: 100%;
}
.up-input:hover { border-color: #60A5FA; }
.up-input:focus {
  outline: none;
  border-color: #60A5FA;
  box-shadow: 0 0 0 4px rgba(252,211,77,0.45);
}
.up-input::placeholder { color: #4b5563; }

.up-input-static {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.75rem 1rem;
  background: rgba(10,8,5,0.5); border: 1px solid rgba(59,130,246,0.1);
  border-radius: 0.625rem;
}
.up-input-static-val { font-size: 0.95rem; color: #9ca3af; }
.up-input-lock { font-size: 0.72rem; color: #4b5563; flex-shrink: 0; }

.up-hint { font-size: 0.76rem; color: #4b5563; }

.scx-key-status { font-size: 0.85rem; color: #00d97e; margin-left: 0.75rem; font-family: monospace; letter-spacing: 0.1em; }

.up-btn-save {
  padding: 0.7rem 1.75rem; border-radius: 0.625rem;
  background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.45);
  color: #60A5FA; font-size: 0.9rem; font-weight: 700; cursor: pointer;
  font-family: inherit; transition: all 0.18s; min-height: 44px;
  margin-top: 1rem;
}
.up-btn-save:hover { background: rgba(59,130,246,0.32); border-color: rgba(59,130,246,0.65); }
.up-btn-save:disabled { opacity: 0.6; cursor: not-allowed; }

/* API Key */
.up-api-key-row { margin-bottom: 1rem; }
.up-api-key-display {
  display: flex; align-items: center; gap: 8px;
  background: rgba(10,8,5,0.9); border: 1px solid rgba(59,130,246,0.25);
  border-radius: 0.625rem; padding: 0.75rem 1rem;
}
.up-api-key-display code {
  flex: 1; font-family: 'Courier New', monospace;
  font-size: 0.85rem; color: #60A5FA; word-break: break-all;
}
.up-api-key-actions { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
.up-api-key-actions .up-hint { margin: 0; }

/* Stats Grid */
.up-stats-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;
  margin-bottom: 0;
}
.up-stat-card {
  background: rgba(8,14,28,0.8); border: 1px solid rgba(59,130,246,0.15);
  border-radius: 1rem; padding: 1.25rem 1.5rem;
}
.up-stat-value {
  font-size: 2rem; font-weight: 800; color: #60A5FA; line-height: 1;
  margin-bottom: 0.3rem; font-variant-numeric: tabular-nums;
}
.up-stat-label { font-size: 0.875rem; font-weight: 600; color: #e5e7eb; margin-bottom: 0.2rem; }
.up-stat-sub   { font-size: 0.75rem; color: #4b5563; }

/* Activity feed */
.up-empty { padding: 2rem 0; text-align: center; font-size: 0.875rem; color: #4b5563; }
.up-activity-list { display: flex; flex-direction: column; }
.up-activity-row {
  display: flex; align-items: flex-start; gap: 0.875rem;
  padding: 0.75rem 0; border-bottom: 1px solid rgba(59,130,246,0.06);
}
.up-activity-row:last-child { border-bottom: none; }
.up-activity-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
  margin-top: 0.35rem;
}
.up-activity-dot.green { background: #00d97e; }
.up-activity-dot.amber { background: #3B82F6; }
.up-activity-dot.blue  { background: #60a5fa; }
.up-activity-dot.red   { background: #ef4444; }
.up-activity-dot.grey  { background: #4b5563; }
.up-activity-body { flex: 1; min-width: 0; }
.up-activity-desc { font-size: 0.875rem; color: #e5e7eb; margin-bottom: 0.2rem; }
.up-activity-meta { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.up-activity-action {
  font-size: 0.7rem; font-weight: 600; font-family: monospace;
  color: #6b7280; background: rgba(255,255,255,0.04);
  padding: 0.1rem 0.4rem; border-radius: 0.25rem;
}
.up-activity-time { font-size: 0.72rem; color: #4b5563; }

/* Toggles */
.up-toggle-row {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  padding: 1rem 0; border-bottom: 1px solid rgba(59,130,246,0.08);
}
.up-toggle-info { flex: 1; }
.up-toggle-label { font-size: 0.875rem; font-weight: 600; color: #e5e7eb; margin-bottom: 0.25rem; }
.up-toggle-desc { font-size: 0.78rem; color: #6b7280; }

.up-toggle {
  position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0;
}
.up-toggle input { opacity: 0; width: 0; height: 0; }
.up-toggle-track {
  position: absolute; cursor: pointer; inset: 0;
  background: rgba(255,255,255,0.1); border-radius: 12px;
  transition: background 0.2s;
}
.up-toggle-track::before {
  content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px;
  background: #9ca3af; border-radius: 50%; transition: transform 0.2s, background 0.2s;
}
.up-toggle input:checked + .up-toggle-track { background: rgba(59,130,246,0.3); }
.up-toggle input:checked + .up-toggle-track::before {
  transform: translateX(20px); background: #60A5FA;
}

/* API Keys tab */
.up-key-banner { margin-bottom: 1.5rem; background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.3); border-radius: 1rem; padding: 1.25rem 1.5rem; }
.up-key-banner-header { font-size: 0.9rem; color: #60A5FA; margin-bottom: 0.75rem; }
.up-key-banner-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.up-key-code { font-family: monospace; font-size: 0.85rem; color: #e5e7eb; background: rgba(0,0,0,0.35); border: 1px solid rgba(59,130,246,0.2); border-radius: 0.5rem; padding: 0.55rem 0.85rem; word-break: break-all; flex: 1; }

/* Danger Zone */
.up-card-danger { border-color: rgba(239,68,68,0.2); }
.up-danger-title { color: #ef4444; }
.up-danger-row {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  flex-wrap: wrap;
}
.up-danger-info { flex: 1; min-width: 200px; }
.up-danger-label { font-size: 0.95rem; font-weight: 600; color: #e5e7eb; margin-bottom: 0.25rem; }
.up-danger-desc { font-size: 0.8rem; color: #6b7280; }
.up-btn-danger {
  padding: 0.6rem 1.25rem; border-radius: 0.5rem;
  background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.4);
  color: #ef4444; font-size: 0.82rem; font-weight: 600;
  cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.up-btn-danger:hover { background: rgba(239,68,68,0.25); border-color: rgba(239,68,68,0.6); }
.up-delete-confirm { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid rgba(239,68,68,0.2); }
.up-delete-warning { font-size: 0.85rem; color: #ef4444; margin-bottom: 1rem; }
.up-delete-row { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.up-delete-row .up-input { max-width: 280px; }
.up-btn-danger-confirm {
  padding: 0.6rem 1.25rem; border-radius: 0.5rem;
  background: #ef4444; border: 1px solid #ef4444;
  color: #fff; font-size: 0.82rem; font-weight: 600;
  cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.up-btn-danger-confirm:hover:not(:disabled) { background: #dc2626; }
.up-btn-danger-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

/* Icon buttons */
.btn-icon {
  background: none; border: none; padding: 4px; cursor: pointer;
  color: #9ca3af; transition: color 0.2s; flex-shrink: 0;
}
.btn-icon:hover { color: #60A5FA; }

/* Responsive */
@media (max-width: 900px) {
  .up-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
  .up-content { padding: 1rem; }
  .up-hero { flex-direction: column; text-align: center; padding: 1.25rem; }
  .up-stats-grid { grid-template-columns: 1fr; }
  .up-form-row { grid-template-columns: 1fr; }
  .up-danger-row { flex-direction: column; align-items: stretch; }
  .up-danger-row .up-btn-danger { width: 100%; }
  .up-delete-row { flex-direction: column; }
  .up-delete-row .up-input { max-width: none; }
  .up-delete-row .up-btn-danger-confirm { width: 100%; }
}
</style>
