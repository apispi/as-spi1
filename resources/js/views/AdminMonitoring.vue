<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Monitoring</h1>
        <p class="ad-sub">Every monitor in the workspace, whoever owns it. Failing ones come first.</p>
      </div>
      <button class="ad-btn" @click="fetchAll" :disabled="loading">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <div class="ad-stats">
      <div class="ad-stat">
        <div class="ad-stat-value" :class="summary.failing ? 'bad' : ''">{{ summary.failing ?? 0 }}</div>
        <div class="ad-stat-label">Failing</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.passing ?? 0 }}</div>
        <div class="ad-stat-label">Passing</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.total ?? 0 }}</div>
        <div class="ad-stat-label">Monitors</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.disabled ?? 0 }}</div>
        <div class="ad-stat-label">Disabled</div>
      </div>
      <div class="ad-stat">
        <div class="ad-stat-value">{{ summary.alert_channels ?? 0 }}</div>
        <div class="ad-stat-label">Alert channels</div>
      </div>
    </div>

    <p v-if="!summary.alert_channels" class="ad-note">
      No alert channels are configured anywhere, so a failing monitor notifies
      nobody unless its owner has email alerts on and SMTP is set up.
    </p>

    <p v-if="loading && !monitors.length" class="ad-muted">Loading…</p>

    <div v-else-if="!monitors.length" class="ad-empty">
      <Icon name="activity" :size="26" />
      <p>No monitors exist yet. Users create them from their own Monitors page.</p>
    </div>

    <ul v-else class="ad-list">
      <li v-for="m in monitors" :key="m.id" class="ad-row">
        <span class="ad-dot" :class="m.last_status"></span>
        <div class="ad-row-main">
          <div>
            <span class="ad-row-name">{{ m.name }}</span>
            <span class="ad-pill" :class="m.last_status">{{ statusLabel(m.last_status) }}</span>
            <span v-if="!m.is_enabled" class="ad-pill">paused</span>
          </div>
          <span class="ad-row-sub">
            {{ m.owner?.name || 'unknown' }} ({{ m.owner?.email }})
            · {{ m.collection || '—' }}
            <template v-if="m.environment"> · {{ m.environment }}</template>
            · every {{ intervalLabel(m.interval_minutes) }}
            <template v-if="m.uptime !== null"> · {{ m.uptime }}% uptime</template>
            <template v-if="m.consecutive_failures > 1"> · {{ m.consecutive_failures }} failures in a row</template>
          </span>
        </div>
        <router-link v-if="m.owner" :to="`/admin/users/${m.owner.id}`" class="ad-btn">Owner</router-link>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import Icon from '../components/Icon.vue';

const monitors = ref([]);
const summary = ref({});
const loading = ref(true);

const fetchAll = async () => {
  loading.value = true;
  try {
    const res = await axios.get('/api/admin/monitoring');
    monitors.value = res.data.monitors;
    summary.value = res.data.summary;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchAll);

const statusLabel = (s) => ({ passing: 'Passing', failing: 'Failing', unknown: 'Not run' }[s] || s);
const intervalLabel = (m) => {
  if (m < 60) return `${m} min`;
  if (m === 60) return 'hour';
  if (m < 1440) return `${m / 60} hours`;
  return 'day';
};
</script>

<style scoped>
@import './admin-shared.css';
</style>
