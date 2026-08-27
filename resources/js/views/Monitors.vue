<template>
  <div class="mon">
    <header class="mon-head">
      <div>
        <h1 class="mon-title">Monitors</h1>
        <p class="mon-sub">Run a collection on a schedule and get alerted when it starts failing.</p>
      </div>
      <div class="mon-head-actions">
        <button class="mon-btn" @click="openPages">Status pages</button>
        <button class="mon-btn" @click="openChannels">Alert channels</button>
        <button class="mon-primary" @click="startNew" :disabled="!collectionsStore.collections.length">
          New monitor
        </button>
      </div>
    </header>

    <p v-if="store.isLoading" class="mon-muted">Loading…</p>

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
            {{ m.type === 'mcp_drift' ? (m.target_url || 'MCP drift') : (m.collection?.name || '—') }}
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

          <label class="mon-label">Watches</label>
          <select v-model="editing.type" class="input-field">
            <option value="collection">A collection (run it and check assertions)</option>
            <option value="mcp_drift">An MCP server (alert when its tools change)</option>
          </select>

          <template v-if="editing.type === 'mcp_drift'">
            <label class="mon-label">MCP endpoint</label>
            <input v-model="editing.target_url" class="input-field" placeholder="https://mcp.example.com/tools" />
            <p class="mon-note">
              Each run snapshots the server's <code>tools/list</code> and alerts
              when a tool is added, removed, or changes its schema or
              description — then the new shape becomes the baseline.
            </p>
          </template>

          <template v-else>
          <label class="mon-label">Collection</label>
          <select v-model="editing.collection_id" class="input-field">
            <option v-for="c in collectionsStore.collections" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          </template>

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

          <template v-if="channels.length">
            <label class="mon-label">Also alert</label>
            <label v-for="c in channels" :key="c.id" class="mon-check">
              <input type="checkbox" :value="c.id" v-model="editing.alert_channel_ids" />
              <span>{{ c.name }} <em class="mon-chan-type">{{ c.type }}</em></span>
            </label>
          </template>

          <p class="mon-note">
            Alerts fire when a monitor changes between passing and failing — not
            on every failing run.
          </p>

          <p v-if="error" class="mon-error">{{ error }}</p>

          <footer class="mon-modal-actions">
            <button class="mon-primary" @click="save" :disabled="saving || !editing.name.trim() || (editing.type === 'mcp_drift' ? !editing.target_url.trim() : !editing.collection_id)">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="mon-danger" @click="remove" :disabled="saving">Delete</button>
            <button class="mon-btn" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>
        </div>
      </div>
    </div>

    <!-- Alert channels -->
    <div v-if="showChannels" class="mon-scrim" @click.self="showChannels = false">
      <div class="mon-modal">
        <header class="mon-modal-head">
          <h2>Alert channels</h2>
          <button class="mon-x" @click="showChannels = false" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>

        <div class="mon-form">
          <p class="mon-note">
            Post alerts to Slack, Discord, or any endpoint. Webhook alerts need
            no mail server, so they work even if SMTP is not configured.
          </p>

          <ul v-if="channels.length" class="mon-chan-list">
            <li v-for="c in channels" :key="c.id" class="mon-chan">
              <span class="mon-dot" :class="c.last_error ? 'failing' : (c.last_delivered_at ? 'passing' : '')"></span>
              <div class="mon-chan-main">
                <div>
                  <span class="mon-chan-name">{{ c.name }}</span>
                  <em class="mon-chan-type">{{ c.type }}</em>
                  <span v-if="!c.is_enabled" class="mon-pill paused">disabled</span>
                </div>
                <div class="mon-chan-url">{{ c.url_preview }}</div>
                <div v-if="c.last_error" class="mon-chan-error">{{ c.last_error }}</div>
              </div>
              <div class="mon-actions">
                <button class="mon-btn" @click="testChannel(c)" :disabled="busyChannel === c.id">
                  {{ busyChannel === c.id ? 'Sending…' : 'Test' }}
                </button>
                <button class="mon-btn" @click="editChannel(c)">Edit</button>
              </div>
            </li>
          </ul>

          <div v-if="channelForm" class="mon-chan-form">
            <label class="mon-label">Name</label>
            <input v-model="channelForm.name" class="input-field" placeholder="Ops Slack" maxlength="60" />

            <label class="mon-label">Type</label>
            <select v-model="channelForm.type" class="input-field">
              <option value="slack">Slack</option>
              <option value="discord">Discord</option>
              <option value="webhook">Generic webhook</option>
            </select>

            <label class="mon-label">Webhook URL</label>
            <input
              v-model="channelForm.url"
              class="input-field mono"
              :placeholder="channelForm.id ? 'unchanged' : 'https://hooks.slack.com/services/…'"
              autocomplete="off"
            />

            <label class="mon-check">
              <input type="checkbox" v-model="channelForm.is_enabled" />
              <span>Enabled</span>
            </label>

            <p v-if="channelError" class="mon-error">{{ channelError }}</p>

            <footer class="mon-modal-actions">
              <button class="mon-primary" @click="saveChannel" :disabled="savingChannel || !channelForm.name.trim()">
                {{ savingChannel ? 'Saving…' : 'Save' }}
              </button>
              <button v-if="channelForm.id" class="mon-danger" @click="removeChannel" :disabled="savingChannel">Delete</button>
              <button class="mon-btn" @click="channelForm = null" :disabled="savingChannel">Cancel</button>
            </footer>
          </div>

          <button v-else class="mon-btn mon-chan-add" @click="newChannel">+ New channel</button>
        </div>
      </div>
    </div>

    <!-- Status pages -->
    <div v-if="showPages" class="mon-scrim" @click.self="showPages = false">
      <div class="mon-modal">
        <header class="mon-modal-head">
          <h2>Status pages</h2>
          <button class="mon-x" @click="showPages = false" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>

        <div class="mon-form">
          <p class="mon-note">
            A status page is a public, read-only view over monitors you choose —
            names, uptime, and history only. Anyone with the link can see it.
          </p>

          <ul v-if="pages.length" class="mon-chan-list">
            <li v-for="p in pages" :key="p.id" class="mon-chan">
              <span class="mon-dot" :class="p.is_enabled ? 'passing' : ''"></span>
              <div class="mon-chan-main">
                <div>
                  <span class="mon-chan-name">{{ p.name }}</span>
                  <span v-if="!p.is_enabled" class="mon-pill paused">offline</span>
                </div>
                <div class="mon-chan-url">{{ p.url }}</div>
                <div class="mon-chan-url">{{ p.monitors.join(' · ') || 'no monitors yet' }}</div>
              </div>
              <div class="mon-actions">
                <a :href="p.url" target="_blank" rel="noopener" class="mon-btn">Open</a>
                <button class="mon-btn" @click="editPage(p)">Edit</button>
              </div>
            </li>
          </ul>

          <div v-if="pageForm" class="mon-chan-form">
            <label class="mon-label">Name</label>
            <input v-model="pageForm.name" class="input-field" placeholder="Acme API status" maxlength="80" />

            <label class="mon-label">Description</label>
            <input v-model="pageForm.description" class="input-field" placeholder="Optional" maxlength="300" />

            <label class="mon-label">Monitors shown</label>
            <label v-for="m in store.monitors" :key="m.id" class="mon-check">
              <input type="checkbox" :value="m.id" v-model="pageForm.monitor_ids" />
              <span>{{ m.name }}</span>
            </label>

            <label class="mon-check">
              <input type="checkbox" v-model="pageForm.is_enabled" />
              <span>Page is live</span>
            </label>

            <p v-if="pageError" class="mon-error">{{ pageError }}</p>

            <footer class="mon-modal-actions">
              <button class="mon-primary" @click="savePage" :disabled="savingPage || !pageForm.name.trim()">
                {{ savingPage ? 'Saving…' : 'Save' }}
              </button>
              <button v-if="pageForm.id" class="mon-danger" @click="removePage" :disabled="savingPage">Delete</button>
              <button class="mon-btn" @click="pageForm = null" :disabled="savingPage">Cancel</button>
            </footer>
          </div>

          <button v-else class="mon-btn mon-chan-add" @click="newPage">+ New status page</button>
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
import axios from 'axios';
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
const channels = ref([]);
const pages = ref([]);
const showPages = ref(false);
const pageForm = ref(null);
const pageError = ref('');
const savingPage = ref(false);
const showChannels = ref(false);
const channelForm = ref(null);
const channelError = ref('');
const savingChannel = ref(false);
const busyChannel = ref(null);
const history = ref(null);
const saving = ref(false);
const busy = ref(null);
const error = ref('');

