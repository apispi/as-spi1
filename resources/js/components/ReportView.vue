<template>
  <div class="rv">
    <!-- Conformance -->
    <template v-if="type === 'conformance'">
      <div class="rv-hero">
        <span class="rv-grade" :class="gradeClass(data.grade)">{{ data.grade }}</span>
        <div>
          <div class="rv-score">{{ data.score }}/100</div>
          <div class="rv-muted">{{ data.server || 'MCP server' }} · protocol {{ data.protocol_version || '—' }}</div>
        </div>
      </div>
      <div v-for="(c, i) in data.checks" :key="i" class="rv-row" :class="'st-' + c.status">
        <span class="rv-badge">{{ c.status }}</span>
        <div><strong>{{ c.label }}</strong><div class="rv-muted">{{ c.detail }}</div></div>
      </div>
    </template>

    <!-- Security -->
    <template v-else-if="type === 'security'">
      <div class="rv-risk" :class="'risk-' + data.risk">
        Risk: {{ (data.risk || '').toUpperCase() }} · score {{ data.score }}/100 · {{ data.scanned }} item(s)
      </div>
      <p v-if="!data.findings || !data.findings.length" class="rv-clean">No heuristic findings. ✅</p>
      <div v-for="(f, i) in data.findings" :key="i" class="rv-row" :class="'sev-' + f.severity">
        <span class="rv-badge">{{ f.severity }}</span>
        <div><strong>{{ f.title }}</strong> <span class="rv-muted">in {{ f.item }}</span>
          <div class="rv-muted rv-mono">{{ f.match }}</div></div>
      </div>
      <template v-if="data.ai && data.ai.findings && data.ai.findings.length">
        <h3 class="rv-sub">AI review</h3>
        <div v-for="(f, i) in data.ai.findings" :key="'ai'+i" class="rv-row" :class="'sev-' + f.severity">
          <span class="rv-badge">{{ f.severity }}</span>
          <div><strong>{{ f.title }}</strong> <span class="rv-muted">in {{ f.item }}</span>
            <div class="rv-muted">{{ f.detail }}</div></div>
        </div>
      </template>
    </template>

    <!-- Agent loop -->
    <template v-else-if="type === 'agent_loop'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.completed ? 'g-a' : 'g-f'">{{ data.completed ? '✓' : '⏱' }}</span>
        <div>
          <div class="rv-score">{{ data.stop_reason }}</div>
          <div class="rv-muted">{{ data.tool_call_count }} tool call(s) · {{ data.tools_available }} tool(s) available</div>
        </div>
      </div>
      <p v-if="data.goal" class="rv-goal"><strong>Goal:</strong> {{ data.goal }}</p>
      <div v-for="(s, i) in data.steps" :key="i" class="rv-step">
        <div class="rv-step-head">Step {{ s.step }}</div>
        <p v-if="s.assistant_text" class="rv-step-text">{{ s.assistant_text }}</p>
        <div v-for="(t, j) in s.tool_calls" :key="j" class="rv-toolcall" :class="{ 'tc-err': t.is_error }">
          <code>{{ t.name }}({{ jsonArgs(t.arguments) }})</code>
          <div class="rv-muted rv-mono">{{ (t.result_text || t.error || '').slice(0, 400) }}</div>
        </div>
      </div>
      <div v-if="data.final_answer" class="rv-final">
        <strong>Final answer:</strong> {{ data.final_answer }}
      </div>
    </template>
  </div>
</template>

<script setup>
defineProps({
  type: { type: String, required: true },  // conformance | security | agent_loop
  data: { type: Object, required: true },
});

const gradeClass = (g) => {
  const l = (g || '')[0];
  return { A: 'g-a', B: 'g-b', C: 'g-c', D: 'g-d', F: 'g-f' }[l] || 'g-c';
};
const jsonArgs = (a) => { try { return JSON.stringify(a); } catch { return String(a); } };
</script>

<style scoped>
.rv-hero { display: flex; gap: 14px; align-items: center; margin-bottom: 14px; }
.rv-grade { font-size: 2rem; font-weight: 800; padding: 6px 16px; border-radius: 10px; }
.rv-score { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
.rv-muted { color: var(--text-secondary); }
.rv-sub { font-size: 0.9rem; color: var(--accent-color); margin: 14px 0 6px; }
.rv-risk { font-weight: 700; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
.rv-clean { color: #3fb950; }
.rv-goal { color: var(--text-primary); margin: 0 0 12px; }
.rv-row { display: flex; gap: 10px; align-items: flex-start; border: 1px solid var(--border-color); border-radius: 8px; padding: 9px 11px; margin-bottom: 7px; }
.rv-row strong { color: var(--text-primary); }
.rv-badge { font-size: 0.66rem; text-transform: uppercase; font-weight: 700; padding: 2px 7px; border-radius: 5px; flex-shrink: 0; }
.st-pass .rv-badge { background: rgba(63,185,80,.16); color: #3fb950; }
.st-warn .rv-badge { background: rgba(210,153,34,.18); color: #d29922; }
.st-fail .rv-badge { background: rgba(248,81,73,.16); color: #f85149; }
.st-skip .rv-badge { background: var(--border-color); color: var(--text-secondary); }
.sev-high .rv-badge, .sev-critical .rv-badge { background: rgba(248,81,73,.16); color: #f85149; }
.sev-medium .rv-badge { background: rgba(210,153,34,.18); color: #d29922; }
.sev-low .rv-badge { background: var(--border-color); color: var(--text-secondary); }
.rv-mono { font-family: ui-monospace, Menlo, monospace; font-size: 0.78rem; word-break: break-word; }
.rv-step { border-left: 2px solid var(--border-color); padding: 4px 0 4px 12px; margin-bottom: 10px; }
.rv-step-head { font-weight: 700; color: var(--text-secondary); font-size: 0.8rem; }
.rv-step-text { color: var(--text-primary); font-size: 0.88rem; margin: 4px 0; }
.rv-toolcall { background: var(--bg-secondary, #161b22); border-radius: 6px; padding: 7px 9px; margin-top: 5px; }
.rv-toolcall code { color: var(--accent-color); font-size: 0.8rem; }
.rv-toolcall.tc-err code { color: #f85149; }
.rv-final { margin-top: 12px; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); }
.g-a { background: rgba(63,185,80,.16); color: #3fb950; }
.g-b { background: rgba(88,166,255,.16); color: #58a6ff; }
.g-c { background: rgba(210,153,34,.16); color: #d29922; }
.g-d { background: rgba(219,109,40,.18); color: #db6d28; }
.g-f { background: rgba(248,81,73,.16); color: #f85149; }
.risk-none { background: rgba(63,185,80,.14); color: #3fb950; }
.risk-low { background: var(--border-color); color: var(--text-secondary); }
.risk-medium { background: rgba(210,153,34,.18); color: #d29922; }
.risk-high, .risk-critical { background: rgba(248,81,73,.16); color: #f85149; }
</style>
