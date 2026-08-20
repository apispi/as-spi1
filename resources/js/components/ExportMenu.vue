<template>
  <div class="exp" ref="root">
    <button class="exp-trigger" @click="toggle" :disabled="!url" title="Export this request as a snippet">
      Export
    </button>

    <div v-if="open" class="exp-menu">
      <div class="exp-formats">
        <button
          v-for="f in FORMATS"
          :key="f.value"
          :class="['exp-format', format === f.value ? 'active' : '']"
          @click="choose(f.value)"
        >{{ f.label }}</button>
      </div>

      <pre class="exp-snippet">{{ busy ? 'Generating…' : (snippet || error) }}</pre>

      <div class="exp-actions">
        <button class="exp-btn" @click="copy" :disabled="!snippet">{{ copied ? '✓ Copied' : 'Copy' }}</button>
        <button class="exp-btn" @click="open = false">Close</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const props = defineProps({
  method: String,
  url: String,
  headers: Object,
  body: String,
});

// Mirrors RequestExporter::FORMATS.
const FORMATS = [
  { value: 'curl', label: 'cURL' },
  { value: 'fetch', label: 'JS fetch' },
  { value: 'python', label: 'Python' },
  { value: 'http', label: 'HTTP' },
];

const root = ref(null);
const open = ref(false);
const format = ref('curl');
const snippet = ref('');
const error = ref('');
const busy = ref(false);
const copied = ref(false);

const toggle = () => {
  open.value = !open.value;
  if (open.value) generate();
};

const choose = (value) => {
  format.value = value;
  generate();
};

const generate = async () => {
  busy.value = true;
  error.value = '';
  snippet.value = '';
  copied.value = false;

  try {
    const res = await axios.post('/api/export', {
      format: format.value,
      method: props.method || 'GET',
      url: props.url,
      headers: props.headers || {},
      body: props.body || null,
    });
    snippet.value = res.data.snippet;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not generate a snippet.';
  } finally {
    busy.value = false;
  }
};

const copy = async () => {
  try {
    await navigator.clipboard.writeText(snippet.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1500);
  } catch {
    error.value = 'Clipboard unavailable.';
  }
};

const onDocMousedown = (e) => {
  if (root.value && !root.value.contains(e.target)) open.value = false;
};
const onKey = (e) => { if (e.key === 'Escape') open.value = false; };

onMounted(() => {
  document.addEventListener('mousedown', onDocMousedown);
  document.addEventListener('keydown', onKey);
});
onUnmounted(() => {
  document.removeEventListener('mousedown', onDocMousedown);
  document.removeEventListener('keydown', onKey);
});
</script>

<style scoped>
.exp { position: relative; display: inline-block; }
.exp-trigger { padding: 6px 12px; border-radius: 6px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.exp-trigger:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.exp-trigger:disabled { opacity: .45; cursor: not-allowed; }

.exp-menu {
  position: absolute; right: 0; top: calc(100% + 6px); width: min(520px, 80vw);
  background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color);
  border-radius: 10px; padding: 10px; z-index: var(--z-modal, 100);
}
.exp-formats { display: flex; gap: 4px; margin-bottom: 8px; }
.exp-format { flex: 1; padding: 5px 8px; border-radius: 6px; border: 1px solid transparent; background: none; color: var(--text-secondary); font-size: 12px; cursor: pointer; }
.exp-format.active { background: var(--accent-soft, rgba(88,166,255,.12)); border-color: var(--accent-color); color: var(--accent-color); font-weight: 600; }
.exp-snippet {
  margin: 0; padding: 10px; max-height: 240px; overflow: auto;
  background: #010409; border: 1px solid var(--border-color); border-radius: 8px;
  font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5;
  color: var(--text-primary); white-space: pre;
}
.exp-actions { display: flex; gap: 6px; margin-top: 8px; }
.exp-btn { padding: 5px 11px; border-radius: 6px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12px; cursor: pointer; }
.exp-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.exp-btn:disabled { opacity: .45; cursor: not-allowed; }
</style>
