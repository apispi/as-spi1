<template>
  <div class="auth-container">
    <div class="auth-box">
      <h2>Set up your account</h2>

      <div v-if="!token || !email" class="error-msg center">
        This link is missing information. Please use the link from your email, or
        <router-link to="/register" class="link">start again</router-link>.
      </div>

      <form v-else @submit.prevent="handleComplete">
        <p class="lead">You're verifying <strong>{{ email }}</strong>. Choose a name and password to finish.</p>
        <div class="form-group">
          <label>Name</label>
          <input type="text" v-model="form.name" required class="input-field" placeholder="Your name" />
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" v-model="form.password" required minlength="8" class="input-field" placeholder="Min. 8 characters" />
        </div>
        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" v-model="form.password_confirmation" required minlength="8" class="input-field" />
        </div>
        <div v-if="error" class="error-msg">{{ error }}</div>
        <button type="submit" class="btn btn-primary w-full mt-4" :disabled="isLoading">
          {{ isLoading ? 'Creating account...' : 'Create account' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../store/auth';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const email = ref(route.query.email || '');
const token = ref(route.query.token || '');

const form = reactive({ name: '', password: '', password_confirmation: '' });
const error = ref('');
const isLoading = ref(false);

const handleComplete = async () => {
  error.value = '';
  if (form.password !== form.password_confirmation) {
    error.value = 'Passwords do not match.';
    return;
  }
  isLoading.value = true;
  try {
    const res = await axios.post('/api/register/complete', {
      email: email.value,
      token: token.value,
      name: form.name,
      password: form.password,
      password_confirmation: form.password_confirmation,
    });
    // The complete endpoint logs the user in; hydrate the store and go.
    authStore.user = res.data;
    router.push('/dashboard');
  } catch (err) {
    error.value = err.response?.data?.message
      || Object.values(err.response?.data?.errors || {})[0]?.[0]
      || 'Could not complete registration.';
  } finally {
    isLoading.value = false;
  }
};
</script>

<style scoped>
.auth-container { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: var(--bg-color); }
.auth-box { background-color: var(--panel-bg); padding: 32px; border-radius: 8px; border: 1px solid var(--border-color); width: 100%; max-width: 400px; }
.auth-box h2 { margin-top: 0; margin-bottom: 24px; text-align: center; color: var(--text-primary); }
.lead { color: var(--text-secondary); font-size: 14px; margin: 0 0 16px; text-align: center; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 14px; }
.input-field { width: 100%; padding: 10px 12px; background-color: var(--bg-color); border: 1px solid var(--border-color); border-radius: 4px; color: var(--text-primary); }
.error-msg { color: #ff7b72; font-size: 13px; margin-top: 8px; }
.error-msg.center { text-align: center; }
.btn { padding: 10px 16px; border-radius: 4px; font-weight: 500; cursor: pointer; border: none; }
.btn-primary { background-color: var(--accent-color); color: #fff; }
.btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }
.w-full { width: 100%; }
.mt-4 { margin-top: 16px; }
.link { color: var(--accent-color); text-decoration: none; }
.link:hover { text-decoration: underline; }
</style>
