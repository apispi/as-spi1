<template>
  <div class="col">
    <header class="col-head">
      <div>
        <h1 class="col-title">Collections</h1>
        <p class="col-sub">Your saved requests, the collections that run them in order, and what you've sent recently.</p>
      </div>
      <div class="col-head-actions">
        <button class="col-btn" @click="showManager = true">Manage collections</button>
        <router-link to="/tester" class="col-primary">New request</router-link>
      </div>
    </header>

    <nav class="col-tabs">
      <button :class="['col-tab', tab === 'saved' ? 'active' : '']" @click="tab = 'saved'">
        Saved requests <span class="col-count">{{ requestsStore.savedRequests.length }}</span>
      </button>
      <button :class="['col-tab', tab === 'collections' ? 'active' : '']" @click="tab = 'collections'">
        Collections <span class="col-count">{{ collectionsStore.collections.length }}</span>
      </button>
      <button :class="['col-tab', tab === 'history' ? 'active' : '']" @click="openHistory">History</button>
    </nav>

    <!-- Saved requests -->
    <section v-if="tab === 'saved'">
      <p v-if="requestsStore.isLoading" class="col-muted">Loading…</p>
      <div v-else-if="!requestsStore.savedRequests.length" class="col-empty">
        <Icon name="send" :size="26" />
        <p>No saved requests yet. Build one in the tester and save it — saved requests are the steps a collection runs.</p>
        <router-link to="/tester" class="col-empty-btn">Open the tester</router-link>
      </div>

      <div v-if="fuzzResult" class="col-fuzz">
        <div class="col-fuzz-head">
          <span class="col-parity-verdict" :class="fuzzResult.passed ? 'ok' : 'bad'">
            {{ fuzzResult.passed ? 'Handled cleanly' : fuzzResult.findings + ' finding' + (fuzzResult.findings === 1 ? '' : 's') }}
          </span>
          <span class="col-parity-title">{{ fuzzName }} — {{ fuzzResult.total }} variants</span>
          <button class="col-btn" @click="fuzzResult = null">Close</button>
        </div>
        <ul class="col-parity-steps">
          <li v-for="(r, i) in fuzzResult.results.filter(x => x.verdict === 'server_error' || x.verdict === 'accepted_invalid')" :key="i" class="diverged">
            <span class="col-parity-mark">✕</span>
            <span class="col-parity-name">{{ r.label }}</span>
            <span class="col-parity-note bad">{{ r.verdict === 'server_error' ? 'server error ' + r.status : 'accepted (' + r.status + ')' }}</span>
          </li>
          <li v-if="fuzzResult.passed"><span class="col-parity-mark">✓</span> <span class="col-parity-name">Every malformed variant was rejected or handled.</span></li>
        </ul>
      </div>

      <ul v-else-if="requestsStore.savedRequests.length" class="col-list">
        <li v-for="req in requestsStore.savedRequests" :key="req.id" class="col-row">
          <span class="method-badge" :class="badgeClass(req)">{{ badgeLabel(req) }}</span>
          <div class="col-row-main" @click="open(req)">
            <span class="col-row-name">{{ req.name }} <em v-if="ownerName(req)" class="col-owner">{{ ownerName(req) }}</em></span>
            <span class="col-row-url" :title="req.url">{{ req.url }}</span>
          </div>
          <span v-if="req.assertions && req.assertions.length" class="col-tag">
            {{ req.assertions.length }} assertion{{ req.assertions.length === 1 ? '' : 's' }}
          </span>
          <div class="col-row-actions">
            <button v-if="(req.protocol || 'rest') === 'rest' && req.body" class="col-btn" @click="fuzz(req)" :disabled="fuzzing === req.id">
              {{ fuzzing === req.id ? 'Fuzzing…' : 'Fuzz' }}
            </button>
            <button class="col-btn" @click="open(req)">Open</button>
            <button class="col-del" @click="remove(req)" title="Delete request"><Icon name="close" :size="14" /></button>
          </div>
        </li>
      </ul>
    </section>

    <!-- Collections -->
    <section v-else-if="tab === 'collections'">
      <p v-if="collectionsStore.isLoading" class="col-muted">Loading…</p>
      <div v-else-if="!collectionsStore.collections.length" class="col-empty">
        <Icon name="layers" :size="26" />
        <p>No collections yet. A collection runs saved requests in order against one environment, checking each step's assertions.</p>
        <button class="col-empty-btn" @click="showManager = true">Create a collection</button>
      </div>

      <ul v-else class="col-list">
        <li v-for="c in collectionsStore.collections" :key="c.id" class="col-row">
          <span class="col-steps">{{ c.steps.length }}</span>
          <div class="col-row-main">
            <span class="col-row-name">{{ c.name }} <em v-if="ownerName(c)" class="col-owner">{{ ownerName(c) }}</em></span>
            <span class="col-row-url">{{ c.description || stepNames(c) }}</span>
          </div>
          <div class="col-row-actions">
            <button class="col-btn" @click="run(c)" :disabled="running === c.id || !c.steps.length">
              {{ running === c.id ? 'Running…' : 'Run' }}
            </button>
            <button class="col-btn" @click="openParity(c)" :disabled="!c.steps.length || envStore.environments.length < 2"
                    title="Run against two environments and diff the responses">Compare envs</button>
            <button class="col-btn" @click="showManager = true">Edit</button>
          </div>
        </li>
      </ul>

      <RunResults
        v-if="collectionsStore.lastRun"
        class="col-run"
        :run="collectionsStore.lastRun"
        @close="collectionsStore.lastRun = null"
      />

      <div v-if="parity" class="col-parity col-run">
        <div class="col-parity-head">
          <span class="col-parity-verdict" :class="parity.result ? (parity.result.in_parity ? 'ok' : 'bad') : ''">
            {{ parity.running ? 'Comparing…' : (parity.result ? (parity.result.in_parity ? 'In parity' : parity.result.diverged_count + ' diverged') : '') }}
          </span>
          <span class="col-parity-title">{{ parity.name }}: {{ parity.envA }} vs {{ parity.envB }}</span>
          <button class="col-btn" @click="parity = null">Close</button>
        </div>
        <ul v-if="parity.result" class="col-parity-steps">
          <li v-for="st in parity.result.steps" :key="st.index" :class="st.diverged ? 'diverged' : ''">
            <span class="col-parity-mark">{{ st.diverged ? '✕' : '✓' }}</span>
            <span class="col-parity-name">{{ st.name }}</span>
            <span v-if="st.status_differs" class="col-parity-note bad">{{ st.status_a }} vs {{ st.status_b }}</span>
            <template v-if="st.shape">
              <span v-for="c in st.shape.only_in_a" :key="'a'+c.path" class="col-parity-note bad">only in {{ parity.envA }}: {{ c.path }}</span>
              <span v-for="c in st.shape.only_in_b" :key="'b'+c.path" class="col-parity-note bad">only in {{ parity.envB }}: {{ c.path }}</span>
              <span v-for="c in st.shape.type_differs" :key="'t'+c.path" class="col-parity-note bad">{{ c.path }} {{ c.expected }}→{{ c.actual }}</span>
            </template>
          </li>
        </ul>
      </div>
    </section>

    <!-- History -->
    <section v-else>
      <p v-if="historyLoading" class="col-muted">Loading…</p>
      <div v-else-if="!history.length" class="col-empty">
        <Icon name="activity" :size="26" />
        <p>Nothing sent yet.</p>
      </div>

      <template v-else>
        <ul class="col-list">
          <li v-for="entry in history" :key="entry.id" class="col-row">
            <span class="method-badge" :class="entry.protocol !== 'rest' ? entry.protocol : (entry.method || 'get').toLowerCase()">
              {{ entry.protocol === 'rest' ? entry.method : entry.protocol.toUpperCase() }}
            </span>
            <div class="col-row-main" @click="openHistoryEntry(entry)">
              <span class="col-row-name">{{ entry.url }}</span>
              <span class="col-row-url">
                {{ entry.protocol !== 'rest' ? entry.method + ' · ' : '' }}{{ entry.time_ms }}ms · {{ ago(entry.created_at) }}
              </span>
            </div>
            <span class="col-status" :class="entry.status && entry.status < 400 ? 'ok' : 'fail'">{{ entry.status || 'ERR' }}</span>
            <div class="col-row-actions">
              <button class="col-btn" @click="openHistoryEntry(entry)">Open</button>
            </div>
          </li>
        </ul>
        <button class="col-clear" @click="clearHistory">Clear history</button>
      </template>
    </section>

    <CollectionManager v-if="showManager" @close="closeManager" @ran="onRan" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import CollectionManager from '../components/CollectionManager.vue';
