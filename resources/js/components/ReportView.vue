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

    <!-- Spec diffs: OpenAPI and GraphQL share a change shape -->
    <template v-else-if="type === 'api_diff' || type === 'graphql_diff' || type === 'schema_drift'">
      <div class="rv-hero">
        <span class="rv-grade" :class="diffOf(data).breaking || data.error ? 'g-f' : 'g-a'">{{ diffOf(data).breaking || data.error ? '✗' : '✓' }}</span>
        <div>
          <div class="rv-score">{{ diffOf(data).breaking ? (diffOf(data).breaking_count + ' breaking change' + (diffOf(data).breaking_count === 1 ? '' : 's')) : (data.error || 'No breaking changes') }}</div>
          <div v-if="type === 'schema_drift'" class="rv-muted">
            {{ data.flavour === 'graphql' ? 'GraphQL' : 'OpenAPI' }} · {{ data.target_url }}
          </div>
          <div v-else-if="type === 'graphql_diff'" class="rv-muted">
            {{ data.type_count }} type(s) · {{ data.new_source || 'GraphQL schema' }}
          </div>
          <div v-else class="rv-muted">{{ data.new_title }} · {{ data.old_version || '—' }} → {{ data.new_version || '—' }}</div>
        </div>
      </div>
      <p v-if="!diffOf(data).changes || !diffOf(data).changes.length" class="rv-clean">
        {{ data.error ? data.error : 'Identical — nothing changed. ✅' }}
      </p>
      <div v-for="(c, i) in diffOf(data).changes" :key="i" class="rv-row" :class="'diff-' + c.severity">
        <span class="rv-badge">{{ severityLabel(c.severity) }}</span>
        <div>
          <strong v-if="c.operation || c.location" class="rv-mono">{{ c.operation || c.location }}</strong>
          <div class="rv-muted">{{ c.detail }}</div>
        </div>
      </div>
    </template>

    <!-- Collection run: the most common report there is -->
    <template v-else-if="type === 'collection_run'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.passed ? 'g-a' : 'g-f'">{{ data.passed ? '✓' : '✗' }}</span>
        <div>
          <div class="rv-score">{{ data.passed_count }}/{{ data.total }} step(s) passed</div>
          <div class="rv-muted">
            {{ data.collection?.name || 'Collection' }}
            <template v-if="data.environment"> · {{ data.environment.name }}</template>
            <template v-if="data.time_ms != null"> · {{ data.time_ms }}ms</template>
            <template v-if="data.skipped_count"> · {{ data.skipped_count }} skipped</template>
          </div>
        </div>
      </div>

      <p v-if="data.error" class="rv-err">{{ data.error }}</p>

      <div v-for="(s, i) in data.steps" :key="i" class="rv-row" :class="stepClass(s)">
        <span class="rv-badge">{{ s.skipped ? 'skip' : (s.passed ? 'pass' : 'fail') }}</span>
        <div class="rv-grow">
          <strong>{{ s.method }} {{ s.name }}</strong>
          <span v-if="s.status != null" class="rv-muted"> · {{ s.status }}</span>
          <span v-if="s.time_ms" class="rv-muted"> · {{ s.time_ms }}ms</span>
          <div v-if="s.url" class="rv-muted rv-mono">{{ s.url }}</div>
          <div v-if="s.error" class="rv-muted">{{ s.error }}</div>
          <div v-if="(s.unresolved || []).length" class="rv-muted">
            Unresolved: {{ s.unresolved.join(', ') }}
          </div>
          <div v-for="(a, j) in failedAssertions(s)" :key="j" class="rv-muted rv-mono">
            ✗ {{ a.path }} {{ a.operator }} {{ a.expected }} — actual: {{ printable(a.actual) }}
          </div>
        </div>
      </div>
    </template>

    <!-- Dataset run -->
    <template v-else-if="type === 'dataset_run'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.passed ? 'g-a' : 'g-f'">{{ data.passed ? '✓' : '✗' }}</span>
        <div>
          <div class="rv-score">{{ data.passed_rows }}/{{ data.rows }} row(s) passed</div>
          <div class="rv-muted">
            {{ data.collection?.name || 'Collection' }}
            <template v-if="data.environment"> · {{ data.environment.name }}</template>
          </div>
        </div>
      </div>
      <div v-for="(it, i) in data.iterations" :key="i" class="rv-row" :class="it.passed ? 'st-pass' : 'st-fail'">
        <span class="rv-badge">{{ it.passed ? 'pass' : 'fail' }}</span>
        <div>
          <strong>Row {{ it.row }}</strong>
          <span class="rv-muted"> · {{ it.passed_count }}/{{ it.total }} step(s)</span>
          <div v-if="it.first_failure" class="rv-muted">First failure: {{ it.first_failure }}</div>
          <div v-if="(it.variables || []).length" class="rv-muted rv-mono">{{ it.variables.join(', ') }}</div>
        </div>
      </div>
    </template>

    <!-- Environment parity -->
    <template v-else-if="type === 'parity'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.in_parity ? 'g-a' : 'g-f'">{{ data.in_parity ? '✓' : '✗' }}</span>
        <div>
          <div class="rv-score">{{ data.in_parity ? 'In parity' : data.diverged_count + ' step(s) diverged' }}</div>
          <div class="rv-muted">
            {{ data.environment_a?.name || 'A' }} vs {{ data.environment_b?.name || 'B' }} · {{ data.total }} step(s)
          </div>
        </div>
      </div>
      <div v-for="(s, i) in data.steps" :key="i" class="rv-row" :class="s.diverged ? 'st-fail' : 'st-pass'">
        <span class="rv-badge">{{ s.diverged ? 'differs' : 'same' }}</span>
        <div>
          <strong>{{ s.name || 'Step ' + (i + 1) }}</strong>
          <div v-if="s.reason" class="rv-muted">{{ s.reason }}</div>
        </div>
      </div>
    </template>

    <!-- MCP tool-surface drift -->
    <template v-else-if="type === 'mcp_drift'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.diff?.drifted ? 'g-f' : 'g-a'">{{ data.diff?.drifted ? '✗' : '✓' }}</span>
        <div>
          <div class="rv-score">{{ data.diff?.drifted ? 'Tool surface changed' : 'No drift' }}</div>
          <div class="rv-muted">{{ data.target_url }}</div>
        </div>
      </div>
      <p v-if="data.error" class="rv-err">{{ data.error }}</p>
      <div v-for="(t, i) in (data.diff?.removed || [])" :key="'r' + i" class="rv-row st-fail">
        <span class="rv-badge">removed</span><div><strong>{{ t }}</strong></div>
      </div>
      <div v-for="(c, i) in (data.diff?.changed || [])" :key="'c' + i" class="rv-row st-warn">
        <span class="rv-badge">changed</span>
        <div><strong>{{ c.tool }}</strong> <span class="rv-muted">{{ (c.what || []).join(' + ') }}</span></div>
      </div>
      <div v-for="(t, i) in (data.diff?.added || [])" :key="'a' + i" class="rv-row st-pass">
        <span class="rv-badge">added</span><div><strong>{{ t }}</strong></div>
      </div>
    </template>

    <!-- Performance profile -->
    <template v-else-if="type === 'perf'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.success_rate === 100 ? 'g-a' : 'g-c'">{{ data.success_rate }}%</span>
        <div>
          <div class="rv-score">{{ data.success }}/{{ data.samples }} succeeded</div>
          <div class="rv-muted">
            {{ data.wall_ms }}ms wall
            <template v-if="data.requests_per_sec"> · {{ data.requests_per_sec }} req/s</template>
            <template v-if="data.transport_errors"> · {{ data.transport_errors }} transport error(s)</template>
          </div>
        </div>
      </div>
      <div v-if="data.latency" class="rv-stats">
        <div v-for="k in ['min', 'avg', 'p50', 'p90', 'p95', 'p99', 'max']" :key="k" class="rv-stat">
          <span class="rv-stat-k">{{ k }}</span>
          <span class="rv-stat-v">{{ data.latency[k] == null ? '—' : data.latency[k] + 'ms' }}</span>
        </div>
      </div>
      <div v-for="(count, code) in (data.status_distribution || {})" :key="code" class="rv-row">
        <span class="rv-badge">{{ code }}</span><div>{{ count }} response(s)</div>
      </div>
    </template>

    <!-- Contract fuzzing -->
    <template v-else-if="type === 'fuzz'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.passed ? 'g-a' : 'g-f'">{{ data.passed ? '✓' : '✗' }}</span>
        <div>
          <div class="rv-score">
            {{ data.passed ? 'No findings' : data.findings + ' finding' + (data.findings === 1 ? '' : 's') }}
          </div>
          <div class="rv-muted">
            {{ data.total }} variant(s)
            <template v-if="data.server_errors"> · {{ data.server_errors }} server error(s)</template>
            <template v-if="data.accepted_invalid"> · {{ data.accepted_invalid }} accepted invalid input</template>
          </div>
        </div>
      </div>
      <div v-for="(r, i) in data.results" :key="i" class="rv-row" :class="fuzzClass(r.verdict)">
        <span class="rv-badge">{{ (r.verdict || '').replace('_', ' ') }}</span>
        <div class="rv-grow">
          <strong>{{ r.label }}</strong>
          <span v-if="r.status != null" class="rv-muted"> · HTTP {{ r.status }}</span>
          <div class="rv-muted">
            {{ r.expects_reject ? 'Should have been rejected' : 'Should have been accepted' }}
          </div>
        </div>
      </div>
    </template>

    <!-- Golden snapshot diff -->
    <template v-else-if="type === 'snapshot'">
      <div class="rv-hero">
        <span class="rv-grade" :class="data.matches ? 'g-a' : 'g-f'">{{ data.matches ? '✓' : '✗' }}</span>
        <div>
          <div class="rv-score">{{ data.matches ? 'Matches the snapshot' : 'Drifted from the snapshot' }}</div>
          <div class="rv-muted">
            <template v-if="data.status_changed">status {{ data.status_from }} → {{ data.status_to }} · </template>
            {{ data.changed_count }} changed · {{ data.added_count }} added · {{ data.removed_count }} removed
          </div>
        </div>
      </div>
      <div v-for="(c, i) in (data.changed || [])" :key="'c' + i" class="rv-row st-fail">
        <span class="rv-badge">changed</span>
        <div class="rv-grow">
          <strong class="rv-mono">{{ c.path }}</strong>
          <div class="rv-muted rv-mono">{{ printable(c.from) }} → {{ printable(c.to) }}</div>
        </div>
      </div>
      <div v-for="(r, i) in (data.removed || [])" :key="'r' + i" class="rv-row st-fail">
        <span class="rv-badge">removed</span>
        <div class="rv-grow"><strong class="rv-mono">{{ r.path || r }}</strong></div>
      </div>
      <div v-for="(a, i) in (data.added || [])" :key="'a' + i" class="rv-row st-warn">
        <span class="rv-badge">added</span>
        <div class="rv-grow"><strong class="rv-mono">{{ a.path || a }}</strong></div>
      </div>
    </template>

    <!--
      Anything else. Nine report types used to render an empty modal because
      they had no branch here, so a generic view means a new type is legible on
      the day it ships rather than invisible until someone writes a renderer.
    -->
    <template v-else>
      <div v-for="(row, i) in summaryRows(data)" :key="i" class="rv-row">
        <span class="rv-badge">{{ row.key }}</span>
        <div class="rv-grow">{{ row.value }}</div>
      </div>
      <details class="rv-raw">
        <summary>Show the full record</summary>
        <pre class="rv-pre">{{ pretty(data) }}</pre>
      </details>
    </template>
  </div>
