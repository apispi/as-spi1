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
        <div class="stat-value">{{ stats.admin_users }}</div>
        <div class="stat-label">Admin Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ stats.total_saved_requests }}</div>
        <div class="stat-label">Saved Requests</div>
      </div>
    </div>

    <div class="users-section">
      <h3>All Users</h3>
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
    </div>

    <div v-if="message" class="toast" :class="messageType">{{ message }}</div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { useAuthStore } from '../store/auth';

const authStore = useAuthStore();

const users = ref([]);
const stats = ref(null);
const isLoading = ref(true);
const message = ref('');
const messageType = ref('success');

const isCurrentUser = (user) => {
  return authStore.user && user.id === authStore.user.id;
};

const formatDate = (dateStr) => {
  return new Date(dateStr).toLocaleDateString('en-AU', {
    year: 'numeric', month: 'short', day: 'numeric'
  });
};

const showMessage = (msg, type = 'success') => {
  message.value = msg;
  messageType.value = type;
  setTimeout(() => { message.value = ''; }, 3000);
};

const fetchData = async () => {
  isLoading.value = true;
  try {
    const [usersRes, statsRes] = await Promise.all([
      axios.get('/api/admin/users'),
      axios.get('/api/admin/stats')
    ]);
    users.value = usersRes.data;
    stats.value = statsRes.data;
  } catch (error) {
    showMessage('Failed to load admin data.', 'error');
  } finally {
    isLoading.value = false;
  }
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
  if (!confirm(`Are you sure you want to delete "${user.name}" (${user.email})? This cannot be undone.`)) return;
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
  height: calc(100vh - 60px);
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
  grid-template-columns: repeat(3, 1fr);
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
