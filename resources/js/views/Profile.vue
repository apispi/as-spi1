<!-- Copyright © 2026 ApiSpi -->
<template>
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
          <button :class="['up-tab', { active: activeTab === 'personalisation' }]" @click="activeTab = 'personalisation'">Personalisation</button>
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

        <!-- ── Personalisation tab ── -->
        <template v-else-if="activeTab === 'personalisation'">
          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Personalisation</h2>
              <p class="up-card-sub">Set the defaults used when you open the request tester</p>
            </div>
            <form @submit.prevent="updatePreferences">
              <div class="up-form-row">
                <div class="up-form-group">
                  <label class="up-label" for="pref-protocol">Default Protocol</label>
                  <select id="pref-protocol" v-model="preferencesForm.default_protocol" class="up-input">
                    <option value="rest">REST</option>
                    <option value="mcp">MCP</option>
                    <option value="a2a">A2A</option>
                  </select>
                </div>
                <div class="up-form-group">
                  <label class="up-label" for="pref-method">Default REST Method</label>
                  <select id="pref-method" v-model="preferencesForm.default_method" class="up-input">
                    <option>GET</option>
                    <option>POST</option>
                    <option>PUT</option>
                    <option>PATCH</option>
                    <option>DELETE</option>
                  </select>
                </div>
              </div>
              <div class="up-form-group">
                <label class="up-label" for="pref-timezone">Timezone</label>
                <select id="pref-timezone" v-model="preferencesForm.timezone" class="up-input">
                  <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
                <p class="up-hint">Used when displaying request timestamps</p>
              </div>
              <div class="up-toggle-row" style="border-bottom: none; padding-top: 0">
                <div class="up-toggle-info">
                  <div class="up-toggle-label">Compact history view</div>
                  <div class="up-toggle-desc">Show request history as denser rows in the dashboard</div>
                </div>
                <label class="up-toggle">
                  <input type="checkbox" v-model="preferencesForm.compact_history">
                  <span class="up-toggle-track"><span class="up-toggle-thumb"></span></span>
                </label>
              </div>
              <div class="up-form-footer">
                <button type="submit" class="up-btn-save" :disabled="savingPreferences">
                  {{ savingPreferences ? 'Saving...' : 'Save Preferences' }}
                </button>
              </div>
            </form>
          </div>

          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Product tour</h2>
              <p class="up-card-sub">Replay the guided walkthrough of the workspace</p>
            </div>
            <p class="up-hint" style="margin-bottom: 1rem">
              Take the interactive tour again to revisit what each part of Spi does. It starts on your dashboard.
            </p>
            <button type="button" class="up-btn-save" style="margin-top: 0" @click="replayTour">
              Replay the tour
            </button>
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
              <h2 class="up-card-title">API Keys</h2>
              <p class="up-card-sub">Named keys that authenticate programmatic requests to <code class="up-inline-code">/api/v1</code>. Create one per app or script so you can revoke them independently.</p>
            </div>

            <form class="up-key-create" @submit.prevent="createKey">
              <input
                v-model="keyForm.name"
                class="up-input"
                type="text"
                maxlength="60"
                placeholder="Key name (e.g. CI pipeline)"
                :disabled="creatingKey"
              >
              <input
                v-model="keyForm.expires_at"
                class="up-input up-key-expiry"
                type="date"
                :min="tomorrow"
                :disabled="creatingKey"
                title="Optional expiry date"
              >
              <button type="submit" class="up-btn-save" :disabled="creatingKey || !keyForm.name.trim()">
                {{ creatingKey ? 'Creating…' : 'Create Key' }}
              </button>
            </form>

            <div v-if="apiKeys.length" class="up-key-table">
              <div v-for="key in apiKeys" :key="key.id" class="up-key-item" :class="{ 'is-revoked': key.revoked, 'is-expired': key.expired }">
                <div class="up-key-item-main">
                  <div class="up-key-item-name">
                    {{ key.name }}
                    <span v-if="key.revoked" class="up-key-badge revoked">revoked</span>
                    <span v-else-if="key.expired" class="up-key-badge expired">expired</span>
                    <span v-else class="up-key-badge active">active</span>
                  </div>
                  <code class="up-key-item-mask">{{ key.masked }}</code>
                </div>
                <div class="up-key-item-meta">
                  <span>created {{ formatMemberSince(key.created_at) }}</span>
                  <span v-if="key.expires_at">· expires {{ formatMemberSince(key.expires_at) }}</span>
                  <span v-if="key.last_used_at">· last used {{ formatMemberSince(key.last_used_at) }}</span>
                  <span v-else>· never used</span>
                </div>
                <button
                  v-if="!key.revoked"
                  class="up-btn-danger-sm"
                  :disabled="revokingId === key.id"
                  @click="revokeKey(key)"
                >
                  {{ revokingId === key.id ? 'Revoking…' : 'Revoke' }}
                </button>
              </div>
            </div>
            <div v-else class="up-empty">You don't have any API keys yet.</div>
            <p class="up-hint">For your security the full key is shown only once, when it is created.</p>
          </div>

          <div class="up-card">
            <div class="up-card-header">
              <h2 class="up-card-title">Using your key</h2>
              <p class="up-card-sub">Send it as a bearer token</p>
            </div>
            <pre class="up-code-block">curl -X POST {{ origin }}/api/v1/proxy \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://api.example.com","method":"GET"}'</pre>
            <p class="up-hint">Also available: <code class="up-inline-code">/api/v1/mcp/test</code> and <code class="up-inline-code">/api/v1/a2a/test</code>. See the <router-link to="/developers" class="up-inline-link">API docs</router-link> for full reference.</p>
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
              <h2 class="up-card-title">Monitoring</h2>
              <p class="up-card-sub">Your scheduled monitors and their current status</p>
            </div>
            <div v-if="!monitors.length" class="up-empty">
              You have no monitors yet. Create one from the <router-link to="/monitors" class="up-inline-link">Monitors</router-link> page to watch an endpoint or collection on a schedule.
            </div>
            <template v-else>
              <div class="up-mon-summary">
                <div class="up-mon-stat"><span class="up-mon-num" :class="{ bad: monitorSummary.failing }">{{ monitorSummary.failing }}</span><span class="up-mon-label">Failing</span></div>
                <div class="up-mon-stat"><span class="up-mon-num">{{ monitorSummary.passing }}</span><span class="up-mon-label">Passing</span></div>
                <div class="up-mon-stat"><span class="up-mon-num">{{ monitors.length }}</span><span class="up-mon-label">Total</span></div>
                <div class="up-mon-stat"><span class="up-mon-num">{{ monitorSummary.disabled }}</span><span class="up-mon-label">Paused</span></div>
              </div>
              <div class="up-mon-list">
                <div v-for="m in monitorsSorted" :key="m.id" class="up-mon-row">
                  <span class="up-mon-dot" :class="m.last_status"></span>
                  <span class="up-mon-name">{{ m.name }}</span>
                  <span class="up-mon-meta">
                    <span class="up-mon-pill" :class="m.last_status">{{ monitorStatusLabel(m.last_status) }}</span>
                    <span v-if="!m.is_enabled" class="up-mon-pill">paused</span>
                    <span v-if="m.uptime !== null"> · {{ m.uptime }}% uptime</span>
                  </span>
                </div>
              </div>
              <div class="up-api-key-actions" style="margin-top: 1rem">
                <router-link to="/monitors" class="up-inline-link">Manage monitors →</router-link>
              </div>
            </template>
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

          <div class="up-card" style="margin-top: 1.5rem">
            <div class="up-card-header">
              <h2 class="up-card-title">Security activity</h2>
              <p class="up-card-sub">Recent security events on your account</p>
            </div>
            <div v-if="!securityLog.length" class="up-empty">No security events recorded yet.</div>
            <div v-else class="up-activity-list">
              <div v-for="(item, i) in securityLog" :key="i" class="up-activity-row">
                <div class="up-activity-dot" :class="item.action.startsWith('auth.login_failed') ? 'red' : (item.action.startsWith('api_key') ? 'amber' : 'blue')"></div>
                <div class="up-activity-body">
                  <div class="up-activity-desc">
                    {{ item.label }}
                    <span v-if="item.metadata?.name"> — {{ item.metadata.name }}</span>
                  </div>
                  <div class="up-activity-meta">
                    <span v-if="item.ip" class="up-activity-action">{{ item.ip }}</span>
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
              <h2 class="up-card-title">SCX AI Integration</h2>
              <p class="up-card-sub">Connect your SCX AI account using an API key</p>
            </div>
            <form @submit.prevent="updateScxApiKey">
              <div class="up-form-group">
                <label class="up-label" for="scx-api-key">SCX API Key</label>
                <span v-if="hasScxKey" class="scx-key-status">••••••••</span>
                <input id="scx-api-key" type="password" v-model="scxApiKeyForm" class="up-input" placeholder="Enter your SCX API key">
                <p v-if="hasScxKey" class="up-hint">A key is saved. Enter a new value to replace it.</p>
              </div>
              <div class="up-form-group">
                <label class="up-label" for="scx-model">AI Model</label>
                <select id="scx-model" v-model="scxModelForm" class="up-input">
                  <option value="scx-ai">SCX AI (Default)</option>
                  <option value="gpt-4o-mini">GPT-4o Mini</option>
                  <option value="MAGPIE">MAGPIE</option>
                  <option value="coder">Coder</option>
                  <option value="MiniMax-M2.7">MiniMax-M2.7</option>
                </select>
              </div>
              <div class="up-form-footer">
                <button type="submit" class="up-btn-save" :disabled="savingScx">
                  {{ savingScx ? 'Saving...' : 'Save SCX Settings' }}
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
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useAuthStore } from '../store/auth';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { restartTour } from '../onboarding';

