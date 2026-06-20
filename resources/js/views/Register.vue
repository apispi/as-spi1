<template>
  <div class="auth-container">
    <div class="auth-box">
      <h2>Register for ApiSpi</h2>
      <form @submit.prevent="handleRegister">
        <div class="form-group">
          <label>Name</label>
          <input type="text" v-model="form.name" required class="input-field" />
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" v-model="form.email" required class="input-field" />
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" v-model="form.password" required class="input-field" minlength="8" />
        </div>
        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" v-model="form.password_confirmation" required class="input-field" minlength="8" />
        </div>
        <div v-if="error" class="error-msg">{{ error }}</div>
        <button type="submit" class="btn btn-primary w-full mt-4" :disabled="isLoading">
          {{ isLoading ? 'Registering...' : 'Register' }}
        </button>
      </form>
      <div class="auth-links mt-4 text-center text-sm text-secondary">
        Already have an account? <router-link to="/login" class="link">Login here</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../store/auth';

const router = useRouter();
const authStore = useAuthStore();

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
});

const error = ref('');
const isLoading = ref(false);

const handleRegister = async () => {
  error.value = '';
  if (form.password !== form.password_confirmation) {
    error.value = "Passwords do not match!";
    return;
  }
  
  isLoading.value = true;
  try {
    await authStore.register(form);
    router.push('/');
  } catch (err) {
    if (err.response && err.response.data && err.response.data.message) {
      error.value = err.response.data.message;
    } else {
      error.value = "Failed to register. Please check your details.";
    }
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
</style>
