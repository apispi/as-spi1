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
        <div class="tester-container">
          <!-- Sample Tests Row -->
          <div class="sample-tests-row">
            <span class="sample-label">Try samples:</span>
            <button @click="loadSample('rest')" class="sample-btn rest">REST</button>
            <button @click="loadSample('graphql')" class="sample-btn graphql">GraphQL</button>
            <button @click="loadSample('websocket')" class="sample-btn websocket">WebSocket</button>
            <button @click="loadSample('grpc')" class="sample-btn grpc">gRPC</button>
            <button @click="loadSample('mqtt')" class="sample-btn mqtt">MQTT</button>
            <button @click="loadSample('soap')" class="sample-btn soap">SOAP</button>
          </div>

          <!-- Protocol Selector -->
          <div class="protocol-row">
            <select v-model="selectedProtocol" class="protocol-select">
              <option value="rest">REST</option>
              <option value="graphql">GraphQL</option>
              <option value="websocket">WebSocket</option>
              <option value="grpc">gRPC</option>
              <option value="mqtt">MQTT</option>
              <option value="amqp">AMQP</option>
              <option value="soap">SOAP</option>
              <option value="webhook">Webhook</option>
              <option value="mcp">MCP</option>
              <option value="a2a">A2A</option>
            </select>
            <select v-if="selectedProtocol === 'rest'" v-model="testMethod" class="method-select">
              <option value="GET">GET</option>
              <option value="POST">POST</option>
              <option value="PUT">PUT</option>
              <option value="PATCH">PATCH</option>
              <option value="DELETE">DELETE</option>
              <option value="HEAD">HEAD</option>
              <option value="OPTIONS">OPTIONS</option>
            </select>
            <select v-else-if="selectedProtocol === 'graphql'" v-model="graphqlOperation" class="method-select">
              <option value="query">Query</option>
              <option value="mutation">Mutation</option>
              <option value="subscription">Subscription</option>
            </select>
            <select v-else-if="selectedProtocol === 'grpc'" v-model="grpcCallType" class="method-select">
              <option value="unary">Unary</option>
              <option value="server-streaming">Server Streaming</option>
              <option value="client-streaming">Client Streaming</option>
              <option value="bidi-streaming">Bidi Streaming</option>
            </select>
            <input 
              v-model="testUrl" 
              type="text" 
              class="url-input" 
              :placeholder="getUrlPlaceholder()"
              @keyup.enter="sendTestRequest"
            />
            <button @click="sendTestRequest" class="btn btn-primary send-btn" :disabled="isTesting">
              {{ isTesting ? 'Connecting...' : 'Send' }}
            </button>
          </div>

          <!-- REST/GraphQL Headers -->
          <div v-if="['rest', 'graphql', 'soap', 'webhook', 'mcp', 'a2a'].includes(selectedProtocol)" class="headers-row">
            <label class="headers-label">Headers (JSON)</label>
            <textarea 
              v-model="testHeaders" 
              class="headers-input" 
              placeholder='{"Content-Type": "application/json"}'
              rows="2"
            ></textarea>
          </div>

          <!-- REST Body -->
          <div v-if="selectedProtocol === 'rest' && ['POST', 'PUT', 'PATCH'].includes(testMethod)" class="body-row">
            <label class="body-label">Body (JSON)</label>
            <textarea 
              v-model="testBody" 
              class="body-input" 
              placeholder='{"key": "value"}'
              rows="4"
            ></textarea>
          </div>

          <!-- GraphQL Body -->
          <div v-if="selectedProtocol === 'graphql'" class="body-row">
            <label class="body-label">{{ graphqlOperation === 'subscription' ? 'Subscription Query' : (graphqlOperation === 'mutation' ? 'Mutation' : 'Query') }}</label>
            <textarea 
              v-model="graphqlQuery" 
              class="body-input" 
              placeholder='query { users { id name email } }'
              rows="4"
            ></textarea>
          </div>

          <!-- GraphQL Variables -->
          <div v-if="selectedProtocol === 'graphql'" class="body-row">
            <label class="body-label">Variables (JSON, optional)</label>
            <textarea 
              v-model="graphqlVariables" 
              class="body-input" 
              placeholder='{"id": 1}'
              rows="2"
            ></textarea>
          </div>

          <!-- WebSocket Config -->
          <div v-if="selectedProtocol === 'websocket'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge ws">WebSocket</span>
              <span class="protocol-hint">Messages are sent and received in real-time</span>
            </div>
            <div class="body-row">
              <label class="body-label">Message to Send</label>
              <textarea 
                v-model="wsMessage" 
                class="body-input" 
                placeholder='{"event": "ping"}'
                rows="3"
              ></textarea>
            </div>
            <div class="ws-messages" v-if="wsMessages.length > 0">
              <label class="headers-label">Messages</label>
              <div class="ws-message-list">
                <div v-for="(msg, idx) in wsMessages" :key="idx" class="ws-message" :class="msg.type">
                  <span class="msg-type">{{ msg.type === 'sent' ? '→' : '←' }}</span>
                  <pre>{{ msg.data }}</pre>
                </div>
              </div>
            </div>
          </div>

          <!-- gRPC Config -->
          <div v-if="selectedProtocol === 'grpc'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge grpc">gRPC</span>
              <span class="protocol-hint">Google's high-performance RPC framework</span>
            </div>
            <div class="body-row">
              <label class="body-label">Proto Definition (optional)</label>
              <textarea 
                v-model="grpcProto" 
                class="body-input" 
                placeholder='syntax = "proto3"; service MyService { rpc MyMethod(Request) returns (Response); }'
                rows="4"
              ></textarea>
            </div>
            <div class="body-row">
              <label class="body-label">Request Message (JSON)</label>
              <textarea 
                v-model="grpcRequest" 
                class="body-input" 
                placeholder='{"name": "test"}'
                rows="3"
              ></textarea>
            </div>
          </div>

          <!-- MQTT Config -->
          <div v-if="selectedProtocol === 'mqtt'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge mqtt">MQTT</span>
              <span class="protocol-hint">Lightweight IoT messaging protocol</span>
            </div>
            <div class="body-row">
              <label class="body-label">Topic</label>
              <input v-model="mqttTopic" type="text" class="url-input" placeholder="sensors/temperature" />
            </div>
            <div class="body-row">
              <label class="body-label">QoS Level</label>
              <select v-model="mqttQos" class="method-select">
                <option value="0">0 - At most once</option>
                <option value="1">1 - At least once</option>
                <option value="2">2 - Exactly once</option>
              </select>
            </div>
            <div class="body-row">
              <label class="body-label">Message Payload (JSON)</label>
              <textarea v-model="mqttMessage" class="body-input" placeholder='{"temp": 25.5}' rows="2"></textarea>
            </div>
          </div>

          <!-- AMQP Config -->
          <div v-if="selectedProtocol === 'amqp'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge amqp">AMQP</span>
              <span class="protocol-hint">Advanced Message Queuing Protocol</span>
            </div>
            <div class="body-row">
              <label class="body-label">Exchange</label>
              <input v-model="amqpExchange" type="text" class="url-input" placeholder="my-exchange" />
            </div>
            <div class="body-row">
              <label class="body-label">Routing Key</label>
              <input v-model="amqpRoutingKey" type="text" class="url-input" placeholder="my.routing.key" />
            </div>
            <div class="body-row">
              <label class="body-label">Message Body (JSON)</label>
              <textarea v-model="amqpMessage" class="body-input" placeholder='{"data": "value"}' rows="2"></textarea>
            </div>
          </div>

          <!-- SOAP Config -->
          <div v-if="selectedProtocol === 'soap'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge soap">SOAP</span>
              <span class="protocol-hint">Simple Object Access Protocol</span>
            </div>
            <div class="body-row">
              <label class="body-label">SOAP Action</label>
              <input v-model="soapAction" type="text" class="url-input" placeholder="urn:my-action" />
            </div>
            <div class="body-row">
              <label class="body-label">SOAP Envelope (XML)</label>
              <textarea 
                v-model="soapEnvelope" 
                class="body-input" 
                placeholder='<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body></soap:Body></soap:Envelope>'
                rows="5"
              ></textarea>
            </div>
          </div>

          <!-- Webhook Config -->
          <div v-if="selectedProtocol === 'webhook'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge webhook">Webhook</span>
              <span class="protocol-hint">HTTP callback with signature verification</span>
            </div>
            <div class="body-row">
              <label class="body-label">Secret (for signature)</label>
              <input v-model="webhookSecret" type="text" class="url-input" placeholder="your-webhook-secret" />
            </div>
            <div class="body-row">
              <label class="body-label">Payload (JSON)</label>
              <textarea v-model="webhookPayload" class="body-input" placeholder='{"event": "user.created", "data": {}}' rows="3"></textarea>
            </div>
          </div>

          <!-- MCP Config -->
          <div v-if="selectedProtocol === 'mcp'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge mcp">MCP</span>
              <span class="protocol-hint">Model Context Protocol</span>
            </div>
            <div class="body-row">
              <label class="body-label">Method</label>
              <select v-model="mcpMethod" class="method-select">
                <option value="initialize">initialize</option>
                <option value="tools/list">tools/list</option>
                <option value="tools/call">tools/call</option>
                <option value="resources/list">resources/list</option>
                <option value="resources/read">resources/read</option>
                <option value="prompts/list">prompts/list</option>
              </select>
            </div>
            <div class="body-row">
              <label class="body-label">Params (JSON)</label>
              <textarea v-model="mcpParams" class="body-input" placeholder='{"name": "my-tool"}' rows="3"></textarea>
            </div>
          </div>

          <!-- A2A Config -->
          <div v-if="selectedProtocol === 'a2a'" class="protocol-section">
            <div class="protocol-info">
              <span class="protocol-badge a2a">A2A</span>
              <span class="protocol-hint">Agent-to-Agent Protocol</span>
            </div>
            <div class="body-row">
              <label class="body-label">Agent ID</label>
              <input v-model="a2aAgentId" type="text" class="url-input" placeholder="agent-123" />
            </div>
            <div class="body-row">
              <label class="body-label">Action</label>
              <select v-model="a2aAction" class="method-select">
                <option value="send_task">send_task</option>
                <option value="get_task">get_task</option>
                <option value="cancel_task">cancel_task</option>
                <option value="send_message">send_message</option>
              </select>
            </div>
            <div class="body-row">
              <label class="body-label">Payload (JSON)</label>
              <textarea v-model="a2aPayload" class="body-input" placeholder='{"task": "do something"}' rows="3"></textarea>
            </div>
          </div>

          <!-- Response Container -->
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
            <li>✓ REST & GraphQL</li>
            <li>✓ WebSocket testing</li>
            <li>✓ Save up to 10 requests</li>
            <li>✓ Basic headers & body support</li>
          </ul>
          <router-link to="/register" class="btn btn-secondary btn-block">Get Started</router-link>
        </div>
        <div class="pricing-card featured">
          <div class="featured-badge">Most Popular</div>
          <h3>Pro</h3>
          <div class="price">$12<span>/month</span></div>
          <ul class="pricing-features">
            <li>✓ All protocols</li>
            <li>✓ Unlimited saved requests</li>
            <li>✓ Team sharing</li>
            <li>✓ Priority support</li>
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

