<template>
  <div class="sp">
    <div class="sp-container">
      <p v-if="loading" class="sp-muted">Loading…</p>

      <div v-else-if="notFound" class="sp-gone">
        <h1>Status page not found</h1>
        <p>This link may have been disabled or revoked.</p>
      </div>

      <template v-else-if="page">
        <header class="sp-head">
          <div class="sp-overall" :class="page.overall">
            {{ overallLabel }}
          </div>
          <h1 class="sp-title">{{ page.name }}</h1>
          <p v-if="page.description" class="sp-desc">{{ page.description }}</p>
        </header>

        <section v-for="m in page.monitors" :key="m.name" class="sp-monitor">
          <div class="sp-mon-head">
            <span class="sp-dot" :class="m.status"></span>
            <h2 class="sp-mon-name">{{ m.name }}</h2>
            <span v-if="m.kind === 'mcp_contract'" class="sp-kind">MCP contract</span>
            <span class="sp-mon-meta">
              <template v-if="m.uptime !== null">{{ m.uptime }}% </template>
              <template v-if="m.last_run_at">· checked {{ ago(m.last_run_at) }}</template>
            </span>
          </div>

          <div class="sp-strip" v-if="m.history.length">
            <span
              v-for="(h, i) in m.history"
              :key="i"
              class="sp-tick"
              :class="h.ok ? 'ok' : 'bad'"
              :title="`${h.ok ? 'OK' : 'Failed'} — ${h.time_ms} ms — ${when(h.at)}`"
            ></span>
          </div>
          <p v-else class="sp-muted sp-none">No checks recorded yet.</p>
        </section>

        <footer class="sp-foot">
          Updated {{ ago(page.generated_at) }} ·
          <a href="https://apispi.com" class="sp-link">Monitored with Spi</a>
        </footer>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';

const route = useRoute();
const page = ref(null);
const loading = ref(true);
const notFound = ref(false);
let timer = null;

const fetchPage = async () => {
  try {
    page.value = (await axios.get(`/api/status/${route.params.token}`)).data;
    notFound.value = false;
  } catch {
    notFound.value = true;
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchPage();
  // A status page is left open on a wall; refresh itself.
  timer = setInterval(fetchPage, 60000);
});
onUnmounted(() => clearInterval(timer));

const overallLabel = computed(() => ({
  passing: 'All systems operational',
  failing: 'Some checks are failing',
  unknown: 'Awaiting first checks',
}[page.value?.overall] || ''));

const ago = (iso) => {
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};
const when = (iso) => new Date(iso).toLocaleString('en-AU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
</script>

<style scoped>
.sp { min-height: 100%; background: var(--bg-color); color: var(--text-primary); overflow-y: auto; }
.sp-container { max-width: 720px; margin: 0 auto; padding: 48px 24px 64px; }
.sp-muted { color: var(--text-secondary); }
.sp-gone { text-align: center; padding: 60px 0; color: var(--text-secondary); }
.sp-gone h1 { font-size: 22px; color: var(--text-primary); margin: 0 0 8px; }

.sp-head { margin-bottom: 30px; }
.sp-overall { display: inline-block; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 999px; margin-bottom: 14px; }
.sp-overall.passing { background: rgba(63,185,80,.16); color: #3fb950; }
.sp-overall.failing { background: rgba(248,81,73,.14); color: #f85149; }
.sp-overall.unknown { background: rgba(255,255,255,.07); color: var(--text-secondary); }
.sp-title { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
.sp-desc { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; }

.sp-monitor { border: 1px solid var(--border-color); border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; }
.sp-mon-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.sp-dot { width: 10px; height: 10px; border-radius: 999px; background: var(--text-secondary); flex-shrink: 0; }
.sp-dot.passing { background: #3fb950; }
.sp-dot.failing { background: #f85149; }
.sp-mon-name { font-size: 15px; font-weight: 600; margin: 0; }
.sp-kind { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 2px 8px; border-radius: 999px; background: rgba(163,113,247,.16); color: #a371f7; }
.sp-mon-meta { margin-left: auto; font-size: 12.5px; color: var(--text-secondary); }

.sp-strip { display: flex; gap: 2px; height: 30px; }
.sp-tick { flex: 1; min-width: 3px; border-radius: 2px; background: #3fb950; }
.sp-tick.bad { background: #f85149; }
.sp-none { font-size: 13px; margin: 0; }

.sp-foot { margin-top: 26px; font-size: 12.5px; color: var(--text-secondary); text-align: center; }
.sp-link { color: var(--accent-color); text-decoration: none; }
.sp-link:hover { text-decoration: underline; }
</style>
