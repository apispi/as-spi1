<template>
  <div class="ad">
    <router-link to="/admin/users" class="ad-back">← Back to users</router-link>

    <p v-if="loading" class="ad-muted">Loading…</p>
    <p v-else-if="error" class="ad-error">{{ error }}</p>

    <template v-else-if="user">
      <header class="ad-head">
        <div>
          <h1 class="ad-title">{{ user.name }}</h1>
          <p class="ad-sub">
            {{ user.email }}
            <span v-if="user.is_admin" class="ad-pill passing">admin</span>
            <span v-if="!user.email_verified" class="ad-pill">unverified</span>
            <span v-if="user.signed_up_with_google" class="ad-pill unknown">google</span>
          </p>
        </div>
      </header>

      <div class="ad-stats">
        <div class="ad-stat" v-for="tile in tiles" :key="tile.label">
          <div class="ad-stat-value">{{ tile.value }}</div>
          <div class="ad-stat-label">{{ tile.label }}</div>
        </div>
      </div>

      <!-- Membership -->
      <h2 class="ad-section">Organisation</h2>
      <div class="ad-row ad-boxed">
        <div class="ad-row-main">
          <span class="ad-row-name">{{ user.organisation?.name || 'Not assigned' }}</span>
          <span class="ad-row-sub">Grouping for administration only — it does not restrict access.</span>
        </div>
        <select class="input-field ad-select" :value="user.organisation?.id ?? ''" @change="assign">
          <option value="">Not assigned</option>
          <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>
      </div>

      <!-- Account -->
      <h2 class="ad-section">Account</h2>
      <ul class="ad-list">
        <li class="ad-row">
          <div class="ad-row-main">
            <span class="ad-row-name">Credentials</span>
            <span class="ad-row-sub">
              SCX key {{ user.has_scx_key ? 'configured' : 'not set' }} ·
              API key {{ user.has_api_key ? 'issued' : 'none' }}
            </span>
          </div>
        </li>
        <li class="ad-row">
          <div class="ad-row-main">
            <span class="ad-row-name">Joined</span>
            <span class="ad-row-sub">{{ user.created_at }} · last updated {{ user.updated_at }}</span>
          </div>
        </li>
      </ul>

      <!-- Security posture + remediation -->
      <h2 class="ad-section">Security</h2>
      <ul class="ad-list">
        <li class="ad-row">
          <div class="ad-row-main">
            <span class="ad-row-name">
              Two-factor
              <span class="ad-pill" :class="user.two_factor_enabled ? 'passing' : 'unknown'">{{ user.two_factor_enabled ? 'enabled' : 'off' }}</span>
            </span>
            <span class="ad-row-sub">{{ user.active_sessions }} active session{{ user.active_sessions === 1 ? '' : 's' }}</span>
          </div>
          <div class="aud-actions">
            <button v-if="user.two_factor_enabled" class="ad-btn" :disabled="busy" @click="disable2fa">Reset 2FA</button>
            <button class="ad-btn" :disabled="busy || !user.active_sessions" @click="revokeSessions">Sign out all</button>
          </div>
        </li>
      </ul>
      <ul v-if="securityEvents.length" class="ad-list">
        <li v-for="(e, i) in securityEvents" :key="i" class="ad-row">
          <div class="ad-row-main">
            <span class="ad-row-name">{{ e.label }}</span>
            <span class="ad-row-sub"><template v-if="e.ip">{{ e.ip }} · </template>{{ e.created_at }}</span>
          </div>
        </li>
      </ul>

      <!-- Monitors -->
      <h2 class="ad-section">Monitors</h2>
      <p v-if="!monitors.length" class="ad-muted">None.</p>
      <ul v-else class="ad-list">
        <li v-for="m in monitors" :key="m.id" class="ad-row">
          <span class="ad-dot" :class="m.last_status"></span>
          <div class="ad-row-main">
            <span class="ad-row-name">{{ m.name }}</span>
            <span class="ad-row-sub">
              {{ m.collection?.name || '—' }}
              <template v-if="!m.is_enabled"> · paused</template>
            </span>
          </div>
          <span class="ad-pill" :class="m.last_status">{{ m.last_status }}</span>
        </li>
      </ul>

      <!-- Recent activity -->
      <h2 class="ad-section">Recent requests</h2>
      <p v-if="!recent.length" class="ad-muted">Nothing sent yet.</p>
      <ul v-else class="ad-list">
        <li v-for="r in recent" :key="r.id" class="ad-row">
          <span class="ad-pill unknown">{{ r.protocol === 'rest' ? r.method : r.protocol.toUpperCase() }}</span>
          <div class="ad-row-main">
            <span class="ad-row-sub ad-url">{{ r.url }}</span>
            <span class="ad-row-sub">{{ r.time_ms }}ms · {{ r.created_at }}</span>
          </div>
          <span class="ad-pill" :class="r.status && r.status < 400 ? 'passing' : 'failing'">{{ r.status || 'ERR' }}</span>
        </li>
      </ul>

      <p class="ad-note">
        Request history never stores request headers, so credentials a user
        sent are not visible here.
      </p>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { confirmDialog } from '../confirm';

