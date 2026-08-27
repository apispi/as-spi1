<template>
  <main class="rp">
    <header class="rp-head">
      <h1 class="rp-title">Inspection Reports</h1>
      <p class="rp-sub">Saved conformance grades, security scans, and agent-in-the-loop runs. Open one to review, share a read-only link, or pick two of the same type to compare.</p>
    </header>

    <div class="rp-toolbar">
      <div class="rp-filters">
        <button v-for="f in filters" :key="f.value"
                :class="['rp-chip', { active: typeFilter === f.value }]"
                @click="setFilter(f.value)">{{ f.label }}</button>
      </div>
      <button v-if="!compareMode" class="rp-btn" @click="compareMode = true">Compare</button>
      <template v-else>
        <span class="rp-hint">Pick two {{ selected.length ? typeName(rows.find(r => r.id === selected[0])?.type) : '' }} reports · {{ selected.length }}/2</span>
        <button class="rp-btn" :disabled="selected.length !== 2" @click="runCompare">Compare</button>
        <button class="rp-btn" @click="cancelCompare">Cancel</button>
      </template>
    </div>

    <p v-if="loading" class="rp-muted">Loading…</p>
    <p v-else-if="!rows.length" class="rp-muted">No reports yet. Run Grade, Scan, or Agent on a connector in the Catalog.</p>

    <table v-else class="rp-table">
      <thead><tr><th v-if="compareMode"></th><th>Connector</th><th>Type</th><th>Summary</th><th>When</th><th></th></tr></thead>
      <tbody>
        <tr v-for="r in rows" :key="r.id" :class="{ 'rp-selectable': compareMode }" @click="rowClick(r)">
          <td v-if="compareMode">
            <input type="checkbox" :checked="selected.includes(r.id)" :disabled="!selectable(r)" @click.stop="toggleSelect(r)" />
          </td>
          <td>{{ r.connector_name || r.connector_slug || '—' }}</td>
          <td><span class="rp-type" :class="'t-' + r.type">{{ typeName(r.type) }}</span></td>
          <td class="rp-summary">{{ r.summary }}</td>
          <td class="rp-muted">{{ ago(r.created_at) }}</td>
          <td>
            <span v-if="r.is_shared" class="rp-shared" title="Has a public link">🔗</span>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Single report modal -->
    <div v-if="open" class="rp-modal-overlay" @click.self="open = null">
      <div class="rp-modal">
        <div class="rp-modal-head">
          <h2>{{ typeName(open.type) }} — {{ open.connector_name || open.connector_slug }}</h2>
          <div class="rp-modal-actions">
            <button class="rp-btn" :disabled="busy" @click="toggleShare(open)">
              {{ open.share_url ? 'Copy link' : (open.is_shared ? 'Copy link' : 'Share') }}
            </button>
            <button v-if="open.is_shared" class="rp-btn" :disabled="busy" @click="revoke(open)">Unshare</button>
            <button class="rp-btn rp-btn-danger" @click="remove(open)">Delete</button>
            <button class="rp-btn" @click="open = null">Close</button>
          </div>
        </div>
        <p v-if="open.share_url" class="rp-share-url">{{ open.share_url }}</p>
        <ReportView :type="open.type" :data="open.data" />
      </div>
    </div>

    <!-- Compare modal -->
    <div v-if="cmp" class="rp-modal-overlay" @click.self="cmp = null">
      <div class="rp-modal rp-modal-wide">
        <div class="rp-modal-head">
          <h2>Compare · {{ typeName(cmp.type) }}</h2>
          <button class="rp-btn" @click="cmp = null">Close</button>
        </div>
        <div class="rp-delta" v-if="cmp.delta">{{ cmp.delta }}</div>
        <div class="rp-compare">
          <div class="rp-compare-col">
            <div class="rp-compare-cap">{{ ago(cmp.a.created_at) }} · {{ cmp.a.summary }}</div>
            <ReportView :type="cmp.type" :data="cmp.a.data" />
          </div>
          <div class="rp-compare-col">
            <div class="rp-compare-cap">{{ ago(cmp.b.created_at) }} · {{ cmp.b.summary }}</div>
            <ReportView :type="cmp.type" :data="cmp.b.data" />
          </div>
        </div>
      </div>
    </div>

    <p v-if="error" class="rp-err">{{ error }}</p>
  </main>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import ReportView from '../components/ReportView.vue';

