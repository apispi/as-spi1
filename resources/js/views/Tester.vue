<template>
  <div class="tester-layout">
    <aside class="sidebar">
      <div class="sidebar-header sidebar-tabs">
        <button :class="['sidebar-tab', sidebarTab === 'saved' ? 'active' : '']" @click="sidebarTab = 'saved'">Saved</button>
        <button :class="['sidebar-tab', sidebarTab === 'history' ? 'active' : '']" @click="switchToHistory">History</button>
      </div>
      <div class="sidebar-content" v-if="sidebarTab === 'saved'">
        <div v-if="requestsStore.isLoading" class="p-4 text-secondary text-sm">Loading...</div>
        <div v-else-if="requestsStore.savedRequests.length === 0" class="p-4 text-secondary text-sm">No saved requests</div>
        <ul v-else class="saved-list">
          <li v-for="req in requestsStore.savedRequests" :key="req.id" class="saved-item flex justify-between items-center">
            <div class="flex-1 cursor-pointer truncate mr-2" @click="loadRequest(req)">
              <span class="method-badge" :class="badgeClass(req)">{{ badgeLabel(req) }}</span>
              <span class="req-name">{{ req.name }}</span>
            </div>
            <button class="delete-icon" @click="deleteRequest(req.id)" title="Delete request">
              <Icon name="close" :size="14" />
            </button>
          </li>
        </ul>
      </div>
      <div class="sidebar-content" v-else>
        <div v-if="historyLoading" class="p-4 text-secondary text-sm">Loading...</div>
        <div v-else-if="history.length === 0" class="p-4 text-secondary text-sm">No requests yet</div>
        <template v-else>
          <ul class="saved-list">
            <li v-for="entry in history" :key="entry.id" class="saved-item history-item" @click="loadHistoryEntry(entry)">
              <div class="flex justify-between items-center">
                <span class="method-badge" :class="entry.protocol !== 'rest' ? entry.protocol : entry.method.toLowerCase()">{{ entry.protocol === 'rest' ? entry.method : entry.protocol.toUpperCase() }}</span>
                <span class="history-status" :class="entry.status && entry.status < 400 ? 'ok' : 'fail'">{{ entry.status || 'ERR' }}</span>
              </div>
              <div class="history-url truncate" :title="entry.url">{{ entry.url }}</div>
              <div class="history-meta">{{ entry.protocol !== 'rest' ? entry.method + ' · ' : '' }}{{ entry.time_ms }}ms · {{ timeAgo(entry.created_at) }}</div>
            </li>
          </ul>
          <div class="p-4">
            <button class="clear-history-btn" @click="clearHistory">Clear history</button>
          </div>
        </template>
      </div>
    </aside>

    <div class="tester-main">
      <!-- Environment bar -->
      <div class="env-bar">
        <Icon name="braces" :size="15" class="env-bar-icon" />
        <select class="input-field env-select" :value="envStore.selectedId ?? ''" @change="onEnvChange">
          <option value="">No environment</option>
          <option v-for="env in envStore.environments" :key="env.id" :value="env.id">
            {{ env.name }}{{ env.is_default ? ' (default)' : '' }}
          </option>
        </select>
        <span v-if="envStore.selectedKeys.length" class="env-keys" :title="envStore.selectedKeys.join(', ')">
          <code v-for="k in envStore.selectedKeys.slice(0, 4)" :key="k" v-text="placeholder(k)"></code>
          <span v-if="envStore.selectedKeys.length > 4" class="env-more">+{{ envStore.selectedKeys.length - 4 }} more</span>
        </span>
        <button class="env-manage" @click="showManager = true">Manage</button>

        <span v-if="unresolved.length" class="env-warn" :title="'No value in this environment for: ' + unresolved.join(', ')">
          Unresolved: <code v-for="u in unresolved" :key="u">{{ placeholder(u) }}</code>
        </span>
      </div>

      <main class="app-main">
        <div class="panel-container">
          <RequestPanel
            @send-request="handleRequest"
            @save-request="handleSaveRequest"
            :isLoading="isLoading"
            :loadedRequest="currentLoadedRequest"
            :defaults="preferences"
            :activeTools="activeTools"
            :activePrompts="activePrompts"
            :activeResources="activeResources"
          />
        </div>
        <div class="panel-container">
          <ResponsePanel :response="responseData" :isLoading="isLoading" />
        </div>
      </main>
    </div>

    <EnvironmentManager v-if="showManager" @close="showManager = false" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import RequestPanel from '../components/RequestPanel.vue';
import ResponsePanel from '../components/ResponsePanel.vue';
import EnvironmentManager from '../components/EnvironmentManager.vue';
import Icon from '../components/Icon.vue';
import { useRequestsStore } from '../store/requests';
import { useAuthStore } from '../store/auth';
import { useEnvironmentsStore } from '../store/environments';

const requestsStore = useRequestsStore();
const authStore = useAuthStore();
const envStore = useEnvironmentsStore();

