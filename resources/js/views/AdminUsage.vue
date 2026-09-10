<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Usage</h1>
        <p class="ad-sub">Platform-wide activity — accounts, requests, and protocol mix.</p>
      </div>
      <button class="ad-btn" @click="fetchAll" :disabled="loading">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <div class="ad-stats" v-if="stats">
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.total_users }}</div>
        <div class="ad-stat-label">Users</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.new_users_this_week }}</div>
        <div class="ad-stat-label">New this week</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ stats.total_saved_requests ?? 0 }}</div>
        <div class="ad-stat-label">Saved requests</div>
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

    <template v-if="dailyValues.length > 1">
      <div class="ad-section-head">
        <h2 class="ad-section">Requests · last 14 days</h2>
        <span class="ad-muted">{{ dailyTotal }} total · peak {{ dailyPeak }}/day</span>
      </div>
      <div class="ad-boxed-block">
        <Sparkline :values="dailyValues" aria-label="Requests per day over the last 14 days" />
        <div class="au-axis"><span>{{ firstDay }}</span><span>{{ lastDay }}</span></div>
      </div>
    </template>

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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import Sparkline from '../components/Sparkline.vue';

const days = computed(() => stats.value?.requests_by_day || []);
const dailyValues = computed(() => days.value.map((d) => d.count));
const dailyTotal = computed(() => dailyValues.value.reduce((a, b) => a + b, 0));
const dailyPeak = computed(() => (dailyValues.value.length ? Math.max(...dailyValues.value) : 0));
const fmtDay = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('en-AU', { day: '2-digit', month: 'short' });
const firstDay = computed(() => (days.value.length ? fmtDay(days.value[0].date) : ''));
const lastDay = computed(() => (days.value.length ? fmtDay(days.value[days.value.length - 1].date) : ''));

const stats = ref(null);
const connectors = ref([]);
const loading = ref(true);

const fetchAll = async () => {
  loading.value = true;
  try {
    const [statsRes, connectorsRes] = await Promise.all([
      axios.get('/api/admin/stats'),
      axios.get('/api/admin/catalog', { params: { type: 'connector' } }),
    ]);
    stats.value = statsRes.data;
    connectors.value = connectorsRes.data;
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
.au-axis { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-secondary); margin-top: 4px; }
</style>
