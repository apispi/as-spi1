<template>
  <div class="profile-page">
    <div class="profile-container">
      <div class="profile-header">
        <div class="avatar-large">
          {{ userInitials }}
        </div>
        <div class="profile-info">
          <h1>{{ authStore.user?.name || 'User' }}</h1>
          <p class="email">{{ authStore.user?.email }}</p>
          <span class="member-since">Member since {{ formatDate(authStore.user?.created_at) }}</span>
        </div>
      </div>

      <div class="profile-sections">
        <!-- Account Settings -->
        <div class="profile-section">
          <h2>Account Settings</h2>
          <div class="settings-card">
            <div class="setting-row">
              <div class="setting-info">
                <label>Full Name</label>
                <input v-model="form.name" type="text" class="setting-input" />
              </div>
              <button @click="updateProfile" class="btn btn-primary btn-sm" :disabled="saving">
                {{ saving ? 'Saving...' : 'Update' }}
              </button>
            </div>
            <div class="setting-row">
              <div class="setting-info">
                <label>Email Address</label>
                <input v-model="form.email" type="email" class="setting-input" disabled />
                <span class="setting-hint">Email cannot be changed</span>
              </div>
            </div>
          </div>
        </div>

        <!-- API Keys -->
        <div class="profile-section">
          <h2>API Keys</h2>
          <div class="settings-card">
            <div class="api-key-row">
              <div class="api-key-info">
                <label>Your API Key</label>
                <div class="api-key-display">
                  <code>{{ apiKey || 'Generating...' }}</code>
                  <button @click="copyApiKey" class="btn btn-icon" :title="copied ? 'Copied!' : 'Copy'">
                    <svg v-if="!copied" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
            <div class="setting-row">
              <button @click="regenerateApiKey" class="btn btn-secondary btn-sm">
                Regenerate Key
              </button>
              <span class="setting-hint">Regenerating will invalidate your old key</span>
            </div>
          </div>
        </div>

        <!-- Stats -->
        <div class="profile-section">
          <h2>Usage Statistics</h2>
          <div class="stats-grid">
            <div class="stat-card">
              <div class="stat-value">{{ stats.requests || 0 }}</div>
              <div class="stat-label">API Requests</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">{{ stats.saved || 0 }}</div>
              <div class="stat-label">Saved Requests</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">{{ formatBytes(stats.bandwidth || 0) }}</div>
              <div class="stat-label">Data Transferred</div>
            </div>
          </div>
        </div>

        <!-- Danger Zone -->
        <div class="profile-section danger-zone">
          <h2>Danger Zone</h2>
          <div class="settings-card">
            <div class="setting-row">
              <div class="setting-info">
                <label>Delete Account</label>
                <span class="setting-hint">Permanently delete your account and all data</span>
              </div>
              <button @click="confirmDelete" class="btn btn-danger btn-sm">
                Delete Account
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="modal-overlay" @click.self="showDeleteModal = false">
      <div class="modal-content">
        <h3>Delete Account</h3>
        <p>Are you sure you want to delete your account? This action cannot be undone.</p>
        <div class="modal-actions">
          <button @click="showDeleteModal = false" class="btn btn-secondary">Cancel</button>
          <button @click="deleteAccount" class="btn btn-danger" :disabled="deleting">
            {{ deleting ? 'Deleting...' : 'Delete Forever' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '../store/auth';
import axios from 'axios';

const authStore = useAuthStore();

const form = ref({
  name: '',
  email: ''
});

const saving = ref(false);
const apiKey = ref('');
const copied = ref(false);
const stats = ref({});
const showDeleteModal = ref(false);
const deleting = ref(false);

const userInitials = computed(() => {
  const name = authStore.user?.name || 'U';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
});

onMounted(() => {
  form.value.name = authStore.user?.name || '';
  form.value.email = authStore.user?.email || '';
  loadApiKey();
  loadStats();
});

const loadApiKey = async () => {
  try {
    const res = await axios.get('/api/user/api-key');
    apiKey.value = res.data.api_key || 'No key generated';
  } catch (error) {
    apiKey.value = 'Error loading key';
  }
};

const loadStats = async () => {
  try {
    const res = await axios.get('/api/user/stats');
    stats.value = res.data;
  } catch (error) {
    stats.value = { requests: 0, saved: 0, bandwidth: 0 };
  }
};

const updateProfile = async () => {
  saving.value = true;
  try {
    const res = await axios.put('/api/user/profile', {
      name: form.value.name
    });
    authStore.user.name = res.data.name;
  } catch (error) {
    console.error('Failed to update profile');
  } finally {
    saving.value = false;
  }
};

const copyApiKey = async () => {
  try {
    await navigator.clipboard.writeText(apiKey.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch (error) {
    console.error('Failed to copy');
  }
};

const regenerateApiKey = async () => {
  if (!confirm('Are you sure? Your old API key will stop working.')) return;
  try {
    const res = await axios.post('/api/user/api-key/regenerate');
    apiKey.value = res.data.api_key;
  } catch (error) {
    console.error('Failed to regenerate key');
  }
};

const formatDate = (date) => {
  if (!date) return 'Unknown';
  return new Date(date).toLocaleDateString('en-US', { 
    month: 'long', 
    year: 'numeric' 
  });
};

const formatBytes = (bytes) => {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const confirmDelete = () => {
  showDeleteModal.value = true;
};

const deleteAccount = async () => {
  deleting.value = true;
  try {
    await axios.delete('/api/user/account');
    await authStore.logout();
    window.location.href = '/';
  } catch (error) {
    console.error('Failed to delete account');
    deleting.value = false;
  }
};
</script>

<style scoped>
.profile-page {
  padding: 32px 24px;
  max-width: 800px;
  margin: 0 auto;
}

.profile-container {
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  overflow: hidden;
}

.profile-header {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 32px;
  background: linear-gradient(180deg, rgba(88, 166, 255, 0.08) 0%, var(--panel-bg) 100%);
  border-bottom: 1px solid var(--border-color);
}

.avatar-large {
  width: 80px;
  height: 80px;
  background: var(--accent-color);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 28px;
  font-weight: 600;
  flex-shrink: 0;
}

.profile-info h1 {
  font-size: 24px;
  font-weight: 600;
  margin: 0 0 4px 0;
}

.email {
  color: var(--text-secondary);
  margin: 0 0 8px 0;
}

.member-since {
  font-size: 13px;
  color: var(--text-secondary);
}

.profile-sections {
  padding: 24px 32px;
}

.profile-section {
  margin-bottom: 32px;
}

.profile-section:last-child {
  margin-bottom: 0;
}

.profile-section h2 {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 16px 0;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.settings-card {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 20px;
}

.setting-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 0;
}

.setting-row:first-child {
  padding-top: 0;
}

.setting-row:not(:last-child) {
  border-bottom: 1px solid var(--border-color);
}

.setting-info {
  flex: 1;
}

.setting-info label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  margin-bottom: 8px;
}

.setting-input {
  width: 100%;
  padding: 10px 12px;
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  font-size: 14px;
  color: var(--text-primary);
  transition: all 0.2s;
}

.setting-input:focus {
  outline: none;
  border-color: var(--accent-color);
  border-width: 2px;
}

.setting-input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.setting-hint {
  display: block;
  font-size: 12px;
  color: var(--text-secondary);
  margin-top: 6px;
}

.api-key-row {
  padding-bottom: 16px;
  margin-bottom: 8px;
  border-bottom: 1px solid var(--border-color);
}

.api-key-display {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 10px 12px;
}

.api-key-display code {
  flex: 1;
  font-family: 'Courier New', monospace;
  font-size: 13px;
  color: var(--accent-color);
  word-break: break-all;
}

.btn-icon {
  background: none;
  border: none;
  padding: 4px;
  cursor: pointer;
  color: var(--text-secondary);
  transition: color 0.2s;
}

.btn-icon:hover {
  color: var(--accent-color);
}

/* Stats Grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

.stat-card {
  background: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 20px;
  text-align: center;
}

.stat-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--accent-color);
  margin-bottom: 4px;
}

.stat-label {
  font-size: 13px;
  color: var(--text-secondary);
}

/* Danger Zone */
.danger-zone h2 {
  color: #f85149;
}

.danger-zone .settings-card {
  border-color: rgba(248, 81, 73, 0.3);
}

/* Buttons */
.btn {
  display: inline-block;
  padding: 10px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  border: none;
}

.btn-sm {
  padding: 8px 12px;
  font-size: 13px;
}

.btn-primary {
  background: var(--accent-color);
  color: #fff;
}

.btn-primary:hover:not(:disabled) {
  background: #4a9eff;
}

.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-secondary {
  background: var(--panel-bg);
  color: var(--text-primary);
  border: 1px solid var(--border-color);
}

.btn-secondary:hover {
  background: var(--border-color);
}

.btn-danger {
  background: #f85149;
  color: #fff;
}

.btn-danger:hover:not(:disabled) {
  background: #da3633;
}

.btn-danger:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: var(--panel-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 24px;
  max-width: 400px;
  width: 90%;
}

.modal-content h3 {
  font-size: 18px;
  font-weight: 600;
  margin: 0 0 12px 0;
}

.modal-content p {
  color: var(--text-secondary);
  margin: 0 0 24px 0;
  line-height: 1.5;
}

.modal-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}

/* Responsive */
@media (max-width: 640px) {
  .profile-header {
    flex-direction: column;
    text-align: center;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
  }
  
  .setting-row {
    flex-direction: column;
  }
}
</style>