const isLoading = ref(false);
const responseData = ref(null);
const currentLoadedRequest = ref(null);
const sidebarTab = ref('saved');
const history = ref([]);
const historyLoading = ref(false);
const preferences = ref(null);
const activeTools = ref([]);
const activePrompts = ref([]);
const activeResources = ref([]);
const showManager = ref(false);
const unresolved = ref([]);

onMounted(async () => {
  if (!authStore.isAuthenticated) return;

  await Promise.all([requestsStore.fetchSavedRequests(), envStore.fetch()]);

  try {
    const res = await axios.get('/api/user/preferences');
    preferences.value = res.data;
  } catch {
    preferences.value = null;
  }
  try {
    const [toolsRes, promptsRes, resourcesRes] = await Promise.all([
      axios.get('/api/tools/active'),
      axios.get('/api/prompts/active'),
      axios.get('/api/resources/active'),
    ]);
    activeTools.value = toolsRes.data;
    activePrompts.value = promptsRes.data;
    activeResources.value = resourcesRes.data;
  } catch {
    activeTools.value = [];
    activePrompts.value = [];
    activeResources.value = [];
  }
});

const onEnvChange = (e) => {
  const value = e.target.value;
  envStore.select(value ? Number(value) : null);
  unresolved.value = [];
};

const badgeLabel = (req) => (req.protocol && req.protocol !== 'rest' ? req.protocol.toUpperCase() : req.method);
const badgeClass = (req) => (req.protocol && req.protocol !== 'rest' ? req.protocol : (req.method || 'get').toLowerCase());

const loadRequest = (req) => {
  currentLoadedRequest.value = { ...req };
};

const fetchHistory = async () => {
  historyLoading.value = true;
  try {
    const res = await axios.get('/api/history');
    history.value = res.data;
  } catch (error) {
    console.error('Failed to fetch history', error);
  } finally {
    historyLoading.value = false;
  }
};

const switchToHistory = () => {
  sidebarTab.value = 'history';
  fetchHistory();
};

const loadHistoryEntry = (entry) => {
  currentLoadedRequest.value = {
    protocol: entry.protocol,
    method: entry.method,
    url: entry.url,
    body: entry.body || '',
    params: entry.params || null,
    headers: null,
  };
};

const clearHistory = async () => {
  if (!confirm('Clear your entire request history?')) return;
  try {
    await axios.delete('/api/history');
    history.value = [];
  } catch (error) {
    console.error('Failed to clear history', error);
  }
};

const timeAgo = (dateStr) => {
  const seconds = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
  if (seconds < 60) return 'just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
};

const deleteRequest = async (id) => {
  if (confirm('Are you sure you want to delete this saved request?')) {
    await requestsStore.deleteRequest(id);
  }
};

const handleSaveRequest = async (requestData) => {
  try {
    await requestsStore.saveRequest(requestData);
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to save request. Ensure you are logged in.');
  }
};

// The selected environment travels with every tester call; the server does the
// substitution so the SSRF and URL checks run against the real target.
const withEnv = (payload) =>
  envStore.selectedId ? { ...payload, environment_id: envStore.selectedId } : payload;

// gRPC/MQTT/AMQP endpoints return protocol-specific JSON; reshape it into the
// {status, headers, body, ...} form ResponsePanel renders.
const normalizeBrokerResponse = (requestConfig, data, status) => ({
  status,
  headers: data.metadata || {},
  body: JSON.stringify(data, null, 2),
  time_ms: data.time_ms ?? 0,
  request_payload: JSON.stringify(requestConfig.payload, null, 2),
  request_headers: requestConfig.payload?.metadata || {},
});

const handleRequest = async (requestConfig) => {
  isLoading.value = true;
  responseData.value = null;
  unresolved.value = [];

  try {
    let res;

    if (requestConfig.protocol === 'mcp' || requestConfig.protocol === 'a2a') {
      res = await axios.post(`/api/${requestConfig.protocol}/test`, withEnv({
        url: requestConfig.url,
        method: requestConfig.protocolMethod,
        params: requestConfig.params,
        headers: requestConfig.headers,
      }));
    } else if (['grpc', 'mqtt', 'amqp'].includes(requestConfig.protocol)) {
      try {
        res = await axios.post(`/api/${requestConfig.protocol}/test`, withEnv(requestConfig.payload));
        responseData.value = normalizeBrokerResponse(requestConfig, res.data, 200);
        noteUnresolved(res.data);
      } catch (err) {
        const status = err.response?.status || 0;
        const data = err.response?.data || { error: 'Network error or endpoint unreachable' };
        responseData.value = normalizeBrokerResponse(requestConfig, data, status);
        noteUnresolved(data);
      }
      return;
    } else {
      res = await axios.post('/api/proxy', withEnv({
        url: requestConfig.url,
        method: requestConfig.method,
        headers: requestConfig.headers,
        body: requestConfig.body,
      }));
    }

    responseData.value = res.data;
    noteUnresolved(res.data);
  } catch (error) {
    if (error.response && error.response.data) {
      responseData.value = error.response.data;
      noteUnresolved(error.response.data);
    } else {
      responseData.value = {
        status: 0,
        headers: {},
        body: 'Network error or proxy unreachable',
        time_ms: 0,
      };
    }
  } finally {
    isLoading.value = false;
    if (sidebarTab.value === 'history') {
      fetchHistory();
    }
  }
};

