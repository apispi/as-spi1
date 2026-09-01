<template>
  <div class="mk">
    <header class="mk-head">
      <div>
        <h1 class="mk-title">Mock Servers</h1>
        <p class="mk-sub">
          Spi serves a fake MCP server at a URL you can point an agent at —
          before the real server exists, or to avoid its rate limits and cost.
          Define tools by hand, or seed one from a flight recorder's real traffic.
        </p>
      </div>
      <button class="mk-primary" @click="startNew">New mock</button>
    </header>

    <div v-if="recorders.length" class="mk-seed">
      <span>Seed from a recorder:</span>
      <select v-model="seedProxy" class="input-field">
        <option value="">Pick a recorder…</option>
        <option v-for="p in recorders" :key="p.id" :value="p.id">{{ p.name }} ({{ p.exchanges_count }} exchanges)</option>
      </select>
      <button class="mk-btn" @click="seed" :disabled="!seedProxy || seeding">{{ seeding ? 'Seeding…' : 'Create mock' }}</button>
    </div>

    <p v-if="loading" class="mk-muted">Loading…</p>

    <div v-else-if="!mocks.length" class="mk-empty">
      <Icon name="layers" :size="26" />
      <p>No mocks yet. Create one, or seed from a recorder to replay observed traffic as a stand-in server.</p>
    </div>

    <ul v-else class="mk-list">
      <li v-for="m in mocks" :key="m.id" class="mk-row">
        <span class="mk-dot" :class="{ off: !m.is_enabled }"></span>
        <div class="mk-main">
          <div>
            <span class="mk-name">{{ m.name }}</span>
            <em v-if="ownerName(m)" class="mk-owner">{{ ownerName(m) }}</em>
            <span class="mk-badge">{{ m.tool_count }} tool{{ m.tool_count === 1 ? '' : 's' }}</span>
          </div>
          <div class="mk-meta">
            <code class="mk-url" :title="m.url">{{ m.url }}</code>
            <button class="mk-copy" @click="copy(m)">{{ copied === m.id ? '✓' : 'Copy' }}</button>
          </div>
        </div>
        <button class="mk-btn" @click="edit(m)">Edit</button>
      </li>
    </ul>

    <div v-if="editing" class="mk-scrim" @click.self="editing = null">
      <div class="mk-modal">
        <header class="mk-modal-head">
          <h2>{{ editing.id ? 'Edit mock' : 'New mock' }}</h2>
          <button class="mk-x" @click="editing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="mk-form">
          <label class="mk-label">Name</label>
          <input v-model="editing.name" class="input-field" maxlength="60" />

          <div class="mk-grid">
            <div>
              <label class="mk-label">Server name</label>
              <input v-model="editing.server_name" class="input-field" maxlength="80" />
            </div>
            <div>
              <label class="mk-label">Version</label>
              <input v-model="editing.server_version" class="input-field" maxlength="40" />
            </div>
          </div>

          <label class="mk-check">
            <input type="checkbox" v-model="editing.is_enabled" />
            <span>Enabled</span>
          </label>

          <label class="mk-label">Tools (JSON)</label>
          <p class="mk-hint">
            <code v-pre>[{ "name", "description", "inputSchema", "response" }]</code> —
            <code>response</code> is the tools/call result the mock returns.
          </p>
          <textarea v-model="toolsText" class="input-field mono mk-tools" rows="12" spellcheck="false"></textarea>

          <p v-if="error" class="mk-error">{{ error }}</p>

          <footer class="mk-actions">
            <button class="mk-primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="mk-danger" @click="remove" :disabled="saving">Delete</button>
            <button class="mk-btn" @click="editing = null" :disabled="saving">Cancel</button>
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
import { useAuthStore } from '../store/auth';

const authStore = useAuthStore();
const mocks = ref([]);
const recorders = ref([]);
const loading = ref(true);
const editing = ref(null);
const toolsText = ref('[]');
const seedProxy = ref('');
const seeding = ref(false);
const saving = ref(false);
const error = ref('');
const copied = ref(null);

const ownerName = (m) => (m.owner && m.owner.id !== authStore.user?.id ? m.owner.name : '');

