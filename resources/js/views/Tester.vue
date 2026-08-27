<template>
  <div class="tester-layout">

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
        <button class="env-manage" @click="showImport = true">Import</button>

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
          <RunResults v-if="collectionsStore.lastRun" :run="collectionsStore.lastRun" @close="collectionsStore.lastRun = null" />
          <AssertionsPanel
            :response="responseData"
            :savedRequestId="currentLoadedRequest?.id || null"
            :initial="currentLoadedRequest?.assertions || []"
          />
          <ContractPanel
            :response="responseData"
            :savedRequestId="currentLoadedRequest?.id || null"
            :hasContractInitial="!!currentLoadedRequest?.contract"
          />
        </div>
      </main>
    </div>

    <EnvironmentManager v-if="showManager" @close="showManager = false" />
    <ImportDialog v-if="showImport" @close="showImport = false" @loaded="onImported" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import RequestPanel from '../components/RequestPanel.vue';
import ResponsePanel from '../components/ResponsePanel.vue';
import EnvironmentManager from '../components/EnvironmentManager.vue';
import AssertionsPanel from '../components/AssertionsPanel.vue';
import ContractPanel from '../components/ContractPanel.vue';
import ImportDialog from '../components/ImportDialog.vue';
import RunResults from '../components/RunResults.vue';
import Icon from '../components/Icon.vue';
import { useRequestsStore } from '../store/requests';
import { useAuthStore } from '../store/auth';
import { useEnvironmentsStore } from '../store/environments';
import { useCollectionsStore } from '../store/collections';

const requestsStore = useRequestsStore();
const authStore = useAuthStore();
const envStore = useEnvironmentsStore();
const collectionsStore = useCollectionsStore();

const isLoading = ref(false);
const responseData = ref(null);
const currentLoadedRequest = ref(null);
const preferences = ref(null);
const activeTools = ref([]);
const activePrompts = ref([]);
const activeResources = ref([]);
const showManager = ref(false);
const showImport = ref(false);
const unresolved = ref([]);

onMounted(async () => {
  if (!authStore.isAuthenticated) return;

  // Opened from the Collections view.
  const pending = requestsStore.takePendingLoad();
  if (pending) currentLoadedRequest.value = { ...pending };

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

// A curl import fills the tester without saving; loading it through the same
// path as a saved request keeps RequestPanel's field-restoring logic in one
// place.
const onImported = (request) => {
  currentLoadedRequest.value = { ...request, id: null, assertions: [] };
};

const onEnvChange = (e) => {
  const value = e.target.value;
  envStore.select(value ? Number(value) : null);
  unresolved.value = [];
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
  }
};

const noteUnresolved = (data) => {
  unresolved.value = data?.environment?.unresolved || [];
};
</script>

<style scoped>
.tester-layout { display: flex; flex-direction: column; height: 100%; }






.saved-item:hover 
/* Environment bar */
.tester-main { flex: 1; display: flex; flex-direction: column; min-width: 0; min-height: 0; }
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
/* ResponsePanel/RequestPanel set height:100% on their root, which would push
   the stacked assertions panel out of view. Let them flex instead. */
.panel-container > :deep(.panel) { flex: 1; min-height: 0; height: auto; }

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
  }
@media (max-width: 768px) {
  .app-main { flex-direction: column; }
  .panel-container { border-right: none; border-bottom: 1px solid var(--border-color); }
}
</style>