const selectedProtocol = ref('rest');
const testMethod = ref('GET');
const testUrl = ref('');
const testHeaders = ref('');
const testBody = ref('');
const testResponse = ref(null);
const testError = ref('');
const isTesting = ref(false);

// GraphQL
const graphqlOperation = ref('query');
const graphqlQuery = ref('');
const graphqlVariables = ref('');

// WebSocket
const wsMessage = ref('');
const wsMessages = ref([]);
let ws = null;

// gRPC
const grpcCallType = ref('unary');
const grpcProto = ref('');
const grpcRequest = ref('');

// MQTT
const mqttTopic = ref('');
const mqttQos = ref('0');
const mqttMessage = ref('');

// AMQP
const amqpExchange = ref('');
const amqpRoutingKey = ref('');
const amqpMessage = ref('');

// SOAP
const soapAction = ref('');
const soapEnvelope = ref('');

// Webhook
const webhookSecret = ref('');
const webhookPayload = ref('');

// MCP
const mcpMethod = ref('initialize');
const mcpParams = ref('');

// A2A
const a2aAgentId = ref('');
const a2aAction = ref('send_task');
const a2aPayload = ref('');

const getUrlPlaceholder = () => {
  const placeholders = {
    rest: 'https://api.example.com/endpoint',
    graphql: 'https://api.example.com/graphql',
    websocket: 'wss://api.example.com/ws',
    grpc: 'grpc://api.example.com:50051',
    mqtt: 'mqtt://broker.example.com:1883',
    amqp: 'amqp://broker.example.com:5672',
    soap: 'https://api.example.com/soap',
    webhook: 'https://your-server.com/webhook',
    mcp: 'https://api.example.com/mcp',
    a2a: 'https://agents.example.com/a2a'
  };
  return placeholders[selectedProtocol.value] || 'https://api.example.com';
};

