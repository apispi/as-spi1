<template>
  <div class="rec">
    <header class="rec-head">
      <div>
        <h1 class="rec-title">MCP Recorder</h1>
        <p class="rec-sub">
          Point an agent at a recorder URL instead of the MCP server. Every
          call it makes is forwarded, recorded, and scanned for injection —
          so you can see what your agent actually did.
        </p>
      </div>
      <button class="rec-primary" @click="startNew">New recorder</button>
    </header>

    <p v-if="loading" class="rec-muted">Loading…</p>

    <div v-else-if="!proxies.length" class="rec-empty">
      <Icon name="plug" :size="26" />
      <p>
        Create a recorder for an MCP server. You get a proxy URL — give that to
        your agent in place of the server's own URL, and its whole session
        shows up here.
      </p>
      <button class="rec-primary" @click="startNew">Create one</button>
    </div>

    <ul v-else class="rec-list">
      <li v-for="p in proxies" :key="p.id" class="rec-row">
        <span class="rec-dot" :class="{ off: !p.is_enabled }"></span>
        <div class="rec-main">
          <div>
            <span class="rec-name">{{ p.name }}</span>
            <span v-if="p.flagged_count" class="rec-pill bad">{{ p.flagged_count }} flagged</span>
            <span v-if="!p.is_enabled" class="rec-pill">disabled</span>
          </div>
          <div class="rec-meta">→ {{ p.upstream_url }}</div>
          <div class="rec-meta rec-urlrow">
            <code class="rec-url" :title="p.url">{{ p.url }}</code>
            <button class="rec-copy" @click="copy(p)">{{ copied === p.id ? '✓' : 'Copy' }}</button>
            <span class="rec-sub2">
              {{ p.exchanges_count }} exchange{{ p.exchanges_count === 1 ? '' : 's' }}
              <template v-if="p.last_used_at"> · last {{ ago(p.last_used_at) }}</template>
            </span>
          </div>
        </div>
        <div class="rec-actions">
          <button class="rec-btn" @click="open(p)">Timeline</button>
          <button class="rec-btn" @click="edit(p)">Edit</button>
        </div>
      </li>
    </ul>

    <!-- Timeline -->
    <div v-if="viewing" class="rec-scrim" @click.self="viewing = null">
      <div class="rec-modal wide">
        <header class="rec-modal-head">
          <h2>{{ viewing.proxy.name }}</h2>
          <label class="rec-flagtoggle">
            <input type="checkbox" v-model="onlyFlagged" @change="reloadExchanges" />
            <span>Flagged only</span>
          </label>
          <button class="rec-btn" @click="synthesize" :disabled="synthesizing" title="Reverse-engineer the server's real contract from this traffic">
            {{ synthesizing ? '…' : 'Synthesize contract' }}
          </button>
          <button class="rec-btn" @click="reloadExchanges" :disabled="reloading">{{ reloading ? '…' : 'Refresh' }}</button>
          <button class="rec-x" @click="viewing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="rec-body">
          <div v-if="synth" class="rec-synth">
            <div class="rec-synth-head">
              <strong>Observed contract</strong>
              <span class="rec-muted">learned from {{ synth.exchanges_seen }} exchange(s)</span>
              <button class="rec-btn" @click="synth = null">Hide</button>
            </div>
            <p v-if="!synth.tools.length" class="rec-muted">No tool calls recorded yet — use the agent, then synthesize.</p>
            <div v-for="t in synth.tools" :key="t.name" class="rec-tool">
              <div class="rec-tool-head">
                <span class="rec-verb">{{ t.name }}</span>
                <span v-if="t.only_observed" class="rec-pill bad">undeclared</span>
                <span class="rec-muted">{{ t.call_count }} call(s)</span>
              </div>
              <div class="rec-tool-grid">
                <div>
                  <h5>Declared input</h5>
                  <pre>{{ t.declared_input_schema ? props_(t.declared_input_schema) : '—' }}</pre>
                </div>
                <div>
                  <h5>Observed input</h5>
                  <pre :class="{ 'rec-diff': differs(t) }">{{ t.observed_input_schema ? props_(t.observed_input_schema) : '—' }}</pre>
                </div>
                <div>
                  <h5>Observed output</h5>
                  <pre>{{ t.observed_output_schema ? props_(t.observed_output_schema) : '—' }}</pre>
                </div>
              </div>
            </div>
          </div>

          <p v-if="!viewing.exchanges.length" class="rec-muted">
            No exchanges yet. Point an agent at
            <code class="rec-url">{{ viewing.proxy.url }}</code> and refresh.
          </p>
          <div v-for="ex in viewing.exchanges" :key="ex.id" class="rec-ex" :class="{ flagged: ex.flagged }">
            <button class="rec-ex-head" @click="expanded = expanded === ex.id ? null : ex.id">
              <span class="rec-verb">{{ ex.method }}</span>
              <span v-if="ex.flagged" class="rec-flag" :title="ex.flag_summary">⚠ {{ ex.flag_summary }}</span>
              <span v-if="ex.enforcement" class="rec-pill" :class="ex.enforcement.action.includes('blocked') ? 'bad' : 'warn'" :title="ex.enforcement.note">
                {{ enforcementLabel(ex.enforcement) }}
              </span>
              <span class="rec-ex-meta">{{ ex.status || 'ERR' }} · {{ ex.duration_ms }}ms · {{ when(ex.created_at) }}</span>
            </button>
            <div v-if="expanded === ex.id" class="rec-ex-detail">
              <h4>Request</h4>
              <pre>{{ pretty(ex.request) }}</pre>
              <h4>Response</h4>
              <pre :class="{ 'rec-danger': ex.flagged }">{{ pretty(ex.response) }}</pre>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor -->
    <div v-if="editing" class="rec-scrim" @click.self="editing = null">
      <div class="rec-modal">
        <header class="rec-modal-head">
          <h2>{{ editing.id ? 'Edit recorder' : 'New recorder' }}</h2>
          <button class="rec-x" @click="editing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="rec-form">
          <label class="rec-label">Name</label>
          <input v-model="editing.name" class="input-field" placeholder="Acme MCP recorder" maxlength="60" />

          <label class="rec-label">Upstream MCP server</label>
          <input v-model="editing.upstream_url" class="input-field mono"
                 :placeholder="editing.id ? 'unchanged' : 'https://mcp.example.com/tools'" />
          <p class="rec-hint">
            Calls to your recorder URL are forwarded here. The agent's
            Authorization header is passed through so upstream auth works, but
            it is never stored in the recording.
          </p>

          <label class="rec-check">
            <input type="checkbox" v-model="editing.is_enabled" />
            <span>Enabled</span>
          </label>

          <label class="rec-label">Firewall policy</label>
          <p class="rec-hint">
            Rules run in order on every call. Block a tool, redact secrets in
            arguments before they leave, or withhold an injection-flagged
            response before it reaches the agent.
          </p>
          <div v-for="(r, i) in editing.policy" :key="i" class="rec-rule">
            <select v-model="r.action" class="input-field">
              <option value="block">block</option>
              <option value="redact">redact</option>
            </select>
            <select v-model="r.direction" class="input-field">
              <option value="request">request</option>
              <option value="response">response</option>
            </select>
            <input v-if="r.direction === 'request'" v-model="r.tool" class="input-field mono" placeholder="tool regex (blank = any)" />
            <input v-if="r.action === 'redact' || r.direction === 'response'" v-model="r.pattern" class="input-field mono" placeholder="pattern regex" />
            <label v-if="r.direction === 'response'" class="rec-rule-check">
              <input type="checkbox" v-model="r.on_injection" /><span>on injection</span>
            </label>
            <button class="rec-del" @click="editing.policy.splice(i, 1)" aria-label="Remove rule"><Icon name="close" :size="13" /></button>
          </div>
          <button class="rec-btn rec-rule-add" @click="addRule">+ Add rule</button>

          <p v-if="error" class="rec-error">{{ error }}</p>

          <footer class="rec-actions-row">
            <button class="rec-primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="rec-danger" @click="remove" :disabled="saving">Delete</button>
            <button class="rec-btn" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import Icon from '../components/Icon.vue';