const route = useRoute();
const user = ref(null);
const counts = ref({});
const recent = ref([]);
const monitors = ref([]);
const securityEvents = ref([]);
const organisations = ref([]);
const loading = ref(true);
const busy = ref(false);
const error = ref('');

const tiles = computed(() => [
  { label: 'Saved requests', value: counts.value.saved_requests ?? 0 },
  { label: 'Collections', value: counts.value.collections ?? 0 },
  { label: 'Environments', value: counts.value.environments ?? 0 },
  { label: 'Monitors', value: counts.value.monitors ?? 0 },
  { label: 'Requests sent', value: counts.value.request_histories ?? 0 },
]);

onMounted(async () => {
  try {
    const [detail, orgs] = await Promise.all([
      axios.get(`/api/admin/users/${route.params.id}`),
      axios.get('/api/admin/organisations'),
    ]);
    user.value = detail.data.user;
    counts.value = detail.data.counts;
    recent.value = detail.data.recent_requests;
    monitors.value = detail.data.monitors;
    securityEvents.value = detail.data.security_events || [];
    organisations.value = orgs.data.organisations;
  } catch (e) {
    error.value = e.response?.status === 404 ? 'That user no longer exists.' : 'Could not load this user.';
  } finally {
    loading.value = false;
  }
});

const revokeSessions = async () => {
  if (!(await confirmDialog(`Sign ${user.value.name} out of all devices? They will need to log in again.`))) return;
  busy.value = true;
  try {
    const res = await axios.post(`/api/admin/users/${route.params.id}/revoke-sessions`);
    user.value.active_sessions = res.data.active_sessions;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not sign the user out.';
  } finally {
    busy.value = false;
  }
};

const disable2fa = async () => {
  if (!(await confirmDialog(`Turn off two-factor for ${user.value.name}? Use this only for account recovery.`))) return;
  busy.value = true;
  try {
    await axios.post(`/api/admin/users/${route.params.id}/disable-2fa`);
    user.value.two_factor_enabled = false;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not disable two-factor.';
  } finally {
    busy.value = false;
  }
};

const assign = async (e) => {
  const value = e.target.value;
  try {
    await axios.put(`/api/admin/users/${route.params.id}/organisation`, {
      organisation_id: value === '' ? null : Number(value),
    });
    const chosen = organisations.value.find((o) => o.id === Number(value));
    user.value.organisation = chosen ? { id: chosen.id, name: chosen.name } : null;
  } catch {
    error.value = 'Could not change the organisation.';
  }
};
</script>

<style scoped>
@import './admin-shared.css';

.ad-section { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); margin: 26px 0 10px; }
.ad-boxed { border: 1px solid var(--border-color); border-radius: 12px; }
.ad-select { max-width: 220px; padding: 6px 10px; font-size: 13px; }
.aud-actions { display: flex; gap: 8px; flex-shrink: 0; }
.ad-url { color: var(--text-primary); font-family: 'Courier New', monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
