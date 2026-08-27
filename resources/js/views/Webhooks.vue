<template>
  <div class="wh">
    <header class="wh-head">
      <div>
        <h1 class="wh-title">Webhooks</h1>
        <p class="wh-sub">
          Capture anything sent to your URL, and get alerted when an expected
          sender goes quiet — a dead-man's switch for crons, queues, and callbacks.
        </p>
      </div>
      <button class="wh-primary" @click="startNew">New endpoint</button>
    </header>

    <p v-if="loading" class="wh-muted">Loading…</p>

    <div v-else-if="!endpoints.length" class="wh-empty">
      <Icon name="plug" :size="26" />
      <p>
        Create an endpoint to get a capture URL. Point a webhook, a cron
        heartbeat, or an agent's callback at it and watch requests arrive here.
      </p>
      <button class="wh-primary" @click="startNew">Create one</button>
    </div>

    <ul v-else class="wh-list">
      <li v-for="e in endpoints" :key="e.id" class="wh-row">
        <span class="wh-dot" :class="e.last_status"></span>
        <div class="wh-main">
          <div>
            <span class="wh-name">{{ e.name }}</span>
            <span class="wh-pill" :class="e.last_status">{{ statusLabel(e) }}</span>
          </div>
          <div class="wh-meta">
            <code class="wh-url" :title="e.url">{{ e.url }}</code>
            <button class="wh-copy" @click="copy(e)">{{ copied === e.id ? '✓' : 'Copy' }}</button>
          </div>
          <div class="wh-meta wh-sub2">
            {{ e.captures_count }} capture{{ e.captures_count === 1 ? '' : 's' }}
            <template v-if="e.expect_interval_minutes"> · expects a hit every {{ e.expect_interval_minutes }} min</template>
            <template v-if="e.last_received_at"> · last {{ ago(e.last_received_at) }}</template>
          </div>
        </div>
        <div class="wh-actions">
          <button class="wh-btn" @click="open(e)">Captures</button>
          <button class="wh-btn" @click="edit(e)">Edit</button>
        </div>
      </li>
    </ul>

    <!-- Captures -->
    <div v-if="viewing" class="wh-scrim" @click.self="viewing = null">
      <div class="wh-modal wide">
        <header class="wh-modal-head">
          <h2>{{ viewing.endpoint.name }}</h2>
          <button class="wh-btn" @click="refreshCaptures" :disabled="refreshing">{{ refreshing ? '…' : 'Refresh' }}</button>
          <button class="wh-x" @click="viewing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="wh-body">
          <p v-if="!viewing.captures.length" class="wh-muted">
            Nothing captured yet. Send anything to
            <code class="wh-url">{{ viewing.endpoint.url }}</code> and refresh.
          </p>
          <div v-for="c in viewing.captures" :key="c.id" class="wh-cap">
            <button class="wh-cap-head" @click="expanded = expanded === c.id ? null : c.id">
              <span class="wh-pill unknown">{{ c.method }}</span>
              <span class="wh-cap-when">{{ when(c.created_at) }}</span>
              <span class="wh-cap-ip">{{ c.ip }}</span>
              <span class="wh-cap-size">{{ (c.body || '').length }} B</span>
            </button>
            <div v-if="expanded === c.id" class="wh-cap-detail">
              <template v-if="c.query && Object.keys(c.query).length">
                <h4>Query</h4>
                <pre>{{ pretty(c.query) }}</pre>
              </template>
              <h4>Headers</h4>
              <pre>{{ pretty(c.headers) }}</pre>
              <h4>Body</h4>
              <pre>{{ prettyBody(c.body) }}</pre>
              <button class="wh-btn" @click="openInTester(c)">Open in tester</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor -->
    <div v-if="editing" class="wh-scrim" @click.self="editing = null">
      <div class="wh-modal">
        <header class="wh-modal-head">
          <h2>{{ editing.id ? 'Edit endpoint' : 'New endpoint' }}</h2>
          <button class="wh-x" @click="editing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="wh-form">
          <label class="wh-label">Name</label>
          <input v-model="editing.name" class="input-field" placeholder="Payments callback" maxlength="60" />

          <label class="wh-label">Expect a request every</label>
          <select v-model="editing.expect_interval_minutes" class="input-field">
            <option :value="null">No expectation — just capture</option>
            <option v-for="i in INTERVALS" :key="i" :value="i">{{ intervalLabel(i) }}</option>
          </select>
          <p class="wh-hint">
            With an expectation set, silence beyond the interval marks the
            endpoint silent and alerts you — once, and again on recovery.
          </p>

          <label class="wh-check">
            <input type="checkbox" v-model="editing.alerts_enabled" />
            <span>Alert me on silence and recovery</span>
          </label>

          <p v-if="error" class="wh-error">{{ error }}</p>

          <footer class="wh-actions-row">
            <button class="wh-primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="wh-danger" @click="remove" :disabled="saving">Delete</button>
            <button class="wh-btn" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import { useRequestsStore } from '../store/requests';