import RunResults from '../components/RunResults.vue';
import { useRequestsStore } from '../store/requests';
import { useCollectionsStore } from '../store/collections';
import { useEnvironmentsStore } from '../store/environments';
import { useAuthStore } from '../store/auth';

const router = useRouter();
const requestsStore = useRequestsStore();
const collectionsStore = useCollectionsStore();
const envStore = useEnvironmentsStore();
const authStore = useAuthStore();

const tab = ref('saved');
const history = ref([]);
const historyLoading = ref(false);
const showManager = ref(false);
const running = ref(null);
const parity = ref(null);
const fuzzing = ref(null);
const fuzzResult = ref(null);
const fuzzName = ref('');

onMounted(() => {
  requestsStore.fetchSavedRequests();
  collectionsStore.fetch();
  envStore.fetch();
});

// In a shared workspace, show whose resource this is — but only when it
// is not the current user's, so solo accounts see no noise.
const ownerName = (x) => (x.owner && x.owner.id !== authStore.user?.id ? x.owner.name : '');

const badgeLabel = (req) => (req.protocol && req.protocol !== 'rest' ? req.protocol.toUpperCase() : req.method);
const badgeClass = (req) => (req.protocol && req.protocol !== 'rest' ? req.protocol : (req.method || 'get').toLowerCase());
const stepNames = (c) => c.steps.map((s) => s.saved_request?.name).filter(Boolean).join(' → ') || 'No steps yet';