const sendTestRequest = async () => {
  if (!testUrl.value) {
    testError.value = 'Please enter a URL';
    return;
  }

  isTesting.value = true;
  testResponse.value = null;
  testError.value = '';

  const startTime = Date.now();

  try {
    if (selectedProtocol.value === 'websocket') {
      await testWebSocket(startTime);
    } else if (selectedProtocol.value === 'grpc') {
      await testGrpc(startTime);
    } else if (selectedProtocol.value === 'mqtt') {
      await testMqtt(startTime);
    } else if (selectedProtocol.value === 'amqp') {
      await testAmqp(startTime);
    } else {
      await testHttpProtocol(startTime);
    }
  } catch (error) {
    testError.value = error.message || 'Request failed';
  } finally {
    isTesting.value = false;
  }
};

const testHttpProtocol = async (startTime) => {
  let headers = {};
  if (testHeaders.value.trim()) {
    try {
      headers = JSON.parse(testHeaders.value);
    } catch {
      testError.value = 'Invalid JSON in headers';
      return;
    }
  }

  let body = undefined;
  let method = testMethod.value;

  if (selectedProtocol.value === 'graphql') {
    method = 'POST';
    headers['Content-Type'] = 'application/json';
    body = {
      query: graphqlQuery.value,
      variables: graphqlVariables.value ? JSON.parse(graphqlVariables.value) : {}
    };
  } else if (selectedProtocol.value === 'soap') {
    method = 'POST';
    headers['Content-Type'] = 'text/xml';
    if (soapAction.value) {
      headers['SOAPAction'] = soapAction.value;
    }
    body = soapEnvelope.value;
  } else if (selectedProtocol.value === 'webhook') {
    method = 'POST';
    const payload = webhookPayload.value ? JSON.parse(webhookPayload.value) : {};
    if (webhookSecret.value) {
      const signature = btoa(webhookSecret.value + JSON.stringify(payload));
      headers['X-Webhook-Signature'] = signature;
    }
    body = payload;
  } else if (selectedProtocol.value === 'mcp') {
    method = 'POST';
    headers['Content-Type'] = 'application/json';
    body = {
      jsonrpc: '2.0',
      method: mcpMethod.value,
      params: mcpParams.value ? JSON.parse(mcpParams.value) : {},
      id: Date.now()
    };
  } else if (selectedProtocol.value === 'a2a') {
    method = 'POST';
    headers['Content-Type'] = 'application/json';
    body = {
      agent_id: a2aAgentId.value,
      action: a2aAction.value,
      payload: a2aPayload.value ? JSON.parse(a2aPayload.value) : {}
    };
  } else if (['POST', 'PUT', 'PATCH'].includes(testMethod.value)) {
    try {
      body = testBody.value ? JSON.parse(testBody.value) : {};
    } catch {
      testError.value = 'Invalid JSON in body';
      return;
    }
  }

  const res = await axios.post('/api/proxy', {
    url: testUrl.value,
    method,
    headers,
    body
  });
  
  testResponse.value = {
    status: res.data.status || 200,
    body: res.data.body,
    time_ms: Date.now() - startTime
  };
};

