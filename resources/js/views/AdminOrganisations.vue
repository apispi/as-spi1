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
        <button class="ad-btn" @click="edit(o)">Edit</button>
      </li>
    </ul>

    <p v-if="unassigned" class="ad-note">
      {{ unassigned }} user{{ unassigned === 1 ? ' is' : 's are' }} not in any organisation.
    </p>

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
</style>
