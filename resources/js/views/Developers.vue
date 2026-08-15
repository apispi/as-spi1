<template>
  <div class="dev-page">
    <div class="dev-container">
      <header class="dev-header">
        <router-link to="/" class="dev-back">← Back</router-link>
        <h1>Developer API</h1>
        <p class="dev-lead">
          Drive the Spi testers from your own scripts. Every request you can make
          in the dashboard — REST, MCP, A2A, gRPC, MQTT, and AMQP — is available
          over a small, token-authenticated HTTP API.
        </p>
      </header>

      <!-- TOC -->
      <nav class="dev-toc">
        <a href="#auth">Authentication</a>
        <a href="#base">Base URL</a>
        <a href="#proxy">REST proxy</a>
        <a href="#mcp">MCP</a>
        <a href="#a2a">A2A</a>
        <a href="#grpc">gRPC</a>
        <a href="#mqtt">MQTT</a>
        <a href="#amqp">AMQP</a>
        <a href="#environments">Environments</a>
        <a href="#limits">Rate limits</a>
        <a href="#errors">Errors</a>
      </nav>

      <!-- Auth -->
      <section id="auth" class="dev-section">
        <h2>Authentication</h2>
        <p>
          Requests are authenticated with a <strong>personal API key</strong>.
          Generate one in
          <router-link to="/profile" class="dev-link">Profile → API Keys</router-link>.
          The full key is shown once at creation — store it securely.
        </p>
        <p>Send it as a bearer token on every request:</p>
        <CodeBlock :code="`Authorization: Bearer spi_your_key_here`" />
        <p class="dev-note">
          Keys look like <code>spi_…</code>. Regenerating a key immediately
          invalidates the previous one. Keys are stored hashed — Spi cannot show
          you an existing key again, only issue a new one.
        </p>
      </section>

      <!-- Base URL -->
      <section id="base" class="dev-section">
        <h2>Base URL</h2>
        <CodeBlock :code="`${origin}/api/v1`" />
        <p>All endpoints below are relative to this base and use <code>POST</code> with a JSON body.</p>
      </section>

      <!-- Proxy -->
      <section id="proxy" class="dev-section">
        <h2><span class="dev-verb">POST</span> /proxy</h2>
        <p>Send an HTTP request to any public URL and get the response back.</p>
        <h3>Request body</h3>
        <table class="dev-table">
          <tbody>
            <tr><td><code>url</code></td><td>required</td><td>Target URL (must be publicly routable).</td></tr>
            <tr><td><code>method</code></td><td>required</td><td>GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD.</td></tr>
            <tr><td><code>headers</code></td><td>optional</td><td>Object of request headers.</td></tr>
            <tr><td><code>body</code></td><td>optional</td><td>String or JSON object; objects are sent as JSON.</td></tr>
          </tbody>
        </table>
        <h3>Example</h3>
        <CodeBlock :code="proxyExample" />
        <h3>Response</h3>
        <CodeBlock :code="proxyResponse" />
      </section>

      <!-- MCP -->
      <section id="mcp" class="dev-section">
        <h2><span class="dev-verb">POST</span> /mcp/test</h2>
        <p>Call a Model Context Protocol server. The client performs the
          <code>initialize</code> handshake for you, then runs your method.</p>
        <h3>Request body</h3>
        <table class="dev-table">
          <tbody>
            <tr><td><code>url</code></td><td>required</td><td>MCP server endpoint (Streamable HTTP).</td></tr>
            <tr><td><code>method</code></td><td>required</td><td>e.g. <code>tools/list</code>, <code>tools/call</code>, <code>resources/read</code>, <code>prompts/get</code>.</td></tr>
            <tr><td><code>params</code></td><td>optional</td><td>JSON-RPC params for the method.</td></tr>
            <tr><td><code>headers</code></td><td>optional</td><td>Extra headers (e.g. the server's own auth).</td></tr>
          </tbody>
        </table>
        <h3>Example</h3>
        <CodeBlock :code="mcpExample" />
      </section>

      <!-- A2A -->
      <section id="a2a" class="dev-section">
        <h2><span class="dev-verb">POST</span> /a2a/test</h2>
        <p>Call an Agent-to-Agent endpoint. Use <code>agent-card</code> to fetch
          the agent's capabilities, or a JSON-RPC method like
          <code>message/send</code>.</p>
        <h3>Example</h3>
        <CodeBlock :code="a2aExample" />
      </section>

      <!-- gRPC -->
      <section id="grpc" class="dev-section">
        <h2><span class="dev-verb">POST</span> /grpc/test</h2>
        <p>Make a <strong>unary</strong> gRPC call over HTTP/2. Messages are
          described as explicit field lists (no <code>.proto</code> compilation);
          the response is decoded generically and the gRPC status is returned.</p>
        <h3>Request body</h3>
        <table class="dev-table">
          <tbody>
            <tr><td><code>host</code></td><td>required</td><td>Server hostname (must be publicly routable).</td></tr>
            <tr><td><code>port</code></td><td>optional</td><td>Defaults to 443 (TLS) or 80.</td></tr>
            <tr><td><code>tls</code></td><td>optional</td><td>Use HTTP/2 over TLS. Default <code>false</code>.</td></tr>
            <tr><td><code>service_method</code></td><td>required</td><td>e.g. <code>helloworld.Greeter/SayHello</code>.</td></tr>
            <tr><td><code>request</code></td><td>optional</td><td>Array of <code>{ field, type, value }</code> descriptors.</td></tr>
            <tr><td><code>metadata</code></td><td>optional</td><td>Extra call metadata (headers).</td></tr>
          </tbody>
        </table>
        <h3>Example</h3>
        <CodeBlock :code="grpcExample" />
      </section>

      <!-- MQTT -->
      <section id="mqtt" class="dev-section">
        <h2><span class="dev-verb">POST</span> /mqtt/test</h2>
        <p>Publish to and/or subscribe to a broker topic. Subscribe loops are
          bounded by <code>timeout</code> and <code>max_messages</code>.</p>
        <h3>Request body</h3>
        <table class="dev-table">
          <tbody>
            <tr><td><code>host</code></td><td>required</td><td>Broker hostname.</td></tr>
            <tr><td><code>port</code></td><td>optional</td><td>Defaults to 1883 (or 8883 for TLS).</td></tr>
            <tr><td><code>action</code></td><td>required</td><td><code>publish</code>, <code>subscribe</code>, or <code>publish_subscribe</code>.</td></tr>
            <tr><td><code>topic</code></td><td>required</td><td>Topic to publish/subscribe.</td></tr>
            <tr><td><code>message</code></td><td>optional</td><td>Payload to publish.</td></tr>
            <tr><td><code>qos</code></td><td>optional</td><td>0, 1, or 2. Default 0.</td></tr>
          </tbody>
        </table>
        <h3>Example</h3>
        <CodeBlock :code="mqttExample" />
      </section>

      <!-- AMQP -->
      <section id="amqp" class="dev-section">
        <h2><span class="dev-verb">POST</span> /amqp/test</h2>
        <p>Publish to an exchange/routing-key and/or pull messages from a queue
          (via <code>basic_get</code>).</p>
        <h3>Request body</h3>
        <table class="dev-table">
          <tbody>
            <tr><td><code>host</code></td><td>required</td><td>RabbitMQ hostname.</td></tr>
            <tr><td><code>port</code></td><td>optional</td><td>Defaults to 5672 (or 5671 for TLS).</td></tr>
            <tr><td><code>action</code></td><td>required</td><td><code>publish</code>, <code>get</code>, or <code>publish_get</code>.</td></tr>
            <tr><td><code>exchange</code>, <code>routing_key</code></td><td>optional</td><td>Publish target.</td></tr>
            <tr><td><code>queue</code></td><td>required for get</td><td>Queue to pull from.</td></tr>
            <tr><td><code>message</code></td><td>optional</td><td>Body to publish.</td></tr>
          </tbody>
        </table>
        <h3>Example</h3>
        <CodeBlock :code="amqpExample" />
      </section>

      <!-- Limits -->
      <section id="environments" class="dev-section">
        <h2>Environments</h2>
        <p>
          Any endpoint above accepts an <code>environment</code> (name) or
          <code>environment_id</code>. Placeholders written as
          <code v-pre>{{variable}}</code> anywhere in the payload — URL, headers,
          body, topics — are substituted from that environment before the
          request is sent. Manage environments in
          <router-link to="/tester" class="dev-link">Tester → Manage</router-link>.
        </p>
        <CodeBlock :code="envExample" />
        <p>
          Substitution runs before validation, so the <em>resolved</em> URL is
          what the SSRF guard checks. Unknown placeholders are left untouched
          and listed back to you:
        </p>
        <CodeBlock :code="envResponse" />
        <p class="dev-note">
          Variables marked <strong>secret</strong> are masked as
          <code>••••••</code> in request history and in the echoed
          <code>request_payload</code> — the real value still reaches the target
          server, but is never stored or returned. If no environment is named,
          your default environment applies whenever the payload contains a
          placeholder.
        </p>
      </section>

      <section id="limits" class="dev-section">
        <h2>Rate limits</h2>
        <p>The <code>/api/v1</code> endpoints are limited to <strong>60 requests
          per minute</strong> per key. Exceeding the limit returns
          <code>429 Too Many Requests</code>.</p>
      </section>

      <!-- Errors -->
      <section id="errors" class="dev-section">
        <h2>Errors</h2>
        <table class="dev-table">
          <tbody>
            <tr><td><code>401</code></td><td>Missing or invalid API key.</td></tr>
            <tr><td><code>422</code></td><td>Validation failed — e.g. a missing field, or a URL that resolves to a private/loopback address (blocked for security).</td></tr>
            <tr><td><code>429</code></td><td>Rate limit exceeded.</td></tr>
            <tr><td><code>500 / 502</code></td><td>The upstream target errored or was unreachable; the message describes what happened.</td></tr>
          </tbody>
        </table>
        <p class="dev-note">
          Successful calls return <code>{ status, headers, body, time_ms }</code>,
          where <code>status</code> is the <em>target's</em> HTTP status — a
          <code>200</code> from Spi with a <code>body.status</code> of 404 means
          your target returned 404, not the API.
        </p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, h } from 'vue';

