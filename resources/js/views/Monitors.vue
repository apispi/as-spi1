<template>
  <div class="mon">
    <header class="mon-head">
      <div>
        <h1 class="mon-title">Monitors</h1>
        <p class="mon-sub">Run a collection on a schedule and get alerted when it starts failing.</p>
      </div>
      <button class="mon-primary" @click="startNew" :disabled="!collectionsStore.collections.length">
        New monitor
      </button>
    </header>

    <p v-if="!collectionsStore.collections.length && collectionsStore.loaded" class="mon-empty">
      Monitors run a collection, so create one first in
      <router-link to="/tester" class="mon-link">Tester → Collections</router-link>.
    </p>

    <p v-else-if="store.isLoading" class="mon-muted">Loading…</p>

    <div v-else-if="!store.monitors.length" class="mon-empty">
      <Icon name="activity" :size="26" />
      <p>No monitors yet. Create one to watch a collection on a schedule.</p>
    </div>

    <ul v-else class="mon-list">
      <li v-for="m in store.monitors" :key="m.id" class="mon-row">
        <span class="mon-dot" :class="m.last_status"></span>

        <div class="mon-main">
          <div class="mon-row-top">
            <span class="mon-name">{{ m.name }}</span>
            <span class="mon-pill" :class="m.last_status">{{ statusLabel(m) }}</span>
            <span v-if="!m.is_enabled" class="mon-pill paused">paused</span>
          </div>
          <div class="mon-meta">
            {{ m.collection?.name || '—' }}
            <template v-if="m.environment"> · {{ m.environment.name }}</template>
            · every {{ intervalLabel(m.interval_minutes) }}
            <template v-if="m.last_run_at"> · last run {{ ago(m.last_run_at) }}</template>
            <template v-if="m.uptime !== null"> · {{ m.uptime }}% uptime</template>
            <template v-if="m.consecutive_failures > 1"> · {{ m.consecutive_failures }} failures in a row</template>
          </div>
        </div>

        <div class="mon-actions">
          <button class="mon-btn" @click="runNow(m)" :disabled="busy === m.id">
            {{ busy === m.id ? 'Running…' : 'Run now' }}
          </button>
          <button class="mon-btn" @click="openHistory(m)">History</button>
          <button class="mon-btn" @click="edit(m)">Edit</button>
        </div>
      </li>
    </ul>

    <!-- Editor -->
    <div v-if="editing" class="mon-scrim" @click.self="editing = null">
      <div class="mon-modal">
        <header class="mon-modal-head">
          <h2>{{ editing.id ? 'Edit monitor' : 'New monitor' }}</h2>
          <button class="mon-x" @click="editing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>

        <div class="mon-form">
          <label class="mon-label">Name</label>
          <input v-model="editing.name" class="input-field" placeholder="Production smoke tests" maxlength="80" />

          <label class="mon-label">Collection</label>
          <select v-model="editing.collection_id" class="input-field">
            <option v-for="c in collectionsStore.collections" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>

          <label class="mon-label">Environment</label>
          <select v-model="editing.environment_id" class="input-field">
            <option :value="null">No environment</option>
            <option v-for="e in envStore.environments" :key="e.id" :value="e.id">{{ e.name }}</option>
          </select>

          <label class="mon-label">Run every</label>
          <select v-model.number="editing.interval_minutes" class="input-field">
            <option v-for="i in INTERVALS" :key="i" :value="i">{{ intervalLabel(i) }}</option>
          </select>

          <label class="mon-check">
            <input type="checkbox" v-model="editing.is_enabled" />
            <span>Enabled</span>
          </label>
          <label class="mon-check">
            <input type="checkbox" v-model="editing.alerts_enabled" />
            <span>Email me when the status changes</span>
          </label>

          <p class="mon-note">
            Alerts fire when a monitor changes between passing and failing — not
            on every failing run.
          </p>

          <p v-if="error" class="mon-error">{{ error }}</p>

          <footer class="mon-modal-actions">
            <button class="mon-primary" @click="save" :disabled="saving || !editing.name.trim() || !editing.collection_id">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="mon-danger" @click="remove" :disabled="saving">Delete</button>
            <button class="mon-btn" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>
        </div>
      </div>
    </div>

    <!-- History -->
    <div v-if="history" class="mon-scrim" @click.self="history = null">
      <div class="mon-modal">
        <header class="mon-modal-head">
          <h2>{{ history.name }}</h2>
          <span class="mon-pill" :class="history.last_status">{{ statusLabel(history) }}</span>
          <button class="mon-x" @click="history = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>

        <div class="mon-history">
          <p v-if="!history.results.length" class="mon-muted">No runs recorded yet.</p>

          <template v-else>
            <!-- Uptime strip: one bar per run, oldest first -->
            <div class="mon-strip">
              <span
                v-for="r in history.results"
                :key="r.id"
                class="mon-tick"
                :class="r.passed ? 'pass' : 'fail'"
                :title="`${r.passed ? 'Passed' : 'Failed'} — ${r.time_ms} ms — ${when(r.created_at)}`"
              ></span>
            </div>
            <p class="mon-strip-meta">
              {{ history.uptime }}% uptime over {{ history.results.length }} runs
              · median {{ medianLatency }} ms
            </p>

            <ul class="mon-runs">
              <li v-for="r in [...history.results].reverse()" :key="r.id" :class="r.passed ? 'pass' : 'fail'">
                <span class="mon-run-mark">{{ r.passed ? '✓' : '✕' }}</span>
                <span class="mon-run-when">{{ when(r.created_at) }}</span>
                <span class="mon-run-steps">{{ r.passed_count }}/{{ r.total }}</span>
                <span class="mon-run-time">{{ r.time_ms }} ms</span>
                <span class="mon-run-summary">{{ r.summary }}</span>
              </li>
            </ul>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useMonitorsStore } from '../store/monitors';
