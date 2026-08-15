<template>
  <div class="panel">
    <div class="panel-header flex items-center justify-between">
      <div class="flex items-center gap-4">
        <h2>Request</h2>
        <button class="secondary text-sm" @click="openSave" :disabled="isLoading || !url">Save Request</button>
      </div>
      <button class="primary flex items-center gap-2" @click="send" :disabled="isLoading" title="Send (⌘/Ctrl + Enter)">
        <svg v-if="!isLoading" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        <span v-else class="loader"></span>
        Send
      </button>
    </div>

    <!-- Inline save row (replaces the native prompt) -->
    <div v-if="showSave" class="save-row flex items-center gap-2">
      <input
        ref="saveInput"
        v-model="saveName"
        class="input-field w-full"
        placeholder="Name this request..."
        @keyup.enter="confirmSave"
        @keyup.esc="showSave = false"
      />
      <button class="primary text-sm" @click="confirmSave" :disabled="!saveName.trim()">Save</button>
      <button class="secondary text-sm" @click="showSave = false">Cancel</button>
    </div>
    
    <div class="panel-content">
      <div class="url-bar flex gap-2">
        <select class="input-field protocol-select" v-model="protocol">
          <option value="rest">REST</option>
          <option value="mcp">MCP</option>
          <option value="a2a">A2A</option>
          <option value="grpc">gRPC</option>
          <option value="mqtt">MQTT</option>
          <option value="amqp">AMQP</option>
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
        <select v-else-if="protocol === 'a2a'" class="input-field method-select" v-model="a2aMethod">
          <option value="agent-card">agent-card</option>
          <option value="message/send">message/send</option>
          <option value="tasks/get">tasks/get</option>
          <option value="tasks/cancel">tasks/cancel</option>
        </select>
        <select v-else-if="protocol === 'mqtt'" class="input-field method-select" v-model="mqttAction">
          <option value="publish">publish</option>
          <option value="subscribe">subscribe</option>
          <option value="publish_subscribe">pub + sub</option>
        </select>
        <select v-else-if="protocol === 'amqp'" class="input-field method-select" v-model="amqpAction">
          <option value="publish">publish</option>
          <option value="get">get</option>
          <option value="publish_get">pub + get</option>
        </select>
        <input
          type="text"
          class="input-field w-full"
          :placeholder="urlPlaceholder"
          v-model="url"
          @keyup.enter="send"
        />
      </div>

      <div v-if="protocol === 'mcp' && activeTools && activeTools.length" class="mcp-toolbar flex gap-2 items-center mt-4">
        <span class="text-secondary text-sm">Active tools:</span>
        <select class="input-field method-select" v-model="selectedActiveTool" @change="applyActiveTool">
          <option value="" disabled>Pick a synced tool...</option>
          <option v-for="t in activeTools" :key="t.id" :value="t.id">{{ t.name }} ({{ t.provider }})</option>
        </select>
      </div>

      <div v-if="protocol === 'mcp' && activePrompts && activePrompts.length" class="mcp-toolbar flex gap-2 items-center mt-4">
        <span class="text-secondary text-sm">Active prompts:</span>
        <select class="input-field method-select" v-model="selectedActivePrompt" @change="applyActivePrompt">
          <option value="" disabled>Pick a synced prompt...</option>
          <option v-for="p in activePrompts" :key="p.id" :value="p.id">{{ p.name }} ({{ p.provider }})</option>
        </select>
      </div>

      <div v-if="protocol === 'mcp' && activeResources && activeResources.length" class="mcp-toolbar flex gap-2 items-center mt-4">
        <span class="text-secondary text-sm">Active resources:</span>
        <select class="input-field method-select" v-model="selectedActiveResource" @change="applyActiveResource">
          <option value="" disabled>Pick a synced resource...</option>
          <option v-for="r in activeResources" :key="r.id" :value="r.id">{{ r.name }} ({{ r.provider }})</option>
        </select>
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

      <!-- gRPC / MQTT / AMQP connection + protocol options -->
      <div v-if="isBrokerProtocol" class="proto-config mt-4">
        <div class="proto-grid">
          <label class="proto-field">
            <span>Port</span>
            <input type="number" class="input-field" v-model="port" :placeholder="String(defaultPort)" />
          </label>
          <label class="proto-field checkbox">
            <input type="checkbox" v-model="tls" />
            <span>TLS{{ protocol === 'grpc' ? ' (HTTP/2)' : '' }}</span>
          </label>
          <label v-if="tls" class="proto-field checkbox">
            <input type="checkbox" v-model="tlsVerify" />
            <span>Verify certificate</span>
          </label>
        </div>

        <div v-if="protocol === 'grpc'" class="proto-grid mt-2">
          <label class="proto-field wide">
            <span>Service / Method</span>
            <input type="text" class="input-field" v-model="grpcMethod" placeholder="package.Service/Method" />
          </label>
        </div>

        <div v-if="protocol === 'mqtt'" class="proto-grid mt-2">
          <label class="proto-field wide">
            <span>Topic</span>
            <input type="text" class="input-field" v-model="mqttTopic" placeholder="sensors/temperature" />
          </label>
          <label class="proto-field">
            <span>QoS</span>
            <select class="input-field" v-model="mqttQos">
              <option value="0">0</option>
              <option value="1">1</option>
              <option value="2">2</option>
            </select>
          </label>
          <label class="proto-field checkbox">
            <input type="checkbox" v-model="mqttRetain" />
            <span>Retain</span>
          </label>
        </div>

        <div v-if="protocol === 'amqp'" class="proto-grid mt-2">
          <label class="proto-field">
            <span>Exchange</span>
            <input type="text" class="input-field" v-model="amqpExchange" placeholder="(default)" />
          </label>
          <label class="proto-field">
            <span>Routing key</span>
            <input type="text" class="input-field" v-model="amqpRoutingKey" placeholder="jobs" />
          </label>
          <label class="proto-field">
            <span>Queue</span>
            <input type="text" class="input-field" v-model="amqpQueue" placeholder="jobs" />
          </label>
          <label class="proto-field">
            <span>Vhost</span>
            <input type="text" class="input-field" v-model="amqpVhost" placeholder="/" />
          </label>
        </div>

        <div v-if="protocol === 'mqtt' || protocol === 'amqp'" class="proto-grid mt-2">
          <label class="proto-field">
            <span>Username</span>
            <input type="text" class="input-field" v-model="brokerUsername" autocomplete="off" />
          </label>
          <label class="proto-field">
            <span>Password</span>
            <input type="password" class="input-field" v-model="brokerPassword" autocomplete="new-password" />
          </label>
        </div>

        <p class="proto-hint mt-2">{{ brokerHint }}</p>
      </div>

      <div class="tabs mt-6">
        <div class="tab-list flex gap-4">
          <button
            v-if="protocol !== 'mqtt' && protocol !== 'amqp'"
            :class="['tab', activeTab === 'headers' ? 'active' : '']"
            @click="activeTab = 'headers'"
          >{{ protocol === 'grpc' ? 'Metadata' : 'Headers' }}</button>
          <button
            :class="['tab', activeTab === 'body' ? 'active' : '']"
            @click="activeTab = 'body'"
          >{{ bodyTabLabel }}</button>
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
            @input="bodyError = ''"
          ></textarea>
          <p v-if="bodyError" class="body-error">{{ bodyError }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { useEnvironmentsStore } from '../store/environments';

const envStore = useEnvironmentsStore();

// Discovery calls hit the testers directly rather than going through the
// parent, so they carry the selected environment themselves — otherwise a URL
// like {{mcp_url}} could not be discovered.
const withEnv = (payload) =>
  envStore.selectedId ? { ...payload, environment_id: envStore.selectedId } : payload;

const props = defineProps({
  isLoading: Boolean,
  loadedRequest: Object,
  defaults: Object,
  activeTools: Array,
  activePrompts: Array,
  activeResources: Array
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
const selectedActiveTool = ref('');
const selectedActivePrompt = ref('');
const selectedActiveResource = ref('');
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

// gRPC / MQTT / AMQP shared connection + per-protocol options. For these the
// URL field holds a host (optionally host:port); the port/tls live here.
const port = ref('');
const tls = ref(false);
const tlsVerify = ref(true);
const brokerUsername = ref('');
const brokerPassword = ref('');
const grpcMethod = ref('');
const mqttAction = ref('publish');
const mqttTopic = ref('');
const mqttQos = ref('0');
const mqttRetain = ref(false);
const amqpAction = ref('publish');
const amqpExchange = ref('');
const amqpRoutingKey = ref('');
const amqpQueue = ref('');
const amqpVhost = ref('/');

const isBrokerProtocol = computed(() => ['grpc', 'mqtt', 'amqp'].includes(protocol.value));

const defaultPort = computed(() => {
  if (protocol.value === 'grpc') return tls.value ? 443 : 80;
  if (protocol.value === 'mqtt') return tls.value ? 8883 : 1883;
  if (protocol.value === 'amqp') return tls.value ? 5671 : 5672;
  return 443;
});

const bodyTabLabel = computed(() => {
  if (protocol.value === 'grpc') return 'Request';
  if (protocol.value === 'mqtt' || protocol.value === 'amqp') return 'Message';
  if (protocol.value === 'rest') return 'Body';
  return 'Params';
});

const brokerHint = computed(() => ({
  grpc: 'Unary calls only. Describe the request message as a JSON array of fields, e.g. [{"field":1,"type":"string","value":"world"}].',
  mqtt: 'Publishes and/or subscribes with a bounded timeout. The Message tab holds the payload to publish.',
  amqp: 'Publishes to an exchange/routing-key and/or pulls messages from a queue. The Message tab holds the body to publish.',
}[protocol.value] || ''));

// Split a "host" or "host:port" string; an explicit Port field wins.
const parseHostPort = () => {
  let host = (url.value || '').trim();
  // Strip any scheme the user pasted.
  host = host.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');
  host = host.replace(/\/.*$/, '');
  let parsedPort = null;
  const m = host.match(/^(.*):(\d+)$/);
  if (m) {
    host = m[1];
    parsedPort = parseInt(m[2], 10);
  }
  const explicit = port.value !== '' ? parseInt(port.value, 10) : null;
  return { host, port: explicit || parsedPort || defaultPort.value };
};

// Inline save (replaces window.prompt).
const showSave = ref(false);
const saveName = ref('');
const saveInput = ref(null);
// Inline JSON error (replaces alert()).
const bodyError = ref('');

// Send on Cmd/Ctrl+Enter from anywhere in the panel.
const onKeydown = (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && !props.isLoading) {
    e.preventDefault();
    send();
  }
};
onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));

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
  if (protocol.value === 'grpc') return 'grpc.example.com';
  if (protocol.value === 'mqtt') return 'broker.example.com';
  if (protocol.value === 'amqp') return 'rabbit.example.com';
  return 'https://apispi.com/api/gateway/tools';
});

