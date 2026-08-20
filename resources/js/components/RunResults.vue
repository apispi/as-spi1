<template>
  <div class="run">
    <div class="run-head">
      <h3>{{ run.collection.name }}</h3>
      <span class="run-verdict" :class="run.passed ? 'ok' : 'bad'">
        {{ run.passed ? 'Passed' : 'Failed' }}
      </span>
      <span class="run-meta">
        {{ run.passed_count }}/{{ run.total }} steps
        <template v-if="run.skipped_count"> · {{ run.skipped_count }} skipped</template>
        · {{ run.time_ms }} ms
        <template v-if="run.environment"> · {{ run.environment.name }}</template>
      </span>
      <button class="run-x" @click="$emit('close')" aria-label="Close results"><Icon name="close" :size="15" /></button>
    </div>

    <ul class="run-steps">
      <li v-for="step in run.steps" :key="step.index" :class="['run-step', stepClass(step)]">
        <span class="run-mark">{{ step.skipped ? '–' : (step.passed ? '✓' : '✕') }}</span>
        <div class="run-step-main">
          <div class="run-step-top">
            <span class="run-step-name">{{ step.name }}</span>
            <span v-if="step.status" class="run-status" :class="step.status < 400 ? 'ok' : 'bad'">{{ step.status }}</span>
            <span v-if="!step.skipped" class="run-time">{{ step.time_ms }} ms</span>
          </div>

          <div v-if="step.url" class="run-url" :title="step.url">{{ step.url }}</div>
          <p v-if="step.error" class="run-error">{{ step.error }}</p>

          <p v-if="step.unresolved && step.unresolved.length" class="run-warn">
            Unresolved: {{ step.unresolved.join(', ') }}
          </p>

          <ul v-if="step.assertions" class="run-asrts">
            <li v-for="(a, i) in step.assertions.results" :key="i" :class="a.passed ? 'pass' : 'fail'">
              <span class="run-asrt-mark">{{ a.passed ? '✓' : '✕' }}</span>
              <code>{{ a.path }} {{ a.operator }}<template v-if="a.expected !== null"> {{ a.expected }}</template></code>
              <span v-if="!a.passed" class="run-asrt-why">{{ a.error || `got ${format(a.actual)}` }}</span>
            </li>
          </ul>

          <p v-if="step.extracted && step.extracted.length" class="run-extracted">
            Extracted: <code v-for="n in step.extracted" :key="n">{{ n }}</code>
          </p>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import Icon from './Icon.vue';

defineProps({ run: { type: Object, required: true } });
defineEmits(['close']);

const stepClass = (step) => (step.skipped ? 'skip' : step.passed ? 'pass' : 'fail');
const format = (v) => {
  if (v === null || v === undefined) return 'nothing';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};
</script>

<style scoped>
.run { border-top: 1px solid var(--border-color); background: var(--panel-bg); display: flex; flex-direction: column; min-height: 0; max-height: 45vh; }
.run-head { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--border-color); }
.run-head h3 { font-size: 13px; font-weight: 700; margin: 0; color: var(--text-primary); }
.run-verdict { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.run-verdict.ok { color: #3fb950; background: rgba(63,185,80,.16); }
.run-verdict.bad { color: #f85149; background: rgba(248,81,73,.14); }
.run-meta { font-size: 11.5px; color: var(--text-secondary); }
.run-x { margin-left: auto; background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 2px; }
.run-x:hover { color: var(--text-primary); }

.run-steps { list-style: none; margin: 0; padding: 8px 12px 14px; overflow-y: auto; }
.run-step { display: grid; grid-template-columns: 18px 1fr; gap: 8px; padding: 8px 6px; border-bottom: 1px solid var(--border-color); }
.run-step:last-child { border-bottom: none; }
.run-mark { font-weight: 700; font-size: 13px; text-align: center; color: var(--text-secondary); }
.run-step.pass .run-mark { color: #3fb950; }
.run-step.fail .run-mark { color: #f85149; }
.run-step.skip { opacity: .55; }
.run-step-top { display: flex; align-items: center; gap: 8px; }
.run-step-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.run-status { font-size: 11px; font-weight: 700; }
.run-status.ok { color: #3fb950; }
.run-status.bad { color: #f85149; }
.run-time { font-size: 11px; color: var(--text-secondary); }
.run-url { font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--text-secondary); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.run-error { color: #f85149; font-size: 12px; margin: 4px 0 0; }
.run-warn { color: #d29922; font-size: 11.5px; margin: 4px 0 0; }

.run-asrts { list-style: none; margin: 6px 0 0; padding: 0; }
.run-asrts li { display: flex; align-items: baseline; gap: 6px; font-size: 11.5px; margin-bottom: 2px; }
.run-asrts code { font-family: 'Courier New', monospace; color: var(--text-secondary); }
.run-asrts .pass .run-asrt-mark { color: #3fb950; }
.run-asrts .fail .run-asrt-mark { color: #f85149; }
.run-asrt-why { color: #f85149; font-family: 'Courier New', monospace; }
.run-extracted { font-size: 11.5px; color: var(--text-secondary); margin: 5px 0 0; }
.run-extracted code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; margin-right: 4px; }
</style>
