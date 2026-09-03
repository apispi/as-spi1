<template>
  <div class="cp-scrim" @click.self="$emit('close')">
    <div class="cp" role="dialog" aria-modal="true" aria-label="Search">
      <div class="cp-input-row">
        <Icon name="send" :size="15" class="cp-icon" />
        <input
          ref="input"
          v-model="q"
          class="cp-input"
          placeholder="Search requests, collections, monitors, mocks, reports…"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.enter.prevent="choose(results[active])"
          @keydown.esc="$emit('close')"
        />
        <span class="cp-hint">esc</span>
      </div>

      <div class="cp-body">
        <p v-if="loading" class="cp-muted">Searching…</p>
        <p v-else-if="q && !results.length" class="cp-muted">No matches for “{{ q }}”.</p>
        <p v-else-if="!q" class="cp-muted">Type to search across your workspace.</p>

        <ul v-else class="cp-list">
          <li
            v-for="(r, i) in results"
            :key="r.type + r.id"
            :class="['cp-item', i === active ? 'active' : '']"
            @mouseenter="active = i"
            @click="choose(r)"
          >
            <span class="cp-type">{{ r.type_label }}</span>
            <span class="cp-label">{{ r.label }}</span>
            <span v-if="r.sublabel" class="cp-sub">{{ r.sublabel }}</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, nextTick } from 'vue';
import axios from 'axios';
import { useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { useRequestsStore } from '../store/requests';

const emit = defineEmits(['close']);
const router = useRouter();
const requestsStore = useRequestsStore();

const q = ref('');
const results = ref([]);
const active = ref(0);
const loading = ref(false);
const input = ref(null);
let debounce = null;

onMounted(() => nextTick(() => input.value?.focus()));

watch(q, (value) => {
  clearTimeout(debounce);
  active.value = 0;
  if (!value.trim()) { results.value = []; return; }
  loading.value = true;
  debounce = setTimeout(async () => {
    try {
      results.value = (await axios.get('/api/search', { params: { q: value } })).data.results;
    } catch {
      results.value = [];
    } finally {
      loading.value = false;
    }
  }, 200);
});

const move = (delta) => {
  if (!results.value.length) return;
  active.value = (active.value + delta + results.value.length) % results.value.length;
};

const choose = async (r) => {
  if (!r) return;
  emit('close');

  // A saved request opens straight in the tester, ready to send.
  if (r.type === 'saved_request') {
    if (!requestsStore.savedRequests.length) await requestsStore.fetchSavedRequests();
    const req = requestsStore.savedRequests.find((x) => x.id === r.id);
    if (req) requestsStore.openInTester(req);
    router.push('/tester');
    return;
  }

  router.push(r.to);
};
</script>

<style scoped>
.cp-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: flex; align-items: flex-start; justify-content: center; padding: 12vh 24px 24px; z-index: var(--z-modal, 100); }
.cp { width: min(620px, 100%); background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
.cp-input-row { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--border-color); }
.cp-icon { color: var(--text-secondary); }
.cp-input { flex: 1; background: none; border: none; outline: none; color: var(--text-primary); font-size: 15px; }
.cp-hint { font-size: 11px; color: var(--text-secondary); border: 1px solid var(--border-color); border-radius: 5px; padding: 1px 6px; }
.cp-body { max-height: 52vh; overflow-y: auto; }
.cp-muted { color: var(--text-secondary); font-size: 13px; padding: 20px 16px; margin: 0; }
.cp-list { list-style: none; margin: 0; padding: 6px; }
.cp-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; cursor: pointer; }
.cp-item.active { background: var(--accent-soft, rgba(88,166,255,.12)); }
.cp-type { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--accent-color); min-width: 96px; }
.cp-label { font-size: 14px; color: var(--text-primary); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-sub { margin-left: auto; font-size: 11.5px; color: var(--text-secondary); font-family: 'Courier New', monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 45%; }
</style>