</template>

<script setup>
defineProps({
  // Specific branches exist for the common types; anything else falls through
  // to a generic view rather than rendering nothing at all.
  type: { type: String, required: true },
  data: { type: Object, required: true },
});

const stepClass = (s) => (s.skipped ? 'st-skip' : (s.passed ? 'st-pass' : 'st-fail'));

// Mirrors FuzzRunner: only server_error and accepted_invalid are findings.
// "rejected" is a pass — the API correctly refused bad input — and a transport
// "error" is neither a finding nor a clean result.
const FUZZ_FINDINGS = ['server_error', 'accepted_invalid'];
const fuzzClass = (verdict) => {
  if (FUZZ_FINDINGS.includes(verdict)) return 'st-fail';
  return verdict === 'error' ? 'st-warn' : 'st-pass';
};

const failedAssertions = (s) =>
  ((s.assertions && s.assertions.results) || []).filter((a) => !a.passed);

const printable = (v) => {
  if (v === null || v === undefined) return 'null';
  if (typeof v === 'object') { try { return JSON.stringify(v); } catch { return String(v); } }
  return String(v);
};

const pretty = (d) => { try { return JSON.stringify(d, null, 2); } catch { return String(d); } };

// The scalar fields of an unknown payload, which is usually where its verdict
// and counts live. Nested structures are left to the raw record below.
const summaryRows = (d) => {
  if (!d || typeof d !== 'object') return [];

  return Object.entries(d)
    .filter(([, v]) => v === null || ['string', 'number', 'boolean'].includes(typeof v))
    .slice(0, 12)
    .map(([key, value]) => ({ key: key.replace(/_/g, ' '), value: printable(value) }));
};

