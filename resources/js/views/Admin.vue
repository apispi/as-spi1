<template>
  <div class="admin-container">
    <div class="admin-header">
      <div class="header-left">
        <h2>Admin Dashboard</h2>
        <router-link to="/" class="back-link">← Back to Tester</router-link>
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

    <div class="users-section">
      <div class="section-header">
        <h3>All Users</h3>
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
              <th>Role</th>
              <th>Saved Requests</th>
              <th>Registered</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td class="id-col">{{ user.id }}</td>
              <td>{{ user.name }}</td>
              <td class="email-col">{{ user.email }}</td>
              <td>
                <span class="role-badge" :class="user.is_admin ? 'admin' : 'user'">
                  {{ user.is_admin ? 'Admin' : 'User' }}
                </span>
                <span v-if="!user.email_verified" class="role-badge unverified" title="Email not verified">Unverified</span>
              </td>
              <td class="count-col">{{ user.saved_requests_count }}</td>
              <td class="date-col">{{ formatDate(user.created_at) }}</td>
              <td class="actions-col">
                <button 
                  class="action-btn toggle-btn" 
                  @click="toggleAdmin(user)"
                  :title="user.is_admin ? 'Remove admin' : 'Make admin'"
                  :disabled="isCurrentUser(user)"
                >
                  {{ user.is_admin ? 'Demote' : 'Promote' }}
                </button>
                <button 
                  class="action-btn delete-btn" 
                  @click="deleteUser(user)"
                  :disabled="isCurrentUser(user)"
                  title="Delete user"
                >
                  Delete
                </button>
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
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../store/auth';

const authStore = useAuthStore();

const users = ref([]);
const stats = ref(null);
const isLoading = ref(true);
const message = ref('');
const messageType = ref('success');
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const totalUsers = ref(0);
const auditEntries = ref([]);
let searchDebounce = null;

const isCurrentUser = (user) => {
  return authStore.user && user.id === authStore.user.id;
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
    const [usersRes, statsRes, actionsRes] = await Promise.all([
      axios.get('/api/admin/users', { params: { page: page.value, search: search.value || undefined } }),
      axios.get('/api/admin/stats'),
      axios.get('/api/admin/actions')
    ]);
    users.value = usersRes.data.data;
    lastPage.value = usersRes.data.last_page;
    totalUsers.value = usersRes.data.total;
    stats.value = statsRes.data;
    auditEntries.value = actionsRes.data.data;
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

const deleteUser = async (user) => {
  if (isCurrentUser(user)) return;
  const extra = user.saved_requests_count > 0 ? ` Their ${user.saved_requests_count} saved request(s) will also be deleted.` : '';
  if (!confirm(`Are you sure you want to delete "${user.name}" (${user.email})?${extra} This cannot be undone.`)) return;
  try {
    await axios.delete(`/api/admin/users/${user.id}`);
    showMessage('User deleted successfully.');
    await fetchData();
  } catch (error) {
    showMessage(error.response?.data?.message || 'Failed to delete user.', 'error');
  }
};

onMounted(fetchData);
</script>

<style scoped>
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