import { useCollectionsStore } from '../store/collections';
import { useEnvironmentsStore } from '../store/environments';
import Icon from '../components/Icon.vue';

const store = useMonitorsStore();
const collectionsStore = useCollectionsStore();
const envStore = useEnvironmentsStore();

// Mirrors Monitor::INTERVALS.
const INTERVALS = [5, 15, 30, 60, 180, 360, 720, 1440];

const editing = ref(null);
const history = ref(null);
const saving = ref(false);
const busy = ref(null);
const error = ref('');

onMounted(() => {
  store.fetch();
  collectionsStore.fetch();
  envStore.fetch();
});

const intervalLabel = (m) => {
  if (m < 60) return `${m} min`;
  if (m === 60) return 'hour';
  if (m < 1440) return `${m / 60} hours`;
  return 'day';
};

const statusLabel = (m) => ({ passing: 'Passing', failing: 'Failing', unknown: 'Not run yet' }[m.last_status] || m.last_status);

const ago = (iso) => {
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};

const when = (iso) => new Date(iso).toLocaleString('en-AU', {
  day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
});

const medianLatency = computed(() => {
  const times = (history.value?.results || []).map((r) => r.time_ms).sort((a, b) => a - b);
  if (!times.length) return 0;
  const mid = Math.floor(times.length / 2);
  return times.length % 2 ? times[mid] : Math.round((times[mid - 1] + times[mid]) / 2);
});

const startNew = () => {
  error.value = '';
  editing.value = {
    id: null,
    name: '',
    collection_id: collectionsStore.collections[0]?.id || null,
    environment_id: envStore.environments.find((e) => e.is_default)?.id || null,
    interval_minutes: 60,
    is_enabled: true,
    alerts_enabled: true,
  };
};

const edit = (m) => {
  error.value = '';
  editing.value = {
    id: m.id,
    name: m.name,
    collection_id: m.collection?.id || null,
    environment_id: m.environment?.id || null,
    interval_minutes: m.interval_minutes,
    is_enabled: m.is_enabled,
    alerts_enabled: m.alerts_enabled,
  };
};

const save = async () => {
  saving.value = true;
  error.value = '';
  try {
    await store.save({
      name: editing.value.name.trim(),
      collection_id: editing.value.collection_id,
      environment_id: editing.value.environment_id,
      interval_minutes: editing.value.interval_minutes,
      is_enabled: editing.value.is_enabled,
      alerts_enabled: editing.value.alerts_enabled,
    }, editing.value.id);
    editing.value = null;
  } catch (e) {
    const data = e.response?.data;
    error.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save monitor.';
  } finally {
    saving.value = false;
  }
};

const remove = async () => {
  if (!confirm(`Delete the "${editing.value.name}" monitor?`)) return;
  saving.value = true;
  try {
    await store.remove(editing.value.id);
    editing.value = null;
  } catch {
    error.value = 'Failed to delete monitor.';
  } finally {
    saving.value = false;
  }
};

