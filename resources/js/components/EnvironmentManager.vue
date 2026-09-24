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
          <button class="env-new" @click="triggerImport">↥ Import from file</button>
          <input ref="importInput" type="file" accept="application/json,.json" class="env-file" @change="onImportFile" />
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

          <details class="env-dyn" v-if="dynamicVars.length">
            <summary>Dynamic variables — fresh value each send</summary>
            <p class="env-dyn-lead">
              Computed values you can drop into any request without defining them.
              Click one to copy. An environment variable of the same name overrides it.
            </p>
            <ul class="env-dyn-list">
              <li v-for="v in dynamicVars" :key="v.token" @click="copyToken(v.token)" :title="'Copy ' + placeholderFor(v.token)">
                <code>{{ placeholderFor(v.token) }}</code>
                <span class="env-dyn-desc">{{ v.description }}</span>
                <span class="env-dyn-eg">e.g. {{ v.example }}</span>
              </li>
            </ul>
          </details>

          <details class="env-dyn" v-if="editing.auth">
            <summary>
              Authentication
              <span v-if="editing.auth.scheme !== 'none'" class="env-auth-badge">{{ schemeLabel(editing.auth.scheme) }}</span>
            </summary>
            <p class="env-dyn-lead">
              Set the credential once here and every request that inherits it uses it — no need to configure
              an OAuth client on twenty saved requests. A request can still override it, or opt out with
              <strong>No auth</strong>.
            </p>

            <select class="input-field env-auth-scheme" v-model="editing.auth.scheme">
              <option value="none">No auth</option>
              <option value="bearer">Bearer token</option>
              <option value="basic">Basic auth</option>
              <option value="api_key">API key</option>
              <option value="oauth2_client_credentials">OAuth 2.0 (client credentials)</option>
            </select>

            <template v-if="editing.auth.scheme === 'bearer'">
              <input type="text" class="input-field mono env-auth-field" autocomplete="off"
                     :placeholder="secretPlaceholder('token', 'Token — or {{token}}')" v-model="editing.auth.token" />
            </template>

            <template v-else-if="editing.auth.scheme === 'basic'">
              <input type="text" class="input-field env-auth-field" placeholder="Username" autocomplete="off" v-model="editing.auth.username" />
              <input type="text" class="input-field mono env-auth-field" autocomplete="off"
                     :placeholder="secretPlaceholder('password', 'Password — or {{password}}')" v-model="editing.auth.password" />
            </template>

            <template v-else-if="editing.auth.scheme === 'api_key'">
              <input type="text" class="input-field env-auth-field" placeholder="Name (e.g. X-Api-Key)" v-model="editing.auth.key" />
              <input type="text" class="input-field mono env-auth-field" autocomplete="off"
                     :placeholder="secretPlaceholder('value', 'Value — or {{api_key}}')" v-model="editing.auth.value" />
              <select class="input-field env-auth-scheme" v-model="editing.auth.in">
                <option value="header">Send in header</option>
                <option value="query">Send in query string</option>
              </select>
            </template>

            <template v-else-if="editing.auth.scheme === 'oauth2_client_credentials'">
              <input type="text" class="input-field mono env-auth-field" placeholder="Token URL — https://issuer/oauth/token" v-model="editing.auth.token_url" />
              <input type="text" class="input-field env-auth-field" placeholder="Client ID" autocomplete="off" v-model="editing.auth.client_id" />
              <input type="text" class="input-field mono env-auth-field" autocomplete="off"
                     :placeholder="secretPlaceholder('client_secret', 'Client secret — or {{client_secret}}')" v-model="editing.auth.client_secret" />
              <input type="text" class="input-field env-auth-field" placeholder="Scope (optional)" v-model="editing.auth.scope" />
              <input type="text" class="input-field env-auth-field" placeholder="Audience (optional)" v-model="editing.auth.audience" />
              <select class="input-field env-auth-scheme" v-model="editing.auth.credentials_in">
                <option value="basic">Credentials as HTTP Basic</option>
                <option value="body">Credentials in the form body</option>
              </select>
            </template>

            <p class="env-dyn-lead" v-if="editing.auth.scheme !== 'none'">
              Credentials are never sent back to the browser — leave one blank to keep what is stored.
              Better still, put it in a <strong>secret</strong> variable above and reference it as
              <code v-pre>{{name}}</code>.
            </p>
          </details>

          <p v-if="error" class="env-error">{{ error }}</p>

          <footer class="env-actions">
            <button class="primary" @click="save" :disabled="saving || !editing.name.trim()">
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button v-if="editing.id" class="secondary" @click="duplicateEnv(editing)" :disabled="saving">Duplicate</button>
            <button v-if="editing.id" class="secondary" @click="exportEnv(editing)">Export</button>
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
import { confirmDialog } from '../confirm';
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { useEnvironmentsStore } from '../store/environments';
import { useAuthStore } from '../store/auth';
import { toast } from '../toast';
import Icon from './Icon.vue';

const emit = defineEmits(['close']);
const store = useEnvironmentsStore();
const authStore = useAuthStore();
const ownerName = (env) => (env.owner && env.owner.id !== authStore.user?.id ? env.owner.name : '');

const editing = ref(null);
const saving = ref(false);
const error = ref('');
const dynamicVars = ref([]);

// `{{$uuid}}` from the `$uuid` token — kept out of the template so Vue's
// mustache parser never sees the braces.
const placeholderFor = (token) => '{{' + token + '}}';

async function copyToken(token) {
  try {
    await navigator.clipboard.writeText(placeholderFor(token));
    toast.success('Copied ' + placeholderFor(token));
  } catch {
    toast.error('Could not copy to clipboard');
  }
}
const importInput = ref(null);

