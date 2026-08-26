<template>
  <div class="admin-container">
    <div class="admin-header">
      <div class="header-left">
        <h2>Admin Dashboard</h2>
        <router-link to="/dashboard" class="back-link">← Back to workspace</router-link>
      </div>
    </div>

    <div class="stats-grid" v-if="stats">
      <div class="stat-card">
        <div class="stat-value">{{ stats.total_users }}</div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ stats.new_users_this_week }}</div>
        <div class="stat-label">New This Week</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ stats.admin_users }}</div>
        <div class="stat-label">Admin Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ stats.total_saved_requests }}</div>
        <div class="stat-label">Saved Requests</div>
      </div>
    </div>

    <div class="stats-grid usage-grid" v-if="stats">
      <div class="stat-card">
        <div class="stat-value">{{ stats.total_requests ?? 0 }}</div>
        <div class="stat-label">Total Requests</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ stats.requests_this_week ?? 0 }}</div>
        <div class="stat-label">Requests This Week</div>
      </div>
      <div class="stat-card protocol-card">
        <div class="stat-label">By Protocol</div>
        <div class="protocol-bars" v-if="stats.protocol_breakdown">
          <div class="protocol-bar-row" v-for="p in protocolRows" :key="p.key">
            <span class="protocol-name" :class="p.key">{{ p.label }}</span>
            <div class="protocol-track">
              <div class="protocol-fill" :class="p.key" :style="{ width: p.pct + '%' }"></div>
            </div>
            <span class="protocol-count">{{ p.count }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="users-section" v-if="connectors.length">
      <div class="section-header">
        <h3>Connectors</h3>
        <router-link to="/catalog" class="back-link">Manage in Catalog →</router-link>
      </div>
      <div class="table-container">
        <table class="users-table">
          <thead>
            <tr><th>Name</th><th>Endpoint</th><th>Protocol</th><th>Health</th><th>Last synced</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in connectors" :key="c.id">
              <td>{{ c.name }}</td>
              <td class="email-col conn-endpoint">{{ c.metadata?.endpoint || '—' }}</td>
              <td><span class="role-badge user">{{ (c.metadata?.protocol || 'mcp').toUpperCase() }}</span></td>
              <td>
                <span v-if="c.metadata?.last_check_ok === true" class="role-badge health-ok">● Reachable</span>
                <span v-else-if="c.metadata?.last_check_ok === false" class="role-badge health-bad">● Unreachable</span>
                <span v-else class="role-badge user">Unchecked</span>
              </td>
              <td class="date-col">{{ c.metadata?.last_synced_at ? formatDateTime(c.metadata.last_synced_at) : 'Never' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="users-section">
      <div class="section-header">
        <h3>All Users</h3>
        <button class="action-btn create-btn" @click="startCreate">+ New user</button>
        <select class="search-input filter-select" v-model="filter" @change="fetchUsers">
          <option value="active">Active</option>
          <option value="trashed">Deactivated</option>
          <option value="all">All</option>
        </select>
        <input
          type="text"
          class="search-input"
          placeholder="Search name or email..."
          v-model="search"
          @input="onSearchInput"
        />
      </div>
      <div class="table-container">
        <table class="users-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Organisation</th>
              <th>Role</th>
              <th>Saved Requests</th>
              <th>Registered</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id" class="user-row" :class="{ trashed: user.deleted_at }" @click="openUser(user)">
              <td class="id-col">{{ user.id }}</td>
              <td><span class="user-name-link">{{ user.name }}</span></td>
              <td class="email-col">{{ user.email }}</td>
              <td class="org-col">{{ user.organisation?.name || '—' }}</td>
              <td>
                <span class="role-badge" :class="user.is_admin ? 'admin' : 'user'">
                  {{ user.is_admin ? 'Admin' : 'User' }}
                </span>
                <span v-if="!user.email_verified" class="role-badge unverified" title="Email not verified">Unverified</span>
              </td>
              <td class="count-col">{{ user.saved_requests_count }}</td>
              <td class="date-col">{{ formatDate(user.created_at) }}</td>
              <td class="actions-col">
                <template v-if="user.deleted_at">
                  <button class="action-btn toggle-btn" @click.stop="restoreUser(user)">Restore</button>
                  <button class="action-btn delete-btn" @click.stop="hardDeleteUser(user)" :disabled="isCurrentUser(user)">
                    Delete forever
                  </button>
                </template>
                <template v-else>
                <button 
                  class="action-btn toggle-btn" 
                  @click.stop="toggleAdmin(user)"
                  :title="user.is_admin ? 'Remove admin' : 'Make admin'"
                  :disabled="isCurrentUser(user)"
                >
                  {{ user.is_admin ? 'Demote' : 'Promote' }}
                </button>
                <button 
                  class="action-btn delete-btn" 
                  @click.stop="deleteUser(user)"
                  :disabled="isCurrentUser(user)"
                  title="Delete user"
                >
                  Deactivate
                </button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="users.length === 0 && !isLoading" class="empty-state">No users found.</div>
      <div v-if="isLoading" class="empty-state">Loading users...</div>

      <div v-if="lastPage > 1" class="pager">
        <button class="action-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹ Prev</button>
        <span class="pager-info">Page {{ page }} of {{ lastPage }} ({{ totalUsers }} users)</span>
        <button class="action-btn" :disabled="page >= lastPage" @click="goToPage(page + 1)">Next ›</button>
      </div>
    </div>

    <div class="users-section audit-section">
      <h3>Audit Log</h3>
      <div class="table-container">
        <table class="users-table">
          <thead>
            <tr>
              <th>When</th>
              <th>Admin</th>
              <th>Action</th>
              <th>Target</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in auditEntries" :key="entry.id">
              <td class="date-col">{{ formatDateTime(entry.created_at) }}</td>
              <td>{{ entry.admin?.name || 'Unknown' }}</td>
              <td>
                <span class="role-badge" :class="actionClass(entry.action)">{{ actionLabel(entry.action) }}</span>
              </td>
              <td class="email-col">{{ entry.target_email || '—' }}</td>
              <td class="date-col">{{ detailsSummary(entry) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="auditEntries.length === 0" class="empty-state">No admin actions recorded yet.</div>
    </div>

    <div v-if="message" class="toast" :class="messageType">{{ message }}</div>
  </div>
    <div v-if="creating" class="au-scrim" @click.self="creating = null">
      <div class="au-modal">
        <header class="au-modal-head">
          <h2>New user</h2>
          <button class="au-x" @click="creating = null" aria-label="Close">✕</button>
        </header>
        <div class="au-form">
          <label class="au-label">Name</label>
          <input v-model="creating.name" class="search-input au-input" maxlength="255" />

          <label class="au-label">Email</label>
          <input v-model="creating.email" class="search-input au-input" type="email" autocomplete="off" />

          <label class="au-label">Password</label>
          <input v-model="creating.password" class="search-input au-input" type="password" autocomplete="new-password" />
          <p class="au-hint">At least 12 characters. The account is created verified, so they can sign in straight away.</p>

          <label class="au-label">Organisation</label>
          <select v-model="creating.organisation_id" class="search-input au-input">
            <option :value="null">Not assigned</option>
            <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>

          <label class="au-check">
            <input type="checkbox" v-model="creating.is_admin" />
            <span>Grant admin access</span>
          </label>

          <p v-if="createError" class="au-error">{{ createError }}</p>

          <footer class="au-actions">
            <button class="action-btn create-btn" @click="createUser" :disabled="savingUser">
              {{ savingUser ? 'Creating…' : 'Create user' }}
            </button>
            <button class="action-btn" @click="creating = null" :disabled="savingUser">Cancel</button>
          </footer>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../store/auth';

const router = useRouter();
const authStore = useAuthStore();

const users = ref([]);
const stats = ref(null);
const isLoading = ref(true);
const message = ref('');
const messageType = ref('success');
const search = ref('');
const filter = ref('active');
const creating = ref(null);
const savingUser = ref(false);
const createError = ref('');
const organisations = ref([]);
const page = ref(1);
const lastPage = ref(1);
const totalUsers = ref(0);
const auditEntries = ref([]);
const connectors = ref([]);
let searchDebounce = null;

const isCurrentUser = (user) => {
  return authStore.user && user.id === authStore.user.id;
};

const openUser = (user) => {
  router.push(`/admin/users/${user.id}`);
};

const formatDate = (dateStr) => {
  return new Date(dateStr).toLocaleDateString('en-AU', {
    year: 'numeric', month: 'short', day: 'numeric'
  });
};

const formatDateTime = (dateStr) => {
  return new Date(dateStr).toLocaleString('en-AU', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
  });
};

const actionLabel = (action) => ({
  promote_admin: 'Promoted',
  demote_admin: 'Demoted',
  delete_user: 'Deleted'
}[action] || action);

const actionClass = (action) => action === 'delete_user' ? 'destructive' : 'admin';

const protocolRows = computed(() => {
  const b = stats.value?.protocol_breakdown;
  if (!b) return [];
  const max = Math.max(b.rest, b.mcp, b.a2a, 1);
  return [
    { key: 'rest', label: 'REST', count: b.rest },
    { key: 'mcp', label: 'MCP', count: b.mcp },
    { key: 'a2a', label: 'A2A', count: b.a2a },
  ].map(p => ({ ...p, pct: Math.round((p.count / max) * 100) }));
});

const detailsSummary = (entry) => {
  if (!entry.details) return '—';
  const parts = [];
  if (entry.details.name) parts.push(entry.details.name);
  if (entry.details.saved_requests_deleted != null) {
    parts.push(`${entry.details.saved_requests_deleted} saved request(s) removed`);
  }
  return parts.join(' · ') || '—';
};

const showMessage = (msg, type = 'success') => {
  message.value = msg;
  messageType.value = type;
  setTimeout(() => { message.value = ''; }, 3000);
};

const fetchData = async () => {
  isLoading.value = true;
  try {
    const [usersRes, statsRes, actionsRes, connectorsRes] = await Promise.all([
      axios.get('/api/admin/users', { params: { page: page.value, search: search.value || undefined, filter: filter.value } }),
      axios.get('/api/admin/stats'),
      axios.get('/api/admin/actions'),
      axios.get('/api/admin/catalog', { params: { type: 'connector' } })
    ]);
    users.value = usersRes.data.data;
    lastPage.value = usersRes.data.last_page;
    totalUsers.value = usersRes.data.total;
    stats.value = statsRes.data;
    auditEntries.value = actionsRes.data.data;
    connectors.value = connectorsRes.data;
  } catch (error) {
    showMessage('Failed to load admin data.', 'error');
  } finally {
    isLoading.value = false;
  }
};

const goToPage = (p) => {
  page.value = p;
  fetchData();
};

const onSearchInput = () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    page.value = 1;
    fetchData();
  }, 300);
};

const toggleAdmin = async (user) => {
  if (isCurrentUser(user)) return;
  try {
    const res = await axios.post(`/api/admin/users/${user.id}/toggle-admin`);
    showMessage(res.data.message);
    await fetchData();
  } catch (error) {
    showMessage(error.response?.data?.message || 'Failed to update user.', 'error');
  }
};

// Soft delete: reversible, and the account's data is untouched.
const deleteUser = async (user) => {
  if (isCurrentUser(user)) return;
  if (!confirm(`Deactivate "${user.name}" (${user.email})?\n\nThey will not be able to sign in. Their data is kept and you can restore them later.`)) return;
  try {
    const res = await axios.delete(`/api/admin/users/${user.id}`);
    showMessage(res.data.message);
    await fetchData();
  } catch (error) {
    showMessage(error.response?.data?.message || 'Failed to deactivate user.', 'error');
  }
};

const restoreUser = async (user) => {
  try {
    await axios.post(`/api/admin/users/${user.id}/restore`);
    showMessage('User restored.');
    await fetchData();
  } catch (error) {
    showMessage(error.response?.data?.message || 'Failed to restore user.', 'error');
  }
};

// Hard delete: irreversible, and takes everything the user owns with it.
// Typing the email is deliberate friction for an action with no undo.
const hardDeleteUser = async (user) => {
  if (isCurrentUser(user)) return;
  const typed = prompt(
    `PERMANENTLY delete "${user.name}" and everything they own — saved requests, collections, environments, monitors, alert channels and reports.\n\nThis cannot be undone.\n\nType their email to confirm:`
  );
  if (typed === null) return;
  if (typed.trim().toLowerCase() !== user.email.toLowerCase()) {
    showMessage('That did not match the email. Nothing was deleted.', 'error');
    return;
  }
  try {
    const res = await axios.delete(`/api/admin/users/${user.id}/force`);
    const counts = Object.entries(res.data.deleted || {})
      .filter(([, n]) => n > 0)
      .map(([k, n]) => `${n} ${k.replace(/_/g, ' ')}`)
      .join(', ');
    showMessage(counts ? `User deleted, along with ${counts}.` : 'User permanently deleted.');
    await fetchData();
  } catch (error) {
    showMessage(error.response?.data?.message || 'Failed to delete user.', 'error');
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
    showMessage('User created.');
    await fetchData();
  } catch (e) {
    const data = e.response?.data;
    createError.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to create user.';
  } finally {
    savingUser.value = false;
  }
};

onMounted(fetchData);
</script>

<style scoped>
.user-row { cursor: pointer; }
.user-row.trashed { opacity: .55; }
.user-row.trashed .user-name-link { color: var(--text-secondary); text-decoration: line-through; }
.filter-select { max-width: 150px; }
.create-btn { border-color: var(--accent-color); color: var(--accent-color); }

.au-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: var(--z-modal, 100); }
.au-modal { width: min(520px, 100%); background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.au-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border-color); }
.au-modal-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.au-x { background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 15px; }
.au-form { padding: 18px 20px; }
.au-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
.au-label:first-child { margin-top: 0; }
.au-input { width: 100%; }
.au-hint { font-size: 12px; color: var(--text-secondary); margin: 6px 0 0; }
.au-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-top: 14px; cursor: pointer; }
.au-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.au-actions { display: flex; gap: 8px; margin-top: 18px; }
.user-row:hover { background: var(--panel-bg); }
.user-name-link { color: var(--accent-color); font-weight: 600; }
.org-col { color: var(--text-secondary); font-size: 13px; }

