<template>
  <main class="rq">
    <header class="rq-head">
      <h1 class="rq-title">Saved Requests</h1>
      <p class="rq-sub">
        Everything saved in this workspace. Open one in the Tester to edit and send it, or rename,
        duplicate and tidy up from here.
        <span class="rq-count">{{ rows.length }} of {{ LIMIT }} used</span>
      </p>
    </header>

    <div class="rq-toolbar">
      <input v-model="search" type="search" class="rq-search" placeholder="Search name or URL…" />
      <div class="rq-filters">
        <button
          v-for="f in PROTOCOLS" :key="f.value"
          :class="['rq-chip', { active: protocol === f.value }]"
          @click="protocol = f.value"
        >{{ f.label }}</button>
      </div>
    </div>

    <Skeleton v-if="loading" :rows="6" />

    <p v-else-if="!rows.length" class="rq-muted">
      Nothing saved yet. Send something in the <router-link to="/tester">Tester</router-link> and save it.
    </p>

    <p v-else-if="!filtered.length" class="rq-muted">Nothing matches that.</p>

    <ul v-else class="rq-list">
      <li v-for="r in filtered" :key="r.id" class="rq-row">
        <div class="rq-main">
          <div class="rq-row-top">
            <span class="rq-method" :class="'m-' + (r.method || '').toLowerCase()">{{ r.method }}</span>

            <input
              v-if="renaming === r.id"
              ref="renameInput"
              v-model="renameValue"
              class="rq-rename"
              maxlength="255"
              @keyup.enter="commitRename(r)"
              @keyup.esc="renaming = null"
              @blur="commitRename(r)"
            />
            <span v-else class="rq-name" @dblclick="startRename(r)" title="Double-click to rename">{{ r.name }}</span>

            <span v-if="r.protocol !== 'rest'" class="rq-tag">{{ r.protocol }}</span>
            <span v-if="r.has_auth" class="rq-tag" title="Has its own auth">auth</span>
            <span v-if="r.has_assertions" class="rq-tag" title="Has assertions">assertions</span>
            <span v-if="r.has_contract" class="rq-tag" title="Has a response contract">contract</span>
          </div>

          <div class="rq-meta">
            <code class="rq-url">{{ r.url }}</code>
          </div>

          <div class="rq-meta">
            <em v-if="ownerName(r)" class="rq-owner">{{ ownerName(r) }}</em>
            <template v-if="(r.used_by || []).length">
              used by {{ r.used_by.join(', ') }}
            </template>
            <template v-else>not in any collection</template>
          </div>
        </div>

        <div class="rq-actions">
          <button class="rq-btn" @click="open(r)">Open</button>
          <button class="rq-btn" @click="startRename(r)" :disabled="busy === r.id">Rename</button>
          <button class="rq-btn" @click="duplicate(r)" :disabled="busy === r.id">Duplicate</button>
          <button class="rq-btn rq-btn-danger" @click="remove(r)" :disabled="busy === r.id">Delete</button>
        </div>
      </li>
    </ul>
  </main>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import Skeleton from '../components/Skeleton.vue';
import { useRequestsStore } from '../store/requests';
import { useAuthStore } from '../store/auth';
import { confirmDialog } from '../confirm';
import { toast } from '../toast';

// Mirrors SavedRequestController::FREE_PLAN_LIMIT.
const LIMIT = 60;

const PROTOCOLS = [
  { value: '', label: 'All' },
  { value: 'rest', label: 'REST' },
  { value: 'mcp', label: 'MCP' },
  { value: 'a2a', label: 'A2A' },
  { value: 'grpc', label: 'gRPC' },
  { value: 'mqtt', label: 'MQTT' },
  { value: 'amqp', label: 'AMQP' },
];

const router = useRouter();
const store = useRequestsStore();
const authStore = useAuthStore();

const loading = ref(true);
const busy = ref(null);
const search = ref('');
const protocol = ref('');
const renaming = ref(null);
const renameValue = ref('');
const renameInput = ref(null);

const rows = computed(() => store.savedRequests);

const ownerName = (r) => (r.owner && r.owner.id !== authStore.user?.id ? r.owner.name : '');

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase();

  return rows.value.filter((r) => {
    if (protocol.value && (r.protocol || 'rest') !== protocol.value) return false;
    if (!needle) return true;
    return `${r.name} ${r.url}`.toLowerCase().includes(needle);
  });
});

onMounted(async () => {
  try {
    await store.fetchSavedRequests();
  } finally {
    loading.value = false;
  }
});

function open(r) {
  // The Tester picks this up on mount, the same way the command palette does.
  store.openInTester(r);
  router.push('/tester');
}

function startRename(r) {
  renaming.value = r.id;
  renameValue.value = r.name;
  nextTick(() => renameInput.value?.[0]?.focus?.() ?? renameInput.value?.focus?.());
}