const filters = [
  { value: '', label: 'All' },
  { value: 'conformance', label: 'Conformance' },
  { value: 'security', label: 'Security' },
  { value: 'agent_loop', label: 'Agent runs' },
  { value: 'collection_run', label: 'Collection runs' },
  { value: 'mcp_drift', label: 'MCP drift' },
  { value: 'parity', label: 'Env parity' },
];

const rows = ref([]);
const loading = ref(false);
const error = ref('');
const typeFilter = ref('');
const open = ref(null);
const cmp = ref(null);
const busy = ref(false);
const compareMode = ref(false);
const selected = ref([]);

onMounted(load);

async function load() {
  loading.value = true; error.value = '';
  try {
    const res = await axios.get('/api/reports', { params: typeFilter.value ? { type: typeFilter.value } : {} });
    rows.value = res.data.reports;
  } catch (e) {
    error.value = 'Could not load reports.';
  } finally {
    loading.value = false;
  }
}

function setFilter(v) { typeFilter.value = v; cancelCompare(); load(); }
const typeName = (t) => ({ conformance: 'Conformance', security: 'Security', agent_loop: 'Agent run', collection_run: 'Collection run', mcp_drift: 'MCP drift', parity: 'Env parity' }[t] || t);

function rowClick(r) {
  if (compareMode.value) { toggleSelect(r); return; }
  openReport(r.id);
}

async function openReport(id) {
  error.value = '';
  try {
    const res = await axios.get(`/api/reports/${id}`);
    open.value = { ...res.data, share_url: null };
  } catch (e) {
    error.value = 'Could not open report.';
  }
}

// Only reports of the same type as the first pick are selectable for compare.
function selectable(r) {
  if (!selected.value.length) return true;
  const first = rows.value.find((x) => x.id === selected.value[0]);
  return first && r.type === first.type;
}
function toggleSelect(r) {
  if (selected.value.includes(r.id)) { selected.value = selected.value.filter((i) => i !== r.id); return; }
  if (!selectable(r) || selected.value.length >= 2) return;
  selected.value = [...selected.value, r.id];
}
function cancelCompare() { compareMode.value = false; selected.value = []; }

async function runCompare() {
  if (selected.value.length !== 2) return;
  try {
    const res = await axios.get('/api/reports/compare', { params: { a: selected.value[0], b: selected.value[1] } });
    cmp.value = { ...res.data, delta: deltaLine(res.data) };
    cancelCompare();
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not compare.';
  }
}

// A one-line headline describing what changed between the two runs.
function deltaLine(d) {
  const a = d.a.data, b = d.b.data;
  if (d.type === 'conformance') {
    const g = a.grade === b.grade ? `grade unchanged (${b.grade})` : `grade ${a.grade} → ${b.grade}`;
    return `${g} · score ${a.score} → ${b.score}`;
  }
  if (d.type === 'security') {
    const setA = new Set((a.findings || []).map((f) => f.item + '|' + f.title));
    const setB = new Set((b.findings || []).map((f) => f.item + '|' + f.title));
    const added = [...setB].filter((x) => !setA.has(x)).length;
    const removed = [...setA].filter((x) => !setB.has(x)).length;
    return `risk ${a.risk} → ${b.risk} · ${added} new finding(s), ${removed} resolved`;
  }
  return `${a.tool_call_count} → ${b.tool_call_count} tool call(s) · ${a.stop_reason} → ${b.stop_reason}`;
}