const authStore = useAuthStore();
const router = useRouter();

// Clear the completion flag and replay the tour from the dashboard, where all
// of its highlighted targets exist.
const replayTour = async () => {
  await router.push('/dashboard');
  restartTour(authStore.user?.id);
};

onMounted(() => {
  form.name = authStore.user?.name || '';
  loadApiKeys();
  loadScxKeyStatus();
  loadStats();
  loadRecentActivity();
  loadSecurityLog();
  loadMonitors();
  loadPreferences();
});

const userInitial = computed(() => {
  const name = authStore.user?.name || 'U';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
});

const VALID_TABS = ['account', 'personalisation', 'api-keys', 'usage', 'settings', 'danger'];

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

const preferencesForm = reactive({
  default_protocol: 'rest',
  default_method: 'GET',
  timezone: 'UTC',
  compact_history: false
});
const savingPreferences = ref(false);
const timezones = (typeof Intl.supportedValuesOf === 'function')
  ? Intl.supportedValuesOf('timeZone')
  : ['UTC', 'America/New_York', 'Europe/London', 'Australia/Sydney', 'Asia/Singapore'];

const apiKeys = ref([]);
const keyForm = reactive({ name: '', expires_at: '' });
const creatingKey = ref(false);
const revokingId = ref(null);
const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
const newKey = ref('');
const newKeyName = ref('');
const showKeyBanner = ref(false);
const copiedKey = ref(false);
const origin = typeof window !== 'undefined' ? window.location.origin : '';

