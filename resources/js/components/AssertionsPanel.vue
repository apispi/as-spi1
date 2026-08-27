<template>
  <div class="asrt">
    <div class="asrt-head" @click="collapsed = !collapsed">
      <Icon :name="collapsed ? 'chevronRight' : 'chevronDown'" :size="14" />
      <h3>Assertions</h3>

      <span v-if="summary" class="asrt-summary" :class="summary.passed ? 'ok' : 'bad'">
        {{ summary.passed_count }}/{{ summary.total }} passed
      </span>
      <span v-else-if="rows.length" class="asrt-count">{{ rows.length }}</span>

      <div class="asrt-actions" @click.stop>
        <button class="asrt-btn" @click="generate" :disabled="!response || generating"
                title="Generate assertions from the current response with AI">
          {{ generating ? 'Generating…' : 'Generate' }}
        </button>
        <button
          v-if="summary && !summary.passed"
          class="asrt-btn asrt-heal"
          @click="heal"
          :disabled="healing"
          title="The API changed? Let AI propose updated assertions from this response — review before saving."
        >
          {{ healing ? 'Proposing…' : 'Heal' }}
        </button>
        <button class="asrt-btn" @click="run" :disabled="!response || !rows.length || running">
          {{ running ? 'Running…' : 'Run' }}
        </button>
        <button v-if="savedRequestId" class="asrt-btn" @click="save" :disabled="saving">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>

    <div v-if="!collapsed" class="asrt-body">
      <p v-if="error" class="asrt-error">{{ error }}</p>

      <div v-if="healNote" class="asrt-healnote">
        <p><strong>AI proposal applied:</strong> {{ healNote.summary }}</p>
        <p v-for="d in healNote.dropped" :key="d.path" class="asrt-dropped">
          Dropped <code>{{ d.path }}</code> — {{ d.reason }}
        </p>
        <p class="asrt-healhint">Review the rows above, re-run, and Save to accept the new contract — or Undo.</p>
        <button class="asrt-btn" @click="undoHeal">Undo</button>
      </div>

      <p v-if="!rows.length" class="asrt-empty">
        No assertions yet. Send a request, then <strong>Generate</strong> them from the
        response — or add one by hand.
      </p>

      <div v-else class="asrt-rows">
        <div v-for="(row, i) in rows" :key="i" class="asrt-row" :class="verdictClass(i)">
          <span class="asrt-mark" :title="resultFor(i)?.error || ''">
            <template v-if="resultFor(i)">{{ resultFor(i).passed ? '✓' : '✕' }}</template>
            <template v-else>·</template>
          </span>

          <input v-model="row.path" class="input-field mono" placeholder="status, header.x, data.items.0.id" />

          <select v-model="row.operator" class="input-field">
            <option v-for="op in OPERATORS" :key="op" :value="op">{{ op }}</option>
          </select>

          <input
            v-model="row.expected"
            class="input-field mono"
            :placeholder="needsExpected(row.operator) ? 'expected' : '—'"
            :disabled="!needsExpected(row.operator)"
          />

          <button class="asrt-del" @click="rows.splice(i, 1); results = null" aria-label="Remove assertion">
            <Icon name="close" :size="13" />
          </button>

          <p v-if="resultFor(i) && !resultFor(i).passed" class="asrt-why">
            {{ resultFor(i).error || `got ${format(resultFor(i).actual)}` }}
          </p>
        </div>
      </div>

      <button class="asrt-add" @click="addRow">+ Add assertion</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Icon from './Icon.vue';

const props = defineProps({
  response: Object,
  savedRequestId: [Number, String],
  initial: Array,
});

// Mirrors App\Services\Assertions\Assertion::OPERATORS. Kept in sync by
// AssertionVocabularyTest, which fails if the two drift apart.
const OPERATORS = [
  'equals', 'not_equals', 'contains', 'not_contains', 'matches',
  'exists', 'not_exists', 'greater_than', 'greater_or_equal',
  'less_than', 'less_or_equal', 'is_type', 'has_length',
];
const NO_EXPECTED = ['exists', 'not_exists'];

