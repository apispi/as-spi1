<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Identity</h1>
        <p class="ad-sub">Who's who and who's doing what — account security activity and admin actions across the platform.</p>
      </div>
      <button class="ad-btn" @click="refresh" :disabled="loading">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <!-- 24h summary -->
    <div class="ad-stats">
      <div class="ad-stat">
        <div class="ad-stat-value" :class="summary.failed_logins_24h ? 'bad' : ''">{{ summary.failed_logins_24h ?? 0 }}</div>
        <div class="ad-stat-label">Failed sign-ins (24h)</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.logins_24h ?? 0 }}</div>
        <div class="ad-stat-label">Sign-ins (24h)</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.events_24h ?? 0 }}</div>
        <div class="ad-stat-label">Events (24h)</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.distinct_ips_24h ?? 0 }}</div>
        <div class="ad-stat-label">Distinct IPs (24h)</div>
      </div>
    </div>

    <!-- Suspicious IPs -->
    <div v-if="summary.top_failed_ips && summary.top_failed_ips.length" class="ad-note se-suspect">
      <strong>Most failed sign-ins (24h):</strong>
      <span v-for="row in summary.top_failed_ips" :key="row.ip" class="se-ip">
        <code>{{ row.ip }}</code> ×{{ row.attempts }}
      </span>
    </div>

    <!-- Filters -->
    <div class="se-controls">
      <select class="se-input" v-model="action" @change="fetchPage(1)">
        <option value="">All actions</option>
        <option v-for="a in ACTIONS" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
      <input class="se-input se-search" v-model="q" @keyup.enter="fetchPage(1)" placeholder="Filter by email or IP…">
      <button class="ad-btn" @click="fetchPage(1)">Search</button>
    </div>

    <p v-if="loading && !events.length" class="ad-muted">Loading…</p>

    <div v-else-if="!events.length" class="ad-empty">
      <Icon name="shield" :size="26" />
      <p>No security events match.</p>
    </div>

    <ul v-else class="se-list">
      <li v-for="e in events" :key="e.id" class="se-row">
        <span class="se-badge" :class="badgeClass(e.action)">{{ e.label }}</span>
        <div class="se-main">
          <span class="se-actor">
            <template v-if="e.actor">
              <router-link :to="`/admin/users/${e.actor.id}`" class="se-link">{{ e.actor.name }}</router-link>
              <span class="ad-muted"> · {{ e.actor.email }}</span>
            </template>
            <template v-else>{{ e.actor_email || 'unknown' }}</template>
            <span v-if="e.metadata && e.metadata.name" class="ad-muted"> · {{ e.metadata.name }}</span>
          </span>
          <span class="se-meta">
            <code v-if="e.ip" class="se-ipc">{{ e.ip }}</code>
            <span class="se-time">{{ when(e.created_at) }}</span>
          </span>
        </div>
      </li>
    </ul>

    <div v-if="pagination.last_page > 1" class="se-pager">
      <button class="ad-btn" :disabled="pagination.current_page <= 1" @click="fetchPage(pagination.current_page - 1)">← Prev</button>
      <span class="ad-muted">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
      <button class="ad-btn" :disabled="pagination.current_page >= pagination.last_page" @click="fetchPage(pagination.current_page + 1)">Next →</button>
    </div>

    <!-- Recent admin actions -->
    <div class="ad-section-head">
      <h2 class="ad-section">Recent admin actions</h2>
      <router-link to="/admin/logs" class="ad-back">Application logs →</router-link>
    </div>
    <p v-if="!actions.length" class="ad-muted">No admin actions recorded yet.</p>
    <ul v-else class="se-list">
      <li v-for="entry in actions" :key="entry.id" class="se-row">
        <span class="se-badge" :class="entry.action.includes('delete') ? 'bad' : 'warn'">{{ actionLabel(entry.action) }}</span>
        <div class="se-main">
          <span class="se-actor">
            <strong>{{ entry.admin?.name || entry.admin_email || 'Unknown' }}</strong>
            <span class="ad-muted"> → {{ entry.target_email || '—' }}</span>
          </span>
          <span class="se-meta"><span class="se-time">{{ when(entry.created_at) }}</span></span>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';