const runNow = async (m) => {
  busy.value = m.id;
  try {
    await store.run(m.id);
  } catch {
    // A failing run is still a result; only transport errors land here.
  } finally {
    busy.value = null;
  }
};

const openHistory = async (m) => {
  history.value = await store.show(m.id);
};
</script>

<style scoped>
.mon { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
.mon-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
.mon-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.mon-sub { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; }
.mon-muted { color: var(--text-secondary); }
.mon-link { color: var(--accent-color); text-decoration: none; }

.mon-primary { padding: 9px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.mon-primary:hover:not(:disabled) { background: var(--accent-hover, var(--accent-color)); }
.mon-primary:disabled { opacity: .45; cursor: not-allowed; }
.mon-btn { padding: 6px 12px; border-radius: 7px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.mon-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.mon-btn:disabled { opacity: .45; cursor: not-allowed; }
.mon-danger { margin-left: auto; padding: 8px 14px; border-radius: 8px; background: none; border: 1px solid var(--border-color); color: #f85149; font-size: 13px; cursor: pointer; }

.mon-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 14px; color: var(--text-secondary); }
.mon-empty p { max-width: 440px; margin: 0; font-size: 14px; line-height: 1.6; }

.mon-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.mon-row { display: flex; align-items: center; gap: 12px; padding: 14px 16px; }
.mon-row + .mon-row { border-top: 1px solid var(--border-color); }
.mon-dot { width: 9px; height: 9px; border-radius: 999px; flex-shrink: 0; background: var(--text-secondary); }
.mon-dot.passing { background: #3fb950; }
.mon-dot.failing { background: #f85149; }
.mon-main { flex: 1; min-width: 0; }
.mon-row-top { display: flex; align-items: center; gap: 8px; }
.mon-name { font-size: 14px; font-weight: 600; color: var(--text-primary); }
.mon-pill { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; }
.mon-pill.passing { color: #3fb950; background: rgba(63,185,80,.16); }
.mon-pill.failing { color: #f85149; background: rgba(248,81,73,.14); }
.mon-pill.unknown { color: var(--text-secondary); background: rgba(255,255,255,.07); }
.mon-pill.paused { color: #d29922; background: rgba(210,153,34,.14); }
.mon-meta { font-size: 12px; color: var(--text-secondary); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mon-actions { display: flex; gap: 6px; flex-shrink: 0; }

.mon-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.mon-modal { width: min(720px, 100%); max-height: 84vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.mon-modal-head { display: flex; align-items: center; gap: 10px; padding: 16px 20px; border-bottom: 1px solid var(--border-color); }
.mon-modal-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.mon-x { margin-left: auto; background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.mon-x:hover { color: var(--text-primary); }

.mon-form { padding: 18px 20px; overflow-y: auto; }
.mon-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
.mon-label:first-child { margin-top: 0; }
.mon-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 12px; cursor: pointer; }
.mon-note { font-size: 12.5px; line-height: 1.6; color: var(--text-secondary); background: var(--panel-bg); border-left: 3px solid var(--accent-color); padding: 10px 14px; border-radius: 0 8px 8px 0; margin: 16px 0 0; }
.mon-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.mon-modal-actions { display: flex; gap: 8px; margin-top: 18px; }

.mon-history { padding: 18px 20px; overflow-y: auto; }
.mon-strip { display: flex; gap: 2px; align-items: flex-end; height: 32px; }
.mon-tick { flex: 1; min-width: 3px; height: 100%; border-radius: 2px; background: #3fb950; }
.mon-tick.fail { background: #f85149; }
.mon-strip-meta { font-size: 12px; color: var(--text-secondary); margin: 8px 0 16px; }

.mon-runs { list-style: none; margin: 0; padding: 0; }
.mon-runs li { display: grid; grid-template-columns: 16px 130px 52px 70px 1fr; gap: 8px; align-items: baseline; font-size: 12.5px; padding: 6px 0; border-top: 1px solid var(--border-color); }
.mon-run-mark { font-weight: 700; }
.mon-runs .pass .mon-run-mark { color: #3fb950; }
.mon-runs .fail .mon-run-mark { color: #f85149; }
.mon-run-when, .mon-run-steps, .mon-run-time { color: var(--text-secondary); }
.mon-run-summary { color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

@media (max-width: 720px) {
  .mon-row { flex-wrap: wrap; }
  .mon-actions { width: 100%; }
  .mon-runs li { grid-template-columns: 16px 1fr 52px; }
  .mon-run-time, .mon-run-summary { display: none; }
}
</style>