const rows = ref([]);
const results = ref(null);
const collapsed = ref(true);
const running = ref(false);
const generating = ref(false);
const saving = ref(false);
const error = ref('');
const healing = ref(false);
const healNote = ref(null);
let preHealRows = null;

const needsExpected = (op) => !NO_EXPECTED.includes(op);
const summary = computed(() => results.value);
const resultFor = (i) => results.value?.results?.[i] || null;
const verdictClass = (i) => {
  const r = resultFor(i);
  return r ? (r.passed ? 'pass' : 'fail') : '';
};

const format = (v) => {
  if (v === null || v === undefined) return 'nothing';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};

const addRow = () => {
  rows.value.push({ path: '', operator: 'equals', expected: '' });
  collapsed.value = false;
};

// Load assertions stored on a saved request when one is opened.
watch(() => props.initial, (incoming) => {
  rows.value = (incoming || []).map((a) => ({
    path: a.path || '',
    operator: a.operator || 'equals',
    expected: a.expected ?? '',
    description: a.description || null,
  }));
  results.value = null;
  if (rows.value.length) collapsed.value = false;
}, { immediate: true });

// A new response invalidates the previous verdicts; re-run automatically so
// the panel always reflects the response on screen.
watch(() => props.response, (res) => {
  results.value = null;
  if (res && rows.value.length) run();
});

const payloadRows = () =>
  rows.value
    .filter((r) => r.path.trim())
    .map((r) => ({
      path: r.path.trim(),
      operator: r.operator,
      expected: needsExpected(r.operator) && r.expected !== '' ? r.expected : null,
      description: r.description || null,
    }));

const run = async () => {
  if (!props.response) return;
  const assertions = payloadRows();
  if (!assertions.length) return;

  running.value = true;
  error.value = '';
  try {
    const res = await axios.post('/api/assertions/evaluate', {
      assertions,
      response: {
        status: props.response.status,
        time_ms: props.response.time_ms,
        headers: props.response.headers || {},
        body: props.response.body ?? null,
      },
    });
    results.value = res.data;
    collapsed.value = false;
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to evaluate assertions.';
  } finally {
    running.value = false;
  }
};

const generate = async () => {
  if (!props.response) return;
  generating.value = true;
  error.value = '';
  try {
    const res = await axios.post('/api/ai/assert', {
      response: typeof props.response.body === 'string'
        ? props.response.body
        : JSON.stringify(props.response.body ?? ''),
      status: props.response.status,
    });
    const generated = res.data?.assertions || [];
    if (!generated.length) {
      error.value = 'The model returned no assertions.';
      return;
    }
    // Append rather than replace, so hand-written assertions survive.
    generated.forEach((a) => rows.value.push({
      path: a.path || '',
      operator: OPERATORS.includes(a.operator) ? a.operator : 'equals',
      expected: a.expected ?? '',
      description: a.description || null,
    }));
    collapsed.value = false;
    await run();
  } catch (e) {
    error.value = e.response?.data?.error || 'Failed to generate assertions.';
  } finally {
    generating.value = false;
  }
};

// The API changed and assertions started failing: ask AI for the updated set
// that preserves intent, applied as a reviewable proposal — never saved
// without the user pressing Save.
const heal = async () => {
  if (!props.response) return;
  healing.value = true;
  error.value = '';
  try {
    const res = await axios.post('/api/ai/heal', {
      assertions: payloadRows(),
      response: typeof props.response.body === 'string'
        ? props.response.body
        : JSON.stringify(props.response.body ?? ''),
      status: props.response.status,
    });
    const proposed = res.data?.assertions || [];
    if (!proposed.length) {
      error.value = 'The model proposed nothing usable.';
      return;
    }
    preHealRows = rows.value.map((r) => ({ ...r }));
    rows.value = proposed.map((a) => ({
      path: a.path || '',
      operator: OPERATORS.includes(a.operator) ? a.operator : 'equals',
      expected: a.expected ?? '',
      description: a.description || null,
    }));
    healNote.value = {
      summary: res.data.summary || 'Assertions updated to match the new response.',
      dropped: res.data.dropped || [],
    };
    await run();
  } catch (e) {
    error.value = e.response?.data?.error || 'Failed to propose a fix.';
  } finally {
    healing.value = false;
  }
};