const gradeClass = (g) => {
  const l = (g || '')[0];
  return { A: 'g-a', B: 'g-b', C: 'g-c', D: 'g-d', F: 'g-f' }[l] || 'g-c';
};
const jsonArgs = (a) => { try { return JSON.stringify(a); } catch { return String(a); } };
// A drift report nests the comparison under `diff`; the manual diffs put it
// at the top level. Both render the same way once unwrapped.
const diffOf = (d) => (d && d.diff ? d.diff : (d || {}));
const severityLabel = (s) => ({ breaking: 'breaking', non_breaking: 'safe', info: 'info' }[s] || s);
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
.diff-breaking .rv-badge { background: rgba(248,81,73,.16); color: #f85149; }
.diff-non_breaking .rv-badge { background: rgba(210,153,34,.18); color: #d29922; }
.diff-info .rv-badge { background: rgba(63,185,80,.16); color: #3fb950; }
.rv-grow { min-width: 0; flex: 1; }
.rv-err { color: #f85149; margin: 0 0 12px; }
.rv-stats { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.rv-stat {
  border: 1px solid var(--border-color); border-radius: 8px; padding: 7px 11px;
  display: flex; flex-direction: column; gap: 2px; min-width: 64px;
}
.rv-stat-k { font-size: 0.66rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); }
.rv-stat-v { font-weight: 700; color: var(--text-primary); font-size: 0.85rem; }
.rv-raw { margin-top: 12px; }
.rv-raw summary { cursor: pointer; font-size: 0.82rem; color: var(--text-secondary); }
.rv-raw summary:hover { color: var(--text-primary); }
.rv-pre {
  margin: 10px 0 0; padding: 11px; max-height: 360px; overflow: auto;
  background: var(--bg-secondary, #161b22); border-radius: 8px;
  font-family: ui-monospace, Menlo, monospace; font-size: 0.74rem; line-height: 1.5;
  color: var(--text-secondary); white-space: pre-wrap; word-break: break-word;
}
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
