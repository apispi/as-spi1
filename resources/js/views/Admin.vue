<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Overview</h1>
        <p class="ad-sub">Is anything wrong, and where. Each section links into its own page.</p>
      </div>
      <button class="ad-btn" @click="fetchAll" :disabled="loading">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <!-- Is anything wrong? -->
    <div class="ad-stats" v-if="stats">
      <div class="ad-stat">
        <div class="ad-stat-value" :class="monitoring.failing ? 'bad' : ''">{{ monitoring.failing ?? 0 }}</div>
        <div class="ad-stat-label">Failing monitors</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.total_users }}</div>
        <div class="ad-stat-label">Users</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.new_users_this_week }}</div>
        <div class="ad-stat-label">New this week</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.total_requests ?? 0 }}</div>
        <div class="ad-stat-label">Requests sent</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.requests_this_week ?? 0 }}</div>
        <div class="ad-stat-label">This week</div>
      </div>
    </div>

    <!-- Failing monitors, when any -->
    <template v-if="failing.length">
      <div class="ad-section-head">
        <h2 class="ad-section">Failing monitors</h2>
        <router-link to="/admin/monitoring" class="ad-back">All monitoring →</router-link>
      </div>
      <ul class="ad-list">
        <li v-for="m in failing" :key="m.id" class="ad-row">
          <span class="ad-dot failing"></span>
          <div class="ad-row-main">
            <span class="ad-row-name">{{ m.name }}</span>
            <span class="ad-row-sub">
              {{ m.owner?.email }} · {{ m.collection || '—' }}
              <template v-if="m.consecutive_failures > 1"> · {{ m.consecutive_failures }} failures in a row</template>
            </span>
          </div>
          <router-link v-if="m.owner" :to="`/admin/users/${m.owner.id}`" class="ad-btn">Owner</router-link>
        </li>
      </ul>
    </template>

    <!-- Protocol usage -->
    <template v-if="protocolRows.length">
      <h2 class="ad-section">Requests by protocol</h2>
      <div class="ad-proto ad-boxed-block">
        <div class="ad-proto-row" v-for="p in protocolRows" :key="p.key">
          <span class="ad-proto-name">{{ p.label }}</span>
          <div class="ad-proto-track"><div class="ad-proto-fill" :style="{ width: p.pct + '%' }"></div></div>
          <span class="ad-proto-count">{{ p.count }}</span>
        </div>
      </div>
    </template>

    <!-- Connectors -->
    <template v-if="connectors.length">
      <div class="ad-section-head">
        <h2 class="ad-section">Connectors</h2>
        <router-link to="/catalog" class="ad-back">Manage in Catalog →</router-link>
      </div>
      <ul class="ad-list">
        <li v-for="c in connectors" :key="c.id" class="ad-row">
          <span
            class="ad-dot"
            :class="c.metadata?.last_check_ok === true ? 'passing' : (c.metadata?.last_check_ok === false ? 'failing' : '')"
          ></span>
          <div class="ad-row-main">
            <span class="ad-row-name">{{ c.name }} <em class="ad-type">{{ (c.metadata?.protocol || 'mcp').toUpperCase() }}</em></span>
            <span class="ad-row-sub">{{ c.metadata?.endpoint || '—' }}</span>
          </div>
          <span class="ad-row-sub">{{ c.metadata?.last_synced_at ? 'synced ' + when(c.metadata.last_synced_at) : 'never synced' }}</span>
        </li>
      </ul>
    </template>

    <!-- Recent admin actions -->
    <div class="ad-section-head">
      <h2 class="ad-section">Recent admin actions</h2>
      <router-link to="/admin/logs" class="ad-back">Application logs →</router-link>
    </div>
    <p v-if="!audit.length" class="ad-muted">No admin actions recorded yet.</p>
    <ul v-else class="ad-list">
      <li v-for="entry in audit" :key="entry.id" class="ad-row">
        <span class="ad-pill" :class="entry.action.includes('delete') ? 'failing' : 'unknown'">{{ actionLabel(entry.action) }}</span>
        <div class="ad-row-main">
          <span class="ad-row-sub">
            <strong>{{ entry.admin?.name || entry.admin_email || 'Unknown' }}</strong>
            → {{ entry.target_email || '—' }}
          </span>
        </div>
        <span class="ad-row-sub">{{ when(entry.created_at) }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const stats = ref(null);
const audit = ref([]);
const connectors = ref([]);
const monitoring = ref({});
const failing = ref([]);
const loading = ref(true);

const fetchAll = async () => {
  loading.value = true;
  try {
    const [statsRes, actionsRes, connectorsRes, monitoringRes] = await Promise.all([
      axios.get('/api/admin/stats'),
      axios.get('/api/admin/actions'),
      axios.get('/api/admin/catalog', { params: { type: 'connector' } }),
      axios.get('/api/admin/monitoring'),
    ]);
    stats.value = statsRes.data;
    audit.value = (actionsRes.data.data || []).slice(0, 8);
    connectors.value = connectorsRes.data;
    monitoring.value = monitoringRes.data.summary;
    failing.value = monitoringRes.data.monitors.filter((m) => m.last_status === 'failing').slice(0, 5);
  } finally {
    loading.value = false;
  }
};

onMounted(fetchAll);

const protocolRows = computed(() => {
  const b = stats.value?.protocol_breakdown;
  if (!b) return [];
  const max = Math.max(b.rest, b.mcp, b.a2a, 1);
  return [
    { key: 'rest', label: 'REST', count: b.rest },
    { key: 'mcp', label: 'MCP', count: b.mcp },
    { key: 'a2a', label: 'A2A', count: b.a2a },
  ].map((p) => ({ ...p, pct: Math.round((p.count / max) * 100) }));
});

const actionLabel = (action) => ({
  promote_admin: 'Promoted',
  demote_admin: 'Demoted',
  delete_user: 'Deactivated',
  force_delete_user: 'Deleted forever',
  restore_user: 'Restored',
  create_user: 'Created',
  assign_organisation: 'Assigned org',
  unassign_organisation: 'Unassigned org',
}[action] || action);

const when = (iso) => new Date(iso).toLocaleString('en-AU', {
  day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
});
</script>

<style scoped>
@import './admin-shared.css';

.ad-section { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); margin: 26px 0 10px; }
.ad-section-head { display: flex; align-items: baseline; justify-content: space-between; }
.ad-type { font-size: 11px; color: var(--text-secondary); font-style: normal; margin-left: 6px; }
.ad-boxed-block { border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px; }
.ad-proto-row { display: grid; grid-template-columns: 52px 1fr 48px; gap: 10px; align-items: center; margin: 6px 0; }
.ad-proto-name { font-size: 12px; font-weight: 700; color: var(--text-secondary); }
.ad-proto-track { height: 8px; border-radius: 999px; background: rgba(255,255,255,.06); overflow: hidden; }
.ad-proto-fill { height: 100%; border-radius: 999px; background: var(--accent-color); }
.ad-proto-count { font-size: 12px; color: var(--text-secondary); text-align: right; }
</style>