// Hand the request to the tester, which picks it up on mount.
const open = (req) => {
  requestsStore.openInTester(req);
  router.push('/tester');
};

const openHistoryEntry = (entry) => {
  requestsStore.openInTester({
    protocol: entry.protocol,
    method: entry.method,
    url: entry.url,
    body: entry.body || '',
    params: entry.params || null,
    headers: null,
  });
  router.push('/tester');
};

const remove = async (req) => {
  if (!confirm(`Delete "${req.name}"?`)) return;
  await requestsStore.deleteRequest(req.id);
  // A deleted request takes its collection steps with it.
  collectionsStore.fetch();
};

const run = async (c) => {
  running.value = c.id;
  try {
    await collectionsStore.run(c.id, envStore.selectedId);
  } finally {
    running.value = null;
  }
};

const fuzz = async (req) => {
  fuzzing.value = req.id;
  fuzzName.value = req.name;
  fuzzResult.value = null;
  try {
    const res = await axios.post(`/api/saved-requests/${req.id}/fuzz`, { environment_id: envStore.selectedId || null });
    fuzzResult.value = res.data;
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.results) fuzzResult.value = e.response.data;
    else alert(e.response?.data?.message || 'Fuzzing failed.');
  } finally {
    fuzzing.value = null;
  }
};

const openParity = async (c) => {
  // Default to the first two environments; a fuller picker can come later.
  const envs = envStore.environments;
  parity.value = { name: c.name, envA: envs[0].name, envB: envs[1].name, running: true, result: null };
  try {
    const res = await axios.post(`/api/collections/${c.id}/parity`, {
      environment_a: envs[0].id, environment_b: envs[1].id,
    });
    parity.value.result = res.data;
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.steps) {
      parity.value.result = e.response.data;
    } else {
      parity.value = null;
    }
  } finally {
    if (parity.value) parity.value.running = false;
  }
};

const openHistory = async () => {
  tab.value = 'history';
  historyLoading.value = true;
  try {
    history.value = (await axios.get('/api/history')).data;
  } catch {
    history.value = [];
  } finally {
    historyLoading.value = false;
  }
};

const clearHistory = async () => {
  if (!confirm('Clear your entire request history?')) return;
  await axios.delete('/api/history');
  history.value = [];
};

const closeManager = () => {
  showManager.value = false;
  collectionsStore.fetch();
};

const onRan = () => {
  tab.value = 'collections';
};

const ago = (iso) => {
  if (!iso) return '';
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
};
</script>

<style scoped>
.col { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
.col-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.col-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; color: var(--text-primary); margin: 0; }
.col-sub { color: var(--text-secondary); margin: 8px 0 0; font-size: 15px; }
.col-head-actions { display: flex; gap: 8px; flex-shrink: 0; }
.col-muted { color: var(--text-secondary); }