onMounted(() => {
  store.fetch();
  collectionsStore.fetch();
  envStore.fetch();
  fetchChannels();
});

const fetchChannels = async () => {
  try {
    channels.value = (await axios.get('/api/alert-channels')).data;
  } catch {
    channels.value = [];
  }
};

const fetchPages = async () => {
  try {
    pages.value = (await axios.get('/api/status-pages')).data;
  } catch {
    pages.value = [];
  }
};

const openPages = () => {
  pageError.value = '';
  pageForm.value = null;
  showPages.value = true;
  fetchPages();
};

const newPage = () => {
  pageError.value = '';
  pageForm.value = { id: null, name: '', description: '', is_enabled: true, monitor_ids: [] };
};

const editPage = (p) => {
  pageError.value = '';
  pageForm.value = {
    id: p.id, name: p.name, description: p.description || '',
    is_enabled: p.is_enabled, monitor_ids: [...(p.monitor_ids || [])],
  };
};

const savePage = async () => {
  savingPage.value = true;
  pageError.value = '';
  const payload = {
    name: pageForm.value.name.trim(),
    description: pageForm.value.description || null,
    is_enabled: pageForm.value.is_enabled,
    monitor_ids: pageForm.value.monitor_ids,
  };
  try {
    if (pageForm.value.id) {
      await axios.put(`/api/status-pages/${pageForm.value.id}`, payload);
    } else {
      await axios.post('/api/status-pages', payload);
    }
    pageForm.value = null;
    await fetchPages();
  } catch (e) {
    const data = e.response?.data;
    pageError.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save.';
  } finally {
    savingPage.value = false;
  }
};