const origin = typeof window !== 'undefined' ? window.location.origin : 'https://spi.apispi.com';

const proxyExample = computed(() => `curl -X POST ${origin}/api/v1/proxy \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "url": "https://api.example.com/users",
    "method": "GET"
  }'`);

const envExample = computed(() => `curl -X POST ${origin}/api/v1/proxy \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "environment": "Staging",
    "url": "https://{{base_url}}/users",
    "method": "GET",
    "headers": { "Authorization": "Bearer {{token}}" }
  }'`);

const envResponse = `{
  "status": 200,
  "environment": {
    "name": "Staging",
    "resolved": ["base_url", "token"],
    "unresolved": ["missing_var"]
  }
}`;

const proxyResponse = `{
  "status": 200,
  "headers": { "content-type": ["application/json"] },
  "body": "[{\\"id\\":1,\\"name\\":\\"Ada\\"}]",
  "time_ms": 142
}`;

const mcpExample = computed(() => `curl -X POST ${origin}/api/v1/mcp/test \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "url": "https://mcp.example.com/mcp",
    "method": "tools/list",
    "headers": { "Authorization": "Bearer SERVER_KEY" }
  }'`);

const a2aExample = computed(() => `curl -X POST ${origin}/api/v1/a2a/test \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "url": "https://agents.example.com/a2a",
    "method": "message/send",
    "params": { "message": { "role": "user", "parts": [{ "text": "Hi" }] } }
  }'`);

