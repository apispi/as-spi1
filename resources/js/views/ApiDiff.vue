<template>
  <main class="ad">
    <header class="ad-head">
      <h1 class="ad-title">API Diff</h1>
      <p class="ad-sub">
        Compare two OpenAPI 3 documents and see what changed — before you ship it or approve a pull request.
        Breaking changes (a removed endpoint, a new required input, a dropped success response) are called out, so a
        CI job can gate a merge on the result. Paste JSON or YAML.
      </p>
    </header>

    <div class="ad-grid">
      <div class="ad-pane">
        <label class="ad-label" for="ad-old">Baseline (old) spec</label>
        <textarea id="ad-old" v-model="oldDoc" class="ad-area" spellcheck="false"
                  placeholder="openapi: 3.0.0&#10;info: …&#10;paths: …"></textarea>
      </div>
      <div class="ad-pane">
        <label class="ad-label" for="ad-new">Candidate (new) spec</label>
        <textarea id="ad-new" v-model="newDoc" class="ad-area" spellcheck="false"
                  placeholder="openapi: 3.0.0&#10;info: …&#10;paths: …"></textarea>
      </div>
    </div>

    <div class="ad-actions">
      <button class="ad-btn ad-btn-primary" :disabled="busy || !oldDoc.trim() || !newDoc.trim()" @click="run">
        {{ busy ? 'Comparing…' : 'Compare specs' }}
      </button>
      <button class="ad-btn" :disabled="busy" @click="reset">Clear</button>
      <span v-if="result" class="ad-report">Saved as report #{{ result.report_id }} · see Reports to share.</span>
    </div>

    <p v-if="error" class="ad-err">{{ error }}</p>

    <section v-if="result" class="ad-result">
      <div class="ad-summary" :class="result.breaking ? 'is-breaking' : 'is-clean'">
        <span class="ad-verdict">{{ result.breaking ? '✗ Breaking' : '✓ Compatible' }}</span>
        <div>
          <strong>{{ result.new_title }}</strong>
          <span class="ad-muted"> · {{ result.old_version || '—' }} → {{ result.new_version || '—' }}</span>
          <div class="ad-counts">
            <span class="ad-count c-breaking">{{ result.breaking_count }} breaking</span>
            <span class="ad-count c-safe">{{ result.non_breaking_count }} non-breaking</span>
            <span class="ad-count c-info">{{ result.info_count }} informational</span>
          </div>
        </div>
      </div>

      <p v-if="!result.changes.length" class="ad-clean">The two specs are identical. ✅</p>

      <ul v-else class="ad-changes">
        <li v-for="(c, i) in result.changes" :key="i" class="ad-change" :class="'sev-' + c.severity">
          <span class="ad-sev">{{ label(c.severity) }}</span>
          <span v-if="c.operation" class="ad-op">{{ c.operation }}</span>
          <span class="ad-detail">{{ c.detail }}</span>
        </li>
      </ul>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { toast } from '../toast';

const oldDoc = ref('');
const newDoc = ref('');
const busy = ref(false);
const error = ref('');
const result = ref(null);

const label = (s) => ({ breaking: 'breaking', non_breaking: 'safe', info: 'info' }[s] || s);

async function run() {
  busy.value = true;
  error.value = '';
  try {
    // A breaking diff comes back as 422 with the full body — that is a
    // successful comparison, not an error, so read it from the response.
    const res = await axios.post('/api/diff/openapi',
      { old: oldDoc.value, new: newDoc.value },
      { validateStatus: (s) => s === 200 || s === 422 });

    if (res.data && Array.isArray(res.data.changes)) {
      result.value = res.data;
      if (res.data.breaking) {
        toast.error(`${res.data.breaking_count} breaking change${res.data.breaking_count === 1 ? '' : 's'} found.`);
      } else {
        toast.success('No breaking changes.');
      }
    } else {
      error.value = res.data?.message || 'Could not compare the documents.';
      result.value = null;
    }
  } catch (e) {
    result.value = null;
    error.value = e.response?.data?.message || 'Could not compare the documents.';
  } finally {
    busy.value = false;
  }
}

function reset() {
  oldDoc.value = '';
  newDoc.value = '';
  result.value = null;
  error.value = '';
}
</script>

<style scoped>
.ad { padding: 24px 28px; max-width: 1100px; margin: 0 auto; }
.ad-title { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin: 0 0 4px; }
.ad-sub { color: var(--text-secondary); max-width: 70ch; margin: 0 0 20px; line-height: 1.5; }

.ad-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 720px) { .ad-grid { grid-template-columns: 1fr; } }
.ad-pane { display: flex; flex-direction: column; }
.ad-label { font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.ad-area {
  min-height: 260px; resize: vertical; padding: 11px 13px;
  font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem; line-height: 1.5;
  border: 1px solid var(--border-color); border-radius: 9px;
  background: var(--panel-bg, var(--bg-secondary)); color: var(--text-primary);
}
.ad-area:focus { outline: none; border-color: var(--accent-color); }

.ad-actions { display: flex; align-items: center; gap: 10px; margin: 16px 0; flex-wrap: wrap; }
.ad-btn {
  padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border-color);
  background: var(--panel-bg, transparent); color: var(--text-primary); font-weight: 600; cursor: pointer;
}
.ad-btn:disabled { opacity: 0.5; cursor: default; }
.ad-btn-primary { background: var(--accent-color); border-color: var(--accent-color); color: #fff; }
.ad-report { color: var(--text-secondary); font-size: 0.82rem; }
.ad-err { color: #f85149; margin: 8px 0; }

.ad-result { margin-top: 8px; }
.ad-summary {
  display: flex; gap: 14px; align-items: center; padding: 14px 16px;
  border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 16px;
}
.ad-summary.is-breaking { border-color: rgba(248,81,73,.5); }
.ad-summary.is-clean { border-color: rgba(63,185,80,.5); }
.ad-verdict { font-size: 1.05rem; font-weight: 800; }
.is-breaking .ad-verdict { color: #f85149; }
.is-clean .ad-verdict { color: #3fb950; }
.ad-muted { color: var(--text-secondary); }
.ad-counts { margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap; }
.ad-count { font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
.c-breaking { background: rgba(248,81,73,.16); color: #f85149; }
.c-safe { background: rgba(210,153,34,.18); color: #d29922; }
.c-info { background: rgba(63,185,80,.16); color: #3fb950; }

.ad-clean { color: #3fb950; font-weight: 600; }
.ad-changes { list-style: none; margin: 0; padding: 0; }
.ad-change {
  display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap;
  border: 1px solid var(--border-color); border-radius: 8px; padding: 9px 12px; margin-bottom: 7px;
}
.ad-sev { font-size: 0.66rem; text-transform: uppercase; font-weight: 700; padding: 2px 7px; border-radius: 5px; flex-shrink: 0; }
.sev-breaking .ad-sev { background: rgba(248,81,73,.16); color: #f85149; }
.sev-non_breaking .ad-sev { background: rgba(210,153,34,.18); color: #d29922; }
.sev-info .ad-sev { background: rgba(63,185,80,.16); color: #3fb950; }
.ad-op { font-family: ui-monospace, Menlo, monospace; font-size: 0.78rem; font-weight: 700; color: var(--text-primary); }
.ad-detail { color: var(--text-secondary); }
</style>
