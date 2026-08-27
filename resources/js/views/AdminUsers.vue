<template>
  <div class="ad">
    <header class="ad-head">
      <div>
        <h1 class="ad-title">Users</h1>
        <p class="ad-sub">Every account, active or deactivated. Click a row for the full picture.</p>
      </div>
      <button class="ad-primary" @click="startCreate">New user</button>
    </header>

    <div class="ad-toolbar">
      <select v-model="filter" class="input-field ad-filter" @change="refetch">
        <option value="active">Active</option>
        <option value="trashed">Deactivated</option>
        <option value="all">All</option>
      </select>
      <input
        v-model="search"
        class="input-field ad-search"
        placeholder="Search name or email…"
        @input="onSearchInput"
      />
      <span class="ad-muted ad-total">{{ total }} user{{ total === 1 ? '' : 's' }}</span>
    </div>

    <p v-if="loading && !users.length" class="ad-muted">Loading…</p>

    <div v-else class="ad-tablewrap">
      <table class="ad-table">
        <thead>
          <tr>
            <th class="sortable" @click="sortBy('name')">Name {{ arrow('name') }}</th>
            <th>Email</th>
            <th>Organisation</th>
            <th>Role</th>
            <th class="num">Saved</th>
            <th class="sortable" @click="sortBy('created_at')">Registered {{ arrow('created_at') }}</th>
            <th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="user in sorted"
            :key="user.id"
            class="ad-tr"
            :class="{ trashed: user.deleted_at }"
            @click="openUser(user)"
          >
            <td class="name-cell">{{ user.name }}</td>
            <td class="ad-muted">{{ user.email }}</td>
            <td class="ad-muted">{{ user.organisation?.name || '—' }}</td>
            <td>
              <span class="ad-pill" :class="user.is_admin ? 'passing' : 'unknown'">{{ user.is_admin ? 'Admin' : 'User' }}</span>
              <span v-if="!user.email_verified" class="ad-pill">unverified</span>
            </td>
            <td class="num ad-muted">{{ user.saved_requests_count }}</td>
            <td class="ad-muted">{{ shortDate(user.created_at) }}</td>
            <td class="actions" @click.stop>
              <template v-if="user.deleted_at">
                <button class="ad-btn" @click="restoreUser(user)">Restore</button>
                <button class="ad-btn danger" @click="hardDeleteUser(user)" :disabled="isCurrentUser(user)">Delete forever</button>
              </template>
              <template v-else>
                <button class="ad-btn" @click="toggleAdmin(user)" :disabled="isCurrentUser(user)">
                  {{ user.is_admin ? 'Demote' : 'Promote' }}
                </button>
                <button class="ad-btn danger" @click="deleteUser(user)" :disabled="isCurrentUser(user)">Deactivate</button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="ad-pager" v-if="lastPage > 1">
      <button class="ad-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹ Prev</button>
      <span class="ad-muted">Page {{ page }} of {{ lastPage }}</span>
      <button class="ad-btn" :disabled="page >= lastPage" @click="goToPage(page + 1)">Next ›</button>
    </div>

    <div v-if="toast" class="ad-toast" :class="toastType">{{ toast }}</div>

    <!-- Create -->
    <div v-if="creating" class="ad-scrim" @click.self="creating = null">
      <div class="ad-modal">
        <header class="ad-modal-head">
          <h2>New user</h2>
          <button class="ad-x" @click="creating = null" aria-label="Close"><Icon name="close" :size="18" /></button>
        </header>
        <div class="ad-form">
          <label class="ad-label">Name</label>
          <input v-model="creating.name" class="input-field" maxlength="255" />

          <label class="ad-label">Email</label>
          <input v-model="creating.email" class="input-field" type="email" autocomplete="off" />

          <label class="ad-label">Password</label>
          <input v-model="creating.password" class="input-field" type="password" autocomplete="new-password" />
          <p class="ad-hint">At least 12 characters. The account is created verified, so they can sign in straight away.</p>

          <label class="ad-label">Organisation</label>
          <select v-model="creating.organisation_id" class="input-field">
            <option :value="null">Not assigned</option>
            <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>

          <label class="ad-check">
            <input type="checkbox" v-model="creating.is_admin" />
            <span>Grant admin access</span>
          </label>

          <p v-if="createError" class="ad-error">{{ createError }}</p>

          <footer class="ad-actions">
            <button class="ad-primary" @click="createUser" :disabled="savingUser">
              {{ savingUser ? 'Creating…' : 'Create user' }}
            </button>
            <button class="ad-btn" @click="creating = null" :disabled="savingUser">Cancel</button>
          </footer>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import Icon from '../components/Icon.vue';
import { useAuthStore } from '../store/auth';

const router = useRouter();
const authStore = useAuthStore();

const users = ref([]);
const total = ref(0);
const page = ref(1);
const lastPage = ref(1);
const search = ref('');
const filter = ref('active');
const loading = ref(true);
const sortKey = ref('created_at');
const sortDir = ref(-1);