const fetchAll = async () => {
  loading.value = true;
  try {
    const [m, r] = await Promise.all([axios.get('/api/mcp-mocks'), axios.get('/api/mcp-proxies')]);
    mocks.value = m.data;
    recorders.value = r.data;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchAll);

const copy = async (m) => {
  try { await navigator.clipboard.writeText(m.url); copied.value = m.id; setTimeout(() => (copied.value = null), 1500); } catch { /* */ }
};

const startNew = () => {
  error.value = '';
  editing.value = { id: null, name: '', server_name: 'Spi Mock', server_version: '1.0.0', is_enabled: true };
  toolsText.value = '[\n  {\n    "name": "example",\n    "description": "An example tool",\n    "inputSchema": { "type": "object", "properties": {} },\n    "response": { "content": [{ "type": "text", "text": "hello" }] }\n  }\n]';
};

const edit = async (m) => {
  error.value = '';
  const full = (await axios.get(`/api/mcp-mocks/${m.id}`)).data;
  editing.value = { id: full.id, name: full.name, server_name: full.server_name, server_version: full.server_version, is_enabled: full.is_enabled };
  toolsText.value = JSON.stringify(full.tools || [], null, 2);
};

const save = async () => {
  let tools;
  try { tools = JSON.parse(toolsText.value || '[]'); } catch { error.value = 'Tools is not valid JSON.'; return; }
  if (!Array.isArray(tools)) { error.value = 'Tools must be a JSON array.'; return; }

  saving.value = true;
  error.value = '';
  const payload = {
    name: editing.value.name.trim(),
    server_name: editing.value.server_name,
    server_version: editing.value.server_version,
    is_enabled: editing.value.is_enabled,
    tools,
  };
  try {
    if (editing.value.id) await axios.put(`/api/mcp-mocks/${editing.value.id}`, payload);
    else await axios.post('/api/mcp-mocks', payload);
    editing.value = null;
    await fetchAll();
  } catch (e) {
    const d = e.response?.data;
    error.value = d?.message || Object.values(d?.errors || {})[0]?.[0] || 'Failed to save.';
  } finally {
    saving.value = false;
  }
};

const remove = async () => {
  if (!confirm(`Delete "${editing.value.name}"? Its URL will stop working.`)) return;
  saving.value = true;
  try { await axios.delete(`/api/mcp-mocks/${editing.value.id}`); editing.value = null; await fetchAll(); }
  catch { error.value = 'Failed to delete.'; }
  finally { saving.value = false; }
};

const seed = async () => {
  seeding.value = true;
  try {
    await axios.post(`/api/mcp-mocks/from-recorder/${seedProxy.value}`);
    seedProxy.value = '';
    await fetchAll();
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to seed.';
  } finally {
    seeding.value = false;
  }
};
</script>

<style scoped>
.mk { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
.mk-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.mk-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.mk-sub { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; line-height: 1.6; max-width: 64ch; }
.mk-muted { color: var(--text-secondary); }
.mk-primary { padding: 9px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.mk-btn { padding: 6px 12px; border-radius: 7px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.mk-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.mk-danger { margin-left: auto; padding: 8px 14px; border-radius: 8px; background: none; border: 1px solid var(--border-color); color: #f85149; font-size: 13px; cursor: pointer; }

.mk-seed { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-bottom: 18px; flex-wrap: wrap; }
.mk-seed .input-field { max-width: 320px; padding: 6px 10px; }

.mk-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 14px; color: var(--text-secondary); }
.mk-empty p { max-width: 460px; margin: 0; font-size: 14px; line-height: 1.6; }

.mk-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.mk-row { display: flex; align-items: center; gap: 12px; padding: 13px 16px; }
.mk-row + .mk-row { border-top: 1px solid var(--border-color); }
.mk-dot { width: 9px; height: 9px; border-radius: 999px; flex-shrink: 0; background: #3fb950; }
.mk-dot.off { background: var(--text-secondary); }
.mk-main { flex: 1; min-width: 0; }
.mk-name { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-right: 8px; }
.mk-owner { font-style: normal; font-size: 11.5px; color: var(--text-secondary); margin-right: 6px; }
.mk-badge { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--text-secondary); }
.mk-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; min-width: 0; }
.mk-url { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); background: rgba(255,255,255,.05); padding: 2px 7px; border-radius: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mk-copy { background: none; border: none; color: var(--accent-color); font-size: 12px; cursor: pointer; }

.mk-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.mk-modal { width: min(680px, 100%); max-height: 86vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.mk-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; border-bottom: 1px solid var(--border-color); }
.mk-modal-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.mk-x { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.mk-form { padding: 16px 20px; overflow-y: auto; }
.mk-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
.mk-label:first-child { margin-top: 0; }
.mk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.mk-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 12px; cursor: pointer; }
.mk-hint { font-size: 12px; color: var(--text-secondary); margin: 0 0 6px; line-height: 1.5; }
.mk-hint code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; }
.mono { font-family: 'Courier New', monospace; }
.mk-tools { width: 100%; resize: vertical; font-size: 12px; line-height: 1.5; }
.mk-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.mk-actions { display: flex; gap: 8px; margin-top: 16px; }
</style>
