<template>
  <div class="auth-container">
    <div class="auth-box">
      <h2>Register for ApiSpi</h2>

      <div v-if="sent" class="check-inbox">
        <div class="check-icon">✉️</div>
        <p class="check-title">Check your inbox</p>
        <p class="check-body">{{ sentMessage }}</p>
        <p class="check-hint">The link expires in 60 minutes.</p>
      </div>

      <form v-else @submit.prevent="handleRegister">
        <p class="register-lead">Enter your email and we'll send you a link to set up your account.</p>
        <div class="form-group">
          <label>Email</label>
          <input type="email" v-model="email" required class="input-field" placeholder="you@example.com" />
        </div>
        <div v-if="error" class="error-msg">{{ error }}</div>
        <button type="submit" class="btn btn-primary w-full mt-4" :disabled="isLoading">
          {{ isLoading ? 'Sending...' : 'Continue with email' }}
        </button>
      </form>

      <GoogleButton v-if="!sent" />

      <div class="auth-links mt-4 text-center text-sm text-secondary">
        Already have an account? <router-link to="/login" class="link">Login here</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import GoogleButton from '../components/GoogleButton.vue';

const email = ref('');
const error = ref('');
const isLoading = ref(false);
const sent = ref(false);
const sentMessage = ref('');

const handleRegister = async () => {
  error.value = '';
  isLoading.value = true;
  try {
    const res = await axios.post('/api/register/start', { email: email.value });
    sentMessage.value = res.data.message;
    sent.value = true;
  } catch (err) {
    error.value = err.response?.data?.message
      || Object.values(err.response?.data?.errors || {})[0]?.[0]
      || 'Something went wrong. Please try again.';
  } finally {
    isLoading.value = false;
  }
};
</script>

<style scoped>
.auth-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
  background-color: var(--bg-color);
}
.auth-box {
  background-color: var(--panel-bg);
  padding: 32px;
  border-radius: 8px;
  border: 1px solid var(--border-color);
  width: 100%;
  max-width: 400px;
}
.auth-box h2 {
  margin-top: 0;
  margin-bottom: 24px;
  text-align: center;
  color: var(--text-primary);
}
.form-group {
  margin-bottom: 16px;
}
.form-group label {
  display: block;
  margin-bottom: 8px;
  color: var(--text-secondary);
  font-size: 14px;
}
.input-field {
  width: 100%;
  padding: 10px 12px;
  background-color: var(--bg-color);
  border: 1px solid var(--border-color);
  border-radius: 4px;
  color: var(--text-primary);
}
.error-msg {
  color: #ff7b72;
  font-size: 13px;
  margin-top: 8px;
}
.btn {
  padding: 10px 16px;
  border-radius: 4px;
  font-weight: 500;
  cursor: pointer;
  border: none;
}
.btn-primary {
  background-color: var(--accent-color);
  color: #fff;
}
.btn-primary:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}
.w-full { width: 100%; }
.mt-4 { margin-top: 16px; }
.text-center { text-align: center; }
.text-sm { font-size: 14px; }
.text-secondary { color: var(--text-secondary); }
.link { color: var(--accent-color); text-decoration: none; }
.link:hover { text-decoration: underline; }
.register-lead { color: var(--text-secondary); font-size: 14px; margin: 0 0 16px; text-align: center; }
.check-inbox { text-align: center; padding: 8px 0 4px; }
.check-icon { font-size: 40px; margin-bottom: 8px; }
.check-title { font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0 0 8px; }
.check-body { color: var(--text-secondary); font-size: 14px; margin: 0 0 8px; }
.check-hint { color: var(--text-secondary); font-size: 12px; margin: 0; }
</style>