import Icon from '../components/Icon.vue';

const ACTIONS = [
  { value: 'auth.login', label: 'Signed in' },
  { value: 'auth.login_failed', label: 'Failed sign-in' },
  { value: 'auth.logout', label: 'Signed out' },
  { value: 'auth.register', label: 'Account created' },
  { value: 'auth.password_changed', label: 'Password changed' },
  { value: 'api_key.created', label: 'API key created' },
  { value: 'api_key.revoked', label: 'API key revoked' },
  { value: 'account.deleted', label: 'Account deleted' },
];

const events = ref([]);
const summary = ref({});
const actions = ref([]);
const pagination = reactive({ current_page: 1, last_page: 1 });
const action = ref('');
const q = ref('');
const loading = ref(true);

const loadActions = async () => {
  try {
    const res = await axios.get('/api/admin/actions');
    actions.value = (res.data.data || []).slice(0, 10);
  } catch { actions.value = []; }
};

const refresh = () => { fetchPage(1); loadActions(); };

const actionLabel = (a) => ({
  promote_admin: 'Promoted', demote_admin: 'Demoted', delete_user: 'Deactivated',
  force_delete_user: 'Deleted forever', restore_user: 'Restored', create_user: 'Created',
  assign_organisation: 'Assigned org', unassign_organisation: 'Unassigned org',
}[a] || a);

const fetchPage = async (page) => {
  loading.value = true;
  try {
    const params = { page };
    if (action.value) params.action = action.value;
    if (q.value.trim()) params.q = q.value.trim();
    const res = await axios.get('/api/admin/security-events', { params });
    events.value = res.data.events.data || [];
    pagination.current_page = res.data.events.current_page;
    pagination.last_page = res.data.events.last_page;
    summary.value = res.data.summary || {};
  } catch {
    events.value = [];
  } finally {
    loading.value = false;
  }
};

const badgeClass = (a) => {
  if (a === 'auth.login_failed') return 'bad';
  if (a.startsWith('api_key')) return 'warn';
  if (a === 'account.deleted') return 'bad';
  return 'ok';
};

const when = (ts) => {
  if (!ts) return '';
  const d = new Date(ts);
  return d.toLocaleString();
};

onMounted(refresh);
</script>

<style scoped>
@import './admin-shared.css';

.se-suspect { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.se-suspect .se-ip { font-size: 13px; }
.se-suspect code { color: var(--error-color); }

.se-controls { display: flex; gap: 10px; flex-wrap: wrap; margin: 16px 0; }
.se-input { padding: 8px 12px; border-radius: 8px; font-size: 13px; font-family: inherit; background: var(--panel-bg); border: 1px solid var(--border-color); color: var(--text-primary); }
.se-search { flex: 1; min-width: 180px; }

.se-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.se-row { display: flex; align-items: center; gap: 12px; padding: 9px 12px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--panel-bg); }
.se-badge { flex-shrink: 0; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 8px; border-radius: 5px; min-width: 96px; text-align: center; background: rgba(127,127,127,.14); color: var(--text-secondary); }
.se-badge.ok { background: rgba(63,185,80,.14); color: #3fb950; }
.se-badge.warn { background: rgba(210,153,34,.16); color: #d29922; }
.se-badge.bad { background: rgba(248,81,73,.16); color: #f85149; }
.se-main { flex: 1; display: flex; align-items: center; justify-content: space-between; gap: 12px; min-width: 0; flex-wrap: wrap; }
.se-actor { font-size: 13.5px; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; }
.se-link { color: var(--accent-color); text-decoration: none; }
.se-meta { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.se-ipc { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); }
.se-time { font-size: 12px; color: var(--text-secondary); }

.se-pager { display: flex; align-items: center; gap: 14px; margin-top: 16px; }

.ad-section { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); margin: 30px 0 10px; }
.ad-section-head { display: flex; align-items: baseline; justify-content: space-between; }
</style>