async function commitRename(r) {
  if (renaming.value !== r.id) return;

  const name = renameValue.value.trim();
  renaming.value = null;

  if (!name || name === r.name) return;

  busy.value = r.id;
  try {
    await store.updateRequest(r.id, {
      name,
      // The endpoint validates the whole shape, so the unchanged fields travel
      // with the new name rather than being omitted and tripping validation.
      method: r.method,
      url: r.url,
    });
    toast.success(`Renamed to "${name}".`);
  } catch (e) {
    toast.error(e.response?.data?.message || 'Could not rename that request.');
  } finally {
    busy.value = null;
  }
}

async function duplicate(r) {
  busy.value = r.id;
  try {
    await store.duplicateRequest(r.id);
    toast.success(`Duplicated "${r.name}".`);
  } catch (e) {
    toast.error(e.response?.data?.message || 'Could not duplicate that request.');
  } finally {
    busy.value = null;
  }
}

async function remove(r) {
  // Deleting cascades into collection steps, so say which suites will get
  // shorter rather than letting someone find out later.
  const warning = (r.used_by || []).length
    ? `\n\nIt is used by ${(r.used_by || []).join(', ')} — those collections will lose that step.`
    : '';

  if (!(await confirmDialog(`Delete "${r.name}"?${warning}`))) return;

  busy.value = r.id;
  try {
    const result = await store.deleteRequest(r.id);
    toast.success(
      result?.removed_steps
        ? `Deleted "${r.name}" and removed ${result.removed_steps} collection step(s).`
        : `Deleted "${r.name}".`
    );
  } catch (e) {
    toast.error(e.response?.data?.message || 'Could not delete that request.');
  } finally {
    busy.value = null;
  }
}
</script>

<style scoped>
.rq { padding: 24px 28px; max-width: 1100px; margin: 0 auto; }
.rq-title { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin: 0 0 4px; }
.rq-sub { color: var(--text-secondary); max-width: 72ch; margin: 0 0 20px; line-height: 1.5; }
.rq-count { display: inline-block; margin-left: 8px; font-size: 0.8rem; opacity: .8; }
.rq-muted { color: var(--text-secondary); }

.rq-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
.rq-search {
  flex: 1 1 240px; padding: 8px 12px; border-radius: 8px;
  border: 1px solid var(--border-color); background: var(--panel-bg, var(--bg-secondary)); color: var(--text-primary);
}
.rq-search:focus { outline: none; border-color: var(--accent-color); }
.rq-filters { display: flex; gap: 6px; flex-wrap: wrap; }
.rq-chip {
  padding: 5px 12px; border-radius: 999px; font-size: 12px; cursor: pointer;
  border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary);
}
.rq-chip:hover { color: var(--text-primary); }
.rq-chip.active { background: var(--accent-soft, rgba(88,166,255,.12)); border-color: var(--accent-color); color: var(--accent-color); font-weight: 600; }

.rq-list { list-style: none; margin: 0; padding: 0; }
.rq-row {
  display: flex; gap: 14px; align-items: flex-start; justify-content: space-between;
  border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 14px; margin-bottom: 8px;
}
.rq-main { min-width: 0; }
.rq-row-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.rq-name { font-weight: 600; color: var(--text-primary); cursor: text; }
.rq-rename {
  font-weight: 600; padding: 3px 8px; border-radius: 6px;
  border: 1px solid var(--accent-color); background: var(--panel-bg, var(--bg-secondary)); color: var(--text-primary);
}
.rq-method {
  font-size: 11px; font-weight: 700; letter-spacing: .03em;
  padding: 2px 7px; border-radius: 5px; background: var(--border-color); color: var(--text-secondary);
}
.m-get { background: rgba(63,185,80,.16); color: #3fb950; }
.m-post { background: rgba(88,166,255,.16); color: #58a6ff; }
.m-put, .m-patch { background: rgba(210,153,34,.18); color: #d29922; }
.m-delete { background: rgba(248,81,73,.16); color: #f85149; }
.rq-tag {
  font-size: 10px; text-transform: uppercase; letter-spacing: .04em; font-weight: 700;
  padding: 2px 6px; border-radius: 5px; background: var(--border-color); color: var(--text-secondary);
}
.rq-meta { font-size: 12px; color: var(--text-secondary); margin-top: 4px; }
.rq-url { font-family: ui-monospace, Menlo, monospace; font-size: 12px; word-break: break-all; }
.rq-owner { margin-right: 6px; opacity: .85; }

.rq-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }
.rq-btn {
  padding: 5px 11px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer;
  border: 1px solid var(--border-color); background: transparent; color: var(--text-primary);
}
.rq-btn:hover { border-color: var(--accent-color); color: var(--accent-color); }
.rq-btn:disabled { opacity: .5; cursor: default; }
.rq-btn-danger:hover { border-color: #f85149; color: #f85149; }
</style>