const testWebSocket = async (startTime) => {
  return new Promise((resolve, reject) => {
    try {
      ws = new WebSocket(testUrl.value);
      
      ws.onopen = () => {
        if (wsMessage.value) {
          ws.send(wsMessage.value);
          wsMessages.value.push({ type: 'sent', data: wsMessage.value });
        }
      };
      
      ws.onmessage = (event) => {
        wsMessages.value.push({ type: 'received', data: event.data });
      };
      
      ws.onerror = (error) => {
        testError.value = 'WebSocket connection failed';
        reject(error);
      };
      
      ws.onclose = () => {
        testResponse.value = {
          status: 101,
          body: { messages: wsMessages.value, count: wsMessages.value.length },
          time_ms: Date.now() - startTime
        };
        resolve();
      };
      
      // Timeout after 10 seconds
      setTimeout(() => {
        if (ws) {
          ws.close();
        }
      }, 10000);
    } catch (error) {
      reject(error);
    }
  });
};

const testGrpc = async (startTime) => {
  // gRPC would require a backend proxy - sending config for now
  const res = await axios.post('/api/proxy', {
    url: testUrl.value,
    method: 'POST',
    headers: { 'Content-Type': 'application/grpc' },
    body: {
      protocol: 'grpc',
      callType: grpcCallType.value,
      proto: grpcProto.value,
      request: grpcRequest.value ? JSON.parse(grpcRequest.value) : {}
    }
  });
  
  testResponse.value = {
    status: res.data.status || 200,
    body: res.data.body || { message: 'gRPC request configured', config: { callType: grpcCallType.value } },
    time_ms: Date.now() - startTime
  };
};

