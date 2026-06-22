<template>
  <div class="home-page">
    <section class="hero">
      <div class="hero-content">
        <div class="logo-icon">
          <svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        </div>
        <h1>Spi</h1>
        <p class="hero-subtitle">Test any API endpoint instantly. No account required.</p>
      </div>
    </section>

    <section class="api-tester">
      <div class="tester-container">
        <div class="tester-row">
          <select v-model="testMethod" class="method-select">
            <option value="GET">GET</option>
            <option value="POST">POST</option>
            <option value="PUT">PUT</option>
            <option value="PATCH">PATCH</option>
            <option value="DELETE">DELETE</option>
          </select>
          <input 
            v-model="testUrl" 
            type="text" 
            class="url-input" 
            placeholder="https://api.example.com/endpoint"
            @keyup.enter="sendTestRequest"
          />
          <button @click="sendTestRequest" class="btn btn-primary send-btn" :disabled="isTesting">
            {{ isTesting ? 'Sending...' : 'Send' }}
          </button>
        </div>
        
        <div class="headers-row">
          <label class="headers-label">Headers (JSON)</label>
          <textarea 
            v-model="testHeaders" 
            class="headers-input" 
            placeholder='{"Content-Type": "application/json", "Authorization": "Bearer token"}'
            rows="2"
          ></textarea>
        </div>
        
        <div class="body-row">
          <label class="body-label">Body (JSON)</label>
          <textarea 
            v-model="testBody" 
            class="body-input" 
            placeholder='{"key": "value"}'
            rows="4"
          ></textarea>
        </div>
        
        <div v-if="testResponse" class="response-container">
          <div class="response-header">
            <span class="response-status" :class="getStatusClass(testResponse.status)">
              {{ testResponse.status }} {{ getStatusText(testResponse.status) }}
            </span>
            <span class="response-time">{{ testResponse.time_ms }}ms</span>
          </div>
          <div class="response-body">
            <pre>{{ formatJson(testResponse.body) }}</pre>
          </div>
        </div>
        
        <div v-if="testError" class="error-container">
          <p>{{ testError }}</p>
        </div>
      </div>
    </section>

    <footer class="home-footer">
      <p>&copy; 2026 Spi</p>
    </footer>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const testMethod = ref('GET');
const testUrl = ref('');
const testHeaders = ref('');
const testBody = ref('');
const testResponse = ref(null);
const testError = ref('');
const isTesting = ref(false);

const sendTestRequest = async () => {
  if (!testUrl.value) {
    testError.value = 'Please enter a URL';
    return;
  }

  isTesting.value = true;
  testResponse.value = null;
  testError.value = '';

  let headers = {};
  if (testHeaders.value.trim()) {
    try {
      headers = JSON.parse(testHeaders.value);
    } catch {
      testError.value = 'Invalid JSON in headers';
      isTesting.value = false;
      return;
    }
  }

  let body = undefined;
  if (['POST', 'PUT', 'PATCH'].includes(testMethod.value) && testBody.value.trim()) {
    try {
      body = JSON.parse(testBody.value);
    } catch {
      testError.value = 'Invalid JSON in body';
      isTesting.value = false;
      return;
    }
  }

  try {
    const res = await axios.post('/api/proxy', {
      url: testUrl.value,
      method: testMethod.value,
      headers,
      body
    });
    testResponse.value = res.data;
  } catch (error) {
    if (error.response && error.response.data) {
      testResponse.value = error.response.data;
    } else {
      testError.value = 'Network error or proxy unreachable';
    }
  } finally {
    isTesting.value = false;
  }
};

const getStatusClass = (status) => {
  if (status >= 200 && status < 300) return 'status-success';
  if (status >= 400 && status < 500) return 'status-client-error';
  if (status >= 500) return 'status-server-error';
  return 'status-default';
};

const getStatusText = (status) => {
  const texts = {
    200: 'OK', 201: 'Created', 204: 'No Content',
    400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found',
    500: 'Internal Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable'
  };
  return texts[status] || '';
};

