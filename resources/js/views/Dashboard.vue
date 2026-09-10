<template>
  <div class="hub">
    <header class="hub-head">
      <h1 class="hub-title">Welcome back, {{ firstName }}</h1>
      <p class="hub-sub">Test, inspect, and harden MCP servers, agents, and APIs — with AI in the loop.</p>
    </header>

    <!-- At-a-glance overview -->
    <router-link v-if="failingCount" to="/monitors" class="ov-alert">
      <Icon name="activity" :size="16" />
      {{ failingCount }} monitor{{ failingCount === 1 ? ' is' : 's are' }} failing — investigate →
    </router-link>

    <section v-if="stats" class="ov">
      <div class="ov-stats">
        <div class="ov-stat"><div class="ov-v">{{ stats.requests }}</div><div class="ov-l">Requests</div></div>
        <div class="ov-stat"><div class="ov-v">{{ stats.saved }}</div><div class="ov-l">Saved</div></div>
        <router-link to="/monitors" class="ov-stat ov-link">
          <div class="ov-v" :class="{ bad: failingCount }">{{ passingCount }}/{{ monitorTotal }}</div>
          <div class="ov-l">Monitors passing</div>
        </router-link>
        <div class="ov-stat"><div class="ov-v">{{ stats.active_days }}</div><div class="ov-l">Active days</div></div>
      </div>
      <div v-if="trendValues.length > 1" class="ov-trend">
        <div class="ov-trend-l">Your requests · last {{ trendValues.length }} days</div>
        <Sparkline :values="trendValues" aria-label="Your requests per day" />
      </div>
    </section>

    <!-- Feature bento -->
    <section class="bento">
      <router-link
        v-for="(card, i) in cards"
        :key="card.to"
        :to="card.to"
        class="card"
        :class="{ 'card-hero': i === 0 }"
      >
        <span class="card-icon" :style="{ color: card.color }">
          <Icon :name="card.icon" :size="i === 0 ? 26 : 22" />
        </span>
        <div class="card-body">
          <h3 class="card-title">{{ card.title }}</h3>
          <p class="card-desc">{{ card.desc }}</p>
        </div>
        <span class="card-go"><Icon name="arrowRight" :size="18" /></span>
      </router-link>
    </section>

    <!-- Recent reports -->
    <section class="recent">
      <div class="recent-head">
        <h2 class="recent-title">Recent reports</h2>
        <router-link to="/reports" class="recent-all">View all <Icon name="chevronRight" :size="14" /></router-link>
      </div>

      <p v-if="loadingReports" class="muted">Loading…</p>
      <div v-else-if="!reports.length" class="empty">
        <Icon name="report" :size="26" />
        <p>No reports yet. Run a conformance grade, security scan, or agent session on a connector to see it here.</p>
        <router-link to="/ai-lab" class="empty-btn">Open AI Lab</router-link>
      </div>

      <ul v-else class="rlist">
        <li v-for="r in reports" :key="r.id">
          <router-link to="/reports" class="rrow">
            <span class="rtype" :class="'t-' + r.type">{{ typeName(r.type) }}</span>
            <span class="rname">{{ r.connector_name || r.connector_slug || '—' }}</span>
            <span class="rsummary">{{ r.summary }}</span>
            <span class="rago">{{ ago(r.created_at) }}</span>
          </router-link>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../store/auth';
import Icon from '../components/Icon.vue';
import Sparkline from '../components/Sparkline.vue';

const authStore = useAuthStore();
const firstName = computed(() => (authStore.user?.name || 'there').split(' ')[0]);

const reports = ref([]);
const loadingReports = ref(true);
const stats = ref(null);
const monitors = ref([]);

const trendValues = computed(() => (stats.value?.requests_by_day || []).map((d) => d.count));
const monitorTotal = computed(() => monitors.value.length);
const failingCount = computed(() => monitors.value.filter((m) => m.last_status === 'failing').length);
const passingCount = computed(() => monitors.value.filter((m) => m.last_status === 'passing').length);

const cards = computed(() => {
  const base = [
    { to: '/collections', title: 'Collections', icon: 'layers', color: '#d29922',
      desc: 'Your saved requests and the collections that run them in order against an environment.' },
    { to: '/tester', title: 'Tester', icon: 'send', color: '#58a6ff',
      desc: 'Send REST, MCP, A2A, gRPC, MQTT, and AMQP requests, with environments for reusable {{variables}} across staging and production.' },
    { to: '/ai-lab', title: 'AI Lab', icon: 'sparkles', color: '#a371f7',
      desc: 'Author requests from plain English, explain responses, generate assertions, and scan MCP tools for prompt-injection.' },
    { to: '/monitors', title: 'Monitors', icon: 'activity', color: '#f778ba',
      desc: 'Run a collection on a schedule, track uptime and latency, and get alerted when it starts failing.' },
    { to: '/reports', title: 'Reports', icon: 'report', color: '#58a6ff',
      desc: 'Saved conformance grades, security scans, and agent runs — shareable and diffable over time.' },
    { to: '/chat', title: 'Spi', icon: 'chat', color: '#3fb950',
      desc: 'Ask Spi, the built-in assistant, for help building requests, understanding protocols, and debugging failures.' },
  ];
  return base;
});

