<template>
  <main class="cat-content">
    <div class="cat-header">
      <h1 class="cat-title">{{ sectionLabel }}</h1>
      <p class="cat-sub">{{ sectionSub }}</p>
    </div>

    <div v-if="flash" class="cat-flash">{{ flash }}</div>

    <div class="cat-tabs">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        :class="['cat-tab', { active: activeTab === tab.key }]"
        @click="selectTab(tab.key)"
      >
        <span class="cat-tab-icon">{{ tab.icon }}</span>
        {{ sectionLabel }} {{ tab.label }}
        <span class="cat-tab-count">{{ counts[tab.type] ?? 0 }}</span>
      </button>
    </div>

    <div class="cat-panel">
      <div class="cat-panel-head">
        <div>
          <h2 class="cat-panel-title">{{ sectionLabel }} {{ currentTab.label }}</h2>
          <p class="cat-panel-sub">{{ currentTab.description }}</p>
        </div>
        <button v-if="mode === 'catalog'" class="cat-btn-primary" @click="openCreate">
          + Add {{ singular }}
        </button>
      </div>

      <div v-if="loading" class="cat-empty">Loading...</div>

      <div v-else-if="items.length === 0" class="cat-empty">
        <div class="cat-empty-icon">{{ currentTab.icon }}</div>
        <p class="cat-empty-title">No {{ currentTab.label.toLowerCase() }} {{ mode === 'active' ? 'active' : 'in the catalog' }} yet</p>
        <p class="cat-empty-hint">{{ emptyHint }}</p>
      </div>

      <table v-else class="cat-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Provider</th>
            <th>Version</th>
            <th>Status</th>
            <th class="cat-actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>
              <div class="cat-item-name">{{ item.name }}</div>
              <div class="cat-item-desc">{{ item.description || '—' }}</div>
              <div v-if="item.type === 'connector'" class="cat-conn-meta">
                <span class="cat-endpoint">{{ item.metadata?.endpoint }}</span>
                <span v-if="connectorStatus(item)" class="cat-status" :class="connectorStatus(item).cls">
                  ● {{ connectorStatus(item).label }}
                </span>
                <span v-if="item.metadata?.last_synced_at" class="cat-muted"> · synced {{ ago(item.metadata.last_synced_at) }}</span>
                <span v-if="item.metadata?.conformance_grade" class="cat-grade" :class="gradeClass(item.metadata.conformance_grade)" title="MCP conformance grade">
                  {{ item.metadata.conformance_grade }} ({{ item.metadata.conformance_score }})
                </span>
                <span v-if="item.metadata?.security_risk" class="cat-risk" :class="'risk-' + item.metadata.security_risk" title="Security scan risk">
                  🛡 {{ item.metadata.security_risk }}
                </span>
              </div>
            </td>
            <td class="cat-muted">{{ item.provider || '—' }}</td>
            <td class="cat-muted">{{ item.version || '—' }}</td>
            <td>
              <span class="cat-badge" :class="item.is_active ? 'on' : 'off'">
                {{ item.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="cat-actions-col">
              <template v-if="item.type === 'connector'">
                <button class="cat-btn" :disabled="checkingId === item.id" @click="check(item)">
                  {{ checkingId === item.id ? 'Checking...' : 'Check' }}
                </button>
                <button class="cat-btn cat-btn-sync" :disabled="syncingId === item.id" @click="sync(item)">
                  {{ syncingId === item.id ? 'Syncing...' : 'Sync' }}
                </button>
                <button class="cat-btn cat-btn-sync" :disabled="syncingId === item.id" @click="sync(item, true)" title="Sync and activate everything it imports">
                  Sync + Activate
                </button>
                <button class="cat-btn" @click="edit(item)">Edit</button>
                <template v-if="(item.metadata?.protocol || 'mcp') === 'mcp'">
                  <button class="cat-btn cat-btn-ai" :disabled="busyId === item.id" @click="grade(item)" title="Grade MCP spec conformance">
                    {{ busyAction === 'grade' && busyId === item.id ? 'Grading...' : 'Grade' }}
                  </button>
                  <button class="cat-btn cat-btn-ai" :disabled="busyId === item.id" @click="securityScan(item)" title="Scan tools/prompts for poisoning">
                    {{ busyAction === 'scan' && busyId === item.id ? 'Scanning...' : 'Scan' }}
                  </button>
                  <button class="cat-btn cat-btn-ai" :disabled="busyId === item.id" @click="agentRun(item)" title="Run an agent-in-the-loop session">
                    {{ busyAction === 'agent' && busyId === item.id ? 'Running...' : 'Agent' }}
                  </button>
                </template>
              </template>
              <button class="cat-btn" @click="toggleActive(item)">
                {{ item.is_active ? 'Deactivate' : 'Activate' }}
              </button>
              <button class="cat-btn cat-btn-danger" @click="destroy(item)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create / edit form -->
    <div v-if="showCreate" class="cat-panel cat-create">
      <div class="cat-panel-head">
        <h2 class="cat-panel-title">{{ editingId ? 'Edit' : 'Add' }} {{ singular }}</h2>
      </div>
      <form @submit.prevent="save">
        <div class="cat-form-row">
          <div class="cat-form-group">
            <label class="cat-label">Name</label>
            <input v-model="form.name" required class="cat-input" placeholder="e.g. Research Agent" />
          </div>
          <div class="cat-form-group">
            <label class="cat-label">Provider</label>
            <input v-model="form.provider" class="cat-input" placeholder="e.g. internal" />
          </div>
          <div class="cat-form-group">
            <label class="cat-label">Version</label>
            <input v-model="form.version" class="cat-input" placeholder="e.g. 1.0.0" />
          </div>
        </div>
        <div class="cat-form-group">
          <label class="cat-label">Description</label>
          <textarea v-model="form.description" class="cat-input" rows="2"></textarea>
        </div>

        <template v-if="currentTab.type === 'connector'">
          <div class="cat-form-row cat-conn-row">
            <div class="cat-form-group">
              <label class="cat-label">Endpoint URL</label>
              <input v-model="form.endpoint" required class="cat-input" placeholder="https://server.example.com/mcp" />
            </div>
            <div class="cat-form-group">
              <label class="cat-label">Protocol</label>
              <select v-model="form.protocol" class="cat-input">
                <option value="mcp">MCP</option>
                <option value="a2a">A2A</option>
              </select>
            </div>
          </div>
          <div class="cat-form-group">
            <label class="cat-label">Auth header (optional)</label>
            <input v-model="form.authHeader" class="cat-input" placeholder="Bearer YOUR_TOKEN" />
            <p class="cat-hint">Sent as the Authorization header when syncing this connector.</p>
          </div>
        </template>

        <div class="cat-form-actions">
          <button type="submit" class="cat-btn-primary" :disabled="saving">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
          <button type="button" class="cat-btn" @click="showCreate = false">Cancel</button>
        </div>
        <p v-if="formError" class="cat-error">{{ formError }}</p>
      </form>
    </div>

    <!-- Deep-inspection results (conformance / security / agent trace) -->
    <div v-if="inspect" class="cat-modal-overlay" @click.self="inspect = null">
      <div class="cat-modal">
        <div class="cat-modal-head">
          <h2>{{ inspect.title }}</h2>
          <div class="cat-modal-actions">
            <button v-if="inspect.reportId" class="cat-btn cat-btn-ai" :disabled="sharing" @click="shareReport(inspect)">
              {{ inspect.shareUrl ? 'Copy link' : (sharing ? 'Sharing...' : 'Share') }}
            </button>
            <router-link class="cat-btn" to="/reports">All reports</router-link>
            <button class="cat-btn" @click="inspect = null">Close</button>
          </div>
        </div>
        <p v-if="inspect.shareUrl" class="cat-share-url">{{ inspect.shareUrl }}</p>
        <ReportView :type="inspect.type" :data="inspect.data" />
      </div>
    </div>
  </main>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import ReportView from '../components/ReportView.vue';

const route = useRoute();

// The section (catalog | active) comes from route meta so one component
// backs both routes.
const mode = computed(() => route.meta.section || 'catalog');

const tabs = [
  { key: 'agents', type: 'agent', label: 'Agents', icon: '◆', description: 'Autonomous agents that orchestrate tools and skills to complete tasks.' },
  { key: 'skills', type: 'skill', label: 'Skills', icon: '✦', description: 'Reusable capabilities an agent can invoke.' },
  { key: 'connectors', type: 'connector', label: 'Connectors', icon: '⇄', description: 'Integrations that link agents to external systems and data.' },
  { key: 'tools', type: 'tool', label: 'Tools', icon: '⚙', description: 'Individual MCP/A2A tools callable by agents.' },
  { key: 'prompts', type: 'prompt', label: 'Prompts', icon: '❝', description: 'Prompt templates available to agents and skills.' },
  { key: 'resources', type: 'resource', label: 'Resources', icon: '⧉', description: 'Readable data exposed by MCP servers (files, records, context).' },
];

const activeTab = ref('agents');
const items = ref([]);
const counts = ref({});
const loading = ref(false);
const flash = ref('');
const showCreate = ref(false);
const editingId = ref(null);
const saving = ref(false);
const syncingId = ref(null);
const checkingId = ref(null);
const checkResults = ref({}); // id -> { reachable, latency_ms, info }
const busyId = ref(null);        // connector id currently under deep inspection
const busyAction = ref(null);    // 'grade' | 'scan' | 'agent'
const inspect = ref(null);       // { type, title, data, reportId, shareUrl } for the modal
const sharing = ref(false);
const formError = ref('');
const form = ref({ name: '', provider: '', version: '', description: '', endpoint: '', protocol: 'mcp', authHeader: '' });

const currentTab = computed(() => tabs.find((t) => t.key === activeTab.value) || tabs[0]);
const singular = computed(() => currentTab.value.label.replace(/s$/, ''));
const sectionLabel = computed(() => (mode.value === 'active' ? 'Active' : 'Catalog'));

const sectionSub = computed(() =>
  mode.value === 'active'
    ? 'Everything currently enabled in your workspace.'
    : 'Browse everything available to add to your workspace.'
);

const emptyHint = computed(() =>
  mode.value === 'active'
    ? `Activate ${currentTab.value.label.toLowerCase()} from the Catalog to see them here.`
    : `Add ${currentTab.value.label.toLowerCase()} to the catalog to see them here.`
);

const showFlash = (msg) => {
  flash.value = msg;
  setTimeout(() => { flash.value = ''; }, 2500);
};

const fetchAll = async () => {
  loading.value = true;
  try {
    const params = { type: currentTab.value.type };
    if (mode.value === 'active') params.active = 1;

    const [itemsRes, countsRes] = await Promise.all([
      axios.get('/api/admin/catalog', { params }),
      axios.get('/api/admin/catalog/counts', { params: mode.value === 'active' ? { active: 1 } : {} }),
    ]);
    items.value = itemsRes.data;
    counts.value = countsRes.data;
  } catch (error) {
    items.value = [];
  } finally {
    loading.value = false;
  }
};

const selectTab = (key) => {
  activeTab.value = key;
  showCreate.value = false;
  fetchAll();
};

// Re-fetch when switching between the Catalog and Active routes.
watch(mode, () => {
  showCreate.value = false;
  fetchAll();
});

const openCreate = () => {
  editingId.value = null;
  form.value = { name: '', provider: '', version: '', description: '', endpoint: '', protocol: 'mcp', authHeader: '' };
  formError.value = '';
  showCreate.value = true;
};

const edit = (item) => {
  editingId.value = item.id;
  form.value = {
    name: item.name || '',
    provider: item.provider || '',
    version: item.version || '',
    description: item.description || '',
    endpoint: item.metadata?.endpoint || '',
    protocol: item.metadata?.protocol || 'mcp',
    authHeader: item.metadata?.auth_header || '',
  };
  formError.value = '';
  showCreate.value = true;
};

const save = async () => {
  saving.value = true;
  formError.value = '';

  const payload = {
    name: form.value.name,
    provider: form.value.provider,
    version: form.value.version,
    description: form.value.description,
  };

  // Connectors carry their endpoint wiring in metadata.
  if (currentTab.value.type === 'connector') {
    payload.metadata = {
      endpoint: form.value.endpoint,
      protocol: form.value.protocol,
      auth_header: form.value.authHeader || '',
    };
  }

  try {
    if (editingId.value) {
      await axios.put(`/api/admin/catalog/${editingId.value}`, payload);
      showFlash(`${singular.value} updated.`);
    } else {
      await axios.post('/api/admin/catalog', { ...payload, type: currentTab.value.type });
      showFlash(`${singular.value} added.`);
    }
    showCreate.value = false;
    await fetchAll();
  } catch (error) {
    formError.value = error.response?.data?.message
      || Object.values(error.response?.data?.errors || {})[0]?.[0]
      || 'Failed to save.';
  } finally {
    saving.value = false;
  }
};

const sync = async (connector, activate = false) => {
  syncingId.value = connector.id;
  try {
    const res = await axios.post(`/api/admin/catalog/${connector.id}/sync`, { activate });
    showFlash(res.data.message);
    await fetchAll();
  } catch (error) {
    showFlash(error.response?.data?.message || 'Sync failed.');
  } finally {
    syncingId.value = null;
  }
};

const check = async (connector) => {
  checkingId.value = connector.id;
  try {
    const res = await axios.post(`/api/admin/catalog/${connector.id}/check`);
    checkResults.value = { ...checkResults.value, [connector.id]: res.data };
    showFlash(res.data.reachable
      ? `Reachable (${res.data.latency_ms}ms)${res.data.info ? ' — ' + res.data.info : ''}`
      : `Unreachable: ${res.data.message}`);
  } catch (error) {
    showFlash(error.response?.data?.message || 'Check failed.');
  } finally {
    checkingId.value = null;
  }
};

// Deep-inspection actions. Each hits a connector endpoint and opens the
// results modal; the grade/scan variants also persist a badge onto the item.
const deepInspect = async (connector, action, url, type, title, payload = {}) => {
  busyId.value = connector.id;
  busyAction.value = action;
  try {
    const res = await axios.post(url, payload);
    inspect.value = {
      type, title: `${title} — ${connector.name}`, data: res.data,
      reportId: res.data.report_id || null, shareUrl: null,
    };
    if (action !== 'agent') await fetchAll();
  } catch (error) {
    showFlash(error.response?.data?.message || error.response?.data?.error || `${title} failed.`);
  } finally {
    busyId.value = null;
    busyAction.value = null;
  }
};

const grade = (c) => deepInspect(c, 'grade', `/api/admin/catalog/${c.id}/conformance`, 'conformance', 'Conformance');
const securityScan = (c) => deepInspect(c, 'scan', `/api/admin/catalog/${c.id}/security-scan`, 'security', 'Security scan');
const agentRun = (c) => {
  const goal = prompt(`Agent goal for "${c.name}":`, 'List the available tools and describe what this server can do.');
  if (!goal) return;
  deepInspect(c, 'agent', `/api/admin/catalog/${c.id}/agent-loop`, 'agent_loop', 'Agent run', { goal });
};

// Share the report behind the open modal: mint a link on first click, then
// copy it to the clipboard on subsequent clicks.
const shareReport = async (view) => {
  if (view.shareUrl) {
    try { await navigator.clipboard.writeText(view.shareUrl); showFlash('Link copied.'); } catch { showFlash(view.shareUrl); }
    return;
  }
  sharing.value = true;
  try {
    const res = await axios.post(`/api/reports/${view.reportId}/share`);
    view.shareUrl = res.data.url;
    try { await navigator.clipboard.writeText(res.data.url); showFlash('Share link copied.'); } catch { showFlash('Shared: ' + res.data.url); }
  } catch (error) {
    showFlash(error.response?.data?.message || 'Could not share report.');
  } finally {
    sharing.value = false;
  }
};

const gradeClass = (g) => {
  const l = (g || '')[0];
  return { A: 'g-a', B: 'g-b', C: 'g-c', D: 'g-d', F: 'g-f' }[l] || 'g-c';
};

// Prefer a fresh in-session check result; fall back to the stored last check.
const connectorStatus = (item) => {
  const r = checkResults.value[item.id];
  if (r) return r.reachable
    ? { cls: 'ok', label: `reachable ${r.latency_ms}ms` }
    : { cls: 'bad', label: 'unreachable' };
  if (item.metadata?.last_check_ok === true) return { cls: 'ok', label: 'reachable' };
  if (item.metadata?.last_check_ok === false) return { cls: 'bad', label: 'unreachable' };
  return null;
};

const ago = (iso) => {
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};

const toggleActive = async (item) => {
  try {
    const res = await axios.post(`/api/admin/catalog/${item.id}/toggle-active`);
    showFlash(res.data.message);
    await fetchAll();
  } catch (error) {
    showFlash('Failed to update item.');
  }
};

const destroy = async (item) => {
  if (!confirm(`Delete "${item.name}"? This cannot be undone.`)) return;
  try {
    await axios.delete(`/api/admin/catalog/${item.id}`);
    showFlash('Item deleted.');
    await fetchAll();
  } catch (error) {
    showFlash('Failed to delete item.');
  }
};

onMounted(fetchAll);
</script>

<style scoped>
.cat-content { padding: 2rem 2.5rem; }

.cat-header { margin-bottom: 1.5rem; }
.cat-title { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }
.cat-sub { font-size: 0.9rem; color: var(--text-secondary); margin-top: 0.25rem; }

.cat-flash {
  padding: 0.6rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem;
  background: rgba(63, 185, 80, 0.12); border: 1px solid rgba(63, 185, 80, 0.3);
  color: #3fb950; font-size: 0.85rem;
}

.cat-tabs { display: flex; gap: 0.35rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.cat-tab {
  display: flex; align-items: center; gap: 0.45rem;
  padding: 0.55rem 1rem; border-radius: 0.5rem;
  border: 1px solid var(--border-color);
  background: var(--panel-bg); cursor: pointer;
  font-family: inherit; font-size: 0.82rem; font-weight: 600;
  color: var(--text-secondary); transition: all 0.15s; white-space: nowrap;
}
.cat-tab:hover { color: var(--text-primary); border-color: var(--accent-color); }
.cat-tab.active { color: var(--accent-color); border-color: var(--accent-color); background: rgba(88, 166, 255, 0.1); }
.cat-tab-icon { font-size: 0.9rem; }
.cat-tab-count {
  font-size: 0.7rem; font-weight: 700; padding: 0.05rem 0.4rem;
  border-radius: 999px; background: var(--bg-color); color: var(--text-secondary);
}

.cat-panel {
  background: var(--panel-bg); border: 1px solid var(--border-color);
  border-radius: 1rem; padding: 1.5rem;
}
.cat-create { margin-top: 1.5rem; }
.cat-panel-head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
  padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color);
}
.cat-panel-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); }
.cat-panel-sub { font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.25rem; }

