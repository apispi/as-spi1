<template>
  <div class="nt">
    <header class="nt-head">
      <div>
        <h1 class="nt-title">Notifications</h1>
        <p class="nt-sub">Monitor and webhook alerts for your workspace.</p>
      </div>
      <div class="nt-actions">
        <router-link to="/profile?tab=personalisation" class="nt-btn">Preferences</router-link>
        <button class="nt-btn" @click="markAll" :disabled="!hasUnread">Mark all read</button>
      </div>
    </header>

    <p v-if="loading && !items.length" class="nt-muted">Loading…</p>
    <div v-else-if="!items.length" class="nt-empty">You have no notifications.</div>

    <ul v-else class="nt-list">
      <li
        v-for="n in items"
        :key="n.id"
        class="nt-item"
        :class="{ unread: !n.read_at }"
        @click="openItem(n)"
      >
        <span class="nt-dot" :class="dotClass(n.type)"></span>
        <div class="nt-body">
          <div class="nt-item-title">{{ n.title }}</div>
          <div v-if="n.body" class="nt-item-sub">{{ n.body }}</div>
        </div>
        <span class="nt-time">{{ when(n.created_at) }}</span>
      </li>
    </ul>

    <div v-if="page < lastPage" class="nt-more">
      <button class="nt-btn" @click="loadMore" :disabled="loading">{{ loading ? 'Loading…' : 'Load more' }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();
const items = ref([]);
const page = ref(0);
const lastPage = ref(1);
const loading = ref(false);

const hasUnread = computed(() => items.value.some((n) => !n.read_at));

const fetchPage = async (p) => {
  loading.value = true;
  try {
    const res = await axios.get('/api/notifications/history', { params: { page: p } });
    items.value = p === 1 ? res.data.data : [...items.value, ...res.data.data];
    page.value = res.data.current_page;
    lastPage.value = res.data.last_page;
  } finally {
    loading.value = false;
  }
};

const loadMore = () => fetchPage(page.value + 1);

const markAll = async () => {
  await axios.post('/api/notifications/read');
  items.value = items.value.map((n) => ({ ...n, read_at: n.read_at || new Date().toISOString() }));
};

const openItem = async (n) => {
  if (!n.read_at) {
    try { await axios.post(`/api/notifications/${n.id}/read`); n.read_at = new Date().toISOString(); } catch { /* ignore */ }
  }
  if (n.url) router.push(n.url);
};

const dotClass = (type) => {
  if (/recovered/.test(type)) return 'ok';
  if (/failing|silent/.test(type)) return 'bad';
  return 'info';
};

const when = (ts) => new Date(ts).toLocaleString();

onMounted(() => fetchPage(1));
</script>

<style scoped>
.nt { max-width: 760px; margin: 0 auto; padding: 28px 24px 80px; }
.nt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.nt-title { font-size: 1.6rem; font-weight: 700; color: var(--text-primary); margin: 0; }
.nt-sub { font-size: 0.9rem; color: var(--text-secondary); margin: 0.25rem 0 0; }
.nt-actions { display: flex; gap: 8px; }
.nt-btn { padding: 0.5rem 1rem; border-radius: 0.5rem; background: var(--panel-bg); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.85rem; cursor: pointer; text-decoration: none; font-family: inherit; }
.nt-btn:hover:not(:disabled) { border-color: var(--accent-color); }
.nt-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.nt-muted, .nt-empty { color: var(--text-secondary); font-size: 0.9rem; padding: 2rem 0; text-align: center; }
.nt-list { list-style: none; margin: 0; padding: 0; }
.nt-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 12px; border-bottom: 1px solid var(--border-color); cursor: pointer; }
.nt-item:hover { background: var(--accent-soft); }
.nt-item.unread { background: rgba(88,166,255,.06); }
.nt-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; background: var(--text-secondary); }
.nt-dot.ok { background: var(--success-color); }
.nt-dot.bad { background: var(--error-color); }
.nt-dot.info { background: var(--accent-color); }
.nt-body { flex: 1; min-width: 0; }
.nt-item-title { font-size: 0.92rem; font-weight: 600; color: var(--text-primary); }
.nt-item-sub { font-size: 0.82rem; color: var(--text-secondary); margin-top: 2px; }
.nt-time { font-size: 0.75rem; color: var(--text-secondary); flex-shrink: 0; white-space: nowrap; }
.nt-more { text-align: center; margin-top: 1.5rem; }
</style>
