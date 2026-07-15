<template>
  <div class="panel">
    <div class="panel-header flex items-center justify-between">
      <div class="flex items-center gap-4">
        <h2>Request</h2>
        <button class="secondary text-sm" @click="save" :disabled="isLoading || !url">Save Request</button>
      </div>
      <button class="primary flex items-center gap-2" @click="send" :disabled="isLoading">
        <svg v-if="!isLoading" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        <span v-else class="loader"></span>
        Send
      </button>
    </div>
    
    <div class="panel-content">
      <div class="url-bar flex gap-2">
        <select class="input-field protocol-select" v-model="protocol">
          <option value="rest">REST</option>
          <option value="mcp">MCP</option>
          <option value="a2a">A2A</option>
        </select>
        <select v-if="protocol === 'rest'" class="input-field method-select" v-model="method">
          <option>GET</option>
          <option>POST</option>
          <option>PUT</option>
          <option>PATCH</option>
          <option>DELETE</option>
        </select>
        <select v-else-if="protocol === 'mcp'" class="input-field method-select" v-model="mcpMethod">
          <option value="initialize">initialize</option>
          <option value="tools/list">tools/list</option>
          <option value="tools/call">tools/call</option>
          <option value="resources/list">resources/list</option>
          <option value="resources/read">resources/read</option>
          <option value="prompts/list">prompts/list</option>
          <option value="ping">ping</option>
        </select>
        <select v-else class="input-field method-select" v-model="a2aMethod">
          <option value="agent-card">agent-card</option>
          <option value="message/send">message/send</option>
          <option value="tasks/get">tasks/get</option>
          <option value="tasks/cancel">tasks/cancel</option>
        </select>
        <input
          type="text"
          class="input-field w-full"
          :placeholder="urlPlaceholder"
          v-model="url"
          @keyup.enter="send"
        />
      </div>

      <div v-if="protocol === 'mcp'" class="mcp-toolbar flex gap-2 items-center mt-4">
        <button class="secondary text-sm" @click="discoverTools" :disabled="isDiscovering || !url">
          {{ isDiscovering ? 'Discovering...' : 'Discover Tools' }}
        </button>
        <select
          v-if="discoveredTools.length"
          class="input-field method-select"
          v-model="selectedToolName"
          @change="applyToolTemplate"
        >
          <option value="" disabled>Select a tool...</option>
          <option v-for="tool in discoveredTools" :key="tool.name" :value="tool.name">{{ tool.name }}</option>
        </select>
        <span v-if="discoveredTools.length" class="text-secondary text-sm">{{ discoveredTools.length }} tool(s) found</span>
        <span v-if="discoverError" class="discover-error text-sm">{{ discoverError }}</span>
      </div>

      <div v-if="protocol === 'a2a'" class="mcp-toolbar flex gap-2 items-center mt-4">
        <button class="secondary text-sm" @click="fetchAgentCard" :disabled="isFetchingCard || !url">
          {{ isFetchingCard ? 'Fetching...' : 'Fetch Agent Card' }}
        </button>
        <span v-if="agentCard" class="text-secondary text-sm">
          {{ agentCard.name || 'Unnamed agent' }}
          <template v-if="agentCard.skills?.length"> · {{ agentCard.skills.length }} skill(s)</template>
          <template v-if="agentCard.capabilities?.streaming"> · streaming</template>
        </span>
        <button v-if="agentCard" class="secondary text-sm" @click="applyMessageTemplate">Fill message/send</button>
        <span v-if="cardError" class="discover-error text-sm">{{ cardError }}</span>
      </div>

      <div v-if="protocol === 'a2a' && agentCard?.skills?.length" class="agent-skills mt-4">
        <span
          v-for="skill in agentCard.skills"
          :key="skill.id || skill.name"
          class="skill-chip"
          :title="skill.description || ''"
        >{{ skill.name || skill.id }}</span>
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
          >{{ protocol === 'rest' ? 'Body' : 'Params' }}</button>
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
            :placeholder="bodyPlaceholder"
            v-model="body"
          ></textarea>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
  isLoading: Boolean,
  loadedRequest: Object,
  defaults: Object
});

