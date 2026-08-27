<template>
  <div class="ex">
    <header class="ex-head">
      <h1 class="ex-title">Agent Explorer</h1>
      <p class="ex-sub">
        Point an autonomous agent at an MCP server with a goal. It calls tools
        to achieve it — safely by default — and reports the path it took, the
        capabilities it found, and every destructive tool it reached for.
      </p>
    </header>

    <form class="ex-form" @submit.prevent="run">
      <div class="ex-row">
        <label class="ex-label">MCP server URL</label>
        <input v-model="url" class="input-field mono" placeholder="https://mcp.example.com/mcp" />
      </div>
      <div class="ex-row">
        <label class="ex-label">Goal</label>
        <input v-model="goal" class="input-field" placeholder="Find the current weather in Sydney" maxlength="2000" />
      </div>
      <div class="ex-opts">
        <label class="ex-check">
          <input type="checkbox" v-model="safeMode" />
          <span>Safe mode — refuse destructive tools</span>
        </label>
        <label class="ex-steps">
          <span>Max steps</span>
          <select v-model.number="maxSteps" class="input-field">
            <option v-for="n in [4,6,8,10,12]" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
        <button class="ex-primary" type="submit" :disabled="running || !url.trim() || !goal.trim()">
          {{ running ? 'Exploring…' : 'Explore' }}
        </button>
      </div>
      <p v-if="!safeMode" class="ex-warn">
        Safe mode is off — the agent may call tools that change or delete data on the target server.
      </p>
      <p v-if="error" class="ex-error">{{ error }}</p>
    </form>

    <section v-if="result" class="ex-result">
      <!-- Verdict + capabilities -->
      <div class="ex-summary">
        <span class="ex-verdict" :class="result.completed ? 'ok' : 'warn'">
          {{ result.completed ? 'Goal met' : 'Incomplete' }}
        </span>
        <span class="ex-meta">{{ result.tool_call_count }} tool call{{ result.tool_call_count === 1 ? '' : 's' }} · {{ result.tools_available }} tools available</span>
        <span v-if="result.blocked_attempts.length" class="ex-pill bad">{{ result.blocked_attempts.length }} blocked</span>
        <span v-if="result.capabilities.risk && result.capabilities.risk !== 'none'" class="ex-pill" :class="riskClass">{{ result.capabilities.risk }} risk surface</span>
      </div>

      <p v-if="result.final_answer" class="ex-answer">{{ result.final_answer }}</p>

      <!-- Findings -->
      <div v-if="result.blocked_attempts.length || result.capabilities.destructive_tools.length || result.capabilities.findings.length" class="ex-findings">
        <div v-for="b in result.blocked_attempts" :key="'b'+b.name" class="ex-finding bad">
          <span class="ex-tag">blocked</span>
          <span>The agent tried to call <code>{{ b.name }}</code> to meet the goal.</span>
        </div>
        <div v-for="f in result.capabilities.findings" :key="'f'+f.title+f.tool" class="ex-finding" :class="f.severity">
          <span class="ex-tag">{{ f.severity }}</span>
          <span><code>{{ f.tool }}</code>: {{ f.title }}</span>
        </div>
        <div v-if="result.capabilities.destructive_tools.length" class="ex-finding warn">
          <span class="ex-tag">side-effecting</span>
          <span>Server exposes: <code v-for="t in result.capabilities.destructive_tools" :key="t">{{ t }}</code></span>
        </div>
      </div>

      <!-- The path -->
      <h2 class="ex-section">The path</h2>
      <ol class="ex-steps">
        <li v-for="s in result.steps" :key="s.step" class="ex-step">
          <p v-if="s.assistant_text" class="ex-think">{{ s.assistant_text }}</p>
          <div v-for="(call, i) in s.tool_calls" :key="i" class="ex-call" :class="{ blocked: call.blocked, err: call.is_error && !call.blocked }">
            <div class="ex-call-head">
              <span class="ex-verb">{{ call.name }}</span>
              <span v-if="call.blocked" class="ex-pill bad">blocked</span>
              <span v-else-if="call.is_error" class="ex-pill bad">error</span>
              <code class="ex-args">{{ compact(call.arguments) }}</code>
            </div>
            <pre v-if="call.result_text" class="ex-out">{{ truncate(call.result_text) }}</pre>
          </div>
        </li>
      </ol>
    </section>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';