const undoHeal = () => {
  if (preHealRows) rows.value = preHealRows.map((r) => ({ ...r }));
  preHealRows = null;
  healNote.value = null;
  results.value = null;
};

const save = async () => {
  if (!props.savedRequestId) return;
  saving.value = true;
  error.value = '';
  try {
    await axios.put(`/api/saved-requests/${props.savedRequestId}/assertions`, {
      assertions: payloadRows(),
    });
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to save assertions.';
  } finally {
    saving.value = false;
  }
};
</script>

<style scoped>
.asrt { border-top: 1px solid var(--border-color); background: var(--panel-bg); display: flex; flex-direction: column; min-height: 0; }
.asrt-head { display: flex; align-items: center; gap: 8px; padding: 10px 16px; cursor: pointer; user-select: none; color: var(--text-secondary); }
.asrt-head h3 { font-size: 13px; font-weight: 600; margin: 0; color: var(--text-primary); text-transform: uppercase; letter-spacing: .04em; }
.asrt-summary { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.asrt-summary.ok { color: #3fb950; background: rgba(63,185,80,.16); }
.asrt-summary.bad { color: #f85149; background: rgba(248,81,73,.14); }
.asrt-count { font-size: 11.5px; color: var(--text-secondary); background: rgba(255,255,255,.06); padding: 2px 8px; border-radius: 999px; }
.asrt-actions { margin-left: auto; display: flex; gap: 6px; }
.asrt-btn { padding: 5px 11px; font-size: 12px; border-radius: 6px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer; }
.asrt-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.asrt-btn:disabled { opacity: .45; cursor: not-allowed; }

.asrt-body { padding: 0 16px 14px; overflow-y: auto; max-height: 40vh; }
.asrt-empty { font-size: 12.5px; color: var(--text-secondary); line-height: 1.6; margin: 4px 0 12px; }
.asrt-error { color: #f85149; font-size: 12.5px; margin: 4px 0 8px; }
.asrt-heal { border-color: #a371f7; color: #a371f7; }
.asrt-heal:hover:not(:disabled) { border-color: #a371f7; color: #a371f7; background: rgba(163,113,247,.1); }
.asrt-healnote { border: 1px solid #a371f7; border-radius: 9px; padding: 10px 12px; margin: 8px 0; font-size: 12.5px; color: var(--text-secondary); }
.asrt-healnote p { margin: 0 0 6px; line-height: 1.5; }
.asrt-healnote code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; }
.asrt-dropped { color: #d29922; }
.asrt-healhint { font-size: 12px; }

.asrt-row { display: grid; grid-template-columns: 18px 1.3fr 1fr 1.2fr 26px; gap: 6px; align-items: center; margin-bottom: 6px; }
.asrt-row .input-field { padding: 5px 8px; font-size: 12.5px; }
.mono { font-family: 'Courier New', monospace; }
.asrt-mark { text-align: center; font-weight: 700; font-size: 13px; color: var(--text-secondary); }
.asrt-row.pass .asrt-mark { color: #3fb950; }
.asrt-row.fail .asrt-mark { color: #f85149; }
.asrt-why { grid-column: 2 / 5; margin: 0 0 4px; font-size: 11.5px; color: #f85149; font-family: 'Courier New', monospace; }
.asrt-del { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 2px; }
.asrt-del:hover { color: #f85149; }
.asrt-add { margin-top: 4px; padding: 6px 11px; border: 1px dashed var(--border-color); border-radius: 7px; background: none; color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.asrt-add:hover { border-color: var(--accent-color); color: var(--accent-color); }
</style>
