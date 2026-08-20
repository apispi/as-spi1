<template>
  <div class="col-scrim" @click.self="$emit('close')">
    <div class="col-modal" role="dialog" aria-modal="true" aria-label="Manage collections">
      <header class="col-head">
        <h2>Collections</h2>
        <button class="col-x" @click="$emit('close')" aria-label="Close"><Icon name="close" :size="18" /></button>
      </header>

      <div class="col-body">
        <aside class="col-list">
          <button
            v-for="c in store.collections"
            :key="c.id"
            :class="['col-item', editing && editing.id === c.id ? 'active' : '']"
            @click="edit(c)"
          >
            <span class="col-item-name">{{ c.name }}</span>
            <span class="col-badge">{{ c.steps.length }}</span>
          </button>
          <p v-if="!store.collections.length" class="col-none">No collections yet.</p>
          <button class="col-new" @click="startNew">+ New collection</button>
        </aside>

        <section v-if="editing" class="col-editor">
          <div class="col-row">
            <label class="col-label">Name</label>
            <input v-model="editing.name" class="input-field" placeholder="Smoke tests" maxlength="80" />
          </div>
          <div class="col-row">
            <label class="col-label">Description</label>
            <input v-model="editing.description" class="input-field" placeholder="Optional" maxlength="500" />
          </div>

          <label class="col-check">
            <input type="checkbox" v-model="editing.continue_on_failure" />
            <span>Keep running after a step fails</span>
          </label>

          <label class="col-label">Steps</label>
          <p v-if="!editing.steps.length" class="col-hint">
            Add saved requests below. They run top to bottom, and values you
            extract become <code v-pre>{{variables}}</code> for later steps.
          </p>

          <div v-for="(step, i) in editing.steps" :key="i" class="col-step">
            <div class="col-step-head">
              <span class="col-step-n">{{ i + 1 }}</span>
              <span class="col-step-name">{{ nameFor(step.saved_request_id) }}</span>
              <button class="col-move" @click="move(i, -1)" :disabled="i === 0" aria-label="Move up">↑</button>
              <button class="col-move" @click="move(i, 1)" :disabled="i === editing.steps.length - 1" aria-label="Move down">↓</button>
              <button class="col-del" @click="editing.steps.splice(i, 1)" aria-label="Remove step">
                <Icon name="close" :size="13" />
              </button>
            </div>

            <div v-for="(rule, j) in step.extract" :key="j" class="col-extract">
              <span class="col-extract-label">extract</span>
              <input v-model="rule.name" class="input-field mono" placeholder="token"
                     @input="rule.name = rule.name.replace(/[^A-Za-z0-9_.-]/g, '')" />
              <span class="col-extract-from">from</span>
              <input v-model="rule.path" class="input-field mono" placeholder="data.token" />
              <button class="col-del" @click="step.extract.splice(j, 1)" aria-label="Remove extraction">
                <Icon name="close" :size="12" />
              </button>
            </div>

            <button class="col-add-x" @click="step.extract.push({ name: '', path: '' })">+ Extract a value</button>
          </div>

          <div class="col-add-step">
            <select v-model="pendingRequestId" class="input-field">
              <option value="">Add a saved request…</option>
              <option v-for="r in requestsStore.savedRequests" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <button class="col-btn" @click="addStep" :disabled="!pendingRequestId">Add step</button>
          </div>

          <p v-if="error" class="col-error">{{ error }}</p>

          <footer class="col-actions">
            <button class="primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="col-btn" @click="runIt" :disabled="saving || !editing.steps.length">Run</button>
            <button v-if="editing.id" class="danger" @click="remove" :disabled="saving">Delete</button>
            <button class="secondary" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>
        </section>

        <section v-else class="col-empty">
          <p>Pick a collection to edit, or create one.</p>
          <p class="col-hint">
            A collection runs saved requests in order against one environment,
            checking each step's assertions. Run it here, or from CI with
            <code>POST /api/v1/collections/{id}/run</code>.
          </p>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useCollectionsStore } from '../store/collections';
import { useRequestsStore } from '../store/requests';
import { useEnvironmentsStore } from '../store/environments';
import Icon from './Icon.vue';

const emit = defineEmits(['close', 'ran']);
const store = useCollectionsStore();
const requestsStore = useRequestsStore();
const envStore = useEnvironmentsStore();

const editing = ref(null);
const pendingRequestId = ref('');
const saving = ref(false);
const error = ref('');

const nameFor = (id) => requestsStore.savedRequests.find((r) => r.id === id)?.name || `Request #${id}`;

const startNew = () => {
  error.value = '';
  editing.value = { id: null, name: '', description: '', continue_on_failure: false, steps: [] };
};

const edit = (c) => {
  error.value = '';
  editing.value = {
    id: c.id,
    name: c.name,
    description: c.description || '',
    continue_on_failure: !!c.continue_on_failure,
    steps: (c.steps || []).map((s) => ({
      saved_request_id: s.saved_request_id,
      extract: (s.extract || []).map((e) => ({ ...e })),
    })),
  };
};

const addStep = () => {
  editing.value.steps.push({ saved_request_id: Number(pendingRequestId.value), extract: [] });
  pendingRequestId.value = '';
};

const move = (i, delta) => {
  const steps = editing.value.steps;
  [steps[i], steps[i + delta]] = [steps[i + delta], steps[i]];
};

