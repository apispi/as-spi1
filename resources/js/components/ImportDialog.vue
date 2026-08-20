<template>
  <div class="imp-scrim" @click.self="$emit('close')">
    <div class="imp-modal" role="dialog" aria-modal="true" aria-label="Import">
      <header class="imp-head">
        <h2>Import</h2>
        <div class="imp-tabs">
          <button :class="['imp-tab', mode === 'curl' ? 'active' : '']" @click="mode = 'curl'">cURL</button>
          <button :class="['imp-tab', mode === 'openapi' ? 'active' : '']" @click="mode = 'openapi'">OpenAPI</button>
        </div>
        <button class="imp-x" @click="$emit('close')" aria-label="Close"><Icon name="close" :size="18" /></button>
      </header>

      <div class="imp-body">
        <template v-if="mode === 'curl'">
          <p class="imp-hint">
            Paste a <code>curl</code> command — including “Copy as cURL” from your
            browser's network tab. It fills the tester; nothing is saved until you save it.
          </p>
          <textarea
            v-model="command"
            class="input-field imp-area mono"
            rows="8"
            placeholder="curl 'https://api.example.com/users' -H 'Accept: application/json'"
          ></textarea>
        </template>

        <template v-else>
          <p class="imp-hint">
            Paste an OpenAPI 3 document (YAML or JSON). Each operation becomes a
            saved request, with the server URL as
            <code v-pre>{{base_url}}</code> so you can point it at any environment.
          </p>
          <textarea
            v-model="spec"
            class="input-field imp-area mono"
            rows="8"
            placeholder="openapi: 3.0.0&#10;info:&#10;  title: My API"
          ></textarea>

          <label class="imp-check">
            <input type="checkbox" v-model="createCollection" />
            <span>Also create a collection with every operation as a step</span>
          </label>
          <label class="imp-check">
            <input type="checkbox" v-model="createEnvironment" />
            <span>Also create an environment holding the server URL</span>
          </label>
        </template>

        <p v-if="error" class="imp-error">{{ error }}</p>

        <ul v-if="warnings.length" class="imp-warnings">
          <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
        </ul>

        <p v-if="success" class="imp-success">{{ success }}</p>

        <footer class="imp-actions">
          <button class="primary" @click="submit" :disabled="busy || !canSubmit">
            {{ busy ? 'Importing…' : 'Import' }}
          </button>
          <button class="secondary" @click="$emit('close')" :disabled="busy">Close</button>
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import Icon from './Icon.vue';
import { useRequestsStore } from '../store/requests';
import { useCollectionsStore } from '../store/collections';
import { useEnvironmentsStore } from '../store/environments';

const emit = defineEmits(['close', 'loaded']);

const requestsStore = useRequestsStore();
const collectionsStore = useCollectionsStore();
const envStore = useEnvironmentsStore();

const mode = ref('curl');
const command = ref('');
const spec = ref('');
const createCollection = ref(true);
const createEnvironment = ref(true);
const busy = ref(false);
const error = ref('');
const success = ref('');
const warnings = ref([]);

const canSubmit = computed(() =>
  mode.value === 'curl' ? command.value.trim().length > 0 : spec.value.trim().length > 0);

const reset = () => {
  error.value = '';
  success.value = '';
  warnings.value = [];
};

const submit = async () => {
  reset();
  busy.value = true;

  try {
    if (mode.value === 'curl') {
      const res = await axios.post('/api/import/curl', { command: command.value });
      warnings.value = res.data.warnings || [];
      // Hand the parsed request to the tester rather than saving it.
      emit('loaded', {
        protocol: 'rest',
        method: res.data.method,
        url: res.data.url,
        headers: res.data.headers,
        body: res.data.body || '',
      });
      if (!warnings.value.length) emit('close');
      else success.value = 'Loaded into the tester.';
    } else {
      const res = await axios.post('/api/import/openapi', {
        document: spec.value,
        create_collection: createCollection.value,
        create_environment: createEnvironment.value,
      });
      warnings.value = res.data.warnings || [];
      success.value = `Imported ${res.data.imported} request(s)`
        + (res.data.collection ? ` into “${res.data.collection.name}”` : '')
        + (res.data.environment ? `, with environment “${res.data.environment.name}”` : '')
        + '.';

      await Promise.all([requestsStore.fetchSavedRequests(), collectionsStore.fetch(), envStore.fetch()]);
    }
  } catch (e) {
    error.value = e.response?.data?.message
      || Object.values(e.response?.data?.errors || {})[0]?.[0]
      || 'Import failed.';
  } finally {
    busy.value = false;
  }
};

const onKey = (e) => { if (e.key === 'Escape') emit('close'); };
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.imp-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.imp-modal { width: min(760px, 100%); max-height: 84vh; display: flex; flex-direction: column; background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.imp-head { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
.imp-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.imp-tabs { display: flex; gap: 4px; }
.imp-tab { padding: 6px 12px; border-radius: 7px; border: 1px solid transparent; background: none; color: var(--text-secondary); font-size: 13px; cursor: pointer; }
.imp-tab.active { background: var(--accent-soft, rgba(88,166,255,.12)); border-color: var(--accent-color); color: var(--accent-color); font-weight: 600; }
.imp-x { margin-left: auto; background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.imp-x:hover { color: var(--text-primary); }

.imp-body { padding: 18px 20px; overflow-y: auto; }
.imp-hint { font-size: 12.5px; line-height: 1.6; color: var(--text-secondary); margin: 0 0 12px; }
.imp-hint code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; }
.imp-area { width: 100%; resize: vertical; }
.mono { font-family: 'Courier New', monospace; font-size: 12.5px; }
.imp-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 12px; cursor: pointer; }
.imp-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.imp-success { color: #3fb950; font-size: 13px; margin: 12px 0 0; }
.imp-warnings { margin: 12px 0 0; padding-left: 20px; color: #d29922; font-size: 12.5px; }
.imp-warnings li { margin-bottom: 4px; }
.imp-actions { display: flex; gap: 8px; margin-top: 18px; }
</style>
