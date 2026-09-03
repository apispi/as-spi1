<template>
  <div class="sn">
    <div class="sn-head" @click="collapsed = !collapsed">
      <Icon :name="collapsed ? 'chevronRight' : 'chevronDown'" :size="14" />
      <h3>Snapshot</h3>

      <span v-if="result" class="sn-verdict" :class="result.matches ? 'ok' : 'bad'">
        {{ result.matches ? 'Matches' : 'Changed' }}
      </span>
      <span v-else-if="hasSnapshot" class="sn-badge">captured</span>
      <span v-else class="sn-badge muted">none</span>

      <div class="sn-actions" @click.stop>
        <button class="sn-btn" @click="capture" :disabled="!response || busy" title="Save the current response as the golden snapshot">
          {{ busy ? '…' : (hasSnapshot ? 'Recapture' : 'Capture') }}
        </button>
        <button v-if="hasSnapshot && savedRequestId" class="sn-btn" @click="clear" :disabled="busy">Clear</button>
      </div>
    </div>

    <div v-if="!collapsed" class="sn-body">
      <p v-if="error" class="sn-error">{{ error }}</p>

      <p v-if="!hasSnapshot" class="sn-empty">
        A snapshot is the exact response this request returned, captured as a
        baseline. Send a known-good response, then <strong>Capture</strong>.
        Every later run is diffed value-by-value — a shifted id, a moved total, a
        flipped flag — even when the shape and status are unchanged.
        <template v-if="!savedRequestId"><br>Save this request first to keep the snapshot.</template>
      </p>

      <template v-else>
        <div v-if="result && !result.matches" class="sn-changes">
          <div v-if="result.status_changed" class="sn-change bad">
            <span class="sn-tag">status</span>
            <span class="sn-note">{{ result.status_from }} → {{ result.status_to }}</span>
          </div>
          <div v-for="c in result.changed" :key="'c'+c.path" class="sn-change bad">
            <span class="sn-tag">changed</span><code>{{ c.path }}</code>
            <span class="sn-note">{{ fmt(c.from) }} → {{ fmt(c.to) }}</span>
          </div>
          <div v-for="c in result.removed" :key="'r'+c.path" class="sn-change bad">
            <span class="sn-tag">removed</span><code>{{ c.path }}</code>
            <span class="sn-note">{{ fmt(c.from) }}</span>
          </div>
          <div v-for="c in result.added" :key="'a'+c.path" class="sn-change warn">
            <span class="sn-tag">added</span><code>{{ c.path }}</code>
            <span class="sn-note">{{ fmt(c.to) }}</span>
          </div>
          <p v-if="result.truncated" class="sn-empty">Showing the first {{ MAX }} differences.</p>
        </div>
        <p v-else-if="result" class="sn-ok">The current response matches the snapshot.</p>
        <p v-else class="sn-empty">
          Send this request to diff the live response against the snapshot.
          <template v-if="takenAt"><br>Captured {{ takenAt }}.</template>
        </p>
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
  hasSnapshotInitial: Boolean,
});

const MAX = 200;
const collapsed = ref(true);
const busy = ref(false);
const error = ref('');
const result = ref(null);
const localHasSnapshot = ref(false);
const takenAt = ref(null);

const hasSnapshot = computed(() => props.hasSnapshotInitial || localHasSnapshot.value);

const payload = () => {
  const b = props.response?.body;
  return {
    status: props.response?.status ?? null,
    body: typeof b === 'string' ? b : JSON.stringify(b ?? ''),
  };
};

const fmt = (v) => {
  if (v === null) return 'null';
  if (typeof v === 'string') return `"${v}"`;
  return String(v);
};

// Re-diff whenever a new response arrives.
watch(() => props.response, async (res) => {
  result.value = null;
  if (!res || !hasSnapshot.value || !props.savedRequestId) return;
  try {
    const r = await axios.post(`/api/saved-requests/${props.savedRequestId}/snapshot/check`, payload());
    result.value = r.data;
    if (!result.value.matches) collapsed.value = false;
  } catch (e) {
    // A 422 with a diff body is the "changed" case, not an error.
    if (e.response?.status === 422 && e.response.data?.matches === false) {
      result.value = e.response.data;
      collapsed.value = false;
    }
  }
});

const capture = async () => {
  if (!props.response) return;
  if (!props.savedRequestId) {
    error.value = 'Save this request first, then capture its snapshot.';
    collapsed.value = false;
    return;
  }
  busy.value = true;
  error.value = '';
  try {
    const r = await axios.put(`/api/saved-requests/${props.savedRequestId}/snapshot`, payload());
    localHasSnapshot.value = true;
    takenAt.value = new Date(r.data.taken_at).toLocaleString();
    result.value = { matches: true, changed: [], added: [], removed: [] };
    collapsed.value = false;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not capture a snapshot.';
  } finally {
    busy.value = false;
  }
};

const clear = async () => {
  busy.value = true;
  try {
    await axios.put(`/api/saved-requests/${props.savedRequestId}/snapshot`, { body: '' });
    localHasSnapshot.value = false;
    result.value = null;
    takenAt.value = null;
  } finally {
    busy.value = false;
  }
};
</script>

<style scoped>
.sn { border-top: 1px solid var(--border-color); background: var(--panel-bg); }
.sn-head { display: flex; align-items: center; gap: 8px; padding: 10px 16px; cursor: pointer; user-select: none; color: var(--text-secondary); }
.sn-head h3 { font-size: 13px; font-weight: 600; margin: 0; color: var(--text-primary); text-transform: uppercase; letter-spacing: .04em; }
.sn-verdict { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.sn-verdict.ok { color: #3fb950; background: rgba(63,185,80,.16); }
.sn-verdict.bad { color: #f85149; background: rgba(248,81,73,.14); }
.sn-badge { font-size: 11px; color: var(--text-secondary); background: rgba(255,255,255,.06); padding: 2px 8px; border-radius: 999px; }
.sn-badge.muted { opacity: .7; }
.sn-actions { margin-left: auto; display: flex; gap: 6px; }
.sn-btn { padding: 5px 11px; font-size: 12px; border-radius: 6px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); cursor: pointer; }
.sn-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.sn-btn:disabled { opacity: .45; cursor: not-allowed; }

.sn-body { padding: 0 16px 14px; }
.sn-empty { font-size: 12.5px; color: var(--text-secondary); line-height: 1.6; margin: 4px 0; }
.sn-error { color: #f85149; font-size: 12.5px; margin: 4px 0; }
.sn-ok { color: #3fb950; font-size: 12.5px; margin: 4px 0; }
.sn-changes { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
.sn-change { display: flex; align-items: baseline; gap: 8px; font-size: 12px; flex-wrap: wrap; }
.sn-change code { font-family: 'Courier New', monospace; color: var(--text-primary); }
.sn-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 1px 6px; border-radius: 4px; }
.sn-change.bad .sn-tag { background: rgba(248,81,73,.16); color: #f85149; }
.sn-change.warn .sn-tag { background: rgba(210,153,34,.16); color: #d29922; }
.sn-note { color: var(--text-secondary); font-family: 'Courier New', monospace; font-size: 11.5px; }
</style>
