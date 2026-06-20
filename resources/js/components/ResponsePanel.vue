<template>
  <div class="panel">
    <div class="panel-header flex items-center justify-between">
      <h2>Response</h2>
      
      <div v-if="response && !isLoading" class="response-meta flex items-center gap-4">
        <div class="status-badge" :class="statusClass">
          {{ response.status }} {{ statusText }}
        </div>
        <div class="meta-item">
          <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          {{ response.time_ms }} ms
        </div>
        <div class="meta-item" v-if="sizeFormatted">
          <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
          {{ sizeFormatted }}
        </div>
      </div>
    </div>
    
    <div class="panel-content">
      <div v-if="isLoading" class="loading-state flex flex-col items-center justify-center h-full">
        <div class="pulse-ring"></div>
        <p class="mt-4 text-secondary">Sending request...</p>
      </div>
      
      <div v-else-if="!response" class="empty-state flex flex-col items-center justify-center h-full">
        <svg viewBox="0 0 24 24" width="48" height="48" stroke="var(--border-color)" stroke-width="1" fill="none"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
        <p class="mt-4 text-secondary">Enter a URL and click Send to get a response</p>
      </div>

      <div v-else class="response-tabs">
        <div class="tab-list flex gap-4">
          <button 
            :class="['tab', activeTab === 'body' ? 'active' : '']" 
            @click="activeTab = 'body'"
          >Response Body</button>
          <button 
            :class="['tab', activeTab === 'headers' ? 'active' : '']" 
            @click="activeTab = 'headers'"
          >Response Headers <span class="count">{{ headerCount }}</span></button>
          <button 
            :class="['tab', activeTab === 'reqBody' ? 'active' : '']" 
            @click="activeTab = 'reqBody'"
          >Request Body</button>
          <button 
            :class="['tab', activeTab === 'reqHeaders' ? 'active' : '']" 
            @click="activeTab = 'reqHeaders'"
          >Request Headers</button>
        </div>

        <div class="tab-content mt-4" v-show="activeTab === 'body'">
          <pre class="code-block" v-html="formattedBody"></pre>
        </div>

        <div class="tab-content mt-4" v-show="activeTab === 'headers'">
          <div class="headers-grid">
            <template v-for="(val, key) in response.headers" :key="key">
              <div class="header-name">{{ key }}</div>
              <div class="header-value">{{ Array.isArray(val) ? val.join(', ') : val }}</div>
            </template>
          </div>
        </div>

        <div class="tab-content mt-4" v-show="activeTab === 'reqBody'">
          <pre v-if="response.request_payload" class="code-block" v-html="formattedReqBody"></pre>
          <p v-else class="text-secondary">No request body sent.</p>
        </div>

        <div class="tab-content mt-4" v-show="activeTab === 'reqHeaders'">
          <div class="headers-grid" v-if="response.request_headers && Object.keys(response.request_headers).length > 0">
            <template v-for="(val, key) in response.request_headers" :key="key">
              <div class="header-name">{{ key }}</div>
              <div class="header-value">{{ Array.isArray(val) ? val.join(', ') : val }}</div>
            </template>
          </div>
          <p v-else class="text-secondary">No custom request headers sent.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  response: Object,
  isLoading: Boolean
});

const activeTab = ref('body');

const statusClass = computed(() => {
  if (!props.response) return '';
  const s = props.response.status;
  if (s >= 200 && s < 300) return 'status-success';
  if (s >= 300 && s < 400) return 'status-redirect';
  if (s >= 400 && s < 500) return 'status-client-error';
  if (s >= 500) return 'status-server-error';
  return '';
});

const statusText = computed(() => {
  if (!props.response) return '';
  const s = props.response.status;
  // Common ones
  const map = {
    200: 'OK', 201: 'Created', 204: 'No Content',
    301: 'Moved Permanently', 302: 'Found',
    400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found',
    500: 'Internal Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable'
  };
  return map[s] || '';
});

const headerCount = computed(() => {
  if (!props.response || !props.response.headers) return 0;
  return Object.keys(props.response.headers).length;
});

const sizeFormatted = computed(() => {
  if (!props.response || !props.response.body) return null;
  const bytes = new Blob([props.response.body]).size;
  if (bytes < 1024) return bytes + ' B';
  return (bytes / 1024).toFixed(2) + ' KB';
});

