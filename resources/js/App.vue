<template>
  <div class="app-container">
    <header class="app-header">
      <div class="logo">
        <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="icon"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        <h1>ApiSpi Tester</h1>
      </div>
    </header>
    <main class="app-main">
      <div class="panel-container">
        <RequestPanel 
          @send-request="handleRequest" 
          :isLoading="isLoading"
        />
      </div>
      <div class="panel-container">
        <ResponsePanel 
          :response="responseData" 
          :isLoading="isLoading" 
        />
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import RequestPanel from './components/RequestPanel.vue';
import ResponsePanel from './components/ResponsePanel.vue';

const isLoading = ref(false);
const responseData = ref(null);

const handleRequest = async (requestConfig) => {
  isLoading.value = true;
  responseData.value = null;

  try {
    const res = await axios.post('/api/proxy', {
      url: requestConfig.url,
      method: requestConfig.method,
      headers: requestConfig.headers,
      body: requestConfig.body
    });
    
    responseData.value = res.data;
  } catch (error) {
    if (error.response && error.response.data) {
      responseData.value = error.response.data;
    } else {
      responseData.value = {
        status: 0,
        headers: {},
        body: 'Network error or proxy unreachable',
        time_ms: 0
      };
    }
  } finally {
    isLoading.value = false;
  }
};
</script>

<style scoped>
.app-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
}

.app-header {
  height: 60px;
  background-color: var(--panel-bg);
  border-bottom: 1px solid var(--border-color);
  display: flex;
  align-items: center;
  padding: 0 24px;
}

.logo {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--accent-color);
}

.logo h1 {
  font-size: 18px;
  font-weight: 600;
  margin: 0;
  color: var(--text-primary);
  letter-spacing: -0.5px;
}

.app-main {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.panel-container {
  flex: 1;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--border-color);
  min-width: 0; /* Prevent flex overflow */
}

.panel-container:last-child {
  border-right: none;
}

@media (max-width: 768px) {
  .app-main {
    flex-direction: column;
  }
  .panel-container {
    border-right: none;
    border-bottom: 1px solid var(--border-color);
  }
}
</style>
