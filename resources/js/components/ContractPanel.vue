<template>
  <div class="ct">
    <div class="ct-head" @click="collapsed = !collapsed">
      <Icon :name="collapsed ? 'chevronRight' : 'chevronDown'" :size="14" />
      <h3>Contract</h3>

      <span v-if="result" class="ct-verdict" :class="result.breaking ? 'bad' : (result.conforms ? 'ok' : 'warn')">
        {{ result.breaking ? 'Breaking drift' : (result.conforms ? 'Matches' : 'Changed') }}
      </span>
      <span v-else-if="hasContract" class="ct-badge">captured</span>
      <span v-else class="ct-badge muted">none</span>

      <div class="ct-actions" @click.stop>
        <button class="ct-btn" @click="capture" :disabled="!response || busy" title="Learn the contract from the current response">
          {{ busy ? '…' : (hasContract ? 'Recapture' : 'Capture') }}
        </button>
        <button v-if="hasContract && savedRequestId" class="ct-btn" @click="clear" :disabled="busy">Clear</button>
      </div>
    </div>

    <div v-if="!collapsed" class="ct-body">
      <p v-if="error" class="ct-error">{{ error }}</p>

      <p v-if="!hasContract" class="ct-empty">
        A contract is the response's shape, learned from a known-good response —
        no assertions to write. Send a good response, then <strong>Capture</strong>.
        Every run then flags removed fields and type changes, even at a green 200.
        <template v-if="!savedRequestId"><br>Save this request first to keep the contract.</template>
      </p>

      <template v-else>
        <!-- Live verdict against the current response -->
        <div v-if="result && !result.conforms" class="ct-changes">
          <div v-for="c in result.removed" :key="'r'+c.path" class="ct-change bad">
            <span class="ct-tag">removed</span><code>{{ c.path }}</code>
          </div>
          <div v-for="c in result.type_changed" :key="'t'+c.path" class="ct-change bad">
            <span class="ct-tag">type</span><code>{{ c.path }}</code>
            <span class="ct-note">{{ c.expected }} → {{ c.actual }}</span>
          </div>
          <div v-for="c in result.added" :key="'a'+c.path" class="ct-change warn">
            <span class="ct-tag">added</span><code>{{ c.path }}</code>
            <span class="ct-note">{{ c.type }}</span>
          </div>
        </div>
        <p v-else-if="result" class="ct-ok">The current response matches the contract.</p>
        <p v-else class="ct-empty">Send this request to check the live response against the contract.</p>
      </template>
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
  // Whether the loaded saved request already has a contract.
  hasContractInitial: Boolean,
});

const collapsed = ref(true);
const busy = ref(false);
const error = ref('');
const result = ref(null);
const localHasContract = ref(false);

const hasContract = computed(() => props.hasContractInitial || localHasContract.value);

const bodyString = () => {
  const b = props.response?.body;
  return typeof b === 'string' ? b : JSON.stringify(b ?? '');
};

// Re-check against the contract whenever a new response arrives.
watch(() => props.response, async (res) => {
  result.value = null;
  if (!res || !hasContract.value || !props.savedRequestId) return;
  try {
    const r = await axios.post(`/api/saved-requests/${props.savedRequestId}/contract/check`, {
      response: bodyString(),
    });
    result.value = r.data;
    if (result.value.breaking || !result.value.conforms) collapsed.value = false;
  } catch { /* no contract or transient */ }
});

const capture = async () => {
  if (!props.response) return;
  if (!props.savedRequestId) {
    error.value = 'Save this request first, then capture its contract.';
    collapsed.value = false;
    return;
  }
  busy.value = true;
  error.value = '';
  try {
    await axios.put(`/api/saved-requests/${props.savedRequestId}/contract`, { response: bodyString() });
    localHasContract.value = true;
    result.value = { conforms: true, breaking: false, removed: [], type_changed: [], added: [] };
    collapsed.value = false;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not capture a contract.';
  } finally {
    busy.value = false;
  }
};

const clear = async () => {
  busy.value = true;
  try {
    await axios.put(`/api/saved-requests/${props.savedRequestId}/contract`, { response: '' });
    localHasContract.value = false;
    result.value = null;
  } finally {
    busy.value = false;
  }
};
</script>

<style scoped>
.ct { border-top: 1px solid var(--border-color); background: var(--panel-bg); }
.ct-head { display: flex; align-items: center; gap: 8px; padding: 10px 16px; cursor: pointer; user-select: none; color: var(--text-secondary); }
.ct-head h3 { font-size: 13px; font-weight: 600; margin: 0; color: var(--text-primary); text-transform: uppercase; letter-spacing: .04em; }
.ct-verdict { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.ct-verdict.ok { color: #3fb950; background: rgba(63,185,80,.16); }
.ct-verdict.bad { color: #f85149; background: rgba(248,81,73,.14); }
.ct-verdict.warn { color: #d29922; background: rgba(210,153,34,.14); }
.ct-badge { font-size: 11px; color: var(--text-secondary); background: rgba(255,255,255,.06); padding: 2px 8px; border-radius: 999px; }
.ct-badge.muted { opacity: .7; }
.ct-actions { margin-left: auto; display: flex; gap: 6px; }
.ct-btn { padding: 5px 11px; font-size: 12px; border-radius: 6px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer; }
.ct-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.ct-btn:disabled { opacity: .45; cursor: not-allowed; }

.ct-body { padding: 0 16px 14px; }
.ct-empty { font-size: 12.5px; color: var(--text-secondary); line-height: 1.6; margin: 4px 0; }
.ct-error { color: #f85149; font-size: 12.5px; margin: 4px 0; }
.ct-ok { color: #3fb950; font-size: 12.5px; margin: 4px 0; }
.ct-changes { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
.ct-change { display: flex; align-items: baseline; gap: 8px; font-size: 12px; }
.ct-change code { font-family: 'Courier New', monospace; color: var(--text-primary); }
.ct-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 1px 6px; border-radius: 4px; }
.ct-change.bad .ct-tag { background: rgba(248,81,73,.16); color: #f85149; }
.ct-change.warn .ct-tag { background: rgba(210,153,34,.16); color: #d29922; }
.ct-note { color: var(--text-secondary); font-family: 'Courier New', monospace; font-size: 11.5px; }
</style>