const url = ref('');
const goal = ref('');
const safeMode = ref(true);
const maxSteps = ref(8);
const running = ref(false);
const error = ref('');
const result = ref(null);

const riskClass = computed(() => ({ high: 'bad', medium: 'warn', low: 'muted' }[result.value?.capabilities.risk] || 'muted'));

const run = async () => {
  running.value = true;
  error.value = '';
  result.value = null;
  try {
    const res = await axios.post('/api/explore', {
      url: url.value.trim(), goal: goal.value.trim(),
      safe_mode: safeMode.value, max_steps: maxSteps.value,
    });
    result.value = res.data;
  } catch (e) {
    error.value = e.response?.data?.error || e.response?.data?.message
      || Object.values(e.response?.data?.errors || {})[0]?.[0] || 'Exploration failed.';
  } finally {
    running.value = false;
  }
};

const compact = (args) => {
  const s = JSON.stringify(args ?? {});
  return s.length > 80 ? s.slice(0, 80) + '…' : s;
};
const truncate = (t) => (t && t.length > 600 ? t.slice(0, 600) + '…' : t);
</script>

<style scoped>
.ex { max-width: 860px; margin: 0 auto; padding: 32px 24px 64px; }
.ex-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.ex-sub { color: var(--text-secondary); margin: 8px 0 22px; font-size: 15px; line-height: 1.6; }

.ex-form { border: 1px solid var(--border-color); border-radius: 14px; padding: 18px; margin-bottom: 24px; }
.ex-row { margin-bottom: 12px; }
.ex-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .04em; }
.mono { font-family: 'Courier New', monospace; }
.ex-opts { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 6px; }
.ex-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); cursor: pointer; }
.ex-steps { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
.ex-steps .input-field { padding: 5px 8px; }
.ex-primary { margin-left: auto; padding: 9px 18px; border-radius: 8px; background: var(--accent-color); color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.ex-primary:disabled { opacity: .45; cursor: not-allowed; }
.ex-warn { color: #d29922; font-size: 12.5px; margin: 12px 0 0; }
.ex-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }

.ex-summary { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.ex-verdict { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.ex-verdict.ok { color: #3fb950; background: rgba(63,185,80,.16); }
.ex-verdict.warn { color: #d29922; background: rgba(210,153,34,.14); }
.ex-meta { font-size: 12.5px; color: var(--text-secondary); }
.ex-pill { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--text-secondary); }
.ex-pill.bad { color: #f85149; background: rgba(248,81,73,.14); }
.ex-pill.warn { color: #d29922; background: rgba(210,153,34,.14); }
.ex-answer { font-size: 14px; line-height: 1.6; color: var(--text-primary); background: var(--panel-bg); border-left: 3px solid var(--accent-color); padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 0 0 16px; }

.ex-findings { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
.ex-finding { display: flex; align-items: baseline; gap: 8px; font-size: 12.5px; color: var(--text-secondary); }
.ex-finding code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; margin-right: 3px; color: var(--text-primary); }
.ex-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 1px 6px; border-radius: 4px; background: rgba(255,255,255,.08); flex-shrink: 0; }
.ex-finding.bad .ex-tag, .ex-finding.high .ex-tag { background: rgba(248,81,73,.16); color: #f85149; }
.ex-finding.warn .ex-tag, .ex-finding.medium .ex-tag { background: rgba(210,153,34,.16); color: #d29922; }

.ex-section { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); margin: 8px 0 12px; }
.ex-steps { }
.ex-step { list-style: none; border-left: 2px solid var(--border-color); padding: 0 0 14px 16px; margin-left: 4px; position: relative; }
.ex-think { font-size: 13.5px; line-height: 1.6; color: var(--text-primary); margin: 0 0 8px; }
.ex-call { border: 1px solid var(--border-color); border-radius: 9px; padding: 9px 12px; margin-bottom: 6px; }
.ex-call.blocked { border-color: #f85149; }
.ex-call.err { border-color: #d29922; }
.ex-call-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ex-verb { font-family: 'Courier New', monospace; font-weight: 700; color: var(--accent-color); }
.ex-args { font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--text-secondary); }
.ex-out { margin: 8px 0 0; padding: 8px 10px; background: #010409; border: 1px solid var(--border-color); border-radius: 6px; font-family: 'Courier New', monospace; font-size: 11.5px; line-height: 1.5; color: var(--text-primary); overflow-x: auto; white-space: pre-wrap; max-height: 200px; }
</style>
