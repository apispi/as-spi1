<template>
  <div class="env-scrim" @click.self="close">
    <div class="env-modal" role="dialog" aria-modal="true" aria-label="Manage environments">
      <header class="env-head">
        <h2>Environments</h2>
        <button class="env-x" @click="close" aria-label="Close"><Icon name="close" :size="18" /></button>
      </header>

      <div class="env-body">
        <!-- Environment list -->
        <aside class="env-list">
          <button
            v-for="env in store.environments"
            :key="env.id"
            :class="['env-item', editing && editing.id === env.id ? 'active' : '']"
            @click="edit(env)"
          >
            <span class="env-item-name">{{ env.name }}</span>
            <span v-if="ownerName(env)" class="env-owner">{{ ownerName(env) }}</span>
            <span v-if="env.is_default" class="env-badge">default</span>
          </button>
          <p v-if="!store.environments.length" class="env-none">No environments yet.</p>
          <button class="env-new" @click="startNew">+ New environment</button>
        </aside>

        <!-- Editor -->
        <section class="env-editor" v-if="editing">
          <div class="env-row">
            <label class="env-label">Name</label>
            <input v-model="editing.name" class="input-field" placeholder="Staging" maxlength="60" />
          </div>

          <label class="env-check">
            <input type="checkbox" v-model="editing.is_default" />
            <span>Use by default when a request has variables and none is picked</span>
          </label>

          <div class="env-vars">
            <div class="env-vars-head">
              <span>Variable</span>
              <span>Value</span>
              <span title="Secret values are masked in history and reports, and never sent back to the browser">Secret</span>
              <span></span>
            </div>

            <div v-for="(row, i) in editing.variables" :key="i" class="env-var">
              <input
                v-model="row.key"
                class="input-field mono"
                placeholder="base_url"
                @input="row.key = row.key.replace(/[^A-Za-z0-9_.-]/g, '')"
              />
              <input
                v-model="row.value"
                class="input-field mono"
                :type="row.secret && !row.reveal ? 'password' : 'text'"
                :placeholder="row.secret && row.has_value && !row.value ? 'unchanged' : 'https://api.staging.example.com'"
                autocomplete="off"
              />
              <label class="env-secret">
                <input type="checkbox" v-model="row.secret" />
              </label>
              <button class="env-del" @click="editing.variables.splice(i, 1)" aria-label="Remove variable">
                <Icon name="close" :size="14" />
              </button>
            </div>

            <button class="env-add" @click="addRow">+ Add variable</button>
          </div>

          <p class="env-hint">
            Reference these anywhere in a request — URL, headers, body, topics —
            as <code v-pre>{{name}}</code>. They are substituted server-side
            when the request is sent.
          </p>

          <p v-if="error" class="env-error">{{ error }}</p>

          <footer class="env-actions">
            <button class="primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="danger" @click="remove" :disabled="saving">Delete</button>
            <button class="secondary" @click="editing = null" :disabled="saving">Cancel</button>
          </footer>
        </section>

        <section v-else class="env-empty">
          <p>Pick an environment to edit, or create one.</p>
          <p class="env-hint">
            Environments hold reusable values like <code>base_url</code> or
            <code>token</code>, so one saved request can run against staging
            and production.
          </p>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useEnvironmentsStore } from '../store/environments';
import { useAuthStore } from '../store/auth';
import Icon from './Icon.vue';

const emit = defineEmits(['close']);
const store = useEnvironmentsStore();
const authStore = useAuthStore();
const ownerName = (env) => (env.owner && env.owner.id !== authStore.user?.id ? env.owner.name : '');

const editing = ref(null);
const saving = ref(false);
const error = ref('');

const close = () => emit('close');

const startNew = () => {
  error.value = '';
  editing.value = { id: null, name: '', is_default: !store.environments.length, variables: [{ key: '', value: '', secret: false }] };
};

const edit = (env) => {
  error.value = '';
  // Clone so cancelling leaves the store untouched.
  editing.value = {
    id: env.id,
    name: env.name,
    is_default: env.is_default,
    variables: env.variables.map((v) => ({ ...v, reveal: false })),
  };
};

const addRow = () => editing.value.variables.push({ key: '', value: '', secret: false });