const bodyPlaceholder = computed(() => {
  if (protocol.value === 'mcp') return '{\n  "name": "my-tool",\n  "arguments": {}\n}';
  if (protocol.value === 'a2a') return '{\n  "message": {"role": "user", "parts": [{"text": "Hello"}]}\n}';
  if (protocol.value === 'grpc') return '[\n  {"field": 1, "type": "string", "value": "world"}\n]';
  if (protocol.value === 'mqtt' || protocol.value === 'amqp') return '{"event": "ping"}';
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
    } else if (['grpc', 'mqtt', 'amqp'].includes(protocol.value)) {
      restoreBrokerRequest(newReq);
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
  // The Headers tab is hidden for MQTT/AMQP; fall back to the Message tab.
  if ((protocol.value === 'mqtt' || protocol.value === 'amqp') && activeTab.value === 'headers') {
    activeTab.value = 'body';
  }
});

// Restore a saved gRPC/MQTT/AMQP request from its stored payload (in params).
const restoreBrokerRequest = (newReq) => {
  const p = newReq.params || {};
  port.value = p.port != null ? String(p.port) : '';
  tls.value = !!p.tls;
  tlsVerify.value = p.tls_verify !== false;
  brokerUsername.value = p.username || '';
  brokerPassword.value = p.password || '';

  if (protocol.value === 'grpc') {
    grpcMethod.value = p.service_method || newReq.method || '';
    body.value = p.request ? JSON.stringify(p.request, null, 2) : '';
  } else if (protocol.value === 'mqtt') {
    mqttAction.value = p.action || 'publish';
    mqttTopic.value = p.topic || '';
    mqttQos.value = String(p.qos ?? 0);
    mqttRetain.value = !!p.retain;
    body.value = p.message || '';
  } else {
    amqpAction.value = p.action || 'publish';
    amqpExchange.value = p.exchange || '';
    amqpRoutingKey.value = p.routing_key || '';
    amqpQueue.value = p.queue || '';
    amqpVhost.value = p.vhost || '/';
    body.value = p.message || '';
  }
  activeTab.value = 'body';
};

