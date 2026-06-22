<template>
  <div class="home-page">
    <!-- Hero Section -->
    <section class="hero">
      <div class="hero-content">
        <h1>Test APIs Without the Hassle</h1>
      </div>
    </section>

    <!-- API Tester Demo -->
    <section class="demo" id="tester">
      <div class="demo-container">
        <p class="tester-subtitle">The simplest way to test, debug, and document your APIs. No setup required.</p>
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
              placeholder='{"Content-Type": "application/json"}'
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
      </div>
    </section>

    <!-- Features Section -->
    <section class="features">
      <h2>Everything You Need</h2>
      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
          </div>
          <h3>Lightning Fast</h3>
          <p>Send requests and get responses instantly. No more waiting around for your API tests.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <h3>Secure & Private</h3>
          <p>Your API keys and credentials stay private. We never store sensitive data on our servers.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
          </div>
          <h3>Save Requests</h3>
          <p>Save your API requests for quick access later. Build a library of your most-used endpoints.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
          </div>
          <h3>Response Timing</h3>
          <p>Track exactly how long your API takes to respond. Identify performance bottlenecks.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          </div>
          <h3>Headers & Body</h3>
          <p>Full support for custom headers, JSON bodies, and all HTTP methods.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="2" fill="none"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <h3>Team Collaboration</h3>
          <p>Built for individuals and teams. Share your testing workflow with colleagues.</p>
        </div>
      </div>
    </section>

    <!-- Pricing Section -->
    <section class="pricing">
      <h2>Simple, Transparent Pricing</h2>
      <div class="pricing-grid">
        <div class="pricing-card">
          <h3>Free</h3>
          <div class="price">$0<span>/month</span></div>
          <ul class="pricing-features">
            <li>✓ Unlimited API requests</li>
            <li>✓ Save up to 10 requests</li>
            <li>✓ Basic headers & body support</li>
            <li>✓ Response timing</li>
          </ul>
          <router-link to="/register" class="btn btn-secondary btn-block">Get Started</router-link>
        </div>
        <div class="pricing-card featured">
          <div class="featured-badge">Most Popular</div>
          <h3>Pro</h3>
          <div class="price">$12<span>/month</span></div>
          <ul class="pricing-features">
            <li>✓ Everything in Free</li>
            <li>✓ Unlimited saved requests</li>
            <li>✓ Team sharing</li>
            <li>✓ Priority support</li>
            <li>✓ Advanced auth headers</li>
          </ul>
          <router-link to="/register" class="btn btn-primary btn-block">Start Free Trial</router-link>
        </div>
        <div class="pricing-card">
          <h3>Enterprise</h3>
          <div class="price">Custom</div>
          <ul class="pricing-features">
            <li>✓ Everything in Pro</li>
            <li>✓ Custom integrations</li>
            <li>✓ Dedicated support</li>
            <li>✓ SSO & SAML</li>
            <li>✓ SLA guarantee</li>
          </ul>
          <router-link to="/register" class="btn btn-secondary btn-block">Contact Sales</router-link>
        </div>
      </div>
    </section>

    <!-- Testimonials -->
    <section class="testimonials">
      <h2>Loved by Developers</h2>
      <div class="testimonials-grid">
        <div class="testimonial-card">
          <p>"Spi made API testing so much easier. I use it daily and couldn't imagine going back to Postman for quick tests."</p>
          <div class="testimonial-author">
            <div class="author-avatar">JD</div>
            <div>
              <strong>Jane Doe</strong>
              <span>Senior Developer at TechCorp</span>
            </div>
          </div>
        </div>
        <div class="testimonial-card">
          <p>"The simplicity is what sets Spi apart. No account needed for basic testing - just open and start testing."</p>
          <div class="testimonial-author">
            <div class="author-avatar">MS</div>
            <div>
              <strong>Mike Smith</strong>
              <span>Freelance Developer</span>
            </div>
          </div>
        </div>
        <div class="testimonial-card">
          <p>"Finally, an API tester that just works. Clean interface, fast responses, and the team features are fantastic."</p>
          <div class="testimonial-author">
            <div class="author-avatar">SA</div>
            <div>
              <strong>Sarah Anderson</strong>
              <span>CTO at StartupXYZ</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA Section -->
    <section class="cta">
      <h2>Ready to Simplify Your API Testing?</h2>
      <p>Join thousands of developers who use Spi daily. Get started in seconds.</p>
      <router-link to="/register" class="btn btn-primary btn-large">Create Free Account</router-link>
    </section>

    <!-- Footer -->
    <footer class="home-footer">
      <div class="footer-content">
        <div class="footer-brand">
          <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <span>Spi</span>
        </div>
        <div class="footer-links">
          <a href="#">About</a>
          <a href="#">Documentation</a>
          <a href="#">Pricing</a>
          <a href="#">Blog</a>
          <a href="#">Contact</a>
        </div>
        <div class="footer-social">
          <a href="#" aria-label="GitHub">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
          </a>
          <a href="#" aria-label="Twitter">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
          </a>
        </div>
      </div>
      <div class="footer-bottom">
        <p>&copy; 2026 Spi. All rights reserved.</p>
      </div>
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
  min-height: 100%;
  background-color: var(--bg-color);
  color: var(--text-primary);
  overflow-y: auto;
}