const emit = defineEmits(['send-request', 'save-request']);

let defaultsApplied = false;

const protocol = ref('rest');
const method = ref('GET');
const mcpMethod = ref('initialize');
const a2aMethod = ref('agent-card');
const url = ref('https://apispi.com/api/gateway/tools');
const discoveredTools = ref([]);
const selectedToolName = ref('');
const isDiscovering = ref(false);
const discoverError = ref('');
const agentCard = ref(null);
const isFetchingCard = ref(false);
const cardError = ref('');
const activeTab = ref('headers');

const headers = ref([
  { key: 'Accept', value: 'application/json' }
]);

const body = ref('');

// Apply the user's saved defaults once, when they arrive, and only if the
// user hasn't already loaded a specific request into the panel.
watch(() => props.defaults, (prefs) => {
  if (!prefs || defaultsApplied || props.loadedRequest) return;
  defaultsApplied = true;
  protocol.value = prefs.default_protocol || 'rest';
  if (prefs.default_method) method.value = prefs.default_method;
}, { immediate: true });

const urlPlaceholder = computed(() => {
  if (protocol.value === 'mcp') return 'https://api.example.com/mcp';
  if (protocol.value === 'a2a') return 'https://agents.example.com/a2a';
  return 'https://apispi.com/api/gateway/tools';
});

const bodyPlaceholder = computed(() => {
  if (protocol.value === 'mcp') return '{\n  "name": "my-tool",\n  "arguments": {}\n}';
  if (protocol.value === 'a2a') return '{\n  "message": {"role": "user", "parts": [{"text": "Hello"}]}\n}';
  return '{\n  "key": "value"\n}';
});

watch(() => props.loadedRequest, (newReq) => {
  if (newReq) {
    protocol.value = newReq.protocol || 'rest';
    url.value = newReq.url;

    if (protocol.value === 'mcp') {
      mcpMethod.value = newReq.method || 'initialize';
      body.value = newReq.params ? JSON.stringify(newReq.params, null, 2) : '';
    } else if (protocol.value === 'a2a') {
      a2aMethod.value = newReq.method || 'agent-card';
      body.value = newReq.params ? JSON.stringify(newReq.params, null, 2) : '';
    } else {
      method.value = newReq.method;
      body.value = newReq.body || '';
    }

    headers.value = [];
    if (newReq.headers) {
      Object.entries(newReq.headers).forEach(([key, value]) => {
        headers.value.push({ key, value: Array.isArray(value) ? value.join(', ') : value });
      });
    }
    if (headers.value.length === 0) {
      headers.value.push({ key: '', value: '' });
    }
  }
});

const addHeader = () => {
  headers.value.push({ key: '', value: '' });
};

const removeHeader = (index) => {
  headers.value.splice(index, 1);
};

const collectHeaders = () => {
  const headerObj = {};
  headers.value.forEach(h => {
    if (h.key && h.key.trim()) {
      headerObj[h.key.trim()] = h.value;
    }
  });
  return headerObj;
};

watch(protocol, () => {
  discoveredTools.value = [];
  selectedToolName.value = '';
  discoverError.value = '';
  agentCard.value = null;
  cardError.value = '';
});

const defaultForSchema = (schema) => {
  if (!schema) return null;
  if ('default' in schema) return schema.default;

  switch (schema.type) {
    case 'string': return '';
    case 'number':
    case 'integer': return 0;
    case 'boolean': return false;
    case 'array': return [];
    case 'object': {
      const obj = {};
      Object.entries(schema.properties || {}).forEach(([key, propSchema]) => {
        obj[key] = defaultForSchema(propSchema);
      });
      return obj;
    }
    default: return null;
  }
};