const save = async () => {
  saving.value = true;
  error.value = '';
  const payload = {
    name: editing.value.name.trim(),
    is_default: editing.value.is_default,
    variables: editing.value.variables
      .filter((v) => v.key.trim())
      .map((v) => ({ key: v.key.trim(), value: v.value, secret: !!v.secret })),
  };

  try {
    if (editing.value.id) {
      await store.update(editing.value.id, payload);
    } else {
      await store.create(payload);
    }
    editing.value = null;
  } catch (e) {
    const data = e.response?.data;
    error.value = data?.message || Object.values(data?.errors || {})[0]?.[0] || 'Failed to save environment.';
  } finally {
    saving.value = false;
  }
};

const remove = async () => {
  if (!confirm(`Delete the "${editing.value.name}" environment?`)) return;
  saving.value = true;
  try {
    await store.remove(editing.value.id);
    editing.value = null;
  } catch {
    error.value = 'Failed to delete environment.';
  } finally {
    saving.value = false;
  }
};

const onKey = (e) => {
  if (e.key === 'Escape') close();
};

onMounted(() => {
  window.addEventListener('keydown', onKey);
  if (!store.loaded) store.fetch();
});
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.env-scrim {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6);
  display: flex; align-items: center; justify-content: center; padding: 24px;
  z-index: var(--z-modal, 100);
}
.env-modal {
  width: min(860px, 100%); max-height: 82vh; display: flex; flex-direction: column;
  background: var(--bg-secondary, var(--panel-bg)); border: 1px solid var(--border-color);
  border-radius: 14px; overflow: hidden;
}
.env-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border-color); }
.env-head h2 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary); }
.env-x { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.env-x:hover { color: var(--text-primary); }

.env-body { display: grid; grid-template-columns: 220px 1fr; min-height: 0; flex: 1; }
.env-list { border-right: 1px solid var(--border-color); overflow-y: auto; padding: 10px; display: flex; flex-direction: column; gap: 4px; }
.env-item {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 9px 11px; border-radius: 8px; border: 1px solid transparent;
  background: none; color: var(--text-primary); font-size: 13px; cursor: pointer; text-align: left;
}
.env-item:hover { background: var(--bg-color); }
.env-item.active { background: var(--accent-soft, rgba(88,166,255,.12)); border-color: var(--accent-color); color: var(--accent-color); font-weight: 600; }
.env-item-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.env-owner { font-size: 10.5px; color: var(--text-secondary); }
.env-badge { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: rgba(63,185,80,.16); color: #3fb950; }
.env-none { color: var(--text-secondary); font-size: 13px; padding: 8px 11px; margin: 0; }
.env-new { margin-top: 4px; padding: 9px; border: 1px dashed var(--border-color); border-radius: 8px; background: none; color: var(--text-secondary); font-size: 13px; cursor: pointer; }
.env-new:hover { border-color: var(--accent-color); color: var(--accent-color); }

.env-editor { padding: 18px 20px; overflow-y: auto; }
.env-empty { padding: 32px 24px; color: var(--text-secondary); }
.env-empty p { margin: 0 0 10px; font-size: 14px; }

.env-row { margin-bottom: 14px; }
.env-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .04em; }
.env-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); margin-bottom: 18px; cursor: pointer; }

.env-vars-head, .env-var { display: grid; grid-template-columns: 1fr 1.4fr 56px 32px; gap: 8px; align-items: center; }
.env-vars-head { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); margin-bottom: 8px; }
.env-vars-head span:nth-child(3) { text-align: center; }
.env-var { margin-bottom: 8px; }
.env-secret { display: flex; justify-content: center; }
.mono { font-family: 'Courier New', monospace; font-size: 13px; }
.env-del { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; }
.env-del:hover { color: #f85149; }
.env-add { margin-top: 4px; padding: 7px 12px; border: 1px dashed var(--border-color); border-radius: 8px; background: none; color: var(--text-secondary); font-size: 13px; cursor: pointer; }
.env-add:hover { border-color: var(--accent-color); color: var(--accent-color); }

.env-hint { font-size: 12.5px; line-height: 1.6; color: var(--text-secondary); margin: 16px 0 0; }
.env-hint code { font-family: 'Courier New', monospace; background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; }
.env-error { color: #f85149; font-size: 13px; margin: 12px 0 0; }
.env-actions { display: flex; gap: 8px; margin-top: 20px; }
.env-actions .danger { margin-left: auto; background: none; border: 1px solid var(--border-color); color: #f85149; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; }
.env-actions .danger:hover { border-color: #f85149; }

@media (max-width: 720px) {
  .env-body { grid-template-columns: 1fr; }
  .env-list { border-right: none; border-bottom: 1px solid var(--border-color); flex-direction: row; flex-wrap: wrap; }
  .env-vars-head, .env-var { grid-template-columns: 1fr 1fr 46px 28px; }
}
</style>