// Build the exact API payload for a broker-style protocol (gRPC/MQTT/AMQP).
const buildBrokerPayload = () => {
  const { host, port: resolvedPort } = parseHostPort();
  const base = { host, port: resolvedPort, tls: tls.value, tls_verify: tlsVerify.value };

  if (protocol.value === 'grpc') {
    let request = [];
    if (body.value.trim()) request = JSON.parse(body.value);
    return { ...base, service_method: grpcMethod.value, request, metadata: collectHeaders() };
  }
  if (protocol.value === 'mqtt') {
    return {
      ...base, action: mqttAction.value, topic: mqttTopic.value,
      message: body.value, qos: Number(mqttQos.value), retain: mqttRetain.value,
      username: brokerUsername.value || null, password: brokerPassword.value || null,
    };
  }
  return {
    ...base, action: amqpAction.value, exchange: amqpExchange.value,
    routing_key: amqpRoutingKey.value, queue: amqpQueue.value, vhost: amqpVhost.value || '/',
    message: body.value, username: brokerUsername.value || null, password: brokerPassword.value || null,
  };
};

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
    const res = await axios.post('/api/mcp/test', withEnv({
      url: url.value,
      method: 'tools/list',
      params: {},
      headers: collectHeaders()
    }));

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

// Pick a synced, active tool: set the connector URL and a call template from
// its stored schema. Auth is not injected — add it in the Headers tab.
const applyActiveTool = () => {
  const tool = (props.activeTools || []).find(t => t.id === selectedActiveTool.value);
  if (!tool) return;

  url.value = tool.endpoint;
  mcpMethod.value = 'tools/call';
  body.value = JSON.stringify({
    name: tool.name,
    arguments: defaultForSchema(tool.input_schema) || {}
  }, null, 2);
  activeTab.value = 'body';
};