.cat-empty { text-align: center; padding: 3rem 1rem; color: var(--text-secondary); font-size: 0.85rem; }
.cat-empty-icon { font-size: 2.5rem; opacity: 0.4; margin-bottom: 0.75rem; }
.cat-empty-title { font-size: 0.95rem; font-weight: 600; color: var(--text-primary); }
.cat-empty-hint { font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.35rem; }

.cat-table { width: 100%; border-collapse: collapse; }
.cat-table th {
  text-align: left; padding: 0.6rem 0.75rem; font-size: 0.7rem;
  text-transform: uppercase; letter-spacing: 0.05em;
  color: var(--text-secondary); border-bottom: 1px solid var(--border-color);
}
.cat-table td { padding: 0.75rem; border-bottom: 1px solid var(--border-color); font-size: 0.85rem; color: var(--text-primary); }
.cat-table tr:last-child td { border-bottom: none; }
.cat-item-name { font-weight: 600; }
.cat-item-desc { font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.15rem; }
.cat-conn-meta { font-size: 0.72rem; margin-top: 0.35rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
.cat-endpoint { font-family: monospace; color: var(--accent-color); word-break: break-all; }
.cat-status { font-weight: 600; }
.cat-status.ok { color: #3fb950; }
.cat-status.bad { color: #f85149; }
.cat-muted { color: var(--text-secondary); }
.cat-actions-col { white-space: nowrap; text-align: right; }

.cat-badge { font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 999px; }
.cat-badge.on { color: #3fb950; background: rgba(63, 185, 80, 0.15); }
.cat-badge.off { color: #8b949e; background: rgba(139, 148, 158, 0.15); }

.cat-btn {
  padding: 0.3rem 0.7rem; border-radius: 0.35rem; font-size: 0.75rem; font-weight: 600;
  cursor: pointer; border: 1px solid var(--border-color); background: none;
  color: var(--text-primary); margin-left: 0.35rem; font-family: inherit;
}
.cat-btn:hover { border-color: var(--accent-color); color: var(--accent-color); }
.cat-btn-danger:hover { border-color: #f85149; color: #f85149; }
.cat-btn-sync:hover:not(:disabled) { border-color: #3fb950; color: #3fb950; }
.cat-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.cat-conn-row { grid-template-columns: 2fr 1fr; }
.cat-hint { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.25rem; }
.cat-btn-primary {
  padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 700;
  cursor: pointer; border: 1px solid var(--accent-color);
  background: rgba(88, 166, 255, 0.15); color: var(--accent-color); font-family: inherit;
}
.cat-btn-primary:hover { background: rgba(88, 166, 255, 0.28); }
.cat-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.cat-form-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 0.75rem; }
.cat-form-group { display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.75rem; }
.cat-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); }
.cat-input {
  padding: 0.5rem 0.7rem; background: var(--bg-color);
  border: 1px solid var(--border-color); border-radius: 0.4rem;
  color: var(--text-primary); font-size: 0.85rem; font-family: inherit; width: 100%;
}
.cat-input:focus { outline: none; border-color: var(--accent-color); }
.cat-form-actions { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.5rem; }
.cat-error { color: #f85149; font-size: 0.8rem; margin-top: 0.5rem; }

@media (max-width: 640px) {
  .cat-content { padding: 1rem; }
  .cat-form-row { grid-template-columns: 1fr; }
}

/* Deep-inspection badges */
.cat-btn-ai:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.cat-grade, .cat-risk { font-size: 0.72rem; font-weight: 700; padding: 1px 7px; border-radius: 5px; margin-left: 6px; }
.cat-risk { text-transform: capitalize; }
.g-a { background: rgba(63,185,80,.16); color: #3fb950; }
.g-b { background: rgba(88,166,255,.16); color: #58a6ff; }
.g-c { background: rgba(210,153,34,.16); color: #d29922; }
.g-d { background: rgba(219,109,40,.18); color: #db6d28; }
.g-f { background: rgba(248,81,73,.16); color: #f85149; }
.risk-none { background: rgba(63,185,80,.14); color: #3fb950; }
.risk-low { background: var(--border-color); color: var(--text-secondary); }
.risk-medium { background: rgba(210,153,34,.18); color: #d29922; }
.risk-high, .risk-critical { background: rgba(248,81,73,.16); color: #f85149; }

/* Results modal (body rendered by ReportView) */
.cat-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; z-index: 50; overflow-y: auto; }
.cat-modal { background: var(--bg-primary, #0d1117); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; width: 100%; max-width: 720px; }
.cat-modal-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; }
.cat-modal-head h2 { font-size: 1.05rem; margin: 0; color: var(--text-primary); }
.cat-modal-actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
.cat-share-url { font-family: ui-monospace, Menlo, monospace; font-size: 0.75rem; color: var(--accent-color); word-break: break-all; margin: 0 0 12px; }
</style>