const noteUnresolved = (data) => {
  unresolved.value = data?.environment?.unresolved || [];
};
</script>

<style scoped>
.tester-layout { display: flex; height: 100%; }

.sidebar { width: 260px; background-color: var(--bg-color); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; }
.sidebar-header { padding: 16px; border-bottom: 1px solid var(--border-color); background-color: var(--panel-bg); }
.sidebar-tabs { display: flex; gap: 4px; padding: 8px; }
.sidebar-tab { flex: 1; background: none; border: none; padding: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); cursor: pointer; border-radius: 6px; transition: all 0.2s; }
.sidebar-tab:hover { color: var(--text-primary); }
.sidebar-tab.active { color: var(--text-primary); background: var(--bg-color); font-weight: 600; }
.sidebar-content { flex: 1; overflow-y: auto; }

.saved-list { list-style: none; padding: 0; margin: 0; }
.saved-item { padding: 12px 16px; border-bottom: 1px solid var(--border-color); transition: background-color 0.2s; }
.saved-item:hover { background-color: var(--panel-bg); }

.method-badge { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin-right: 8px; display: inline-block; vertical-align: middle; }
.method-badge.get { color: #3fb950; background: rgba(63, 185, 80, 0.15); }
.method-badge.post { color: #58a6ff; background: rgba(88, 166, 255, 0.15); }
.method-badge.put { color: #d29922; background: rgba(210, 153, 34, 0.15); }
.method-badge.patch { color: #d29922; background: rgba(210, 153, 34, 0.15); }
.method-badge.delete { color: #f85149; background: rgba(248, 81, 73, 0.15); }
.method-badge.mcp { color: #a371f7; background: rgba(163, 113, 247, 0.15); }
.method-badge.a2a { color: #f85149; background: rgba(248, 81, 73, 0.15); }
.method-badge.grpc { color: #58a6ff; background: rgba(88, 166, 255, 0.15); }
.method-badge.mqtt { color: #3fb950; background: rgba(63, 185, 80, 0.15); }
.method-badge.amqp { color: #d29922; background: rgba(210, 153, 34, 0.15); }

.history-item { display: block; cursor: pointer; }
.history-status { font-size: 11px; font-weight: 700; }
.history-status.ok { color: #3fb950; }
.history-status.fail { color: #f85149; }
.history-url { font-size: 12px; color: var(--text-primary); margin-top: 6px; }
.history-meta { font-size: 11px; color: var(--text-secondary); margin-top: 2px; }

.clear-history-btn { width: 100%; padding: 8px; background: none; border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-secondary); font-size: 12px; cursor: pointer; transition: all 0.2s; }
.clear-history-btn:hover { border-color: #f85149; color: #f85149; }

.req-name { font-size: 13px; color: var(--text-primary); vertical-align: middle; }
.delete-icon { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; opacity: 0; transition: opacity 0.2s, color 0.2s; }
.saved-item:hover .delete-icon { opacity: 1; }
.delete-icon:hover { color: #f85149; }

/* Environment bar */
.tester-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.env-bar { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--border-color); background: var(--panel-bg); flex-wrap: wrap; }
.env-bar-icon { color: var(--text-secondary); }
.env-select { max-width: 220px; padding: 6px 10px; font-size: 13px; }
.env-keys { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.env-keys code, .env-warn code { font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--text-secondary); background: rgba(255,255,255,.06); padding: 2px 6px; border-radius: 4px; }
.env-more { font-size: 11.5px; color: var(--text-secondary); }
.env-manage { margin-left: auto; padding: 6px 12px; background: none; border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.env-manage:hover { border-color: var(--accent-color); color: var(--accent-color); }
.env-warn { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; width: 100%; font-size: 12px; color: #d29922; }
.env-warn code { color: #d29922; background: rgba(210,153,34,.14); }

.app-main { display: flex; flex: 1; overflow: hidden; }
.panel-container { flex: 1; display: flex; flex-direction: column; border-right: 1px solid var(--border-color); min-width: 0; }
.panel-container:last-child { border-right: none; }

.p-4 { padding: 16px; }
.text-sm { font-size: 13px; }
.text-secondary { color: var(--text-secondary); }
.flex { display: flex; }
.justify-between { justify-content: space-between; }
.items-center { align-items: center; }
.flex-1 { flex: 1; }
.cursor-pointer { cursor: pointer; }
.truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mr-2 { margin-right: 8px; }

@media (max-width: 900px) {
  .tester-layout { flex-direction: column; }
  .sidebar { width: 100%; max-height: 220px; border-right: none; border-bottom: 1px solid var(--border-color); }
}
@media (max-width: 768px) {
  .app-main { flex-direction: column; }
  .panel-container { border-right: none; border-bottom: 1px solid var(--border-color); }
}
</style>