async function toggleShare(r) {
  if (r.share_url) { copy(r.share_url); return; }
  busy.value = true;
  try {
    const res = await axios.post(`/api/reports/${r.id}/share`);
    r.share_url = res.data.url; r.is_shared = true;
    copy(res.data.url);
    const row = rows.value.find((x) => x.id === r.id); if (row) row.is_shared = true;
  } catch (e) { error.value = 'Could not share.'; } finally { busy.value = false; }
}

async function revoke(r) {
  busy.value = true;
  try {
    await axios.delete(`/api/reports/${r.id}/share`);
    r.is_shared = false; r.share_url = null;
    const row = rows.value.find((x) => x.id === r.id); if (row) row.is_shared = false;
  } catch (e) { error.value = 'Could not unshare.'; } finally { busy.value = false; }
}

async function remove(r) {
  if (!confirm('Delete this report? This cannot be undone.')) return;
  try {
    await axios.delete(`/api/reports/${r.id}`);
    rows.value = rows.value.filter((x) => x.id !== r.id);
    open.value = null;
  } catch (e) { error.value = 'Could not delete.'; }
}

async function copy(url) {
  try { await navigator.clipboard.writeText(url); } catch { /* clipboard unavailable */ }
}

const ago = (iso) => {
  if (!iso) return '—';
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};
</script>

<style scoped>
.rp { max-width: 960px; margin: 0 auto; padding: 24px 20px 60px; }
.rp-title { font-size: 24px; font-weight: 700; color: var(--text-primary); margin: 0; }
.rp-sub { color: var(--text-secondary); margin: 6px 0 18px; }
.rp-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.rp-filters { display: flex; gap: 4px; flex-wrap: wrap; flex: 1; }
.rp-chip { background: none; border: 1px solid var(--border-color); border-radius: 20px; padding: 5px 13px; color: var(--text-secondary); cursor: pointer; font-size: 13px; }
.rp-chip.active { background: var(--accent-color); color: #fff; border-color: var(--accent-color); }
.rp-hint { color: var(--text-secondary); font-size: 13px; }
.rp-btn { background: none; border: 1px solid var(--border-color); border-radius: 8px; padding: 7px 14px; color: var(--text-primary); cursor: pointer; font-size: 13px; }
.rp-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.rp-btn:disabled { opacity: .5; cursor: not-allowed; }
.rp-btn-danger:hover:not(:disabled) { border-color: #f85149; color: #f85149; }
.rp-muted { color: var(--text-secondary); }
.rp-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.rp-table th, .rp-table td { text-align: left; padding: 10px 10px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); }
.rp-table th { color: var(--text-secondary); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
.rp-selectable { cursor: pointer; }
.rp-table tbody tr:hover { background: rgba(88,166,255,.06); cursor: pointer; }
.rp-type { font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 5px; }
.t-conformance { background: rgba(88,166,255,.16); color: #58a6ff; }
.t-security { background: rgba(248,81,73,.14); color: #f85149; }
.t-agent_loop { background: rgba(63,185,80,.16); color: #3fb950; }
.t-collection_run { background: rgba(247,120,186,.16); color: #f778ba; }
.rp-summary { color: var(--text-secondary); }
.rp-shared { font-size: 14px; }
.rp-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; z-index: 50; overflow-y: auto; }
.rp-modal { background: var(--bg-primary, #0d1117); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; width: 100%; max-width: 720px; }
.rp-modal-wide { max-width: 1040px; }
.rp-modal-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; }
.rp-modal-head h2 { font-size: 1.05rem; margin: 0; color: var(--text-primary); }
.rp-modal-actions { display: flex; gap: 6px; flex-shrink: 0; }
.rp-share-url { font-family: ui-monospace, Menlo, monospace; font-size: 0.75rem; color: var(--accent-color); word-break: break-all; margin: 0 0 12px; }
.rp-delta { font-weight: 700; color: var(--text-primary); background: var(--bg-secondary, #161b22); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
.rp-compare { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.rp-compare-cap { font-size: 12px; color: var(--text-secondary); margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--border-color); }
.rp-err { color: #f85149; margin-top: 14px; }
@media (max-width: 700px) { .rp-compare { grid-template-columns: 1fr; } }
</style>