const grpcExample = computed(() => `curl -X POST ${origin}/api/v1/grpc/test \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "host": "grpc.example.com",
    "port": 443,
    "tls": true,
    "service_method": "helloworld.Greeter/SayHello",
    "request": [{ "field": 1, "type": "string", "value": "world" }]
  }'`);

const mqttExample = computed(() => `curl -X POST ${origin}/api/v1/mqtt/test \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "host": "broker.example.com",
    "action": "publish",
    "topic": "sensors/temperature",
    "message": "21.5"
  }'`);

const amqpExample = computed(() => `curl -X POST ${origin}/api/v1/amqp/test \\
  -H "Authorization: Bearer spi_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "host": "rabbit.example.com",
    "action": "publish",
    "routing_key": "jobs",
    "message": "{\\"id\\":1}"
  }'`);

// Small inline copy-to-clipboard code block.
const CodeBlock = (props) => {
  let copied = false;
  return h('div', { class: 'dev-code' }, [
    h('button', {
      class: 'dev-copy',
      onClick: (e) => {
        navigator.clipboard?.writeText(props.code);
        const btn = e.target;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
      },
    }, 'Copy'),
    h('pre', null, props.code),
  ]);
};
</script>

<style scoped>
.dev-page { min-height: 100%; background: var(--bg-color); color: var(--text-primary); overflow-y: auto; }
.dev-container { max-width: 820px; margin: 0 auto; padding: 40px 24px 80px; }