onMounted(async () => {
  try {
    const res = await axios.get('/api/reports');
    reports.value = (res.data.reports || []).slice(0, 5);
  } catch {
    reports.value = [];
  } finally {
    loadingReports.value = false;
  }

  // Overview data — best-effort, never blocks the rest of the page.
  try { stats.value = (await axios.get('/api/user/stats')).data; } catch { /* ignore */ }
  try { monitors.value = (await axios.get('/api/monitors')).data || []; } catch { /* ignore */ }
});

const typeName = (t) => ({ conformance: 'Conformance', security: 'Security', agent_loop: 'Agent run', collection_run: 'Collection run', mcp_drift: 'MCP drift', parity: 'Env parity', exploration: 'Exploration', fuzz: 'Fuzz', replay: 'Replay', dataset_run: 'Dataset run', perf: 'Performance' }[t] || t);
const ago = (iso) => {
  if (!iso) return '';
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};
</script>

<style scoped>
.hub { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
.hub-head { margin-bottom: 24px; }
.hub-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.hub-sub { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; }

/* Bento grid */
/* Overview */
.ov-alert {
  display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding: 10px 14px;
  border-radius: 10px; border: 1px solid rgba(248,81,73,.4); background: rgba(248,81,73,.1);
  color: var(--error-color); font-size: 13px; font-weight: 600; text-decoration: none;
}
.ov-alert:hover { background: rgba(248,81,73,.16); }
.ov { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 28px; }
.ov-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.ov-stat { padding: 14px 16px; border: 1px solid var(--border-color); border-radius: 12px; background: var(--panel-bg); text-decoration: none; }
.ov-link:hover { border-color: var(--accent-color); }
.ov-v { font-size: 1.7rem; font-weight: 800; color: var(--text-primary); font-variant-numeric: tabular-nums; line-height: 1.1; }
.ov-v.bad { color: var(--error-color); }
.ov-l { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary); margin-top: 4px; }
.ov-trend { padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 12px; background: var(--panel-bg); display: flex; flex-direction: column; justify-content: center; }
.ov-trend-l { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary); margin-bottom: 6px; }
@media (max-width: 760px) { .ov { grid-template-columns: 1fr; } .ov-stats { grid-template-columns: repeat(2, 1fr); } }

.bento { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; margin-bottom: 40px; }
.card {
  position: relative; display: flex; flex-direction: column; gap: 14px;
  padding: 18px; border-radius: 14px; text-decoration: none;
  background: var(--bg-secondary); border: 1px solid var(--border-color);
  transition: border-color 0.18s, background 0.18s, transform 0.18s;
  min-height: 150px;
}
.card:hover { border-color: var(--accent-color); background: var(--bg-elevated); }
.card-hero { grid-column: span 2; }
@media (max-width: 560px) { .card-hero { grid-column: span 1; } }
.card-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 44px; height: 44px; border-radius: 11px;
  background: var(--bg-color); border: 1px solid var(--border-color);
}
.card-body { flex: 1; }
.card-title { font-size: 16px; font-weight: 700; color: var(--text-primary); margin: 0 0 5px; }
.card-desc { font-size: 13.5px; line-height: 1.55; color: var(--text-secondary); margin: 0; }
.card-go { position: absolute; top: 18px; right: 18px; color: var(--text-secondary); opacity: 0; transform: translateX(-4px); transition: opacity 0.18s, transform 0.18s; }
.card:hover .card-go { opacity: 1; transform: translateX(0); color: var(--accent-color); }

/* Recent reports */
.recent-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.recent-title { font-size: 16px; font-weight: 700; color: var(--text-primary); margin: 0; }
.recent-all { display: inline-flex; align-items: center; gap: 3px; color: var(--accent-color); text-decoration: none; font-size: 13px; font-weight: 500; }
.recent-all:hover { text-decoration: underline; }
.muted { color: var(--text-secondary); }

.empty {
  display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center;
  padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 14px; color: var(--text-secondary);
}
.empty p { max-width: 440px; margin: 0; font-size: 14px; line-height: 1.6; }
.empty-btn { padding: 8px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; }
.empty-btn:hover { background: var(--accent-hover); }

.rlist { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.rlist li + li { border-top: 1px solid var(--border-color); }
.rrow { display: grid; grid-template-columns: 108px 1fr 1.4fr auto; align-items: center; gap: 12px; padding: 12px 16px; text-decoration: none; transition: background 0.18s; }
.rrow:hover { background: var(--bg-secondary); }
.rtype { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 5px; text-align: center; }
.t-conformance { background: rgba(88,166,255,.16); color: #58a6ff; }
.t-security { background: rgba(248,81,73,.14); color: #f85149; }
.t-agent_loop { background: rgba(63,185,80,.16); color: #3fb950; }
.t-collection_run { background: rgba(247,120,186,.16); color: #f778ba; }
.rname { color: var(--text-primary); font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rsummary { color: var(--text-secondary); font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rago { color: var(--text-secondary); font-size: 12px; white-space: nowrap; }
@media (max-width: 640px) {
  .rrow { grid-template-columns: 90px 1fr auto; }
  .rsummary { display: none; }
}
</style>
