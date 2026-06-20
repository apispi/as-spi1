<template>
  <div class="panel">
    <div class="panel-header flex items-center justify-between">
      <h2>Request</h2>
      <button class="primary flex items-center gap-2" @click="send" :disabled="isLoading">
        <svg v-if="!isLoading" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        <span v-else class="loader"></span>
        Send
      </button>
    </div>
    
    <div class="panel-content">
      <div class="url-bar flex gap-2">
        <select class="input-field method-select" v-model="method">
          <option>GET</option>
          <option>POST</option>
          <option>PUT</option>
          <option>PATCH</option>
          <option>DELETE</option>
        </select>
        <input 
          type="text" 
          class="input-field w-full" 
          placeholder="https://apispi.com/api/gateway/tools"
          v-model="url"
          @keyup.enter="send"
        />
      </div>

      <div class="tabs mt-6">
        <div class="tab-list flex gap-4">
          <button 
            :class="['tab', activeTab === 'headers' ? 'active' : '']" 
            @click="activeTab = 'headers'"
          >Headers</button>
          <button 
            :class="['tab', activeTab === 'body' ? 'active' : '']" 
            @click="activeTab = 'body'"
          >Body</button>
        </div>

        <div class="tab-content mt-4" v-show="activeTab === 'headers'">
          <div v-for="(header, index) in headers" :key="index" class="header-row flex gap-2 mt-2">
            <input type="text" class="input-field w-full" placeholder="Key (e.g. Authorization)" v-model="header.key" />
            <input type="text" class="input-field w-full" placeholder="Value (e.g. Bearer token)" v-model="header.value" />
            <button class="secondary delete-btn" @click="removeHeader(index)">
              <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
          </div>
          <button class="secondary mt-4 text-sm" @click="addHeader">+ Add Header</button>
        </div>

        <div class="tab-content mt-4" v-show="activeTab === 'body'">
          <textarea 
            class="input-field w-full body-editor" 
            placeholder="{&#10;  &quot;key&quot;: &quot;value&quot;&#10;}"
            v-model="body"
          ></textarea>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  isLoading: Boolean
});

const emit = defineEmits(['send-request']);

const method = ref('GET');
const url = ref('https://apispi.com/api/gateway/tools');
const activeTab = ref('headers');

const headers = ref([
  { key: 'Accept', value: 'application/json' }
]);

const body = ref('');

const addHeader = () => {
  headers.value.push({ key: '', value: '' });
};

const removeHeader = (index) => {
  headers.value.splice(index, 1);
};

const send = () => {
  if (!url.value) return;

  // Process headers
  const headerObj = {};
  headers.value.forEach(h => {
    if (h.key && h.key.trim()) {
      headerObj[h.key.trim()] = h.value;
    }
  });

  // Automatically add Content-Type for JSON body if missing
  if (body.value && !Object.keys(headerObj).some(k => k.toLowerCase() === 'content-type')) {
    try {
      JSON.parse(body.value);
      headerObj['Content-Type'] = 'application/json';
    } catch(e) {
      // not JSON
    }
  }

  emit('send-request', {
    method: method.value,
    url: url.value,
    headers: headerObj,
    body: ['GET', 'HEAD'].includes(method.value) ? null : body.value
  });
};
</script>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  background-color: var(--panel-bg);
}

.panel-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border-color);
}

.panel-header h2 {
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--text-secondary);
}

.panel-content {
  padding: 24px;
  flex: 1;
  overflow-y: auto;
}

.method-select {
  width: 110px;
  font-weight: 600;
  color: var(--accent-color);
}

.mt-6 { margin-top: 24px; }
.mt-4 { margin-top: 16px; }
.mt-2 { margin-top: 8px; }

.tab-list {
  border-bottom: 1px solid var(--border-color);
  padding-bottom: 8px;
}

.tab {
  background: none;
  border: none;
  color: var(--text-secondary);
  font-size: 14px;
  padding: 4px 8px;
  position: relative;
}

.tab:hover {
  color: var(--text-primary);
}

.tab.active {
  color: var(--text-primary);
  font-weight: 600;
}

.tab.active::after {
  content: '';
  position: absolute;
  bottom: -9px;
  left: 0;
  right: 0;
  height: 2px;
  background-color: var(--accent-color);
}

.delete-btn {
  padding: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.delete-btn:hover {
  color: var(--error-color);
  border-color: var(--error-color);
}

.body-editor {
  min-height: 200px;
  font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
  font-size: 13px;
  resize: vertical;
  background-color: #010409;
}

.loader {
  border: 2px solid rgba(255,255,255,0.3);
  border-top: 2px solid #fff;
  border-radius: 50%;
  width: 14px;
  height: 14px;
  animation: spin 1s linear infinite;
  display: inline-block;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