const creating = ref(null);
const savingUser = ref(false);
const createError = ref('');
const organisations = ref([]);

const toast = ref('');
const toastType = ref('success');
let searchDebounce = null;

const isCurrentUser = (user) => authStore.user && user.id === authStore.user.id;

const fetchUsers = async () => {
  loading.value = true;
  try {
    const res = await axios.get('/api/admin/users', {
      params: { page: page.value, search: search.value || undefined, filter: filter.value },
    });
    users.value = res.data.data;
    lastPage.value = res.data.last_page;
    total.value = res.data.total;
  } catch {
    show('Failed to load users.', 'error');
  } finally {
    loading.value = false;
  }
};

onMounted(fetchUsers);

const refetch = () => {
  page.value = 1;
  fetchUsers();
};

const onSearchInput = () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(refetch, 300);
};

const goToPage = (p) => {
  page.value = p;
  fetchUsers();
};

// Client-side sort of the current page: the dataset an admin scans is the
// page in front of them, and a server round-trip per header click would be
// slower than useful at this scale.
const sortBy = (key) => {
  if (sortKey.value === key) sortDir.value = -sortDir.value;
  else { sortKey.value = key; sortDir.value = 1; }
};

const arrow = (key) => (sortKey.value === key ? (sortDir.value === 1 ? '↑' : '↓') : '');

const sorted = computed(() =>
  [...users.value].sort((a, b) => {
    const av = a[sortKey.value] ?? '';
    const bv = b[sortKey.value] ?? '';
    return String(av).localeCompare(String(bv)) * sortDir.value;
  }));

const openUser = (user) => router.push(`/admin/users/${user.id}`);

const shortDate = (s) => new Date(s).toLocaleDateString('en-AU', { year: 'numeric', month: 'short', day: 'numeric' });

const show = (text, type = 'success') => {
  toast.value = text;
  toastType.value = type;
  setTimeout(() => { toast.value = ''; }, 3000);
};

const toggleAdmin = async (user) => {
  try {
    const res = await axios.post(`/api/admin/users/${user.id}/toggle-admin`);
    show(res.data.message);
    await fetchUsers();
  } catch (e) {
    show(e.response?.data?.message || 'Failed to update user.', 'error');
  }
};

const deleteUser = async (user) => {
  if (!confirm(`Deactivate "${user.name}" (${user.email})?\n\nThey will not be able to sign in. Their data is kept and you can restore them later.`)) return;
  try {
    const res = await axios.delete(`/api/admin/users/${user.id}`);
    show(res.data.message);
    await fetchUsers();
  } catch (e) {
    show(e.response?.data?.message || 'Failed to deactivate user.', 'error');
  }
};

const restoreUser = async (user) => {
  try {
    await axios.post(`/api/admin/users/${user.id}/restore`);
    show('User restored.');
    await fetchUsers();
  } catch (e) {
    show(e.response?.data?.message || 'Failed to restore user.', 'error');
  }
};

// Typed-email confirmation: deliberate friction for an action with no undo.
const hardDeleteUser = async (user) => {
  const typed = prompt(
    `PERMANENTLY delete "${user.name}" and everything they own — saved requests, collections, environments, monitors, alert channels and reports.\n\nThis cannot be undone.\n\nType their email to confirm:`
  );
  if (typed === null) return;
  if (typed.trim().toLowerCase() !== user.email.toLowerCase()) {
    show('That did not match the email. Nothing was deleted.', 'error');
    return;
  }
  try {
    const res = await axios.delete(`/api/admin/users/${user.id}/force`);
    const counts = Object.entries(res.data.deleted || {})
      .filter(([, n]) => n > 0)
      .map(([k, n]) => `${n} ${k.replace(/_/g, ' ')}`)
      .join(', ');
    show(counts ? `User deleted, along with ${counts}.` : 'User permanently deleted.');
    await fetchUsers();
  } catch (e) {
    show(e.response?.data?.message || 'Failed to delete user.', 'error');
  }
};

const startCreate = async () => {
  createError.value = '';
  creating.value = { name: '', email: '', password: '', is_admin: false, organisation_id: null };
  try {
    organisations.value = (await axios.get('/api/admin/organisations')).data.organisations;
  } catch {
    organisations.value = [];
  }
};

const createUser = async () => {
  savingUser.value = true;
  createError.value = '';
  try {
    await axios.post('/api/admin/users', {
      name: creating.value.name.trim(),
      email: creating.value.email.trim(),
      password: creating.value.password,
      is_admin: creating.value.is_admin,
      organisation_id: creating.value.organisation_id,
    });
    creating.value = null;
    show('User created.');
    await fetchUsers();
  } catch (e) {
    const data = e.response?.data;
    createError.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to create user.';
  } finally {
    savingUser.value = false;
  }
};
</script>

<style scoped>
@import './admin-shared.css';
</style>
