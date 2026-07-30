<template>
  <main class="ail">
    <header class="ail-head">
      <h1 class="ail-title">AI Lab</h1>
      <p class="ail-sub">AI-assisted request authoring and MCP security scanning, powered by your SCX key.</p>
    </header>

    <div v-if="!hasScxKey" class="ail-setup">
      <div class="ail-setup-icon">🔑</div>
      <h2>SCX API Key Required</h2>
      <p>The authoring tools use SCX AI. Add your key in
        <router-link to="/profile">Profile Settings</router-link> to enable them.
        The security scanner’s heuristic pass works without a key.</p>
    </div>

    <nav class="ail-tabs">
      <button v-for="t in tabs" :key="t.id"
              :class="['ail-tab', { active: tab === t.id }]"
              @click="tab = t.id">{{ t.label }}</button>
    </nav>

    <!-- Author -->
    <section v-show="tab === 'author'" class="ail-panel">
      <label class="ail-label">Describe the request in plain English</label>
      <textarea v-model="author.instruction" class="ail-input" rows="3"
                placeholder="e.g. create an invoice for customer 42 for $19.99"></textarea>
      <div class="ail-row">
        <select v-model="author.protocol" class="ail-select">
          <option v-for="p in protocols" :key="p" :value="p">{{ p.toUpperCase() }}</option>
        </select>
        <button class="ail-btn" :disabled="busy || !author.instruction.trim()" @click="runAuthor">
          {{ busy ? 'Thinking…' : 'Author request' }}
        </button>
      </div>
      <label class="ail-label">Target schema (optional — MCP inputSchema, OpenAPI operation…)</label>
      <textarea v-model="author.schema" class="ail-input mono" rows="3" placeholder="{ }"></textarea>
      <pre v-if="results.author" class="ail-out">{{ pretty(results.author) }}</pre>
    </section>

    <!-- Explain -->
    <section v-show="tab === 'explain'" class="ail-panel">
      <label class="ail-label">Response body / error to explain</label>
      <textarea v-model="explain.response" class="ail-input mono" rows="6"
                placeholder="Paste a response body or error…"></textarea>
      <div class="ail-row">
        <input v-model="explain.status" class="ail-select" placeholder="Status (e.g. 404)" />
        <button class="ail-btn" :disabled="busy || !explain.response.trim()" @click="runExplain">
          {{ busy ? 'Thinking…' : 'Explain' }}
        </button>
      </div>
      <div v-if="results.explain" class="ail-cards">
        <div class="ail-card"><h4>Summary</h4><p>{{ results.explain.summary }}</p></div>
        <div class="ail-card" v-if="results.explain.likely_cause"><h4>Likely cause</h4><p>{{ results.explain.likely_cause }}</p></div>
        <div class="ail-card" v-if="results.explain.suggestions?.length">
          <h4>Suggestions</h4>
          <ul><li v-for="(s, i) in results.explain.suggestions" :key="i">{{ s }}</li></ul>
        </div>
      </div>
    </section>

    <!-- Assert -->
    <section v-show="tab === 'assert'" class="ail-panel">
      <label class="ail-label">Response body to generate assertions from</label>
      <textarea v-model="assertForm.response" class="ail-input mono" rows="6" placeholder="Paste a JSON response…"></textarea>
      <div class="ail-row">
        <input v-model="assertForm.status" class="ail-select" placeholder="Status (e.g. 200)" />
        <button class="ail-btn" :disabled="busy || !assertForm.response.trim()" @click="runAssert">
          {{ busy ? 'Thinking…' : 'Generate assertions' }}
        </button>
      </div>
      <table v-if="results.assert?.assertions?.length" class="ail-table">
        <thead><tr><th>Path</th><th>Operator</th><th>Expected</th><th>Description</th></tr></thead>
        <tbody>
          <tr v-for="(a, i) in results.assert.assertions" :key="i">
            <td class="mono">{{ a.path }}</td><td>{{ a.operator }}</td>
            <td class="mono">{{ a.expected }}</td><td>{{ a.description }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Fix -->
    <section v-show="tab === 'fix'" class="ail-panel">
      <label class="ail-label">Failing request</label>
      <textarea v-model="fix.request" class="ail-input mono" rows="4" placeholder="Method, URL, headers, body…"></textarea>
      <label class="ail-label">Error / response</label>
      <textarea v-model="fix.error" class="ail-input mono" rows="3" placeholder="e.g. 415 Unsupported Media Type"></textarea>
      <div class="ail-row">
        <button class="ail-btn" :disabled="busy || !fix.request.trim() || !fix.error.trim()" @click="runFix">
          {{ busy ? 'Thinking…' : 'Diagnose & fix' }}
        </button>
      </div>
      <div v-if="results.fix" class="ail-cards">
        <div class="ail-card"><h4>Diagnosis</h4><p>{{ results.fix.diagnosis }}</p></div>
        <div class="ail-card" v-if="results.fix.changes?.length">
          <h4>Changes</h4><ul><li v-for="(c, i) in results.fix.changes" :key="i">{{ c }}</li></ul>
        </div>
      </div>
      <pre v-if="results.fix?.fixed_request" class="ail-out">{{ pretty(results.fix.fixed_request) }}</pre>
    </section>

    <!-- Scan -->
    <section v-show="tab === 'scan'" class="ail-panel">
      <label class="ail-label">MCP tools / prompts to scan (JSON array of {name, description, schema})</label>
      <textarea v-model="scan.itemsText" class="ail-input mono" rows="7"
                placeholder='[{"name":"search","description":"..."}]'></textarea>
      <div class="ail-row">
        <label class="ail-check"><input type="checkbox" v-model="scan.deep" /> AI deep scan</label>
        <button class="ail-btn" :disabled="busy || !scan.itemsText.trim()" @click="runScan">
          {{ busy ? 'Scanning…' : 'Scan for poisoning' }}
        </button>
      </div>
      <div v-if="results.scan" class="ail-scan">
        <div class="ail-risk" :class="'risk-' + results.scan.risk">
          Risk: {{ results.scan.risk.toUpperCase() }} · score {{ results.scan.score }}/100 ·
          {{ results.scan.scanned }} item(s)
        </div>
        <p v-if="!results.scan.findings.length" class="ail-clean">No heuristic findings. ✅</p>
        <div v-for="(f, i) in results.scan.findings" :key="i" class="ail-finding" :class="'sev-' + f.severity">
          <span class="ail-sev">{{ f.severity }}</span>
          <div>
            <strong>{{ f.title }}</strong> <span class="ail-item">in {{ f.item }}</span>
            <div class="ail-match mono">{{ f.match }}</div>
          </div>
        </div>
        <div v-if="results.scan.ai?.findings?.length" class="ail-ai">
          <h4>AI review</h4>
          <div v-for="(f, i) in results.scan.ai.findings" :key="'ai'+i" class="ail-finding" :class="'sev-' + f.severity">
            <span class="ail-sev">{{ f.severity }}</span>
            <div><strong>{{ f.title }}</strong> <span class="ail-item">in {{ f.item }}</span>
              <div class="ail-match">{{ f.detail }}</div></div>
          </div>
        </div>
      </div>
    </section>

    <p v-if="error" class="ail-err">{{ error }}</p>
  </main>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const tabs = [
  { id: 'author', label: 'Author' },
  { id: 'explain', label: 'Explain' },
  { id: 'assert', label: 'Assert' },
  { id: 'fix', label: 'Fix' },
  { id: 'scan', label: 'Security scan' },
];
const protocols = ['rest', 'graphql', 'mcp', 'a2a', 'soap', 'grpc'];

const tab = ref('author');
const busy = ref(false);
const error = ref('');
const hasScxKey = ref(false);

const author = ref({ instruction: '', protocol: 'rest', schema: '' });
const explain = ref({ response: '', status: '' });
const assertForm = ref({ response: '', status: '' });
const fix = ref({ request: '', error: '' });
const scan = ref({ itemsText: '', deep: false });
const results = ref({});

onMounted(async () => {
  try {
    const res = await axios.get('/api/user/scx-api-key');
    hasScxKey.value = res.data.has_key;
  } catch { hasScxKey.value = false; }
});

const pretty = (o) => JSON.stringify(o, null, 2);

async function call(url, payload, key) {
  busy.value = true; error.value = '';
  try {
    const res = await axios.post(url, payload);
    results.value = { ...results.value, [key]: res.data };
  } catch (e) {
    error.value = e.response?.data?.error || e.response?.data?.message || 'Request failed.';
  } finally {
    busy.value = false;
  }
}

const runAuthor = () => call('/api/ai/author', {
  instruction: author.value.instruction,
  protocol: author.value.protocol,
  schema: author.value.schema ? tryJson(author.value.schema) : null,
}, 'author');

const runExplain = () => call('/api/ai/explain', {
  response: explain.value.response, status: explain.value.status || null,
}, 'explain');

const runAssert = () => call('/api/ai/assert', {
  response: assertForm.value.response, status: assertForm.value.status || null,
}, 'assert');

const runFix = () => call('/api/ai/fix', {
  request: fix.value.request, error: fix.value.error,
}, 'fix');

function runScan() {
  let items;
  try {
    items = JSON.parse(scan.value.itemsText);
    if (!Array.isArray(items)) throw new Error();
  } catch {
    error.value = 'Items must be a JSON array of {name, description}.';
    return;
  }
  call('/api/mcp/security/scan', { items, deep: scan.value.deep }, 'scan');
}

function tryJson(s) { try { return JSON.parse(s); } catch { return s; } }
</script>

<style scoped>
.ail { max-width: 900px; margin: 0 auto; padding: 24px 20px 60px; }
.ail-title { font-size: 24px; font-weight: 700; color: var(--text-primary); margin: 0; }
.ail-sub { color: var(--text-secondary); margin: 6px 0 20px; }
.ail-setup { text-align: center; padding: 28px; border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 20px; background: var(--bg-secondary, transparent); }
.ail-setup-icon { font-size: 30px; }
.ail-tabs { display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 1px solid var(--border-color); margin-bottom: 18px; }
.ail-tab { background: none; border: none; padding: 10px 14px; color: var(--text-secondary); cursor: pointer; font-size: 14px; border-bottom: 2px solid transparent; }
.ail-tab.active { color: var(--accent-color); border-bottom-color: var(--accent-color); font-weight: 600; }
.ail-panel { display: flex; flex-direction: column; gap: 10px; }
.ail-label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); }
.ail-input, .ail-select { width: 100%; background: var(--bg-secondary, #0d1117); color: var(--text-primary); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px; font-size: 14px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
.ail-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ail-select { width: auto; min-width: 140px; }
.ail-btn { background: var(--accent-color); color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 600; cursor: pointer; }
.ail-btn:disabled { opacity: .5; cursor: not-allowed; }
.ail-check { display: flex; gap: 6px; align-items: center; color: var(--text-secondary); font-size: 14px; }
.ail-out { background: var(--bg-secondary, #0d1117); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; overflow-x: auto; color: var(--text-primary); font-size: 13px; }
.ail-cards { display: grid; gap: 10px; }
.ail-card { border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px; }
.ail-card h4 { margin: 0 0 6px; font-size: 13px; color: var(--accent-color); }
.ail-card p, .ail-card li { color: var(--text-primary); font-size: 14px; margin: 2px 0; }
.ail-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ail-table th, .ail-table td { border: 1px solid var(--border-color); padding: 7px 9px; text-align: left; color: var(--text-primary); }
.ail-table th { color: var(--text-secondary); font-weight: 600; }
.ail-risk { font-weight: 700; padding: 10px 12px; border-radius: 8px; }
.risk-none { background: rgba(63,185,80,.15); color: #3fb950; }
.risk-low { background: rgba(210,153,34,.12); color: #d29922; }
.risk-medium { background: rgba(210,153,34,.2); color: #d29922; }
.risk-high, .risk-critical { background: rgba(248,81,73,.16); color: #f85149; }
.ail-clean { color: #3fb950; }
.ail-finding { display: flex; gap: 10px; align-items: flex-start; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 12px; margin-top: 8px; }
.ail-sev { font-size: 11px; text-transform: uppercase; font-weight: 700; padding: 2px 7px; border-radius: 5px; flex-shrink: 0; }
.sev-high .ail-sev, .sev-critical .ail-sev { background: rgba(248,81,73,.16); color: #f85149; }
.sev-medium .ail-sev { background: rgba(210,153,34,.18); color: #d29922; }
.sev-low .ail-sev { background: var(--border-color); color: var(--text-secondary); }
.ail-item { color: var(--text-secondary); font-size: 13px; }
.ail-match { color: var(--text-secondary); font-size: 12px; margin-top: 4px; }
.ail-ai { margin-top: 14px; }
.ail-ai h4 { color: var(--accent-color); font-size: 13px; }
.ail-err { color: #f85149; margin-top: 14px; }
</style>
