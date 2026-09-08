<template>
  <div class="up-card">
    <div class="up-card-header">
      <h2 class="up-card-title">Two-factor authentication</h2>
      <p class="up-card-sub">Require a one-time code from an authenticator app when you sign in</p>
    </div>

    <p v-if="error" class="tfa-error">{{ error }}</p>

    <!-- Enabled -->
    <template v-if="status.enabled">
      <div class="tfa-status on">
        <span class="tfa-dot"></span> Two-factor is <strong>on</strong>.
        <span v-if="recovery.remaining !== null" class="tfa-muted">
          {{ recovery.remaining }} of {{ recovery.total }} recovery codes left.
        </span>
      </div>
      <form class="tfa-disable" @submit.prevent="disable">
        <input v-model="disablePassword" type="password" class="up-input" placeholder="Confirm your password to turn off" />
        <button class="up-btn-danger-sm" :disabled="busy || !disablePassword">{{ busy ? '…' : 'Disable 2FA' }}</button>
      </form>
    </template>

    <!-- Mid-setup: show secret + confirm -->
    <template v-else-if="setup">
      <p class="up-hint" style="margin-bottom: .75rem">
        Add this key to your authenticator app (Google Authenticator, 1Password, Authy…), then enter the 6-digit code it shows.
      </p>
      <div class="tfa-secret-box">
        <span class="tfa-secret">{{ grouped(setup.secret) }}</span>
        <button type="button" class="up-btn-save" style="margin-top:0" @click="copy(setup.secret)">{{ copied ? 'Copied!' : 'Copy key' }}</button>
      </div>
      <p class="up-hint tfa-uri">Or open this link on the device: <code>{{ setup.otpauth_uri }}</code></p>
      <form class="tfa-confirm" @submit.prevent="confirm">
        <input v-model="confirmCode" inputmode="numeric" autocomplete="one-time-code" class="up-input" placeholder="6-digit code" />
        <button class="up-btn-save" style="margin-top:0" :disabled="busy || !confirmCode">{{ busy ? 'Verifying…' : 'Turn on' }}</button>
        <button type="button" class="up-btn cancel" @click="cancelSetup">Cancel</button>
      </form>
    </template>

    <!-- Just confirmed: show recovery codes once -->
    <template v-else-if="recoveryCodes">
      <div class="tfa-status on"><span class="tfa-dot"></span> Two-factor is now <strong>on</strong>.</div>
      <p class="up-hint tfa-recovery-lead">
        Save these recovery codes somewhere safe. Each can be used once to sign in if you lose your device. They won’t be shown again.
      </p>
      <ul class="tfa-codes">
        <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
      </ul>
      <button class="up-btn-save" @click="copy(recoveryCodes.join('\n'))">{{ copied ? 'Copied!' : 'Copy codes' }}</button>
      <button class="up-btn cancel" @click="recoveryCodes = null">Done</button>
    </template>

    <!-- Disabled -->
    <template v-else>
      <div class="tfa-status off"><span class="tfa-dot"></span> Two-factor is <strong>off</strong>.</div>
      <button class="up-btn-save" :disabled="busy" @click="beginSetup">{{ busy ? '…' : 'Enable 2FA' }}</button>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';

const status = reactive({ enabled: false, pending: false });
const recovery = reactive({ remaining: null, total: null });
const setup = ref(null);          // { secret, otpauth_uri } during enrolment
const recoveryCodes = ref(null);  // shown once after confirm
const confirmCode = ref('');
const disablePassword = ref('');
const busy = ref(false);
const error = ref('');
const copied = ref(false);

const load = async () => {
  try {
    const res = await axios.get('/api/user/2fa');
    Object.assign(status, res.data);
    if (status.enabled) {
      const r = await axios.get('/api/user/2fa/recovery');
      Object.assign(recovery, r.data);
    }
  } catch { /* leave defaults */ }
};

const beginSetup = async () => {
  busy.value = true; error.value = '';
  try {
    const res = await axios.post('/api/user/2fa/setup');
    setup.value = res.data;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not start setup.';
  } finally { busy.value = false; }
};

