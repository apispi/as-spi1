<template>
  <main class="chat-content">
    <div class="chat-container">
      <div class="chat-header">
        <h1 class="chat-title">SCX AI Chat</h1>
        <p class="chat-subtitle">Chat with SCX AI using your API key</p>
      </div>

      <div v-if="!hasScxKey" class="chat-setup-card">
        <div class="chat-setup-icon">🔑</div>
        <h2>SCX API Key Required</h2>
        <p>To use the SCX AI Chat, please add your SCX API key in your <router-link to="/profile">Profile Settings</router-link>.</p>
      </div>

      <div v-else class="chat-interface">
        <div class="chat-messages" ref="messagesContainer">
          <div v-if="messages.length === 0" class="chat-empty">
            <div class="chat-empty-icon">💬</div>
            <p>Start a conversation with SCX AI</p>
            <p class="chat-empty-hint">Ask questions, get help with API requests, or debug your code</p>
          </div>
          <div v-for="(msg, i) in messages" :key="i" :class="['chat-message', msg.role]">
            <div class="chat-message-avatar">
              {{ msg.role === 'user' ? userInitial : 'SCX' }}
            </div>
            <div class="chat-message-content">
              <div class="chat-message-text">{{ msg.content }}</div>
              <div class="chat-message-time">{{ formatTime(msg.timestamp) }}</div>
            </div>
          </div>
          <div v-if="isLoading" class="chat-message assistant loading">
            <div class="chat-message-avatar">SCX</div>
            <div class="chat-message-content">
              <div class="chat-message-text typing">
                <span></span><span></span><span></span>
              </div>
            </div>
          </div>
        </div>

        <form @submit.prevent="sendMessage" class="chat-input-form">
          <input
            type="text"
            v-model="inputMessage"
            placeholder="Type your message..."
            class="chat-input"
            :disabled="isLoading"
          >
          <button type="submit" class="chat-send-btn" :disabled="isLoading || !inputMessage.trim()">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="22" y1="2" x2="11" y2="13"></line>
              <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
          </button>
        </form>
      </div>
    </div>
  </main>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useAuthStore } from '../store/auth';
import axios from 'axios';

const authStore = useAuthStore();

onMounted(() => {
  loadScxKeyStatus();
});

const userInitial = computed(() => {
  const name = authStore.user?.name || 'U';
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
});

const hasScxKey = ref(false);
const messages = ref([]);
const inputMessage = ref('');
const isLoading = ref(false);
const messagesContainer = ref(null);

const loadScxKeyStatus = async () => {
  try {
    const res = await axios.get('/api/user/scx-api-key');
    hasScxKey.value = res.data.has_key;
  } catch (error) {
    hasScxKey.value = false;
  }
};

const sendMessage = async () => {
  if (!inputMessage.value.trim() || isLoading.value) return;

  const userMessage = inputMessage.value.trim();
  inputMessage.value = '';

  messages.value.push({
    role: 'user',
    content: userMessage,
    timestamp: new Date()
  });

  scrollToBottom();
  isLoading.value = true;

  try {
    const res = await axios.post('/api/scx/chat', {
      message: userMessage,
      history: messages.value.slice(0, -1).map(m => ({
        role: m.role,
        content: m.content
      }))
    });

    messages.value.push({
      role: 'assistant',
      content: res.data.response,
      timestamp: new Date()
    });
  } catch (error) {
    messages.value.push({
      role: 'assistant',
      content: error.response?.data?.error || 'Sorry, I encountered an error. Please try again.',
      timestamp: new Date()
    });
  } finally {
    isLoading.value = false;
    scrollToBottom();
  }
};

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
  });
};

const formatTime = (date) => {
  return new Date(date).toLocaleTimeString('en-AU', {
    hour: '2-digit',
    minute: '2-digit'
  });
};
</script>

<style scoped>
*, *::before, *::after { box-sizing: border-box; }

.chat-content { padding: 2rem 2.5rem; display: flex; justify-content: center; }