const testMqtt = async (startTime) => {
  const res = await axios.post('/api/proxy', {
    url: testUrl.value,
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: {
      protocol: 'mqtt',
      topic: mqttTopic.value,
      qos: parseInt(mqttQos.value),
      message: mqttMessage.value ? JSON.parse(mqttMessage.value) : {}
    }
  });
  
  testResponse.value = {
    status: res.data.status || 200,
    body: res.data.body || { message: 'MQTT message configured', topic: mqttTopic.value, qos: mqttQos.value },
    time_ms: Date.now() - startTime
  };
};

const testAmqp = async (startTime) => {
  const res = await axios.post('/api/proxy', {
    url: testUrl.value,
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: {
      protocol: 'amqp',
      exchange: amqpExchange.value,
      routingKey: amqpRoutingKey.value,
      message: amqpMessage.value ? JSON.parse(amqpMessage.value) : {}
    }
  });
  
  testResponse.value = {
    status: res.data.status || 200,
    body: res.data.body || { message: 'AMQP message configured', exchange: amqpExchange.value },
    time_ms: Date.now() - startTime
  };
};

const getStatusClass = (status) => {
  if (status >= 200 && status < 300) return 'status-success';
  if (status >= 400 && status < 500) return 'status-client-error';
  if (status >= 500) return 'status-server-error';
  return 'status-default';
};