const formattedBody = computed(() => {
  if (!props.response) return '';
  let bodyStr = props.response.body;
  if (typeof bodyStr === 'object') {
    bodyStr = JSON.stringify(bodyStr, null, 2);
  } else {
    try {
      bodyStr = JSON.stringify(JSON.parse(bodyStr), null, 2);
    } catch(e) {
      // not JSON
    }
  }
  
  // Simple syntax highlighting
  bodyStr = bodyStr.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return bodyStr.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
    let cls = 'number';
    if (/^"/.test(match)) {
        if (/:$/.test(match)) {
            cls = 'key';
        } else {
            cls = 'string';
        }
    } else if (/true|false/.test(match)) {
        cls = 'boolean';
    } else if (/null/.test(match)) {
        cls = 'null';
    }
    return '<span class="' + cls + '">' + match + '</span>';
  });
});

const formattedReqBody = computed(() => {
  if (!props.response || !props.response.request_payload) return '';
  let bodyStr = props.response.request_payload;
  if (typeof bodyStr === 'object') {
    bodyStr = JSON.stringify(bodyStr, null, 2);
  } else {
    try {
      bodyStr = JSON.stringify(JSON.parse(bodyStr), null, 2);
    } catch(e) {
      // not JSON
    }
  }
  
  bodyStr = bodyStr.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return bodyStr.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
    let cls = 'number';
    if (/^"/.test(match)) {
        if (/:$/.test(match)) {
            cls = 'key';
        } else {
            cls = 'string';
        }
    } else if (/true|false/.test(match)) {
        cls = 'boolean';
    } else if (/null/.test(match)) {
        cls = 'null';
    }
    return '<span class="' + cls + '">' + match + '</span>';
  });
});
</script>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  background-color: #0d1117; /* slightly darker for response */
}

.panel-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border-color);
  background-color: var(--panel-bg);
  height: 65px; /* Match request panel header */
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

.response-meta {
  font-size: 13px;
  color: var(--text-secondary);
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.status-badge {
  padding: 4px 8px;
  border-radius: 4px;
  font-weight: 600;
  color: #fff;
}

.status-success { background-color: rgba(35, 134, 54, 0.2); color: #3fb950; }
.status-redirect { background-color: rgba(210, 153, 34, 0.2); color: #e3b341; }
.status-client-error { background-color: rgba(248, 81, 73, 0.2); color: #ff7b72; }
.status-server-error { background-color: rgba(248, 81, 73, 0.4); color: #ff7b72; }

.empty-state {
  opacity: 0.5;
}

.text-secondary {
  color: var(--text-secondary);
}

.mt-4 { margin-top: 16px; }

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

.tab:hover { color: var(--text-primary); }
.tab.active { color: var(--text-primary); font-weight: 600; }
.tab.active::after {
  content: '';
  position: absolute;
  bottom: -9px;
  left: 0;
  right: 0;
  height: 2px;
  background-color: var(--accent-color);
}

.count {
  background-color: var(--border-color);
  color: var(--text-primary);
  padding: 2px 6px;
  border-radius: 10px;
  font-size: 11px;
  margin-left: 4px;
}

.code-block {
  background-color: #010409;
  padding: 16px;
  border-radius: 6px;
  border: 1px solid var(--border-color);
  font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
  font-size: 13px;
  overflow-x: auto;
  white-space: pre-wrap;
  word-wrap: break-word;
}

/* Syntax highlighting */
:deep(.string) { color: #a5d6ff; }
:deep(.number) { color: #79c0ff; }
:deep(.boolean) { color: #ff7b72; }
:deep(.null) { color: #ff7b72; }
:deep(.key) { color: #7ee787; font-weight: 500; }

.headers-grid {
  display: grid;
  grid-template-columns: minmax(150px, auto) 1fr;
  gap: 12px 24px;
  font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
  font-size: 13px;
  background-color: #010409;
  padding: 16px;
  border-radius: 6px;
  border: 1px solid var(--border-color);
}

.header-name {
  color: var(--accent-color);
  font-weight: 600;
  text-align: right;
  white-space: nowrap;
}

.header-value {
  color: var(--text-primary);
  word-break: break-all;
}

/* Loading animation */
.pulse-ring {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  border: 3px solid var(--accent-color);
  border-top-color: transparent;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