const proxies = ref([]);
const loading = ref(true);
const editing = ref(null);
const viewing = ref(null);
const expanded = ref(null);
const onlyFlagged = ref(false);
const reloading = ref(false);
const synthesizing = ref(false);
const synth = ref(null);
const saving = ref(false);
const error = ref('');
const copied = ref(null);

const fetchAll = async () => {
  loading.value = true;
  try {
    proxies.value = (await axios.get('/api/mcp-proxies')).data;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchAll);

const copy = async (p) => {
  try {
    await navigator.clipboard.writeText(p.url);
    copied.value = p.id;
    setTimeout(() => { copied.value = null; }, 1500);
  } catch { /* clipboard unavailable */ }
};

const startNew = () => {
  error.value = '';
  editing.value = { id: null, name: '', upstream_url: '', is_enabled: true, policy: [] };
};

const edit = (p) => {
  error.value = '';
  editing.value = { id: p.id, name: p.name, upstream_url: '', is_enabled: p.is_enabled, policy: (p.policy || []).map((r) => ({ ...r })) };
};

const addRule = () => {
  editing.value.policy.push({ action: 'block', direction: 'request', tool: '', pattern: '', on_injection: false });
};

const save = async () => {
  saving.value = true;
  error.value = '';
  const payload = { name: editing.value.name.trim(), is_enabled: editing.value.is_enabled };
  if (editing.value.upstream_url.trim()) payload.upstream_url = editing.value.upstream_url.trim();
  payload.policy = editing.value.policy
    .filter((r) => r.action && r.direction)
    .map((r) => ({
      action: r.action, direction: r.direction,
      tool: r.direction === 'request' ? (r.tool || null) : null,
      pattern: r.pattern || null,
      on_injection: !!r.on_injection,
    }));
  try {
    if (editing.value.id) {
      await axios.put(`/api/mcp-proxies/${editing.value.id}`, payload);
    } else {
      await axios.post('/api/mcp-proxies', payload);
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
  if (!confirm(`Delete "${editing.value.name}" and its recording? Agents using its URL will get 404s.`)) return;
  saving.value = true;
  try {
    await axios.delete(`/api/mcp-proxies/${editing.value.id}`);
    editing.value = null;
    await fetchAll();
  } catch {
    error.value = 'Failed to delete.';
  } finally {
    saving.value = false;
  }
};

const open = async (p) => {
  expanded.value = null;
  onlyFlagged.value = false;
  synth.value = null;
  viewing.value = (await axios.get(`/api/mcp-proxies/${p.id}/exchanges`)).data;
};

const synthesize = async () => {
  synthesizing.value = true;
  try {
    synth.value = (await axios.get(`/api/mcp-proxies/${viewing.value.proxy.id}/synthesize`)).data;
  } finally {
    synthesizing.value = false;
  }
};

// A compact "field: type" view of a schema's top-level properties.
const props_ = (schema) => {
  const p = schema?.properties;
  if (!p) return schema?.type || '—';
  return Object.entries(p).map(([k, v]) => `${k}: ${Array.isArray(v.type) ? v.type.join('|') : v.type}`).join('\n');
};

// Does observed input have fields the declared schema lacks?
const differs = (t) => {
  const d = Object.keys(t.declared_input_schema?.properties || {});
  const o = Object.keys(t.observed_input_schema?.properties || {});
  return o.some((k) => !d.includes(k));
};

const reloadExchanges = async () => {
  reloading.value = true;
  try {
    const q = onlyFlagged.value ? '?flagged=1' : '';
    viewing.value = (await axios.get(`/api/mcp-proxies/${viewing.value.proxy.id}/exchanges${q}`)).data;
  } finally {
    reloading.value = false;
  }
};

const enforcementLabel = (e) => ({
  blocked_request: 'blocked', blocked_response: 'response blocked',
  redacted_request: 'redacted', redacted_response: 'response redacted',
}[e.action] || e.action);

const pretty = (obj) => JSON.stringify(obj, null, 2);
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
.rec { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
.rec-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
.rec-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.rec-sub { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; max-width: 64ch; }
.rec-muted { color: var(--text-secondary); }

.rec-primary { padding: 9px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.rec-btn { padding: 6px 12px; border-radius: 7px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.rec-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.rec-danger { margin-left: auto; padding: 8px 14px; border-radius: 8px; background: none; border: 1px solid var(--border-color); color: #f85149; font-size: 13px; cursor: pointer; }

.rec-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 14px; color: var(--text-secondary); }
.rec-empty p { max-width: 500px; margin: 0; font-size: 14px; line-height: 1.6; }

.rec-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.rec-row { display: flex; align-items: center; gap: 12px; padding: 13px 16px; }
.rec-row + .rec-row { border-top: 1px solid var(--border-color); }
.rec-dot { width: 9px; height: 9px; border-radius: 999px; flex-shrink: 0; background: #3fb950; }
.rec-dot.off { background: var(--text-secondary); }
.rec-main { flex: 1; min-width: 0; }
.rec-name { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-right: 8px; }
.rec-pill { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--text-secondary); }
.rec-pill.bad { background: rgba(248,81,73,.14); color: #f85149; }
.rec-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; min-width: 0; font-size: 12px; color: var(--text-secondary); }
.rec-urlrow { flex-wrap: wrap; }
.rec-sub2 { color: var(--text-secondary); }
.rec-url { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); background: rgba(255,255,255,.05); padding: 2px 7px; border-radius: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rec-copy { background: none; border: none; color: var(--accent-color); font-size: 12px; cursor: pointer; padding: 2px 4px; }
.rec-actions { display: flex; gap: 6px; flex-shrink: 0; }

.rec-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.rec-modal { width: min(560px, 100%); max-height: 84vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.rec-modal.wide { width: min(820px, 100%); }
.rec-modal-head { display: flex; align-items: center; gap: 10px; padding: 15px 20px; border-bottom: 1px solid var(--border-color); }
.rec-modal-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); flex: 1; }
.rec-flagtoggle { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-secondary); cursor: pointer; }
.rec-x { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.rec-body, .rec-form { padding: 16px 20px; overflow-y: auto; }
.rec-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
.rec-label:first-child { margin-top: 0; }
.mono { font-family: 'Courier New', monospace; }
.rec-hint { font-size: 12px; color: var(--text-secondary); margin: 6px 0 0; line-height: 1.5; }
.rec-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 14px; cursor: pointer; }
.rec-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.rec-actions-row { display: flex; gap: 8px; margin-top: 18px; }

.rec-synth { border: 1px solid #a371f7; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
.rec-synth-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; font-size: 13px; }
.rec-synth-head strong { color: var(--text-primary); }
.rec-synth-head .rec-btn { margin-left: auto; }
.rec-tool { border-top: 1px solid var(--border-color); padding-top: 10px; margin-top: 10px; }
.rec-tool:first-of-type { border-top: none; margin-top: 0; padding-top: 0; }
.rec-tool-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.rec-tool-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.rec-tool-grid h5 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); margin: 0 0 4px; }
.rec-tool-grid pre { margin: 0; padding: 7px 9px; background: #010409; border: 1px solid var(--border-color); border-radius: 6px; font-family: 'Courier New', monospace; font-size: 11px; line-height: 1.5; color: var(--text-primary); overflow-x: auto; white-space: pre-wrap; }
.rec-tool-grid pre.rec-diff { border-color: #a371f7; }
@media (max-width: 640px) { .rec-tool-grid { grid-template-columns: 1fr; } }
.rec-rule { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; flex-wrap: wrap; }
.rec-rule .input-field { padding: 5px 8px; font-size: 12.5px; }
.rec-rule select.input-field { max-width: 110px; }
.rec-rule-check { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-secondary); white-space: nowrap; }
.rec-del { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 3px; }
.rec-del:hover { color: #f85149; }
.rec-rule-add { margin-top: 2px; }
.rec-pill.warn { color: #d29922; background: rgba(210,153,34,.14); }
.rec-ex { border: 1px solid var(--border-color); border-radius: 9px; margin-bottom: 8px; overflow: hidden; }
.rec-ex.flagged { border-color: #f85149; }
.rec-ex-head { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; background: none; border: none; cursor: pointer; color: var(--text-primary); font-size: 12.5px; text-align: left; }
.rec-ex-head:hover { background: var(--panel-bg); }
.rec-verb { font-family: 'Courier New', monospace; font-weight: 700; color: var(--accent-color); }
.rec-flag { color: #f85149; font-size: 11.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rec-ex-meta { margin-left: auto; color: var(--text-secondary); white-space: nowrap; }
.rec-ex-detail { padding: 10px 12px; border-top: 1px solid var(--border-color); }
.rec-ex-detail h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); margin: 10px 0 4px; }
.rec-ex-detail h4:first-child { margin-top: 0; }
.rec-ex-detail pre { margin: 0; padding: 8px 10px; background: #010409; border: 1px solid var(--border-color); border-radius: 7px; font-family: 'Courier New', monospace; font-size: 11.5px; line-height: 1.5; color: var(--text-primary); overflow-x: auto; max-height: 260px; }
.rec-ex-detail pre.rec-danger { border-color: #f85149; }
</style>
