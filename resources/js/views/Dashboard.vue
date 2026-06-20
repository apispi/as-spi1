<template>
  <div class="dashboard-layout">
    <aside class="sidebar">
      <div class="sidebar-header">
        <h3>Saved Requests</h3>
      </div>
      <div class="sidebar-content">
        <div v-if="requestsStore.isLoading" class="p-4 text-secondary text-sm">Loading...</div>
        <div v-else-if="requestsStore.savedRequests.length === 0" class="p-4 text-secondary text-sm">No saved requests</div>
        <ul v-else class="saved-list">
          <li v-for="req in requestsStore.savedRequests" :key="req.id" class="saved-item flex justify-between items-center group">
            <div class="flex-1 cursor-pointer truncate mr-2" @click="loadRequest(req)">
              <span class="method-badge" :class="req.method.toLowerCase()">{{ req.method }}</span>
              <span class="req-name">{{ req.name }}</span>
            </div>
            <button class="delete-icon" @click="deleteRequest(req.id)" title="Delete request">
              <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
          </li>
        </ul>
      </div>
    </aside>
    <main class="app-main">
      <div class="panel-container">
        <RequestPanel 
          @send-request="handleRequest" 
          @save-request="handleSaveRequest"
          :isLoading="isLoading"
          :loadedRequest="currentLoadedRequest"
        />
      </div>
      <div class="panel-container">
        <ResponsePanel 
          :response="responseData" 
          :isLoading="isLoading" 
        />
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import RequestPanel from '../components/RequestPanel.vue';
import ResponsePanel from '../components/ResponsePanel.vue';
import { useRequestsStore } from '../store/requests';

const requestsStore = useRequestsStore();

const isLoading = ref(false);
const responseData = ref(null);
const currentLoadedRequest = ref(null);

onMounted(() => {
  requestsStore.fetchSavedRequests();
});

const loadRequest = (req) => {
  currentLoadedRequest.value = { ...req };
};

const deleteRequest = async (id) => {
  if (confirm("Are you sure you want to delete this saved request?")) {
    await requestsStore.deleteRequest(id);
  }
};

const handleSaveRequest = async (requestData) => {
  try {
    await requestsStore.saveRequest(requestData);
  } catch (error) {
    alert("Failed to save request. Ensure you are logged in.");
  }
};

const handleRequest = async (requestConfig) => {
  isLoading.value = true;
  responseData.value = null;

  try {
    const res = await axios.post('/api/proxy', {
      url: requestConfig.url,
      method: requestConfig.method,
      headers: requestConfig.headers,
      body: requestConfig.body
    });
    
    responseData.value = res.data;
  } catch (error) {
    if (error.response && error.response.data) {
      responseData.value = error.response.data;
    } else {
      responseData.value = {
        status: 0,
        headers: {},
        body: 'Network error or proxy unreachable',
        time_ms: 0
      };
    }
  } finally {
    isLoading.value = false;
  }
};
</script>

<style scoped>
.dashboard-layout {
  display: flex;
  height: calc(100vh - 60px);
}

.sidebar {
  width: 260px;
  background-color: var(--bg-color);
  border-right: 1px solid var(--border-color);
  display: flex;
  flex-direction: column;
}

.sidebar-header {
  padding: 16px;
  border-bottom: 1px solid var(--border-color);
  background-color: var(--panel-bg);
}
.sidebar-header h3 {
  margin: 0;
  font-size: 13px;
  text-transform: uppercase;
  color: var(--text-secondary);
  letter-spacing: 0.5px;
}

.sidebar-content {
  flex: 1;
  overflow-y: auto;
}

.saved-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.saved-item {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border-color);
  transition: background-color 0.2s;
}
.saved-item:hover {
  background-color: var(--panel-bg);
}

.method-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
  margin-right: 8px;
  display: inline-block;
  vertical-align: middle;
}
.method-badge.get { color: #3fb950; background: rgba(63, 185, 80, 0.15); }
.method-badge.post { color: #58a6ff; background: rgba(88, 166, 255, 0.15); }
.method-badge.put { color: #d29922; background: rgba(210, 153, 34, 0.15); }
.method-badge.patch { color: #d29922; background: rgba(210, 153, 34, 0.15); }
.method-badge.delete { color: #f85149; background: rgba(248, 81, 73, 0.15); }

.req-name {
  font-size: 13px;
  color: var(--text-primary);
  vertical-align: middle;
}

.delete-icon {
  background: none;
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
  padding: 4px;
  opacity: 0;
  transition: opacity 0.2s, color 0.2s;
}
.saved-item:hover .delete-icon {
  opacity: 1;
}
.delete-icon:hover {
  color: #f85149;
}

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

.app-main {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.panel-container {
  flex: 1;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--border-color);
  min-width: 0; /* Prevent flex overflow */
}

.panel-container:last-child {
  border-right: none;
}

@media (max-width: 768px) {
  .app-main {
    flex-direction: column;
  }
  .panel-container {
    border-right: none;
    border-bottom: 1px solid var(--border-color);
  }
}
</style>
