<template>
  <div class="up-card">
    <div class="up-card-header">
      <h2 class="up-card-title">Active sessions</h2>
      <p class="up-card-sub">Devices currently signed in to your account</p>
    </div>

    <p v-if="error" class="ss-error">{{ error }}</p>

    <div v-if="loading" class="ss-muted">Loading…</div>
    <div v-else-if="!sessions.length" class="ss-muted">No active sessions recorded.</div>

    <ul v-else class="ss-list">
      <li v-for="s in sessions" :key="s.handle" class="ss-row">
        <div class="ss-info">
          <div class="ss-device">
            {{ s.device }}
            <span v-if="s.is_current" class="ss-current">This device</span>
          </div>
          <div class="ss-meta">
            <span v-if="s.ip">{{ s.ip }}</span>
            <span v-if="s.last_active"> · active {{ ago(s.last_active) }}</span>
          </div>
        </div>
        <button
          v-if="!s.is_current"
          class="ss-revoke"
          :disabled="busy === s.handle"
          @click="revoke(s)"
        >{{ busy === s.handle ? '…' : 'Sign out' }}</button>
      </li>
    </ul>

    <button v-if="others" class="up-btn-danger-sm ss-all" :disabled="busy === 'others'" @click="revokeOthers">
      {{ busy === 'others' ? 'Signing out…' : 'Sign out all other devices' }}
    </button>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const sessions = ref([]);
const loading = ref(true);
const busy = ref(null);
const error = ref('');

const others = computed(() => sessions.value.filter((s) => !s.is_current).length > 0);

const load = async () => {
  loading.value = true;
  try {
    const res = await axios.get('/api/user/sessions');
    sessions.value = res.data || [];
  } catch {
    error.value = 'Could not load sessions.';
  } finally {
    loading.value = false;
  }
};

const revoke = async (s) => {
  busy.value = s.handle;
  error.value = '';
  try {
    await axios.delete(`/api/user/sessions/${s.handle}`);
    sessions.value = sessions.value.filter((x) => x.handle !== s.handle);
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not sign out that device.';
  } finally {
    busy.value = null;
  }
};

const revokeOthers = async () => {
  if (!confirm('Sign out every other device? They will need to log in again.')) return;
  busy.value = 'others';
  error.value = '';
  try {
    await axios.delete('/api/user/sessions/others');
    sessions.value = sessions.value.filter((s) => s.is_current);
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not sign out other devices.';
  } finally {
    busy.value = null;
  }
};

const ago = (ts) => {
  const d = new Date(ts);
  const secs = Math.floor((Date.now() - d.getTime()) / 1000);
  if (secs < 60) return 'just now';
  if (secs < 3600) return `${Math.floor(secs / 60)}m ago`;
  if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`;
  return `${Math.floor(secs / 86400)}d ago`;
};

onMounted(load);
</script>

<style scoped>
/* Self-contained (Profile's .up-* classes are scoped to that view). */
.up-card { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.5rem; }
.up-card-header { margin-bottom: 1rem; }
.up-card-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0; }
.up-card-sub { font-size: 0.82rem; color: var(--text-secondary); margin: 0.25rem 0 0; }
.up-btn-danger-sm { padding: 0.55rem 1rem; border-radius: 0.5rem; background: rgba(248,113,113,.12); border: 1px solid rgba(248,113,113,.4); color: var(--error-color); font-size: 0.82rem; font-weight: 700; cursor: pointer; font-family: inherit; }
.up-btn-danger-sm:disabled { opacity: 0.55; cursor: not-allowed; }

.ss-error { color: var(--error-color); font-size: 0.82rem; margin-bottom: 0.75rem; }
.ss-muted { color: var(--text-secondary); font-size: 0.85rem; }
.ss-list { list-style: none; margin: 0 0 1rem; padding: 0; display: flex; flex-direction: column; }
.ss-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.75rem 0; border-top: 1px solid var(--border-color); }
.ss-row:first-child { border-top: none; }
.ss-device { font-size: 0.9rem; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.ss-current { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--success-color); background: rgba(35,134,54,.14); padding: 1px 7px; border-radius: 999px; }
.ss-meta { font-size: 0.76rem; color: var(--text-secondary); margin-top: 2px; }
.ss-revoke { padding: 0.4rem 0.9rem; border-radius: 0.5rem; background: transparent; border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.8rem; cursor: pointer; font-family: inherit; }
.ss-revoke:hover:not(:disabled) { border-color: var(--error-color); color: var(--error-color); }
.ss-revoke:disabled { opacity: 0.55; cursor: not-allowed; }
.ss-all { margin-top: 0.25rem; }
</style>