const payload = () => ({
  name: editing.value.name.trim(),
  description: editing.value.description || null,
  continue_on_failure: editing.value.continue_on_failure,
  steps: editing.value.steps.map((s) => ({
    saved_request_id: s.saved_request_id,
    extract: s.extract.filter((e) => e.name.trim() && e.path.trim()),
  })),
});

const save = async () => {
  saving.value = true;
  error.value = '';
  try {
    const saved = await store.save(payload(), editing.value.id);
    edit(saved);
  } catch (e) {
    const data = e.response?.data;
    error.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save collection.';
  } finally {
    saving.value = false;
  }
};

const runIt = async () => {
  saving.value = true;
  error.value = '';
  try {
    // Save first so the run reflects what is on screen.
    const saved = await store.save(payload(), editing.value.id);
    await store.run(saved.id, envStore.selectedId);
    emit('ran');
    emit('close');
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to run collection.';
  } finally {
    saving.value = false;
  }
};

const remove = async () => {
  if (!confirm(`Delete the "${editing.value.name}" collection?`)) return;
  saving.value = true;
  try {
    await store.remove(editing.value.id);
    editing.value = null;
  } catch {
    error.value = 'Failed to delete collection.';
  } finally {
    saving.value = false;
  }
};

const onKey = (e) => { if (e.key === 'Escape') emit('close'); };

onMounted(() => {
  window.addEventListener('keydown', onKey);
  if (!store.loaded) store.fetch();
  if (!requestsStore.savedRequests.length) requestsStore.fetchSavedRequests();
});
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.col-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.col-modal { width: min(900px, 100%); max-height: 84vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.col-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border-color); }
.col-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.col-x { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.col-x:hover { color: var(--text-primary); }

.col-body { display: grid; grid-template-columns: 220px 1fr; min-height: 0; flex: 1; }
.col-list { border-right: 1px solid var(--border-color); overflow-y: auto; padding: 10px; display: flex; flex-direction: column; gap: 4px; }
.col-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 11px; border-radius: 8px; border: 1px solid transparent; background: none; color: var(--text-primary); font-size: 13px; cursor: pointer; text-align: left; }
.col-item:hover { background: var(--bg-color); }
.col-item.active { background: var(--accent-soft, rgba(88,166,255,.12)); border-color: var(--accent-color); color: var(--accent-color); font-weight: 600; }
.col-item-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.col-badge { font-size: 10.5px; font-weight: 700; padding: 2px 6px; border-radius: 999px; background: rgba(255,255,255,.07); color: var(--text-secondary); }
.col-none { color: var(--text-secondary); font-size: 13px; padding: 8px 11px; margin: 0; }
.col-new { margin-top: 4px; padding: 9px; border: 1px dashed var(--border-color); border-radius: 8px; background: none; color: var(--text-secondary); font-size: 13px; cursor: pointer; }
.col-new:hover { border-color: var(--accent-color); color: var(--accent-color); }

.col-editor { padding: 18px 20px; overflow-y: auto; }
.col-empty { padding: 32px 24px; color: var(--text-secondary); }
.col-empty p { margin: 0 0 10px; font-size: 14px; }
.col-row { margin-bottom: 12px; }
.col-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .04em; }
.col-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; cursor: pointer; }
.col-hint { font-size: 12.5px; line-height: 1.6; color: var(--text-secondary); margin: 0 0 12px; }
.col-hint code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; }

.col-step { border: 1px solid var(--border-color); border-radius: 9px; padding: 10px 12px; margin-bottom: 8px; }
.col-step-head { display: flex; align-items: center; gap: 8px; }
.col-step-n { width: 20px; height: 20px; border-radius: 999px; background: var(--accent-soft, rgba(88,166,255,.12)); color: var(--accent-color); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.col-step-name { flex: 1; font-size: 13px; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.col-move { background: none; border: 1px solid var(--border-color); border-radius: 5px; color: var(--text-secondary); cursor: pointer; width: 22px; height: 22px; font-size: 11px; }
.col-move:disabled { opacity: .35; cursor: not-allowed; }
.col-del { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 2px; }
.col-del:hover { color: #f85149; }

.col-extract { display: grid; grid-template-columns: auto 1fr auto 1.2fr 22px; gap: 6px; align-items: center; margin-top: 7px; }
.col-extract-label, .col-extract-from { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; }
.col-extract .input-field { padding: 4px 8px; font-size: 12.5px; }
.mono { font-family: 'Courier New', monospace; }
.col-add-x { margin-top: 7px; background: none; border: none; color: var(--text-secondary); font-size: 12px; cursor: pointer; padding: 0; }
.col-add-x:hover { color: var(--accent-color); }

.col-add-step { display: flex; gap: 8px; margin-top: 10px; }
.col-add-step .input-field { flex: 1; }
.col-btn { padding: 7px 14px; border-radius: 7px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 13px; cursor: pointer; }
.col-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.col-btn:disabled { opacity: .45; cursor: not-allowed; }

.col-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.col-actions { display: flex; gap: 8px; margin-top: 18px; }
.col-actions .danger { margin-left: auto; background: none; border: 1px solid var(--border-color); color: #f85149; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; }
.col-actions .danger:hover { border-color: #f85149; }

@media (max-width: 720px) {
  .col-body { grid-template-columns: 1fr; }
  .col-list { border-right: none; border-bottom: 1px solid var(--border-color); }
}
</style>