const stats = ref({});
const recentActivity = ref([]);
const securityLog = ref([]);
const monitors = ref([]);

const monitorSummary = computed(() => ({
  failing: monitors.value.filter((m) => m.last_status === 'failing').length,
  passing: monitors.value.filter((m) => m.last_status === 'passing').length,
  disabled: monitors.value.filter((m) => !m.is_enabled).length,
}));

// Failing first, then by name, so problems surface at the top.
const monitorsSorted = computed(() => [...monitors.value].sort((a, b) => {
  const rank = (m) => (m.last_status === 'failing' ? 0 : m.last_status === 'passing' ? 2 : 1);
  return rank(a) - rank(b) || a.name.localeCompare(b.name);
}));

const monitorStatusLabel = (s) => ({ passing: 'Passing', failing: 'Failing', unknown: 'Not run' }[s] || s || 'Not run');

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

const loadApiKeys = async () => {
  try {
    const res = await axios.get('/api/user/api-keys');
    apiKeys.value = res.data || [];
  } catch (error) {
    apiKeys.value = [];
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

const loadSecurityLog = async () => {
  try {
    const res = await axios.get('/api/user/security-log');
    securityLog.value = res.data || [];
  } catch {
    securityLog.value = [];
  }
};

const loadMonitors = async () => {
  try {
    const res = await axios.get('/api/monitors');
    monitors.value = res.data || [];
  } catch {
    monitors.value = [];
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

const loadPreferences = async () => {
  try {
    const res = await axios.get('/api/user/preferences');
    Object.assign(preferencesForm, res.data);
  } catch (error) {
    // keep defaults
  }
};

const updatePreferences = async () => {
  savingPreferences.value = true;
  flashSuccess.value = '';
  flashError.value = '';
  try {
    const res = await axios.put('/api/user/preferences', { ...preferencesForm });
    Object.assign(preferencesForm, res.data);
    flashSuccess.value = 'Preferences saved';
  } catch (error) {
    flashError.value = error.response?.data?.message || 'Failed to save preferences';
  } finally {
    savingPreferences.value = false;
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

const copyNewKey = async () => {
  try {
    await navigator.clipboard.writeText(newKey.value);
    copiedKey.value = true;
    setTimeout(() => { copiedKey.value = false; }, 1500);
  } catch (error) {
    console.error('Failed to copy');
  }
};

const createKey = async () => {
  if (!keyForm.name.trim()) return;
  creatingKey.value = true;
  flashError.value = '';
  try {
    const payload = { name: keyForm.name.trim() };
    if (keyForm.expires_at) payload.expires_at = keyForm.expires_at;
    const res = await axios.post('/api/user/api-keys', payload);
    // Plaintext is returned once only — surface it in the copy banner.
    newKey.value = res.data.plaintext;
    newKeyName.value = res.data.name;
    showKeyBanner.value = true;
    copiedKey.value = false;
    keyForm.name = '';
    keyForm.expires_at = '';
    await loadApiKeys();
  } catch (error) {
    flashError.value = error.response?.data?.message || 'Failed to create key';
  } finally {
    creatingKey.value = false;
  }
};

const revokeKey = async (key) => {
  if (!confirm(`Revoke "${key.name}"? Any request using it will stop working immediately.`)) return;
  revokingId.value = key.id;
  flashError.value = '';
  try {
    await axios.delete(`/api/user/api-keys/${key.id}`);
    await loadApiKeys();
  } catch (error) {
    flashError.value = 'Failed to revoke key';
  } finally {
    revokingId.value = null;
  }
};

const updateScxApiKey = async () => {
  savingScx.value = true;
  flashSuccess.value = '';
  flashError.value = '';
  try {
    await Promise.all([
      axios.put('/api/user/scx-api-key', { scx_api_key: scxApiKeyForm.value }),
      axios.put('/api/user/scx-model', { scx_model: scxModelForm.value }),
    ]);
    flashSuccess.value = 'SCX settings saved';
    hasScxKey.value = scxApiKeyForm.value !== '' || hasScxKey.value;
  } catch (error) {
    flashError.value = 'Failed to save SCX settings';
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

.up-content { padding: 2rem 2.5rem; max-width: none; }

/* Hero */
.up-hero {
  display: flex; align-items: center; gap: 1.25rem;
  background: var(--panel-bg); border: 1px solid var(--border-color);
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
.up-hero-name  { font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
.up-hero-email { font-size: 0.875rem; color: var(--text-secondary); }
.up-hero-date { font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem; }

/* Tabs */
.up-tabs {
  display: flex; gap: 0.35rem; row-gap: 0.5rem; margin-bottom: 1.5rem;
  flex-wrap: wrap;
}
.up-tab {
  padding: 0.55rem 1.1rem; border-radius: 0.5rem;
  border: 1px solid var(--border-color);
  background: rgba(59,130,246,0.04); cursor: pointer;
  font-family: inherit; font-size: 0.82rem; font-weight: 600;
  color: var(--text-secondary); transition: all 0.15s;
  display: flex; align-items: center; gap: 0.4rem;
  white-space: nowrap; flex-shrink: 0;
}
.up-tab:hover { color: var(--text-primary); background: rgba(59,130,246,0.1); border-color: rgba(59,130,246,0.35); }
.up-tab.active {
  background: var(--panel-bg); color: var(--accent-color);
  border-color: rgba(59,130,246,0.45);
}

/* Flash */
.up-flash {
  padding: 0.75rem 1rem; border-radius: 0.625rem;
  font-size: 0.875rem; margin-bottom: 1.5rem; font-weight: 500;
}
.up-flash.success { background: rgba(0,217,126,0.08); border: 1px solid rgba(0,217,126,0.3); color: var(--success-color); }
.up-flash.error   { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.3); color: var(--error-color); }

/* Cards */
.up-card {
  background: var(--panel-bg); border: 1px solid var(--border-color);
  border-radius: 1.25rem; padding: 1.75rem; margin-bottom: 1.5rem;
}
.up-card-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color); }
.up-card-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem; }
.up-card-sub   { font-size: 0.82rem; color: var(--text-secondary); }

/* Form */
.up-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.up-form-group { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem; }
.up-form-footer { padding-top: 0.5rem; border-top: 1px solid var(--border-color); margin-top: 0.25rem; }

.up-label { font-size: 0.8rem; font-weight: 700; color: var(--text-primary); letter-spacing: 0.02em; text-transform: uppercase; }

.up-input {
  padding: 0.75rem 1rem;
  background: var(--input-bg); border: 1px solid var(--border-color);
  border-radius: 0.625rem; color: var(--text-primary); font-size: 0.95rem; font-family: inherit;
  transition: border-color 0.18s, box-shadow 0.18s; width: 100%;
}
.up-input:hover { border-color: var(--accent-color); }
.up-input:focus {
  outline: none;
  border-color: var(--accent-color);
  box-shadow: 0 0 0 4px rgba(252,211,77,0.45);
}
.up-input::placeholder { color: var(--text-secondary); }

.up-input-static {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.75rem 1rem;
  background: var(--input-bg); border: 1px solid var(--border-color);
  border-radius: 0.625rem;
}
.up-input-static-val { font-size: 0.95rem; color: var(--text-secondary); }
.up-input-lock { font-size: 0.72rem; color: var(--text-secondary); flex-shrink: 0; }

.up-hint { font-size: 0.76rem; color: var(--text-secondary); }

.scx-key-status { font-size: 0.85rem; color: var(--success-color); margin-left: 0.75rem; font-family: monospace; letter-spacing: 0.1em; }

.up-btn-save {
  padding: 0.7rem 1.75rem; border-radius: 0.625rem;
  background: var(--accent-soft); border: 1px solid rgba(59,130,246,0.45);
  color: var(--accent-color); font-size: 0.9rem; font-weight: 700; cursor: pointer;
  font-family: inherit; transition: all 0.18s; min-height: 44px;
  margin-top: 1rem;
}
.up-btn-save:hover { background: rgba(59,130,246,0.32); border-color: rgba(59,130,246,0.65); }
.up-btn-save:disabled { opacity: 0.6; cursor: not-allowed; }

/* API Key */
.up-api-key-row { margin-bottom: 1rem; }
.up-api-key-display {
  display: flex; align-items: center; gap: 8px;
  background: var(--input-bg); border: 1px solid var(--border-color);
  border-radius: 0.625rem; padding: 0.75rem 1rem;
}
.up-api-key-display code {
  flex: 1; font-family: 'Courier New', monospace;
  font-size: 0.85rem; color: var(--accent-color); word-break: break-all;
}
.up-api-key-actions { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
.up-api-key-actions .up-hint { margin: 0; }
.up-key-meta { font-size: 0.75rem; color: var(--text-secondary); flex-shrink: 0; }

/* Named API keys */
.up-key-create { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.up-key-create .up-input { flex: 1; min-width: 180px; margin: 0; }
.up-key-create .up-key-expiry { flex: 0 0 auto; max-width: 170px; }
.up-key-create .up-btn-save { margin-top: 0; }
.up-key-table { display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 0.75rem; }
.up-key-item {
  display: grid; grid-template-columns: 1fr auto; align-items: center;
  gap: 0.35rem 1rem;
  background: var(--input-bg); border: 1px solid var(--border-color);
  border-radius: 0.625rem; padding: 0.75rem 1rem;
}
.up-key-item.is-revoked, .up-key-item.is-expired { opacity: 0.55; }
.up-key-item-main { grid-column: 1; display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; }
.up-key-item-name { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
.up-key-item-mask { font-family: 'Courier New', monospace; font-size: 0.8rem; color: var(--accent-color); word-break: break-all; }
.up-key-item-meta { grid-column: 1; font-size: 0.72rem; color: var(--text-secondary); display: flex; gap: 0.35rem; flex-wrap: wrap; }
.up-key-badge { font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.1rem 0.4rem; border-radius: 0.35rem; }
.up-key-badge.active { color: var(--success-color); background: rgba(0,217,126,0.12); }
.up-key-badge.revoked { color: var(--error-color); background: rgba(248,113,113,0.12); }
.up-key-badge.expired { color: var(--warning-color); background: rgba(251,191,36,0.12); }
.up-btn-danger-sm {
  grid-column: 2; grid-row: 1 / span 2; align-self: center;
  padding: 0.45rem 0.9rem; border-radius: 0.5rem;
  background: rgba(248,113,113,0.12); border: 1px solid rgba(248,113,113,0.4);
  color: var(--error-color); font-size: 0.8rem; font-weight: 700; cursor: pointer;
  font-family: inherit; transition: all 0.18s; white-space: nowrap;
}
.up-btn-danger-sm:hover { background: rgba(248,113,113,0.22); }
.up-btn-danger-sm:disabled { opacity: 0.6; cursor: not-allowed; }
.up-inline-code {
  font-family: monospace; font-size: 0.85em; color: var(--accent-color);
  background: rgba(0,0,0,0.3); padding: 0.1rem 0.35rem; border-radius: 0.25rem;
}
.up-inline-link { color: var(--accent-color); text-decoration: none; }
.up-inline-link:hover { text-decoration: underline; }
.up-code-block {
  font-family: 'Courier New', monospace; font-size: 0.78rem; color: var(--text-primary);
  background: var(--input-bg); border: 1px solid var(--border-color);
  border-radius: 0.625rem; padding: 1rem; overflow-x: auto;
  white-space: pre; margin-bottom: 0.75rem;
}

/* Stats Grid */
.up-stats-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;
  margin-bottom: 0;
}
.up-stat-card {
  background: var(--panel-bg); border: 1px solid var(--border-color);
  border-radius: 1rem; padding: 1.25rem 1.5rem;
}
.up-stat-value {
  font-size: 2rem; font-weight: 800; color: var(--accent-color); line-height: 1;
  margin-bottom: 0.3rem; font-variant-numeric: tabular-nums;
}
.up-stat-label { font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.2rem; }
.up-stat-sub   { font-size: 0.75rem; color: var(--text-secondary); }

/* Activity feed */
.up-empty { padding: 2rem 0; text-align: center; font-size: 0.875rem; color: var(--text-secondary); }
.up-activity-list { display: flex; flex-direction: column; }
.up-activity-row {
  display: flex; align-items: flex-start; gap: 0.875rem;
  padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);
}
.up-activity-row:last-child { border-bottom: none; }
.up-activity-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
  margin-top: 0.35rem;
}
.up-activity-dot.green { background: var(--success-color); }
.up-activity-dot.amber { background: var(--accent-color); }
.up-activity-dot.blue  { background: var(--accent-color); }
.up-activity-dot.red   { background: var(--error-color); }
.up-activity-dot.grey  { background: var(--text-secondary); }

/* Monitoring summary */
.up-mon-summary { display: flex; gap: 1.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.up-mon-stat { display: flex; flex-direction: column; }
.up-mon-num { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); font-variant-numeric: tabular-nums; }
.up-mon-num.bad { color: var(--error-color); }
.up-mon-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); }
.up-mon-list { display: flex; flex-direction: column; gap: 2px; }
.up-mon-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--border-color); font-size: 0.85rem; }
.up-mon-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: var(--text-secondary); }
.up-mon-dot.passing { background: var(--success-color); }
.up-mon-dot.failing { background: var(--error-color); }
.up-mon-name { color: var(--text-primary); font-weight: 600; }
.up-mon-meta { margin-left: auto; color: var(--text-secondary); font-size: 0.78rem; display: flex; align-items: center; gap: 6px; }
.up-mon-pill { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; padding: 1px 7px; border-radius: 999px; background: rgba(127,127,127,.14); color: var(--text-secondary); }
.up-mon-pill.passing { color: var(--success-color); background: rgba(35,134,54,.14); }
.up-mon-pill.failing { color: var(--error-color); background: rgba(248,81,73,.14); }
.up-activity-body { flex: 1; min-width: 0; }
.up-activity-desc { font-size: 0.875rem; color: var(--text-primary); margin-bottom: 0.2rem; }
.up-activity-meta { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.up-activity-action {
  font-size: 0.7rem; font-weight: 600; font-family: monospace;
  color: var(--text-secondary); background: rgba(255,255,255,0.04);
  padding: 0.1rem 0.4rem; border-radius: 0.25rem;
}
.up-activity-time { font-size: 0.72rem; color: var(--text-secondary); }

/* Toggles */
.up-toggle-row {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  padding: 1rem 0; border-bottom: 1px solid var(--border-color);
}
.up-toggle-info { flex: 1; }
.up-toggle-label { font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem; }
.up-toggle-desc { font-size: 0.78rem; color: var(--text-secondary); }

.up-toggle {
  position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0;
}
.up-toggle input { opacity: 0; width: 0; height: 0; }
.up-toggle-track {
  position: absolute; cursor: pointer; inset: 0;
  background: var(--border-color); border-radius: 12px;
  transition: background 0.2s;
}
.up-toggle-track::before {
  content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px;
  background: var(--text-secondary); border-radius: 50%; transition: transform 0.2s, background 0.2s;
}
.up-toggle input:checked + .up-toggle-track { background: rgba(59,130,246,0.3); }
.up-toggle input:checked + .up-toggle-track::before {
  transform: translateX(20px); background: var(--accent-color);
}

/* API Keys tab */
.up-key-banner { margin-bottom: 1.5rem; background: var(--accent-soft); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem; }
.up-key-banner-header { font-size: 0.9rem; color: var(--accent-color); margin-bottom: 0.75rem; }
.up-key-banner-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.up-key-code { font-family: monospace; font-size: 0.85rem; color: var(--text-primary); background: rgba(0,0,0,0.35); border: 1px solid var(--border-color); border-radius: 0.5rem; padding: 0.55rem 0.85rem; word-break: break-all; flex: 1; }

/* Danger Zone */
.up-card-danger { border-color: rgba(239,68,68,0.2); }
.up-danger-title { color: var(--error-color); }
.up-danger-row {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  flex-wrap: wrap;
}
.up-danger-info { flex: 1; min-width: 200px; }
.up-danger-label { font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem; }
.up-danger-desc { font-size: 0.8rem; color: var(--text-secondary); }
.up-btn-danger {
  padding: 0.6rem 1.25rem; border-radius: 0.5rem;
  background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.4);
  color: var(--error-color); font-size: 0.82rem; font-weight: 600;
  cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.up-btn-danger:hover { background: rgba(239,68,68,0.25); border-color: rgba(239,68,68,0.6); }
.up-delete-confirm { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid rgba(239,68,68,0.2); }
.up-delete-warning { font-size: 0.85rem; color: var(--error-color); margin-bottom: 1rem; }
.up-delete-row { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.up-delete-row .up-input { max-width: 280px; }
.up-btn-danger-confirm {
  padding: 0.6rem 1.25rem; border-radius: 0.5rem;
  background: var(--error-color); border: 1px solid var(--error-color);
  color: #fff; font-size: 0.82rem; font-weight: 600;
  cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.up-btn-danger-confirm:hover:not(:disabled) { background: #dc2626; }
.up-btn-danger-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

/* Icon buttons */
.btn-icon {
  background: none; border: none; padding: 4px; cursor: pointer;
  color: var(--text-secondary); transition: color 0.2s; flex-shrink: 0;
}
.btn-icon:hover { color: var(--accent-color); }

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