.admin-container {
  padding: 32px;
  max-width: 1200px;
  margin: 0 auto;
  height: 100%;
  overflow-y: auto;
}

.admin-header {
  margin-bottom: 32px;
}

.header-left h2 {
  margin: 0 0 8px;
  color: var(--text-primary);
  font-size: 24px;
}

.back-link {
  color: var(--accent-color);
  text-decoration: none;
  font-size: 14px;
}
.back-link:hover {
  text-decoration: underline;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 32px;
}

.stat-card {
  background-color: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 24px;
  text-align: center;
}
.stat-value {
  font-size: 36px;
  font-weight: 700;
  color: var(--accent-color);
  line-height: 1;
}
.stat-label {
  margin-top: 8px;
  font-size: 14px;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.usage-grid {
  grid-template-columns: 1fr 1fr 2fr;
}

.protocol-card {
  text-align: left;
}
.protocol-bars {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.protocol-bar-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.protocol-name {
  font-size: 11px;
  font-weight: 700;
  width: 38px;
  flex-shrink: 0;
}
.protocol-name.rest { color: #58a6ff; }
.protocol-name.mcp { color: #a371f7; }
.protocol-name.a2a { color: #f85149; }
.protocol-track {
  flex: 1;
  height: 8px;
  background: var(--bg-color);
  border-radius: 4px;
  overflow: hidden;
}
.protocol-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s;
}
.protocol-fill.rest { background: #58a6ff; }
.protocol-fill.mcp { background: #a371f7; }
.protocol-fill.a2a { background: #f85149; }
.protocol-count {
  font-size: 12px;
  color: var(--text-secondary);
  width: 40px;
  text-align: right;
  flex-shrink: 0;
}

.users-section h3 {
  margin: 0 0 16px;
  color: var(--text-primary);
  font-size: 16px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.table-container {
  border: 1px solid var(--border-color);
  border-radius: 8px;
  overflow: hidden;
}

.users-table {
  width: 100%;
  border-collapse: collapse;
  background-color: var(--panel-bg);
}
.users-table th {
  background-color: var(--bg-color);
  text-align: left;
  padding: 12px 16px;
  font-size: 12px;
  text-transform: uppercase;
  color: var(--text-secondary);
  letter-spacing: 0.5px;
  border-bottom: 1px solid var(--border-color);
}
.users-table td {
  padding: 12px 16px;
  font-size: 14px;
  color: var(--text-primary);
  border-bottom: 1px solid var(--border-color);
}
.users-table tr:last-child td {
  border-bottom: none;
}
.users-table tr:hover td {
  background-color: rgba(88, 166, 255, 0.04);
}

.id-col { width: 60px; color: var(--text-secondary); }
.email-col { color: var(--text-secondary); }
.count-col { text-align: center; }
.date-col { color: var(--text-secondary); font-size: 13px; }
.actions-col { white-space: nowrap; }

.role-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 12px;
  display: inline-block;
}
.role-badge.admin {
  color: #d29922;
  background: rgba(210, 153, 34, 0.15);
}
.role-badge.user {
  color: #8b949e;
  background: rgba(139, 148, 158, 0.15);
}
.role-badge.unverified {
  color: #d29922;
  background: rgba(210, 153, 34, 0.15);
  margin-left: 6px;
}
.role-badge.destructive {
  color: #f85149;
  background: rgba(248, 81, 73, 0.15);
}
.role-badge.health-ok { color: #3fb950; background: rgba(63, 185, 80, 0.15); }
.role-badge.health-bad { color: #f85149; background: rgba(248, 81, 73, 0.15); }
.conn-endpoint { font-family: monospace; font-size: 12px; }

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  gap: 16px;
  flex-wrap: wrap;
}
.section-header h3 {
  margin: 0;
}

.search-input {
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 14px;
  color: var(--text-primary);
  width: 280px;
}
.search-input:focus {
  outline: none;
  border-color: var(--accent-color);
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-top: 16px;
}
.pager-info {
  font-size: 13px;
  color: var(--text-secondary);
}

.audit-section {
  margin-top: 40px;
}

.action-btn {
  padding: 4px 12px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid var(--border-color);
  background: none;
  color: var(--text-primary);
  margin-right: 6px;
  transition: all 0.2s;
}
.action-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}
.toggle-btn:hover:not(:disabled) {
  border-color: var(--accent-color);
  color: var(--accent-color);
}
.delete-btn:hover:not(:disabled) {
  border-color: #f85149;
  color: #f85149;
  background: rgba(248, 81, 73, 0.1);
}

.empty-state {
  padding: 32px;
  text-align: center;
  color: var(--text-secondary);
  font-size: 14px;
}

.toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  padding: 12px 24px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  z-index: 999;
  animation: slideIn 0.3s ease;
}
.toast.success {
  background: rgba(63, 185, 80, 0.15);
  color: #3fb950;
  border: 1px solid rgba(63, 185, 80, 0.3);
}
.toast.error {
  background: rgba(248, 81, 73, 0.15);
  color: #f85149;
  border: 1px solid rgba(248, 81, 73, 0.3);
}

@keyframes slideIn {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

@media (max-width: 768px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }
  .admin-container {
    padding: 16px;
  }
}
</style>