const discoverTools = async () => {
  if (!url.value) return;

  isDiscovering.value = true;
  discoverError.value = '';
  discoveredTools.value = [];

  try {
    const res = await axios.post('/api/mcp/test', {
      url: url.value,
      method: 'tools/list',
      params: {},
      headers: collectHeaders()
    });

    if (res.data.status !== 200) {
      discoverError.value = res.data.body || 'Failed to discover tools';
      return;
    }

    const result = JSON.parse(res.data.body);
    discoveredTools.value = result.tools || [];

    if (discoveredTools.value.length === 0) {
      discoverError.value = 'Server returned no tools';
    }
  } catch (error) {
    discoverError.value = error.response?.data?.body || error.message || 'Failed to discover tools';
  } finally {
    isDiscovering.value = false;
  }
};

const applyToolTemplate = () => {
  const tool = discoveredTools.value.find(t => t.name === selectedToolName.value);
  if (!tool) return;

  mcpMethod.value = 'tools/call';
  body.value = JSON.stringify({
    name: tool.name,
    arguments: defaultForSchema(tool.inputSchema) || {}
  }, null, 2);
  activeTab.value = 'body';
};

const fetchAgentCard = async () => {
  if (!url.value) return;

  isFetchingCard.value = true;
  cardError.value = '';
  agentCard.value = null;

  try {
    const res = await axios.post('/api/a2a/test', {
      url: url.value,
      method: 'agent-card',
      params: {},
      headers: collectHeaders()
    });

    if (res.data.status !== 200) {
      cardError.value = res.data.body || 'Failed to fetch agent card';
      return;
    }

    agentCard.value = JSON.parse(res.data.body);
  } catch (error) {
    cardError.value = error.response?.data?.body || error.message || 'Failed to fetch agent card';
  } finally {
    isFetchingCard.value = false;
  }
};

const applyMessageTemplate = () => {
  a2aMethod.value = 'message/send';
  body.value = JSON.stringify({
    message: {
      role: 'user',
      parts: [{ kind: 'text', text: '' }],
      messageId: crypto.randomUUID()
    }
  }, null, 2);
  activeTab.value = 'body';
};

const send = () => {
  if (!url.value) return;

  if (protocol.value === 'mcp' || protocol.value === 'a2a') {
    let params = {};
    if (body.value.trim()) {
      try {
        params = JSON.parse(body.value);
      } catch (e) {
        alert('Params must be valid JSON');
        return;
      }
    }

    emit('send-request', {
      protocol: protocol.value,
      protocolMethod: protocol.value === 'mcp' ? mcpMethod.value : a2aMethod.value,
      url: url.value,
      headers: collectHeaders(),
      params
    });
    return;
  }

  const headerObj = collectHeaders();

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
    protocol: 'rest',
    method: method.value,
    url: url.value,
    headers: headerObj,
    body: ['GET', 'HEAD'].includes(method.value) ? null : body.value
  });
};

const save = () => {
  if (!url.value) return;
  const name = prompt("Enter a name for this saved request:");
  if (!name) return;

  if (protocol.value === 'mcp' || protocol.value === 'a2a') {
    let params = {};
    if (body.value.trim()) {
      try {
        params = JSON.parse(body.value);
      } catch (e) {
        alert('Params must be valid JSON');
        return;
      }
    }

    emit('save-request', {
      name,
      protocol: protocol.value,
      method: protocol.value === 'mcp' ? mcpMethod.value : a2aMethod.value,
      url: url.value,
      headers: collectHeaders(),
      params
    });
    return;
  }

  emit('save-request', {
    name,
    protocol: 'rest',
    method: method.value,
    url: url.value,
    headers: collectHeaders(),
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

.protocol-select {
  width: 90px;
  font-weight: 600;
  color: var(--accent-color);
}

.mcp-toolbar {
  flex-wrap: wrap;
}

.discover-error {
  color: var(--error-color);
}

.agent-skills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.skill-chip {
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  color: #f85149;
  background: rgba(248, 81, 73, 0.12);
  border: 1px solid rgba(248, 81, 73, 0.3);
  cursor: default;
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
