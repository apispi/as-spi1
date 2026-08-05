<template>
  <main class="sr">
    <div class="sr-inner">
      <router-link to="/" class="sr-brand">apispi.com</router-link>

      <p v-if="loading" class="sr-muted">Loading report…</p>

      <div v-else-if="notFound" class="sr-empty">
        <div class="sr-empty-icon">🔗</div>
        <h1>Report not found</h1>
        <p>This share link is invalid or has been revoked.</p>
      </div>

      <template v-else>
        <header class="sr-head">
          <span class="sr-type" :class="'t-' + report.type">{{ typeName(report.type) }}</span>
          <h1 class="sr-title">{{ report.connector_name || report.connector_slug || 'Connector' }}</h1>
          <p class="sr-meta">{{ report.summary }} · {{ formatted(report.created_at) }}</p>
        </header>
        <div class="sr-card">
          <ReportView :type="report.type" :data="report.data" />
        </div>
        <p class="sr-foot">Shared read-only via apispi.com · a multi-protocol API &amp; MCP testing tool.</p>
      </template>
    </div>
  </main>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import ReportView from '../components/ReportView.vue';

const route = useRoute();
const loading = ref(true);
const notFound = ref(false);
const report = ref(null);

onMounted(async () => {
  try {
    const res = await axios.get(`/api/reports/shared/${route.params.token}`);
    report.value = res.data;
  } catch (e) {
    notFound.value = true;
  } finally {
    loading.value = false;
  }
});

const typeName = (t) => ({ conformance: 'Conformance', security: 'Security', agent_loop: 'Agent run' }[t] || t);
const formatted = (iso) => {
  if (!iso) return '';
  try { return new Date(iso).toLocaleString(); } catch { return iso; }
};
</script>

<style scoped>
.sr { min-height: 100vh; padding: 32px 16px 60px; background: var(--bg-primary, #0d1117); }
.sr-inner { max-width: 760px; margin: 0 auto; }
.sr-brand { display: inline-block; font-weight: 700; color: var(--accent-color); text-decoration: none; margin-bottom: 24px; }
.sr-muted { color: var(--text-secondary); }
.sr-empty { text-align: center; padding: 60px 20px; color: var(--text-secondary); }
.sr-empty-icon { font-size: 34px; }
.sr-empty h1 { color: var(--text-primary); font-size: 20px; margin: 10px 0 6px; }
.sr-head { margin-bottom: 16px; }
.sr-type { font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 5px; }
.t-conformance { background: rgba(88,166,255,.16); color: #58a6ff; }
.t-security { background: rgba(248,81,73,.14); color: #f85149; }
.t-agent_loop { background: rgba(63,185,80,.16); color: #3fb950; }
.sr-title { font-size: 24px; font-weight: 700; color: var(--text-primary); margin: 10px 0 4px; }
.sr-meta { color: var(--text-secondary); margin: 0; }
.sr-card { border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; }
.sr-foot { color: var(--text-secondary); font-size: 12px; margin-top: 20px; text-align: center; }
</style>
