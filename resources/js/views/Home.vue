<template>
  <div class="home-page">
    <section class="hero">
      <div class="hero-content">
        <h1>Test APIs with Confidence</h1>
        <p class="hero-subtitle">ApiSpi is a powerful, intuitive API testing platform that helps developers debug, test, and document their APIs with ease.</p>
        <div class="hero-actions">
          <router-link to="/register" class="btn btn-primary">Get Started Free</router-link>
          <router-link to="/login" class="btn btn-secondary">Sign In</router-link>
        </div>
      </div>
    </section>

    <section class="features">
      <h2>Everything You Need for API Testing</h2>
      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
          </div>
          <h3>Lightning Fast</h3>
          <p>Send requests and get responses instantly. No more waiting around for your API tests to complete.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <h3>Secure & Private</h3>
          <p>Your API keys and credentials stay on your device. We never store sensitive data on our servers.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
          </div>
          <h3>Save & Organize</h3>
          <p>Save your API requests for quick access later. Keep your most-used endpoints just a click away.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
          </div>
          <h3>Response Timing</h3>
          <p>Track exactly how long your API takes to respond. Identify performance bottlenecks with millisecond precision.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          </div>
          <h3>Headers & Body</h3>
          <p>Full support for custom headers, JSON bodies, and all HTTP methods. Test any API scenario you need.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <h3>Team Ready</h3>
          <p>Built for individuals and teams. Share your testing workflow with colleagues and collaborate effectively.</p>
        </div>
      </div>
    </section>

    <section class="how-it-works">
      <h2>How It Works</h2>
      <div class="steps">
        <div class="step">
          <div class="step-number">1</div>
          <h3>Create Your Account</h3>
          <p>Sign up in seconds with just your email and password. No credit card required.</p>
        </div>
        <div class="step">
          <div class="step-number">2</div>
          <h3>Start Testing</h3>
          <p>Enter any API endpoint, add headers and body content, then hit send.</p>
        </div>
        <div class="step">
          <div class="step-number">3</div>
          <h3>Save & Repeat</h3>
          <p>Save your requests for quick access. Build a library of your most-used API calls.</p>
        </div>
      </div>
    </section>

    <section class="api-tester">
      <h2>Try It Now</h2>
      <p class="tester-subtitle">Test any API endpoint right from your browser. No account required.</p>
      
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
      
      <div class="tester-cta">
        <p>Want to save your API requests? <router-link to="/register" class="link">Create a free account</router-link></p>
      </div>
    </section>

    <section class="cta">
      <h2>Ready to Streamline Your API Testing?</h2>
      <p>Join thousands of developers who trust ApiSpi for their API testing needs.</p>
      <router-link to="/register" class="btn btn-primary btn-large">Create Free Account</router-link>
    </section>

    <footer class="home-footer">
      <p>&copy; 2026 ApiSpi. Built for developers, by developers.</p>
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
}

.hero {
  padding: 80px 24px;
  text-align: center;
  background: linear-gradient(180deg, var(--panel-bg) 0%, var(--bg-color) 100%);
  border-bottom: 1px solid var(--border-color);
}

.hero-content {
  max-width: 700px;
  margin: 0 auto;
}

.hero h1 {
  font-size: 48px;
  font-weight: 700;
  margin: 0 0 20px 0;
  letter-spacing: -1px;
  background: linear-gradient(135deg, var(--accent-color), #58a6ff);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.hero-subtitle {
  font-size: 18px;
  color: var(--text-secondary);
  line-height: 1.6;
  margin: 0 0 32px 0;
}

.hero-actions {
  display: flex;
  gap: 16px;
  justify-content: center;
}

.features {
  padding: 80px 24px;
  max-width: 1200px;
  margin: 0 auto;
}

.features h2 {
  font-size: 32px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 48px 0;
}

.features-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 24px;
}

.feature-card {
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 32px;
  transition: transform 0.2s, box-shadow 0.2s;
}

.feature-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}

.feature-icon {
  width: 56px;
  height: 56px;
  background: rgba(88, 166, 255, 0.1);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20px;
  color: var(--accent-color);
}

.feature-card h3 {
  font-size: 18px;
  font-weight: 600;
  margin: 0 0 12px 0;
}

.feature-card p {
  font-size: 14px;
  color: var(--text-secondary);
  line-height: 1.6;
  margin: 0;
}

.how-it-works {
  padding: 80px 24px;
  background: var(--panel-bg);
  border-top: 1px solid var(--border-color);
  border-bottom: 1px solid var(--border-color);
}

.how-it-works h2 {
  font-size: 32px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 48px 0;
}

.steps {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 32px;
  max-width: 1000px;
  margin: 0 auto;
}

.step {
  text-align: center;
}

.step-number {
  width: 48px;
  height: 48px;
  background: var(--accent-color);
  color: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  font-weight: 700;
  margin: 0 auto 20px auto;
}

.step h3 {
  font-size: 18px;
  font-weight: 600;
  margin: 0 0 12px 0;
}

.step p {
  font-size: 14px;
  color: var(--text-secondary);
  line-height: 1.6;
  margin: 0;
}

.cta {
  padding: 80px 24px;
  text-align: center;
}

.cta h2 {
  font-size: 32px;
  font-weight: 600;
  margin: 0 0 16px 0;
}

.cta p {
  font-size: 16px;
  color: var(--text-secondary);
  margin: 0 0 32px 0;
}

.home-footer {
  padding: 32px 24px;
  text-align: center;
  border-top: 1px solid var(--border-color);
}

.home-footer p {
  font-size: 14px;
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

.btn-secondary {
  background: var(--panel-bg);
  color: var(--text-primary);
  border: 1px solid var(--border-color);
}

.btn-secondary:hover {
  background: var(--border-color);
}

.btn-large {
  padding: 16px 32px;
  font-size: 16px;
}

.api-tester {
  padding: 80px 24px;
  background: var(--bg-color);
}

.api-tester h2 {
  font-size: 32px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 12px 0;
}

.tester-subtitle {
  font-size: 16px;
  color: var(--text-secondary);
  text-align: center;
  margin: 0 0 32px 0;
}

.tester-container {
  max-width: 800px;
  margin: 0 auto;
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

.tester-cta {
  text-align: center;
  margin-top: 24px;
}

.tester-cta p {
  font-size: 14px;
  color: var(--text-secondary);
  margin: 0;
}

.link {
  color: var(--accent-color);
  text-decoration: none;
  font-weight: 600;
}

.link:hover {
  text-decoration: underline;
}

@media (max-width: 768px) {
  .hero h1 {
    font-size: 32px;
  }
  
  .hero-subtitle {
    font-size: 16px;
  }
  
  .hero-actions {
    flex-direction: column;
    align-items: center;
  }
  
  .features h2,
  .how-it-works h2,
  .cta h2,
  .api-tester h2 {
    font-size: 24px;
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