const removePage = async () => {
  if (!confirm(`Delete "${pageForm.value.name}"? Its public link will stop working.`)) return;
  savingPage.value = true;
  try {
    await axios.delete(`/api/status-pages/${pageForm.value.id}`);
    pageForm.value = null;
    await fetchPages();
  } catch {
    pageError.value = 'Failed to delete.';
  } finally {
    savingPage.value = false;
  }
};

const openChannels = () => {
  channelError.value = '';
  channelForm.value = null;
  showChannels.value = true;
  fetchChannels();
};

const newChannel = () => {
  channelError.value = '';
  channelForm.value = { id: null, name: '', type: 'slack', url: '', is_enabled: true };
};

const editChannel = (c) => {
  channelError.value = '';
  // The URL is never sent to the browser, so an empty field means "unchanged".
  channelForm.value = { id: c.id, name: c.name, type: c.type, url: '', is_enabled: c.is_enabled };
};

const saveChannel = async () => {
  savingChannel.value = true;
  channelError.value = '';
  const payload = {
    name: channelForm.value.name.trim(),
    type: channelForm.value.type,
    is_enabled: channelForm.value.is_enabled,
  };
  if (channelForm.value.url.trim()) payload.url = channelForm.value.url.trim();

  try {
    if (channelForm.value.id) {
      await axios.put(`/api/alert-channels/${channelForm.value.id}`, payload);
    } else {
      await axios.post('/api/alert-channels', payload);
    }
    channelForm.value = null;
    await fetchChannels();
  } catch (e) {
    const data = e.response?.data;
    channelError.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save channel.';
  } finally {
    savingChannel.value = false;
  }
};

const removeChannel = async () => {
  if (!confirm(`Delete the "${channelForm.value.name}" channel?`)) return;
  savingChannel.value = true;
  try {
    await axios.delete(`/api/alert-channels/${channelForm.value.id}`);
    channelForm.value = null;
    await fetchChannels();
    await store.fetch();
  } catch {
    channelError.value = 'Failed to delete channel.';
  } finally {
    savingChannel.value = false;
  }
};

const testChannel = async (c) => {
  busyChannel.value = c.id;
  channelError.value = '';
  try {
    await axios.post(`/api/alert-channels/${c.id}/test`);
  } catch (e) {
    channelError.value = e.response?.data?.last_error || 'The test alert did not get through.';
  } finally {
    busyChannel.value = null;
    await fetchChannels();
  }
};

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
    type: 'collection',
    target_url: '',
    collection_id: collectionsStore.collections[0]?.id || null,
    environment_id: envStore.environments.find((e) => e.is_default)?.id || null,
    interval_minutes: 60,
    is_enabled: true,
    alerts_enabled: true,
    alert_channel_ids: [],
  };
};

const edit = (m) => {
  error.value = '';
  editing.value = {
    id: m.id,
    name: m.name,
    type: m.type || 'collection',
    target_url: m.target_url || '',
    collection_id: m.collection?.id || null,
    environment_id: m.environment?.id || null,
    interval_minutes: m.interval_minutes,
    is_enabled: m.is_enabled,
    alerts_enabled: m.alerts_enabled,
    alert_channel_ids: [...(m.alert_channel_ids || [])],
  };
};

const save = async () => {
  saving.value = true;
  error.value = '';
  try {
    await store.save({
      name: editing.value.name.trim(),
      type: editing.value.type,
      target_url: editing.value.type === 'mcp_drift' ? editing.value.target_url.trim() : null,
      collection_id: editing.value.type === 'mcp_drift' ? null : editing.value.collection_id,
      environment_id: editing.value.environment_id,
      interval_minutes: editing.value.interval_minutes,
      is_enabled: editing.value.is_enabled,
      alerts_enabled: editing.value.alerts_enabled,
      alert_channel_ids: editing.value.alert_channel_ids,
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

.mon-head-actions { display: flex; gap: 8px; }
.mon-chan-list { list-style: none; margin: 0 0 14px; padding: 0; border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
.mon-chan { display: flex; align-items: center; gap: 10px; padding: 11px 13px; }
.mon-chan + .mon-chan { border-top: 1px solid var(--border-color); }
.mon-chan-main { flex: 1; min-width: 0; }
.mon-chan-name { font-size: 13.5px; font-weight: 600; color: var(--text-primary); margin-right: 6px; }
.mon-chan-type { font-size: 11px; color: var(--text-secondary); font-style: normal; text-transform: uppercase; letter-spacing: .04em; }
.mon-chan-url { font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--text-secondary); margin-top: 2px; }
.mon-chan-error { font-size: 11.5px; color: #f85149; margin-top: 3px; }
.mon-chan-form { border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; }
.mon-chan-add { width: 100%; }
.mono { font-family: 'Courier New', monospace; }

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