const confirm = async () => {
  busy.value = true; error.value = '';
  try {
    const res = await axios.post('/api/user/2fa/confirm', { code: confirmCode.value.trim() });
    recoveryCodes.value = res.data.recovery_codes;
    setup.value = null;
    confirmCode.value = '';
    status.enabled = true;
    await load();
  } catch (e) {
    error.value = e.response?.data?.message || 'That code is not valid.';
  } finally { busy.value = false; }
};

const cancelSetup = () => { setup.value = null; confirmCode.value = ''; error.value = ''; };

const disable = async () => {
  busy.value = true; error.value = '';
  try {
    await axios.delete('/api/user/2fa', { data: { password: disablePassword.value } });
    status.enabled = false;
    disablePassword.value = '';
    recovery.remaining = null;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not disable 2FA.';
  } finally { busy.value = false; }
};

const grouped = (s) => (s || '').replace(/(.{4})/g, '$1 ').trim();

const copy = async (text) => {
  try { await navigator.clipboard.writeText(text); copied.value = true; setTimeout(() => (copied.value = false), 1500); } catch { /* ignore */ }
};

onMounted(load);
</script>

<style scoped>
/* Self-contained styling (Profile's .up-* classes are scoped to that view). */
.up-card { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.5rem; }
.up-card-header { margin-bottom: 1rem; }
.up-card-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 0; }
.up-card-sub { font-size: 0.82rem; color: var(--text-secondary); margin: 0.25rem 0 0; }
.up-hint { font-size: 0.76rem; color: var(--text-secondary); line-height: 1.6; }
.up-input { padding: 0.7rem 0.9rem; border-radius: 0.5rem; font-size: 0.9rem; font-family: inherit; background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-primary); }
.up-input:focus { outline: none; border-color: var(--accent-color); }
.up-btn-save { padding: 0.6rem 1.4rem; border-radius: 0.5rem; background: var(--accent-soft); border: 1px solid var(--accent-color); color: var(--accent-color); font-size: 0.85rem; font-weight: 700; cursor: pointer; font-family: inherit; }
.up-btn-save:disabled { opacity: 0.55; cursor: not-allowed; }
.up-btn { padding: 0.6rem 1.1rem; border-radius: 0.5rem; background: transparent; border: 1px solid var(--border-color); color: var(--text-primary); font-size: 0.85rem; cursor: pointer; font-family: inherit; margin-left: 0.4rem; }
.up-btn-danger-sm { padding: 0.55rem 1rem; border-radius: 0.5rem; background: rgba(248,113,113,.12); border: 1px solid rgba(248,113,113,.4); color: var(--error-color); font-size: 0.82rem; font-weight: 700; cursor: pointer; font-family: inherit; }
.up-btn-danger-sm:disabled { opacity: 0.55; cursor: not-allowed; }

.tfa-status { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: var(--text-primary); margin-bottom: 1rem; flex-wrap: wrap; }
.tfa-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.tfa-status.on .tfa-dot { background: var(--success-color); }
.tfa-status.off .tfa-dot { background: var(--text-secondary); }
.tfa-muted { color: var(--text-secondary); font-size: 0.82rem; }
.tfa-error { color: var(--error-color); font-size: 0.82rem; margin-bottom: 0.75rem; }

.tfa-secret-box { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 0.75rem; }
.tfa-secret { font-family: 'Courier New', monospace; font-size: 1.05rem; letter-spacing: 0.12em; color: var(--accent-color); background: var(--input-bg); padding: 0.6rem 0.9rem; border-radius: 0.5rem; border: 1px solid var(--border-color); }
.tfa-uri code { font-size: 0.72rem; word-break: break-all; }

.tfa-confirm, .tfa-disable { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 0.5rem; }
.tfa-confirm .up-input, .tfa-disable .up-input { max-width: 220px; margin: 0; }
.up-btn.cancel { }

.tfa-recovery-lead { margin: 0.5rem 0 0.75rem; }
.tfa-codes { list-style: none; margin: 0 0 1rem; padding: 0.75rem 1rem; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 0.5rem; display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; font-family: 'Courier New', monospace; font-size: 0.9rem; color: var(--text-primary); }
</style>