const exportEnv = async (env) => {
  try {
    const res = await axios.get(`/api/environments/${env.id}/export`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${(env.name || 'environment').replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.spi-env.json`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success('Environment exported. Secret values are not included.');
  } catch {
    toast.error('Could not export the environment.');
  }
};

const duplicateEnv = async (env) => {
  saving.value = true;
  try {
    const res = await axios.post(`/api/environments/${env.id}/duplicate`);
    await store.fetch();
    edit(res.data);
    toast.success(`Duplicated "${env.name}".`);
  } catch (e) {
    toast.error(e.response?.data?.message || 'Could not duplicate the environment.');
  } finally {
    saving.value = false;
  }
};

const triggerImport = () => importInput.value?.click();

const onImportFile = async (e) => {
  const file = e.target.files?.[0];
  e.target.value = ''; // allow re-selecting the same file later
  if (!file) return;
  try {
    const doc = JSON.parse(await file.text());
    const res = await axios.post('/api/environments/import', {
      name: doc.name || 'Imported environment',
      variables: doc.variables || [],
    });
    await store.fetch();
    edit(res.data);
    toast.success(`Imported "${res.data.name}". Re-enter any secret values.`);
  } catch (err) {
    toast.error(err.response?.data?.message || 'That file is not a valid environment export.');
  }
};

const close = () => emit('close');

// Mirrors the server's auth config. `none` means this environment supplies no
// auth, which is what a request that inherits then gets.
const emptyEnvAuth = () => ({
  scheme: 'none', token: '', username: '', password: '', key: '', value: '', in: 'header',
  token_url: '', client_id: '', client_secret: '', scope: '', audience: '', credentials_in: 'basic',
});

const startNew = () => {
  error.value = '';
  editing.value = {
    id: null, name: '', is_default: !store.environments.length,
    variables: [{ key: '', value: '', secret: false }],
    auth: emptyEnvAuth(),
  };
};

const edit = (env) => {
  error.value = '';
  // Clone so cancelling leaves the store untouched.
  editing.value = {
    id: env.id,
    name: env.name,
    is_default: env.is_default,
    variables: env.variables.map((v) => ({ ...v, reveal: false })),
    auth: { ...emptyEnvAuth(), ...(env.auth || {}) },
  };
};

const addRow = () => editing.value.variables.push({ key: '', value: '', secret: false });

// Only the fields the chosen scheme uses. A blank credential is sent as-is:
// the server reads that as "unchanged" and keeps the stored one.
const collectEnvAuth = () => {
  const a = editing.value.auth || emptyEnvAuth();
  const scheme = a.scheme;
  if (!scheme || scheme === 'none') return { scheme: 'none' };
  if (scheme === 'bearer') return { scheme, token: a.token || '' };
  if (scheme === 'basic') return { scheme, username: a.username || '', password: a.password || '' };
  if (scheme === 'oauth2_client_credentials') {
    return {
      scheme,
      token_url: a.token_url || '',
      client_id: a.client_id || '',
      client_secret: a.client_secret || '',
      scope: a.scope || '',
      audience: a.audience || '',
      credentials_in: a.credentials_in === 'body' ? 'body' : 'basic',
    };
  }
  return { scheme, key: a.key || '', value: a.value || '', in: a.in || 'header' };
};

const SCHEME_LABELS = {
  bearer: 'Bearer', basic: 'Basic', api_key: 'API key', oauth2_client_credentials: 'OAuth 2.0',
};
const schemeLabel = (scheme) => SCHEME_LABELS[scheme] || scheme;

// Placeholder text for a credential the server is holding but never sends back.
const secretPlaceholder = (field, fallback) =>
  editing.value?.auth?.['has_' + field] ? 'unchanged' : fallback;

const save = async () => {
  saving.value = true;
  error.value = '';
  const payload = {
    name: editing.value.name.trim(),
    is_default: editing.value.is_default,
    variables: editing.value.variables
      .filter((v) => v.key.trim())
      .map((v) => ({ key: v.key.trim(), value: v.value, secret: !!v.secret })),
    auth: collectEnvAuth(),
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
  if (!(await confirmDialog(`Delete the "${editing.value.name}" environment?`))) return;
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
  axios.get('/api/dynamic-variables')
    .then((res) => { dynamicVars.value = res.data.variables || []; })
    .catch(() => {});
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
.env-file { display: none; }
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

.env-dyn { margin-top: 14px; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; }
.env-dyn summary { cursor: pointer; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
.env-dyn summary:hover { color: var(--text-primary); }
.env-dyn-lead { font-size: 12px; line-height: 1.5; color: var(--text-secondary); margin: 8px 0 10px; }
.env-dyn-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; max-height: 220px; overflow-y: auto; }
.env-dyn-list li {
  display: grid; grid-template-columns: minmax(130px, auto) 1fr auto; gap: 10px; align-items: baseline;
  padding: 4px 6px; border-radius: 6px; cursor: pointer;
}
.env-dyn-list li:hover { background: var(--accent-soft, rgba(88,166,255,.12)); }
.env-dyn-list code { font-family: 'Courier New', monospace; font-size: 12px; color: var(--accent-color); background: rgba(255,255,255,.06); padding: 1px 5px; border-radius: 4px; }
.env-dyn-desc { font-size: 12px; color: var(--text-secondary); }
.env-auth-badge {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  background: var(--accent-soft, rgba(88,166,255,.12)); color: var(--accent-color);
  padding: 2px 7px; border-radius: 5px; margin-left: 8px;
}
.env-auth-scheme { max-width: 280px; margin-top: 8px; }
.env-auth-field { display: block; width: 100%; margin-top: 8px; }
.env-dyn-eg { font-size: 11px; color: var(--text-secondary); opacity: .8; font-family: 'Courier New', monospace; white-space: nowrap; }
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
