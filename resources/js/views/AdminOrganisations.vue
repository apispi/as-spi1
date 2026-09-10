<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Organisations</h1>
        <p class="ad-sub">Group users by customer. Membership is for administration and reporting — it does not restrict what anyone can see.</p>
      </div>
      <button class="ad-primary" @click="startNew">New organisation</button>
    </header>

    <p v-if="loading" class="ad-muted">Loading…</p>

    <div v-else-if="!organisations.length" class="ad-empty">
      <Icon name="layers" :size="26" />
      <p>No organisations yet. Create one, then assign users from their detail page.</p>
    </div>

    <ul v-else class="ad-list">
      <li v-for="o in organisations" :key="o.id" class="ad-row">
        <span class="ad-count">{{ o.users_count }}</span>
        <div class="ad-row-main">
          <div>
            <span class="ad-row-name">{{ o.name }}</span>
            <span v-if="!o.is_active" class="ad-pill">inactive</span>
          </div>
          <span class="ad-row-sub">{{ o.description || o.slug }}</span>
        </div>
        <button class="ad-btn" @click="openMembers(o)">Members</button>
        <button class="ad-btn" @click="edit(o)">Edit</button>
      </li>
    </ul>

    <p v-if="unassigned" class="ad-note">
      {{ unassigned }} user{{ unassigned === 1 ? ' is' : 's are' }} not in any organisation.
    </p>

    <!-- Members management -->
    <div v-if="members" class="ad-scrim" @click.self="members = null">
      <div class="ad-modal">
        <header class="ad-modal-head">
          <h2>{{ members.org.name }} · members</h2>
          <button class="ad-x" @click="members = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="ad-form">
          <!-- Add a member -->
          <label class="ad-label">Add a member</label>
          <input v-model="memberSearch" class="input-field" placeholder="Search users by name or email…" @input="searchUsers" />
          <ul v-if="candidates.length" class="om-candidates">
            <li v-for="c in candidates" :key="c.id" class="om-candidate">
              <span>{{ c.name }} <span class="ad-muted">· {{ c.email }}</span><span v-if="c.organisation" class="ad-pill">{{ c.organisation.name }}</span></span>
              <button class="ad-btn" :disabled="memberBusy" @click="addMember(c)">Add</button>
            </li>
          </ul>

          <label class="ad-label" style="margin-top:14px">Current members ({{ members.list.length }})</label>
          <p v-if="!members.list.length" class="ad-muted">No members yet.</p>
          <ul v-else class="ad-list om-list">
            <li v-for="m in members.list" :key="m.id" class="ad-row">
              <div class="ad-row-main">
                <span class="ad-row-name">{{ m.name }} <span v-if="m.is_admin" class="ad-pill passing">admin</span></span>
                <span class="ad-row-sub">{{ m.email }}</span>
              </div>
              <button class="ad-btn ad-danger-sm" :disabled="memberBusy" @click="removeMember(m)">Remove</button>
            </li>
          </ul>
          <p v-if="memberError" class="ad-error">{{ memberError }}</p>
        </div>
      </div>
    </div>

    <div v-if="editing" class="ad-scrim" @click.self="editing = null">
      <div class="ad-modal">
        <header class="ad-modal-head">
          <h2>{{ editing.id ? 'Edit organisation' : 'New organisation' }}</h2>
          <button class="ad-x" @click="editing = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="ad-form">
          <label class="ad-label">Name</label>
          <input v-model="editing.name" class="input-field" placeholder="Acme Ltd" maxlength="120" />

          <label class="ad-label">Description</label>
          <input v-model="editing.description" class="input-field" placeholder="Optional" maxlength="500" />

          <label class="ad-check">
            <input type="checkbox" v-model="editing.is_active" />
            <span>Active</span>
          </label>

          <p v-if="error" class="ad-error">{{ error }}</p>

          <footer class="ad-actions">
            <button class="ad-primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="ad-danger" @click="remove" :disabled="saving">Delete</button>
            <button class="ad-btn" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>

          <p v-if="editing.id" class="ad-note">
            Deleting an organisation keeps its members — they simply become unassigned.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import Icon from '../components/Icon.vue';