.col-primary { padding: 9px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
.col-primary:hover { background: var(--accent-hover, var(--accent-color)); }
.col-btn { padding: 6px 12px; border-radius: 7px; background: none; border: 1px solid var(--border-color); color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.col-btn:hover:not(:disabled) { border-color: var(--accent-color); color: var(--accent-color); }
.col-btn:disabled { opacity: .45; cursor: not-allowed; }

.col-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border-color); margin-bottom: 16px; }
.col-tab { padding: 9px 14px; background: none; border: none; border-bottom: 2px solid transparent; color: var(--text-secondary); font-size: 13.5px; cursor: pointer; margin-bottom: -1px; }
.col-tab:hover { color: var(--text-primary); }
.col-tab.active { color: var(--accent-color); border-bottom-color: var(--accent-color); font-weight: 600; }
.col-count { font-size: 11px; background: rgba(255,255,255,.07); padding: 1px 6px; border-radius: 999px; margin-left: 4px; }

.col-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 14px; color: var(--text-secondary); }
.col-empty p { max-width: 460px; margin: 0; font-size: 14px; line-height: 1.6; }
.col-empty-btn { padding: 8px 16px; border-radius: 8px; background: var(--accent-color); color: #fff; text-decoration: none; border: none; font-size: 13px; font-weight: 600; cursor: pointer; }

.col-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.col-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; }
.col-row + .col-row { border-top: 1px solid var(--border-color); }
.col-row-main { flex: 1; min-width: 0; cursor: pointer; display: flex; flex-direction: column; gap: 2px; }
.col-row-name { font-size: 14px; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.col-row-url { font-size: 12px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.col-row-actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
.col-del { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.col-del:hover { color: #f85149; }
.col-owner { font-size: 11.5px; font-style: normal; color: var(--text-secondary); font-weight: 400; }
.col-tag { font-size: 11px; color: var(--text-secondary); background: rgba(255,255,255,.06); padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.col-steps { width: 24px; height: 24px; border-radius: 999px; background: var(--accent-soft, rgba(88,166,255,.12)); color: var(--accent-color); font-size: 11.5px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.col-status { font-size: 11.5px; font-weight: 700; }
.col-status.ok { color: #3fb950; }
.col-status.fail { color: #f85149; }
.col-clear { margin-top: 12px; padding: 7px 13px; background: none; border: 1px solid var(--border-color); border-radius: 7px; color: var(--text-secondary); font-size: 12.5px; cursor: pointer; }
.col-clear:hover { border-color: #f85149; color: #f85149; }
.col-run { margin-top: 16px; border: 1px solid var(--border-color); border-radius: 14px; }
.col-parity { padding: 12px 14px; }
.col-fuzz { border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; }
.col-fuzz-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.col-parity-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.col-parity-verdict { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.col-parity-verdict.ok { color: #3fb950; background: rgba(63,185,80,.16); }
.col-parity-verdict.bad { color: #f85149; background: rgba(248,81,73,.14); }
.col-parity-title { font-size: 13px; color: var(--text-primary); flex: 1; }
.col-parity-steps { list-style: none; margin: 0; padding: 0; }
.col-parity-steps li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; font-size: 12.5px; padding: 6px 0; border-top: 1px solid var(--border-color); }
.col-parity-mark { font-weight: 700; color: #3fb950; }
.col-parity-steps .diverged .col-parity-mark { color: #f85149; }
.col-parity-name { font-weight: 600; color: var(--text-primary); }
.col-parity-note { font-size: 11.5px; color: var(--text-secondary); font-family: 'Courier New', monospace; }
.col-parity-note.bad { color: #f85149; }

.method-badge { font-size: 10px; font-weight: 700; padding: 3px 7px; border-radius: 4px; flex-shrink: 0; }
.method-badge.get { color: #3fb950; background: rgba(63,185,80,.15); }
.method-badge.post { color: #58a6ff; background: rgba(88,166,255,.15); }
.method-badge.put, .method-badge.patch { color: #d29922; background: rgba(210,153,34,.15); }
.method-badge.delete { color: #f85149; background: rgba(248,81,73,.15); }
.method-badge.mcp { color: #a371f7; background: rgba(163,113,247,.15); }
.method-badge.a2a { color: #f85149; background: rgba(248,81,73,.15); }
.method-badge.grpc { color: #58a6ff; background: rgba(88,166,255,.15); }
.method-badge.mqtt { color: #3fb950; background: rgba(63,185,80,.15); }
.method-badge.amqp { color: #d29922; background: rgba(210,153,34,.15); }

@media (max-width: 720px) {
  .col-head { flex-direction: column; }
  .col-tag { display: none; }
}
</style>
