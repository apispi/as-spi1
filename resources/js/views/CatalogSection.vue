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
            </td>
            <td class="cat-muted">{{ item.provider || '—' }}</td>
            <td class="cat-muted">{{ item.version || '—' }}</td>
            <td>
              <span class="cat-badge" :class="item.is_active ? 'on' : 'off'">
                {{ item.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="cat-actions-col">
              <button
                v-if="item.type === 'connector'"
                class="cat-btn cat-btn-sync"
                :disabled="syncingId === item.id"
                @click="sync(item)"
              >{{ syncingId === item.id ? 'Syncing...' : 'Sync' }}</button>
              <button class="cat-btn" @click="toggleActive(item)">
                {{ item.is_active ? 'Deactivate' : 'Activate' }}
              </button>
              <button class="cat-btn cat-btn-danger" @click="destroy(item)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create form -->
    <div v-if="showCreate" class="cat-panel cat-create">
      <div class="cat-panel-head">
        <h2 class="cat-panel-title">Add {{ singular }}</h2>
      </div>
      <form @submit.prevent="create">
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
  </main>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';

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
];

const activeTab = ref('agents');
const items = ref([]);
const counts = ref({});
const loading = ref(false);
const flash = ref('');
const showCreate = ref(false);
const saving = ref(false);
const syncingId = ref(null);
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
  form.value = { name: '', provider: '', version: '', description: '', endpoint: '', protocol: 'mcp', authHeader: '' };
  formError.value = '';
  showCreate.value = true;
};

const create = async () => {
  saving.value = true;
  formError.value = '';

  const payload = {
    type: currentTab.value.type,
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
      ...(form.value.authHeader ? { auth_header: form.value.authHeader } : {}),
    };
  }

  try {
    await axios.post('/api/admin/catalog', payload);
    showCreate.value = false;
    showFlash(`${singular.value} added.`);
    await fetchAll();
  } catch (error) {
    formError.value = error.response?.data?.message
      || Object.values(error.response?.data?.errors || {})[0]?.[0]
      || 'Failed to save.';
  } finally {
    saving.value = false;
  }
};

const sync = async (connector) => {
  syncingId.value = connector.id;
  try {
    const res = await axios.post(`/api/admin/catalog/${connector.id}/sync`);
    showFlash(res.data.message);
    await fetchAll();
  } catch (error) {
    showFlash(error.response?.data?.message || 'Sync failed.');
  } finally {
    syncingId.value = null;
  }
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
</style>
