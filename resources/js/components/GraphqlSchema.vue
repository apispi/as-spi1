<template>
  <div class="gq-scrim" @click.self="$emit('close')">
    <div class="gq-modal" role="dialog" aria-modal="true" aria-label="GraphQL schema">
      <header class="gq-head">
        <h2>GraphQL schema</h2>
        <button class="gq-x" @click="$emit('close')" aria-label="Close"><Icon name="close" :size="18" /></button>
      </header>

      <div class="gq-body">
        <p class="gq-hint">Introspects <code>{{ url }}</code> and lists its operations. Pick one to drop a query skeleton into the request body.</p>

        <p v-if="loading" class="gq-muted">Introspecting…</p>
        <p v-else-if="error" class="gq-error">{{ error }}</p>

        <template v-else-if="summary">
          <p class="gq-meta">
            {{ summary.type_count }} types ·
            {{ queries.length }} quer{{ queries.length === 1 ? 'y' : 'ies' }} ·
            {{ mutations.length }} mutation{{ mutations.length === 1 ? '' : 's' }}
          </p>

          <template v-for="group in groups" :key="group.kind">
            <h3 v-if="group.ops.length" class="gq-group">{{ group.label }}</h3>
            <ul v-if="group.ops.length" class="gq-ops">
              <li v-for="op in group.ops" :key="op.kind + op.name" class="gq-op">
                <div class="gq-op-main">
                  <code class="gq-op-sig">{{ op.name }}<span v-if="op.args.length" class="gq-args">({{ op.args.map(a => a.name + ': ' + a.type).join(', ') }})</span>: {{ op.returns }}</code>
                  <div v-if="op.description" class="gq-op-desc">{{ op.description }}</div>
                </div>
                <button class="gq-use" @click="use(op)">Use</button>
              </li>
            </ul>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import Icon from './Icon.vue';

const props = defineProps({
  url: String,
  headers: Object,
  environmentId: [Number, String],
});
const emit = defineEmits(['close', 'insert']);

const loading = ref(true);
const error = ref('');
const summary = ref(null);

const queries = computed(() => (summary.value?.operations || []).filter((o) => o.kind === 'query'));
const mutations = computed(() => (summary.value?.operations || []).filter((o) => o.kind === 'mutation'));
const groups = computed(() => [
  { kind: 'query', label: 'Queries', ops: queries.value },
  { kind: 'mutation', label: 'Mutations', ops: mutations.value },
]);

const introspect = async () => {
  loading.value = true;
  error.value = '';
  try {
    const payload = { url: props.url, headers: props.headers || {} };
    if (props.environmentId) payload.environment_id = props.environmentId;
    const res = await axios.post('/api/graphql/introspect', payload);
    summary.value = res.data;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not introspect that endpoint.';
  } finally {
    loading.value = false;
  }
};

// Build a minimal request body skeleton for the chosen operation.
const use = (op) => {
  const argList = op.args.length ? '(' + op.args.map((a) => `${a.name}: $${a.name}`).join(', ') + ')' : '';
  const inner = `${op.name}${argList}`;
  const query = op.kind === 'mutation' ? `mutation {\n  ${inner}\n}` : `{\n  ${inner}\n}`;
  emit('insert', JSON.stringify({ query }, null, 2));
  emit('close');
};

onMounted(introspect);
</script>

<style scoped>
.gq-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.gq-modal { width: min(700px, 100%); max-height: 84vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.gq-head { display: flex; align-items: center; padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
.gq-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.gq-x { margin-left: auto; background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.gq-body { padding: 18px 20px; overflow-y: auto; }
.gq-hint { font-size: 12.5px; color: var(--text-secondary); margin: 0 0 12px; line-height: 1.6; }
.gq-hint code { font-family: 'Courier New', monospace; background: rgba(127,127,127,.14); padding: 1px 5px; border-radius: 4px; word-break: break-all; }
.gq-muted { color: var(--text-secondary); font-size: 13px; }
.gq-error { color: var(--error-color); font-size: 13px; }
.gq-meta { font-size: 12px; color: var(--text-secondary); margin: 0 0 14px; }
.gq-group { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); margin: 16px 0 8px; }
.gq-ops { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.gq-op { display: flex; align-items: flex-start; gap: 12px; padding: 9px 12px; border: 1px solid var(--border-color); border-radius: 8px; }
.gq-op-main { flex: 1; min-width: 0; }
.gq-op-sig { font-family: 'Courier New', monospace; font-size: 12.5px; color: var(--text-primary); word-break: break-word; }
.gq-args { color: var(--text-secondary); }
.gq-op-desc { font-size: 12px; color: var(--text-secondary); margin-top: 3px; }
.gq-use { flex-shrink: 0; padding: 5px 12px; border-radius: 6px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12px; cursor: pointer; }
.gq-use:hover { border-color: var(--accent-color); color: var(--accent-color); }
</style>