const formatJson = (str) => {
  try {
    const parsed = typeof str === 'string' ? JSON.parse(str) : str;
    return JSON.stringify(parsed, null, 2);
  } catch {
    return str;
  }
};
</script>

<style scoped>
.home-page {
  min-height: calc(100vh - 60px);
  background-color: var(--bg-color);
  color: var(--text-primary);
  display: flex;
  flex-direction: column;
}

.hero {
  padding: 48px 24px 32px;
  text-align: center;
}

.hero-content {
  max-width: 500px;
  margin: 0 auto;
}

.logo-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 80px;
  height: 80px;
  background: var(--accent-color);
  border-radius: 20px;
  margin-bottom: 16px;
  color: #fff;
}

.hero h1 {
  font-size: 40px;
  font-weight: 700;
  margin: 0 0 8px 0;
  letter-spacing: -1px;
  color: var(--text-primary);
}

.hero-subtitle {
  font-size: 16px;
  color: var(--text-secondary);
  margin: 0;
}

.api-tester {
  flex: 1;
  padding: 0 24px 32px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
}

.tester-container {
  width: 100%;
  max-width: 700px;
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 24px;
}

.tester-row {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.method-select {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--accent-color);
  cursor: pointer;
  min-width: 100px;
}

.url-input {
  flex: 1;
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  color: var(--text-primary);
}

.url-input:focus {
  outline: none;
  border-color: var(--accent-color);
}

.send-btn {
  padding: 10px 24px;
  min-width: 100px;
}

.send-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none;
}

.headers-row,
.body-row {
  margin-bottom: 16px;
}

.headers-label,
.body-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-secondary);
  text-transform: uppercase;
  margin-bottom: 8px;
}

.headers-input,
.body-input {
  width: 100%;
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 13px;
  font-family: 'Courier New', monospace;
  color: var(--text-primary);
  resize: vertical;
}

.headers-input:focus,
.body-input:focus {
  outline: none;
  border-color: var(--accent-color);
}

.response-container {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  overflow: hidden;
}

.response-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: rgba(0, 0, 0, 0.2);
  border-bottom: 1px solid var(--border-color);
}

.response-status {
  font-size: 14px;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 4px;
}

.status-success {
  color: #3fb950;
  background: rgba(63, 185, 80, 0.15);
}

.status-client-error {
  color: #d29922;
  background: rgba(210, 153, 34, 0.15);
}

.status-server-error {
  color: #f85149;
  background: rgba(248, 81, 73, 0.15);
}

.status-default {
  color: var(--text-secondary);
  background: rgba(139, 148, 158, 0.15);
}

.response-time {
  font-size: 13px;
  color: var(--text-secondary);
}

.response-body {
  padding: 16px;
  max-height: 400px;
  overflow-y: auto;
}

.response-body pre {
  margin: 0;
  font-size: 13px;
  font-family: 'Courier New', monospace;
  color: var(--text-primary);
  white-space: pre-wrap;
  word-break: break-word;
}

.error-container {
  background: rgba(248, 81, 73, 0.1);
  border: 1px solid rgba(248, 81, 73, 0.3);
  border-radius: 8px;
  padding: 16px;
}

.error-container p {
  margin: 0;
  color: #f85149;
  font-size: 14px;
}

.home-footer {
  padding: 24px;
  text-align: center;
  border-top: 1px solid var(--border-color);
}

.home-footer p {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 0;
}

.btn {
  display: inline-block;
  padding: 12px 24px;
  border-radius: 8px;
  font-size: 15px;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s;
  cursor: pointer;
  border: none;
}

.btn-primary {
  background: var(--accent-color);
  color: #fff;
}

.btn-primary:hover {
  background: #4a9eff;
  transform: translateY(-2px);
}

.btn-primary:disabled:hover {
  transform: none;
}

@media (max-width: 768px) {
  .hero h1 {
    font-size: 32px;
  }
  
  .tester-row {
    flex-direction: column;
  }
  
  .method-select,
  .send-btn {
    width: 100%;
  }
}
</style>