// Pick a synced, active prompt: set the connector URL and a prompts/get
// template from its declared arguments (an array of {name, ...}).
const applyActivePrompt = () => {
  const prompt = (props.activePrompts || []).find(p => p.id === selectedActivePrompt.value);
  if (!prompt) return;

  const args = {};
  (Array.isArray(prompt.arguments) ? prompt.arguments : []).forEach(a => {
    if (a && a.name) args[a.name] = '';
  });

  url.value = prompt.endpoint;
  mcpMethod.value = 'prompts/get';
  body.value = JSON.stringify({ name: prompt.name, arguments: args }, null, 2);
  activeTab.value = 'body';
};

// Pick a synced, active resource: set the connector URL and a resources/read
// template using its stored URI.
const applyActiveResource = () => {
  const resource = (props.activeResources || []).find(r => r.id === selectedActiveResource.value);
  if (!resource) return;

  url.value = resource.endpoint;
  mcpMethod.value = 'resources/read';
  body.value = JSON.stringify({ uri: resource.uri }, null, 2);
  activeTab.value = 'body';
};

const fetchAgentCard = async () => {
  if (!url.value) return;

  isFetchingCard.value = true;
  cardError.value = '';
  agentCard.value = null;

  try {
    const res = await axios.post('/api/a2a/test', withEnv({
      url: url.value,
      method: 'agent-card',
      params: {},
      headers: collectHeaders()
    }));

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

  if (isBrokerProtocol.value) {
    let payload;
    try {
      payload = buildBrokerPayload();
    } catch (e) {
      bodyError.value = protocol.value === 'grpc'
        ? 'Request must be a JSON array of field descriptors.'
        : 'Message must be valid.';
      activeTab.value = 'body';
      return;
    }
    if (protocol.value === 'grpc' && !grpcMethod.value.trim()) {
      bodyError.value = 'Set a Service / Method (package.Service/Method).';
      return;
    }
    if (protocol.value === 'mqtt' && !mqttTopic.value.trim()) {
      bodyError.value = 'Set an MQTT topic.';
      return;
    }
    emit('send-request', { protocol: protocol.value, payload });
    return;
  }

  if (protocol.value === 'mcp' || protocol.value === 'a2a') {
    let params = {};
    if (body.value.trim()) {
      try {
        params = JSON.parse(body.value);
      } catch (e) {
        bodyError.value = 'Params must be valid JSON.';
        activeTab.value = 'body';
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

const openSave = () => {
  if (!url.value) return;
  saveName.value = '';
  showSave.value = true;
  nextTick(() => saveInput.value?.focus());
};

const confirmSave = () => {
  const name = saveName.value.trim();
  if (!name) return;

  if (isBrokerProtocol.value) {
    let payload;
    try {
      payload = buildBrokerPayload();
    } catch (e) {
      bodyError.value = 'Message/request is not valid.';
      activeTab.value = 'body';
      showSave.value = false;
      return;
    }
    emit('save-request', {
      name,
      protocol: protocol.value,
      method: protocol.value === 'grpc' ? grpcMethod.value : payload.action,
      url: url.value,
      headers: protocol.value === 'grpc' ? collectHeaders() : {},
      params: payload,
    });
    showSave.value = false;
    return;
  }

  if (protocol.value === 'mcp' || protocol.value === 'a2a') {
    let params = {};
    if (body.value.trim()) {
      try {
        params = JSON.parse(body.value);
      } catch (e) {
        bodyError.value = 'Params must be valid JSON.';
        activeTab.value = 'body';
        showSave.value = false;
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
    showSave.value = false;
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
  showSave.value = false;
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

.save-row {
  padding: 12px 24px;
  border-bottom: 1px solid var(--border-color);
  background: rgba(88, 166, 255, 0.05);
}

.body-error {
  color: #f85149;
  font-size: 13px;
  margin-top: 8px;
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

.proto-config {
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 14px 16px;
  background: rgba(88, 166, 255, 0.04);
}

.proto-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 16px;
  align-items: flex-end;
}

.proto-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: var(--text-secondary);
}

.proto-field.wide {
  flex: 1;
  min-width: 220px;
}

.proto-field .input-field {
  min-width: 90px;
}

.proto-field.checkbox {
  flex-direction: row;
  align-items: center;
  gap: 6px;
  padding-bottom: 8px;
}

.proto-field.checkbox input {
  width: auto;
}

.proto-hint {
  font-size: 12px;
  color: var(--text-secondary);
  line-height: 1.5;
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