/* Hero Section */
.hero {
  padding: 60px 24px 40px;
  text-align: center;
  background: linear-gradient(180deg, var(--panel-bg) 0%, var(--bg-color) 100%);
}

.hero-content {
  max-width: 700px;
  margin: 0 auto;
}

.hero h1 {
  font-size: 56px;
  font-weight: 700;
  margin: 0 0 20px 0;
  letter-spacing: -1px;
  background: linear-gradient(135deg, var(--accent-color), #58a6ff);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.tester-subtitle {
  font-size: 18px;
  color: var(--text-secondary);
  line-height: 1.6;
  margin: 0 0 24px 0;
  text-align: center;
}

.hero-actions {
  display: flex;
  gap: 16px;
  justify-content: center;
  flex-wrap: wrap;
}

.hero-note {
  font-size: 14px;
  color: var(--text-secondary);
  margin: 16px 0 0 0;
}

.hero-cta {
  display: inline-block;
  padding: 16px 32px;
  background: var(--accent-color);
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  text-decoration: none;
  border-radius: 8px;
  transition: all 0.2s;
}

.hero-cta:hover {
  background: #4a9eff;
  transform: translateY(-2px);
}

/* Demo Section */
.demo {
  padding: 80px 24px;
  background: var(--bg-color);
}

.demo-container {
  max-width: 800px;
  margin: 0 auto;
}

.demo h2 {
  font-size: 32px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 12px 0;
}

.demo-subtitle {
  font-size: 16px;
  color: var(--text-secondary);
  text-align: center;
  margin: 0 0 32px 0;
}

.tester-container {
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

.status-success { color: #3fb950; background: rgba(63, 185, 80, 0.15); }
.status-client-error { color: #d29922; background: rgba(210, 153, 34, 0.15); }
.status-server-error { color: #f85149; background: rgba(248, 81, 73, 0.15); }
.status-default { color: var(--text-secondary); background: rgba(139, 148, 158, 0.15); }

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

/* Features Section */
.features {
  padding: 100px 24px;
  background: var(--panel-bg);
  border-top: 1px solid var(--border-color);
  border-bottom: 1px solid var(--border-color);
}

.features h2 {
  font-size: 36px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 48px 0;
}

.features-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 32px;
  max-width: 1200px;
  margin: 0 auto;
}

.feature-card {
  padding: 32px;
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
  font-size: 20px;
  font-weight: 600;
  margin: 0 0 12px 0;
}

.feature-card p {
  font-size: 15px;
  color: var(--text-secondary);
  line-height: 1.6;
  margin: 0;
}

/* Pricing Section */
.pricing {
  padding: 100px 24px;
  background: var(--bg-color);
}

.pricing h2 {
  font-size: 36px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 48px 0;
}

.pricing-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 24px;
  max-width: 1000px;
  margin: 0 auto;
}

.pricing-card {
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 32px;
  position: relative;
}

.pricing-card.featured {
  border-color: var(--accent-color);
  box-shadow: 0 0 0 1px var(--accent-color);
}

.featured-badge {
  position: absolute;
  top: -12px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--accent-color);
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  padding: 4px 12px;
  border-radius: 12px;
}

.pricing-card h3 {
  font-size: 20px;
  font-weight: 600;
  margin: 0 0 16px 0;
}

.price {
  font-size: 42px;
  font-weight: 700;
  margin: 0 0 24px 0;
}

.price span {
  font-size: 16px;
  font-weight: 400;
  color: var(--text-secondary);
}

.pricing-features {
  list-style: none;
  padding: 0;
  margin: 0 0 32px 0;
}

.pricing-features li {
  font-size: 14px;
  color: var(--text-secondary);
  padding: 8px 0;
  border-bottom: 1px solid var(--border-color);
}

.pricing-features li:last-child {
  border-bottom: none;
}

/* Testimonials Section */
.testimonials {
  padding: 100px 24px;
  background: var(--panel-bg);
  border-top: 1px solid var(--border-color);
  border-bottom: 1px solid var(--border-color);
}

.testimonials h2 {
  font-size: 36px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 48px 0;
}

.testimonials-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 24px;
  max-width: 1200px;
  margin: 0 auto;
}

.testimonial-card {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 32px;
}

.testimonial-card p {
  font-size: 16px;
  line-height: 1.6;
  color: var(--text-primary);
  margin: 0 0 24px 0;
  font-style: italic;
}

.testimonial-author {
  display: flex;
  align-items: center;
  gap: 12px;
}

.author-avatar {
  width: 44px;
  height: 44px;
  background: var(--accent-color);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 600;
  font-size: 14px;
}

.testimonial-author strong {
  display: block;
  font-size: 14px;
  font-weight: 600;
}

.testimonial-author span {
  font-size: 13px;
  color: var(--text-secondary);
}

/* CTA Section */
.cta {
  padding: 100px 24px;
  text-align: center;
  background: linear-gradient(180deg, var(--bg-color) 0%, var(--panel-bg) 100%);
}

.cta h2 {
  font-size: 36px;
  font-weight: 600;
  margin: 0 0 16px 0;
}

.cta p {
  font-size: 18px;
  color: var(--text-secondary);
  margin: 0 0 32px 0;
}

/* Footer */
.home-footer {
  padding: 48px 24px 24px;
  background: var(--panel-bg);
  border-top: 1px solid var(--border-color);
}

.footer-content {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 24px;
  padding-bottom: 32px;
  border-bottom: 1px solid var(--border-color);
}

.footer-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--accent-color);
  font-weight: 600;
  font-size: 18px;
}