.dev-header { margin-bottom: 24px; }
.dev-back { color: var(--accent-color); text-decoration: none; font-size: 14px; }
.dev-header h1 { font-size: 32px; font-weight: 700; margin: 12px 0 8px; }
.dev-lead { color: var(--text-secondary); font-size: 16px; line-height: 1.6; margin: 0; }

.dev-toc { display: flex; flex-wrap: wrap; gap: 8px 16px; padding: 16px 0 24px; border-bottom: 1px solid var(--border-color); margin-bottom: 24px; }
.dev-toc a { color: var(--accent-color); text-decoration: none; font-size: 14px; }
.dev-toc a:hover { text-decoration: underline; }

.dev-section { margin-bottom: 40px; scroll-margin-top: 20px; }
.dev-section h2 { font-size: 22px; font-weight: 600; margin: 0 0 12px; }
.dev-section h3 { font-size: 14px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.04em; margin: 20px 0 8px; }
.dev-section p { line-height: 1.6; color: var(--text-primary); margin: 0 0 12px; }
.dev-verb { font-family: monospace; font-size: 14px; color: #3fb950; background: rgba(63,185,80,0.15); padding: 2px 8px; border-radius: 4px; vertical-align: middle; }
.dev-link { color: var(--accent-color); text-decoration: none; }
.dev-link:hover { text-decoration: underline; }
.dev-note { font-size: 14px; color: var(--text-secondary); background: var(--panel-bg); border-left: 3px solid var(--accent-color); padding: 12px 16px; border-radius: 0 8px 8px 0; }

.dev-section code { font-family: monospace; font-size: 0.88em; background: rgba(255,255,255,0.06); padding: 1px 5px; border-radius: 4px; }

.dev-code { position: relative; margin: 0 0 12px; }
.dev-code pre { background: #010409; border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5; color: var(--text-primary); white-space: pre; margin: 0; }
.dev-copy { position: absolute; top: 8px; right: 8px; background: var(--panel-bg); border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12px; padding: 4px 10px; border-radius: 6px; cursor: pointer; }
.dev-copy:hover { color: var(--accent-color); border-color: var(--accent-color); }

.dev-table { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
.dev-table td { padding: 8px 12px; border-bottom: 1px solid var(--border-color); font-size: 14px; vertical-align: top; }
.dev-table td:first-child { white-space: nowrap; }
.dev-table td:nth-child(2) { color: var(--text-secondary); white-space: nowrap; }
</style>