const getStatusText = (status) => {
  const texts = {
    101: 'Switching Protocols',
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

const loadSample = (protocol) => {
  selectedProtocol.value = protocol;
  testResponse.value = null;
  testError.value = '';
  wsMessages.value = [];
  
  const samples = {
    rest: () => {
      testMethod.value = 'GET';
      testUrl.value = 'https://jsonplaceholder.typicode.com/posts/1';
      testHeaders.value = '';
      testBody.value = '';
    },
    graphql: () => {
      testUrl.value = 'https://countries.trevorblades.com/graphql';
      testHeaders.value = '';
      graphqlOperation.value = 'query';
      graphqlQuery.value = `query {
  country(code: "US") {
    name
    capital
    currency
    languages { name }
  }
}`;
      graphqlVariables.value = '';
    },
    websocket: () => {
      testUrl.value = 'wss://echo.websocket.org';
      wsMessage.value = 'Hello Spi!';
    },
    grpc: () => {
      testUrl.value = 'http://grpcbin.test.k6.io:9000';
      grpcCallType.value = 'unary';
      grpcProto.value = '';
      grpcRequest.value = '{"name": "World"}';
    },
    mqtt: () => {
      testUrl.value = 'mqtt://test.mosquitto.org:1883';
      mqttTopic.value = 'spi/test';
      mqttQos.value = '0';
      mqttMessage.value = '{"message": "Hello from Spi!"}';
    },
    soap: () => {
      testUrl.value = 'https://www.crcind.com/csp/samples/SOAP.CLS';
      soapAction.value = 'http://tempuri.org/celsiusToFahrenheit';
      testHeaders.value = '';
      soapEnvelope.value = `<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
  <soap:Body>
    <celsiusToFahrenheit xmlns="http://tempuri.org">
      <celsius>100</celsius>
    </celsiusToFahrenheit>
  </soap:Body>
</soap:Envelope>`;
    }
  };
  
  if (samples[protocol]) {
    samples[protocol]();
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
  padding: 24px 24px 16px;
  text-align: center;
  background: linear-gradient(180deg, var(--panel-bg) 0%, var(--bg-color) 100%);
}

.hero-content {
  max-width: 700px;
  margin: 0 auto;
}

.hero h1 {
  font-size: 36px;
  font-weight: 700;
  margin: 0 0 12px 0;
  letter-spacing: -1px;
  background: linear-gradient(135deg, var(--accent-color), #58a6ff);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

/* Demo Section */
.demo {
  padding: 16px 24px 40px;
  background: var(--bg-color);
}

.demo-container {
  max-width: 800px;
  margin: 0 auto;
}

.tester-container {
  background: rgba(88, 166, 255, 0.08);
  border: 1px solid rgba(88, 166, 255, 0.3);
  border-radius: 12px;
  padding: 24px;
}

/* Sample Tests Row */
.sample-tests-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.sample-label {
  font-size: 13px;
  color: var(--text-secondary);
  font-weight: 500;
}

.sample-btn {
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid;
  transition: all 0.2s;
  background: transparent;
}

.sample-btn.rest {
  color: #58a6ff;
  border-color: rgba(88, 166, 255, 0.4);
}
.sample-btn.rest:hover {
  background: rgba(88, 166, 255, 0.15);
}

.sample-btn.graphql {
  color: #e954b2;
  border-color: rgba(233, 84, 178, 0.4);
}
.sample-btn.graphql:hover {
  background: rgba(233, 84, 178, 0.15);
}

.sample-btn.websocket {
  color: #3fb950;
  border-color: rgba(63, 185, 80, 0.4);
}
.sample-btn.websocket:hover {
  background: rgba(63, 185, 80, 0.15);
}

.sample-btn.grpc {
  color: #58a6ff;
  border-color: rgba(88, 166, 255, 0.4);
}
.sample-btn.grpc:hover {
  background: rgba(88, 166, 255, 0.15);
}

.sample-btn.mqtt {
  color: #d29922;
  border-color: rgba(210, 153, 34, 0.4);
}
.sample-btn.mqtt:hover {
  background: rgba(210, 153, 34, 0.15);
}

.sample-btn.soap {
  color: #d29922;
  border-color: rgba(210, 153, 34, 0.4);
}
.sample-btn.soap:hover {
  background: rgba(210, 153, 34, 0.15);
}

.protocol-row {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.protocol-select {
  background: rgba(88, 166, 255, 0.1);
  border: 1px solid rgba(88, 166, 255, 0.4);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--accent-color);
  cursor: pointer;
  min-width: 120px;
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
  transition: all 0.2s;
}

.method-select:hover,
.method-select:focus {
  border-color: var(--accent-color);
  outline: none;
  border-width: 2px;
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
  border-width: 2px;
}

.url-input:hover {
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

.headers-input:hover,
.body-input:hover {
  border-color: var(--accent-color);
}

.headers-input:focus,
.body-input:focus {
  outline: none;
  border-color: var(--accent-color);
  border-width: 2px;
}

/* Protocol Section */
.protocol-section {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid rgba(88, 166, 255, 0.2);
}

.protocol-info {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.protocol-badge {
  padding: 4px 10px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.protocol-badge.rest { background: rgba(88, 166, 255, 0.2); color: #58a6ff; }
.protocol-badge.graphql { background: rgba(233, 84, 178, 0.2); color: #e954b2; }
.protocol-badge.websocket { background: rgba(63, 185, 80, 0.2); color: #3fb950; }
.protocol-badge.grpc { background: rgba(88, 166, 255, 0.2); color: #58a6ff; }
.protocol-badge.mqtt { background: rgba(210, 153, 34, 0.2); color: #d29922; }
.protocol-badge.amqp { background: rgba(139, 148, 158, 0.2); color: #8b949e; }
.protocol-badge.soap { background: rgba(210, 153, 34, 0.2); color: #d29922; }
.protocol-badge.webhook { background: rgba(63, 185, 80, 0.2); color: #3fb950; }
.protocol-badge.mcp { background: rgba(163, 113, 247, 0.2); color: #a371f7; }
.protocol-badge.a2a { background: rgba(248, 81, 73, 0.2); color: #f85149; }

.protocol-hint {
  font-size: 13px;
  color: var(--text-secondary);
}

.ws-messages {
  margin-top: 16px;
}

.ws-message-list {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  max-height: 200px;
  overflow-y: auto;
}

.ws-message {
  display: flex;
  gap: 8px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--border-color);
  font-family: 'Courier New', monospace;
  font-size: 12px;
}

.ws-message:last-child {
  border-bottom: none;
}

.ws-message.sent {
  background: rgba(88, 166, 255, 0.05);
}

.ws-message.received {
  background: rgba(63, 185, 80, 0.05);
}

.msg-type {
  font-weight: 600;
  color: var(--accent-color);
}

.ws-message pre {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.response-container {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  overflow: hidden;
  margin-top: 16px;
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
  margin-top: 16px;
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
  border-radius: 12px;
  transition: all 0.2s;
  border: 2px solid transparent;
}

.feature-card:hover {
  border-color: var(--accent-color);
  box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.2);
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
  transition: all 0.2s;
}

.pricing-card:hover {
  border-color: var(--accent-color);
  box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.2);
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
  transition: all 0.2s;
}

.testimonial-card:hover {
  border-color: var(--accent-color);
  box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.2);
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
  
  .protocol-row {
    flex-direction: column;
  }
  
  .protocol-select,
  .method-select,
  .send-btn {
    width: 100%;
  }
  
  .features h2,
  .pricing h2,
  .testimonials h2,
  .cta h2 {
    font-size: 28px;
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