.footer-links {
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
}

.footer-links a {
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 14px;
  transition: color 0.2s;
}

.footer-links a:hover {
  color: var(--text-primary);
}

.footer-social {
  display: flex;
  gap: 16px;
}

.footer-social a {
  color: var(--text-secondary);
  transition: color 0.2s;
}

.footer-social a:hover {
  color: var(--accent-color);
}

.footer-bottom {
  max-width: 1200px;
  margin: 0 auto;
  padding-top: 24px;
  text-align: center;
}

.footer-bottom p {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 0;
}

/* Buttons */
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

.btn-block {
  display: block;
  text-align: center;
  width: 100%;
}

/* Responsive */
@media (max-width: 768px) {
  .hero h1 {
    font-size: 36px;
  }
  
  .hero-subtitle {
    font-size: 16px;
  }
  
  .hero-actions {
    flex-direction: column;
    align-items: center;
  }
  
  .features h2,
  .pricing h2,
  .testimonials h2,
  .cta h2,
  .demo h2 {
    font-size: 28px;
  }
  
  .tester-row {
    flex-direction: column;
  }
  
  .method-select,
  .send-btn {
    width: 100%;
  }
  
  .footer-content {
    flex-direction: column;
    text-align: center;
  }
  
  .footer-links {
    justify-content: center;
  }
}
</style>