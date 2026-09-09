<template>
  <div ref="root" class="nb">
    <button class="icon-btn nb-trigger" @click="toggle" aria-label="Notifications" :aria-expanded="open">
      <Icon name="bell" :size="18" />
      <span v-if="unread > 0" class="nb-badge">{{ unread > 9 ? '9+' : unread }}</span>
    </button>

    <transition name="nb-pop">
      <div v-if="open" class="nb-menu" @click.stop>
        <div class="nb-head">
          <span>Notifications</span>
          <button v-if="unread > 0" class="nb-mark" @click="markAll">Mark all read</button>
        </div>

        <div v-if="!items.length" class="nb-empty">You're all caught up.</div>

        <ul v-else class="nb-list">
          <li
            v-for="n in items"
            :key="n.id"
            class="nb-item"
            :class="{ unread: !n.read_at }"
            @click="openItem(n)"
          >
            <span class="nb-dot" :class="dotClass(n.type)"></span>
            <div class="nb-body">
              <div class="nb-title">{{ n.title }}</div>
              <div v-if="n.body" class="nb-sub">{{ n.body }}</div>
              <div class="nb-time">{{ ago(n.created_at) }}</div>
            </div>
          </li>
        </ul>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import Icon from './Icon.vue';

const router = useRouter();
const root = ref(null);
const open = ref(false);
const items = ref([]);
const unread = ref(0);
let timer = null;

const loadList = async () => {
  try {
    const res = await axios.get('/api/notifications');
    items.value = res.data.notifications || [];
    unread.value = res.data.unread || 0;
  } catch { /* ignore */ }
};

const loadCount = async () => {
  try {
    const res = await axios.get('/api/notifications/unread-count');
    unread.value = res.data.unread || 0;
  } catch { /* ignore */ }
};

const toggle = async () => {
  open.value = !open.value;
  if (open.value) await loadList();
};

const markAll = async () => {
  try {
    await axios.post('/api/notifications/read');
    items.value = items.value.map((n) => ({ ...n, read_at: n.read_at || new Date().toISOString() }));
    unread.value = 0;
  } catch { /* ignore */ }
};

const openItem = async (n) => {
  if (!n.read_at) {
    try {
      await axios.post(`/api/notifications/${n.id}/read`);
      n.read_at = new Date().toISOString();
      unread.value = Math.max(0, unread.value - 1);
    } catch { /* ignore */ }
  }
  open.value = false;
  if (n.url) router.push(n.url);
};

const dotClass = (type) => {
  if (/recovered/.test(type)) return 'ok';
  if (/failing|silent/.test(type)) return 'bad';
  return 'info';
};

const ago = (ts) => {
  const secs = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (secs < 60) return 'just now';
  if (secs < 3600) return `${Math.floor(secs / 60)}m ago`;
  if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`;
  return `${Math.floor(secs / 86400)}d ago`;
};

const onDocClick = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false; };

onMounted(() => {
  loadCount();
  timer = setInterval(loadCount, 60000); // keep the badge fresh
  document.addEventListener('click', onDocClick);
});
onUnmounted(() => {
  clearInterval(timer);
  document.removeEventListener('click', onDocClick);
});
</script>

<style scoped>
.nb { position: relative; }
.nb-trigger { position: relative; }
.nb-badge {
  position: absolute; top: -3px; right: -3px; min-width: 16px; height: 16px; padding: 0 4px;
  border-radius: 999px; background: var(--error-color); color: #fff;
  font-size: 10px; font-weight: 700; line-height: 16px; text-align: center;
}
.nb-menu {
  position: absolute; top: calc(100% + 8px); right: 0; width: 340px; max-width: 90vw;
  background: var(--bg-elevated, #1c2129); border: 1px solid var(--border-color); border-radius: 12px;
  box-shadow: 0 18px 48px var(--shadow-color, rgba(0,0,0,.5)); z-index: var(--z-dropdown, 50); overflow: hidden;
}
.nb-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid var(--border-color); font-size: 13px; font-weight: 700; color: var(--text-primary); }
.nb-mark { background: none; border: none; color: var(--accent-color); font-size: 12px; cursor: pointer; font-family: inherit; }
.nb-empty { padding: 24px 14px; text-align: center; color: var(--text-secondary); font-size: 13px; }
.nb-list { list-style: none; margin: 0; padding: 0; max-height: 60vh; overflow-y: auto; }
.nb-item { display: flex; gap: 10px; padding: 11px 14px; cursor: pointer; border-bottom: 1px solid var(--border-color); }
.nb-item:hover { background: var(--accent-soft); }
.nb-item.unread { background: rgba(88,166,255,.06); }
.nb-dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; background: var(--text-secondary); }
.nb-dot.ok { background: var(--success-color); }
.nb-dot.bad { background: var(--error-color); }
.nb-dot.info { background: var(--accent-color); }
.nb-body { min-width: 0; }
.nb-title { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.nb-sub { font-size: 12px; color: var(--text-secondary); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nb-time { font-size: 11px; color: var(--text-secondary); margin-top: 3px; }

.nb-pop-enter-active, .nb-pop-leave-active { transition: opacity .15s, transform .15s; }
.nb-pop-enter-from, .nb-pop-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