.chat-container { width: 100%; max-width: 800px; }

.chat-header { margin-bottom: 2rem; }
.chat-title { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.5rem; }
.chat-subtitle { font-size: 0.95rem; color: var(--text-secondary); }

.chat-setup-card {
  background: var(--panel-bg); border: 1px solid var(--border-color);
  border-radius: 1.25rem; padding: 3rem; text-align: center;
}
.chat-setup-icon { font-size: 3rem; margin-bottom: 1rem; }
.chat-setup-card h2 { font-size: 1.25rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.75rem; }
.chat-setup-card p { font-size: 0.95rem; color: var(--text-secondary); }
.chat-setup-card a { color: var(--accent-color); text-decoration: none; }
.chat-setup-card a:hover { text-decoration: underline; }

.chat-interface {
  background: var(--panel-bg); border: 1px solid var(--border-color);
  border-radius: 1.25rem; overflow: hidden;
}

.chat-messages {
  height: 500px; overflow-y: auto; padding: 1.5rem;
  display: flex; flex-direction: column; gap: 1.25rem;
}

.chat-empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; color: var(--text-secondary);
}
.chat-empty-icon { font-size: 3rem; margin-bottom: 1rem; }
.chat-empty p { font-size: 1rem; margin-bottom: 0.25rem; }
.chat-empty-hint { font-size: 0.85rem; color: var(--text-secondary); }

.chat-message { display: flex; gap: 0.875rem; }
.chat-message.user { flex-direction: row-reverse; }
.chat-message-avatar {
  width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
  background: var(--accent-color);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.75rem; font-weight: 700; color: #fff;
}
.chat-message.assistant .chat-message-avatar { background: #8B5CF6; }
.chat-message-content { max-width: 75%; }
.chat-message.user .chat-message-content { text-align: right; }
.chat-message-text {
  background: rgba(88,166,255,0.1); border: 1px solid rgba(88,166,255,0.15);
  border-radius: 1rem; padding: 0.875rem 1.125rem; font-size: 0.95rem;
  color: var(--text-primary); line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;
}
.chat-message.user .chat-message-text {
  background: rgba(88,166,255,0.2); border-color: rgba(88,166,255,0.3);
}
.chat-message-time { font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.375rem; }

.chat-message.typing .chat-message-text {
  display: flex; gap: 4px; align-items: center; padding: 1rem 1.25rem;
}
.chat-message.typing .chat-message-text span {
  width: 8px; height: 8px; background: var(--accent-color); border-radius: 50%;
  animation: typing 1.4s infinite ease-in-out;
}
.chat-message.typing .chat-message-text span:nth-child(2) { animation-delay: 0.2s; }
.chat-message.typing .chat-message-text span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
  0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
  30% { transform: translateY(-4px); opacity: 1; }
}

.chat-input-form {
  display: flex; gap: 0.75rem; padding: 1.25rem 1.5rem;
  border-top: 1px solid var(--border-color);
  background: var(--bg-color);
}
.chat-input {
  flex: 1; padding: 0.875rem 1.25rem;
  background: var(--bg-color); border: 1px solid var(--border-color);
  border-radius: 0.75rem; color: var(--text-primary); font-size: 0.95rem; font-family: inherit;
  transition: border-color 0.18s;
}
.chat-input:focus { outline: none; border-color: var(--accent-color); }
.chat-input::placeholder { color: var(--text-secondary); }
.chat-input:disabled { opacity: 0.6; }

.chat-send-btn {
  padding: 0.875rem; background: rgba(88,166,255,0.2); border: 1px solid rgba(88,166,255,0.45);
  border-radius: 0.75rem; color: var(--accent-color); cursor: pointer; transition: all 0.18s;
  display: flex; align-items: center; justify-content: center;
}
.chat-send-btn:hover:not(:disabled) { background: rgba(88,166,255,0.35); }
.chat-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 640px) {
  .chat-content { padding: 1rem; }
  .chat-messages { height: 400px; }
  .chat-message-content { max-width: 85%; }
}
</style>
