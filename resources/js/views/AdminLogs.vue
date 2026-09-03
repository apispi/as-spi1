<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Logs</h1>
        <p class="ad-sub">Application log output — the tail of the selected file, newest first.</p>
      </div>
      <button class="ad-btn" @click="fetchLogs" :disabled="loading">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <!-- Level counts -->
    <div class="ad-stats" v-if="Object.keys(counts).length">
      <div class="ad-stat" v-for="lvl in orderedCounts" :key="lvl">
        <div class="ad-stat-value" :class="lvl === 'error' || lvl === 'critical' || lvl === 'emergency' || lvl === 'alert' ? 'bad' : ''">{{ counts[lvl] }}</div>
        <div class="ad-stat-label">{{ lvl }}</div>
      </div>
    </div>

    <!-- Controls -->
    <div class="lg-controls">
      <select class="lg-input" v-model="file" @change="fetchLogs">
        <option v-for="f in files" :key="f.name" :value="f.name">{{ f.name }} ({{ fmtSize(f.size) }})</option>
      </select>
      <select class="lg-input" v-model="level" @change="fetchLogs">
        <option value="">All levels</option>
        <option v-for="lvl in LEVELS" :key="lvl" :value="lvl">{{ lvl }} and worse</option>
      </select>
      <input class="lg-input lg-search" v-model="q" @keyup.enter="fetchLogs" placeholder="Search messages…">
      <button class="ad-btn" @click="fetchLogs">Search</button>
    </div>

    <p v-if="loading && !entries.length" class="ad-muted">Loading…</p>

    <div v-else-if="!entries.length" class="ad-empty">
      <Icon name="report" :size="26" />
      <p>{{ message || 'No matching log entries.' }}</p>
    </div>

    <ul v-else class="lg-list">
      <li v-for="(e, i) in entries" :key="i" class="lg-row" :class="{ open: expanded === i }">
        <div class="lg-main" @click="e.detail ? toggle(i) : null" :class="{ clickable: e.detail }">
          <span class="lg-level" :class="e.level">{{ e.level }}</span>
          <span class="lg-time">{{ e.time }}</span>
          <span class="lg-channel">{{ e.channel }}</span>
          <span class="lg-message">{{ e.message }}</span>
          <Icon v-if="e.detail" :name="expanded === i ? 'chevronDown' : 'chevronRight'" :size="14" class="lg-caret" />
        </div>
        <pre v-if="expanded === i && e.detail" class="lg-detail">{{ e.detail }}</pre>
      </li>
    </ul>

    <p v-if="entries.length" class="ad-muted lg-foot">
      Showing {{ returned }} of {{ scanned }} scanned entries · {{ file }}
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import Icon from '../components/Icon.vue';

const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

const files = ref([]);
const file = ref('');
const level = ref('');
const q = ref('');
const entries = ref([]);
const counts = ref({});
const returned = ref(0);
const scanned = ref(0);
const message = ref('');
const loading = ref(true);
const expanded = ref(null);

const orderedCounts = computed(() => LEVELS.filter((l) => counts.value[l]));

const fetchLogs = async () => {
  loading.value = true;
  expanded.value = null;
  try {
    const params = {};
    if (file.value) params.file = file.value;
    if (level.value) params.level = level.value;
    if (q.value.trim()) params.q = q.value.trim();
    const res = await axios.get('/api/admin/logs', { params });
    files.value = res.data.files || [];
    file.value = res.data.file || file.value;
    entries.value = res.data.entries || [];
    counts.value = res.data.counts || {};
    returned.value = res.data.returned || entries.value.length;
    scanned.value = res.data.scanned || 0;
    message.value = res.data.message || '';
  } catch {
    message.value = 'Could not load logs.';
  } finally {
    loading.value = false;
  }
};

const toggle = (i) => { expanded.value = expanded.value === i ? null : i; };

const fmtSize = (b) => {
  if (b < 1024) return `${b} B`;
  if (b < 1048576) return `${(b / 1024).toFixed(0)} KB`;
  return `${(b / 1048576).toFixed(1)} MB`;
};

onMounted(fetchLogs);
</script>

<style scoped>
@import './admin-shared.css';

.lg-controls { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.lg-input {
  padding: 8px 12px; border-radius: 8px; font-size: 13px; font-family: inherit;
  background: var(--panel-bg); border: 1px solid var(--border-color); color: var(--text-primary);
}
.lg-search { flex: 1; min-width: 180px; }

.lg-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.lg-row { border: 1px solid var(--border-color); border-radius: 8px; background: var(--panel-bg); overflow: hidden; }
.lg-main { display: flex; align-items: center; gap: 10px; padding: 9px 12px; font-size: 13px; }
.lg-main.clickable { cursor: pointer; }
.lg-main.clickable:hover { background: rgba(255,255,255,.03); }
.lg-level {
  flex-shrink: 0; font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .04em; padding: 2px 7px; border-radius: 5px; min-width: 62px; text-align: center;
  background: rgba(255,255,255,.08); color: var(--text-secondary);
}
.lg-level.error, .lg-level.critical, .lg-level.emergency, .lg-level.alert { background: rgba(248,81,73,.16); color: #f85149; }
.lg-level.warning { background: rgba(210,153,34,.16); color: #d29922; }
.lg-level.notice, .lg-level.info { background: rgba(63,185,80,.14); color: #3fb950; }
.lg-level.debug { background: rgba(88,166,255,.14); color: #58a6ff; }
.lg-time { flex-shrink: 0; color: var(--text-secondary); font-family: 'Courier New', monospace; font-size: 12px; }
.lg-channel { flex-shrink: 0; color: var(--text-secondary); font-size: 12px; }
.lg-message { flex: 1; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lg-row.open .lg-message { white-space: normal; }
.lg-caret { flex-shrink: 0; color: var(--text-secondary); }
.lg-detail {
  margin: 0; padding: 12px; background: #0d1117; color: #e5e7eb; border-top: 1px solid var(--border-color);
  font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.5; overflow-x: auto; white-space: pre;
}
.lg-foot { margin-top: 14px; }
</style>