const router = useRouter();
const requestsStore = useRequestsStore();

const INTERVALS = [5, 15, 30, 60, 180, 360, 720, 1440];

const endpoints = ref([]);
const loading = ref(true);
const editing = ref(null);
const viewing = ref(null);
const expanded = ref(null);
const refreshing = ref(false);
const saving = ref(false);
const error = ref('');
const copied = ref(null);

const fetchAll = async () => {
  loading.value = true;
  try {
    endpoints.value = (await axios.get('/api/webhook-endpoints')).data;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchAll);

const statusLabel = (e) => ({
  receiving: 'Receiving',
  silent: 'Silent',
  unknown: e.expect_interval_minutes ? 'Waiting for first hit' : 'Capture only',
}[e.last_status] || e.last_status);

const intervalLabel = (m) => {
  if (m < 60) return `${m} minutes`;
  if (m === 60) return 'hour';
  if (m < 1440) return `${m / 60} hours`;
  return 'day';
};

const copy = async (e) => {
  try {
    await navigator.clipboard.writeText(e.url);
    copied.value = e.id;
    setTimeout(() => { copied.value = null; }, 1500);
  } catch { /* clipboard unavailable */ }
};

const startNew = () => {
  error.value = '';
  editing.value = { id: null, name: '', expect_interval_minutes: null, alerts_enabled: true };
};

const edit = (e) => {
  error.value = '';
  editing.value = {
    id: e.id,
    name: e.name,
    expect_interval_minutes: e.expect_interval_minutes,
    alerts_enabled: e.alerts_enabled,
  };
};

const save = async () => {
  saving.value = true;
  error.value = '';
  const payload = {
    name: editing.value.name.trim(),
    expect_interval_minutes: editing.value.expect_interval_minutes,
    alerts_enabled: editing.value.alerts_enabled,
  };
  try {
    if (editing.value.id) {
      await axios.put(`/api/webhook-endpoints/${editing.value.id}`, payload);
    } else {
      await axios.post('/api/webhook-endpoints', payload);
    }
    editing.value = null;
    await fetchAll();
  } catch (e) {
    const data = e.response?.data;
    error.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save.';
  } finally {
    saving.value = false;
  }
};

const remove = async () => {
  if (!confirm(`Delete "${editing.value.name}" and its captures? Senders will start getting 404s.`)) return;
  saving.value = true;
  try {
    await axios.delete(`/api/webhook-endpoints/${editing.value.id}`);
    editing.value = null;
    await fetchAll();
  } catch {
    error.value = 'Failed to delete.';
  } finally {
    saving.value = false;
  }
};

const open = async (e) => {
  expanded.value = null;
  viewing.value = (await axios.get(`/api/webhook-endpoints/${e.id}/captures`)).data;
};

const refreshCaptures = async () => {
  refreshing.value = true;
  try {
    viewing.value = (await axios.get(`/api/webhook-endpoints/${viewing.value.endpoint.id}/captures`)).data;
  } finally {
    refreshing.value = false;
  }
};

// A capture replayed from the tester: same method, headers minus transport
// noise, same body — pointed wherever the user wants to send it.
const openInTester = (c) => {
  const headers = { ...(c.headers || {}) };
  ['host', 'content-length', 'connection', 'accept-encoding'].forEach((h) => delete headers[h]);
  requestsStore.openInTester({
    protocol: 'rest',
    method: c.method,
    url: '',
    headers,
    body: c.body || '',
  });
  router.push('/tester');
};

const pretty = (obj) => JSON.stringify(obj, null, 2);
const prettyBody = (body) => {
  if (!body) return '(empty)';
  try { return JSON.stringify(JSON.parse(body), null, 2); } catch { return body; }
};

const when = (iso) => new Date(iso).toLocaleString('en-AU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
const ago = (iso) => {
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};
</script>

<style scoped>
.wh { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
.wh-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
.wh-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.wh-sub { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; max-width: 62ch; }
.wh-muted { color: var(--text-secondary); }

.wh-primary { padding: 9px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.wh-btn { padding: 6px 12px; border-radius: 7px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.wh-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.wh-danger { margin-left: auto; padding: 8px 14px; border-radius: 8px; background: none; border: 1px solid var(--border-color); color: #f85149; font-size: 13px; cursor: pointer; }

.wh-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 14px; color: var(--text-secondary); }
.wh-empty p { max-width: 480px; margin: 0; font-size: 14px; line-height: 1.6; }

.wh-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.wh-row { display: flex; align-items: center; gap: 12px; padding: 13px 16px; }
.wh-row + .wh-row { border-top: 1px solid var(--border-color); }
.wh-dot { width: 9px; height: 9px; border-radius: 999px; flex-shrink: 0; background: var(--text-secondary); }
.wh-dot.receiving { background: #3fb950; }
.wh-dot.silent { background: #f85149; }
.wh-main { flex: 1; min-width: 0; }
.wh-name { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-right: 8px; }
.wh-pill { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--text-secondary); }
.wh-pill.receiving { background: rgba(63,185,80,.16); color: #3fb950; }
.wh-pill.silent { background: rgba(248,81,73,.14); color: #f85149; }
.wh-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; min-width: 0; }
.wh-sub2 { font-size: 12px; color: var(--text-secondary); }
.wh-url { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); background: rgba(255,255,255,.05); padding: 2px 7px; border-radius: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wh-copy { background: none; border: none; color: var(--accent-color); font-size: 12px; cursor: pointer; padding: 2px 4px; }
.wh-actions { display: flex; gap: 6px; flex-shrink: 0; }

.wh-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.wh-modal { width: min(560px, 100%); max-height: 84vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.wh-modal.wide { width: min(760px, 100%); }
.wh-modal-head { display: flex; align-items: center; gap: 10px; padding: 15px 20px; border-bottom: 1px solid var(--border-color); }
.wh-modal-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); flex: 1; }
.wh-x { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.wh-body, .wh-form { padding: 16px 20px; overflow-y: auto; }
.wh-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
.wh-label:first-child { margin-top: 0; }
.wh-hint { font-size: 12px; color: var(--text-secondary); margin: 6px 0 0; line-height: 1.5; }
.wh-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 14px; cursor: pointer; }
.wh-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.wh-actions-row { display: flex; gap: 8px; margin-top: 18px; }

.wh-cap { border: 1px solid var(--border-color); border-radius: 9px; margin-bottom: 8px; overflow: hidden; }
.wh-cap-head { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; background: none; border: none; cursor: pointer; color: var(--text-primary); font-size: 12.5px; }
.wh-cap-head:hover { background: var(--panel-bg); }
.wh-cap-when { font-weight: 600; }
.wh-cap-ip { color: var(--text-secondary); }
.wh-cap-size { margin-left: auto; color: var(--text-secondary); }
.wh-cap-detail { padding: 10px 12px; border-top: 1px solid var(--border-color); }
.wh-cap-detail h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); margin: 10px 0 4px; }
.wh-cap-detail h4:first-child { margin-top: 0; }
.wh-cap-detail pre { margin: 0; padding: 8px 10px; background: #010409; border: 1px solid var(--border-color); border-radius: 7px; font-family: 'Courier New', monospace; font-size: 11.5px; line-height: 1.5; color: var(--text-primary); overflow-x: auto; max-height: 200px; }
.wh-cap-detail .wh-btn { margin-top: 10px; }
</style>