const organisations = ref([]);
const unassigned = ref(0);
const loading = ref(true);
const editing = ref(null);
const saving = ref(false);
const error = ref('');

const fetchAll = async () => {
  loading.value = true;
  try {
    const res = await axios.get('/api/admin/organisations');
    organisations.value = res.data.organisations;
    unassigned.value = res.data.unassigned_users;
  } finally {
    loading.value = false;
  }
};

onMounted(fetchAll);

const startNew = () => {
  error.value = '';
  editing.value = { id: null, name: '', description: '', is_active: true };
};

const edit = (o) => {
  error.value = '';
  editing.value = { id: o.id, name: o.name, description: o.description || '', is_active: o.is_active };
};

const save = async () => {
  saving.value = true;
  error.value = '';
  const payload = {
    name: editing.value.name.trim(),
    description: editing.value.description || null,
    is_active: editing.value.is_active,
  };
  try {
    if (editing.value.id) {
      await axios.put(`/api/admin/organisations/${editing.value.id}`, payload);
    } else {
      await axios.post('/api/admin/organisations', payload);
    }
    editing.value = null;
    await fetchAll();
  } catch (e) {
    const data = e.response?.data;
    error.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save.';
  } finally {
    saving.value = false;
  }
};

// ── Member management ──
const members = ref(null);           // { org, list }
const memberSearch = ref('');
const candidates = ref([]);
const memberBusy = ref(false);
const memberError = ref('');
let searchTimer = null;

const openMembers = async (o) => {
  memberSearch.value = '';
  candidates.value = [];
  memberError.value = '';
  members.value = { org: o, list: [] };
  await loadMembers(o.id);
};

const loadMembers = async (orgId) => {
  try {
    const res = await axios.get(`/api/admin/organisations/${orgId}/members`);
    members.value.list = res.data.members;
  } catch {
    memberError.value = 'Could not load members.';
  }
};

const searchUsers = () => {
  clearTimeout(searchTimer);
  const term = memberSearch.value.trim();
  if (!term) { candidates.value = []; return; }
  searchTimer = setTimeout(async () => {
    try {
      const res = await axios.get('/api/admin/users', { params: { search: term, per_page: 8 } });
      const memberIds = new Set(members.value.list.map((m) => m.id));
      candidates.value = (res.data.data || []).filter((u) => !memberIds.has(u.id));
    } catch { candidates.value = []; }
  }, 250);
};

const addMember = async (c) => {
  memberBusy.value = true;
  memberError.value = '';
  try {
    await axios.put(`/api/admin/users/${c.id}/organisation`, { organisation_id: members.value.org.id });
    memberSearch.value = '';
    candidates.value = [];
    await loadMembers(members.value.org.id);
    await fetchAll();
  } catch (e) {
    memberError.value = e.response?.data?.message || 'Could not add member.';
  } finally {
    memberBusy.value = false;
  }
};

const removeMember = async (m) => {
  if (!confirm(`Remove ${m.name} from ${members.value.org.name}?`)) return;
  memberBusy.value = true;
  memberError.value = '';
  try {
    await axios.put(`/api/admin/users/${m.id}/organisation`, { organisation_id: null });
    await loadMembers(members.value.org.id);
    await fetchAll();
  } catch (e) {
    memberError.value = e.response?.data?.message || 'Could not remove member.';
  } finally {
    memberBusy.value = false;
  }
};

const remove = async () => {
  if (!confirm(`Delete "${editing.value.name}"? Its members stay, unassigned.`)) return;
  saving.value = true;
  try {
    await axios.delete(`/api/admin/organisations/${editing.value.id}`);
    editing.value = null;
    await fetchAll();
  } catch {
    error.value = 'Failed to delete.';
  } finally {
    saving.value = false;
  }
};
</script>

<style scoped>
@import './admin-shared.css';

.om-candidates { list-style: none; margin: 6px 0 0; padding: 0; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
.om-candidate { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; font-size: 13px; border-bottom: 1px solid var(--border-color); }
.om-candidate:last-child { border-bottom: none; }
.om-list { margin-top: 8px; }
.ad-danger-sm { color: var(--error-color); border-color: rgba(248,113,113,.4); }
.ad-danger-sm:hover:not(:disabled) { background: rgba(248,113,113,.12); }
</style>
