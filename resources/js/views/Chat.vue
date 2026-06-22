<template>
  <div class="chat-shell" :class="{ 'sidebar-open': sidebarOpen, 'is-mobile': isMobile }">
    <div class="chat-overlay" @click="sidebarOpen = false"></div>

    <aside class="chat-sidebar">
      <div class="chat-sidebar-header">
        <a href="/" class="chat-logo">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 27" class="chat-logo-icon">
            <defs>
              <linearGradient id="chlg" x1=".5" y1="0" x2=".5" y2="1">
                <stop offset="0%" stop-color="#60A5FA"/>
                <stop offset="100%" stop-color="#3B82F6"/>
              </linearGradient>
            </defs>
            <path d="M12,0.5 L13.4,3.3 L16,4.5 L13.4,5.7 L12,8.5 L10.6,5.7 L8,4.5 L10.6,3.3 Z" fill="url(#chlg)"/>
            <path d="M12,8.5 L24,26 L20,26 L15.5,18 L8.5,18 L4,26 L0,26 Z" fill="url(#chlg)"/>
          </svg>
          <span>ApiSpi</span>
        </a>
        <button class="chat-sidebar-close" @click="sidebarOpen = false" aria-label="Close menu">✕</button>
      </div>

      <nav class="chat-nav">
        <span class="chat-nav-label">Navigation</span>
        <a href="/" class="chat-nav-link">
          <span class="chat-nav-icon">⬡</span> Home
        </a>
        <a href="/chat" class="chat-nav-link active">
          <span class="chat-nav-icon">◈</span> SCX AI Chat
        </a>
        <a href="/profile" class="chat-nav-link">
          <span class="chat-nav-icon">◈</span> Profile
        </a>
      </nav>

      <div class="chat-sidebar-footer">
        <div class="chat-user-row">
          <div v-if="authStore.user?.avatar" class="chat-avatar">
            <img :src="authStore.user.avatar" :alt="authStore.user.name" class="chat-avatar-photo" referrerpolicy="no-referrer">
          </div>
          <div v-else class="chat-avatar">{{ userInitial }}</div>
          <div class="chat-user-text">
            <div class="chat-user-name">{{ authStore.user?.name || 'User' }}</div>
            <div class="chat-user-email">{{ authStore.user?.email }}</div>
          </div>
        </div>
        <button @click="handleLogout" class="chat-signout">⏻ Sign Out</button>
      </div>
    </aside>

    <div class="chat-main">
      <header class="chat-topbar">
        <div class="chat-topbar-left">
          <a href="/" class="chat-topbar-logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 27" class="chat-logo-icon">
              <defs>
                <linearGradient id="chlg2" x1=".5" y1="0" x2=".5" y2="1">
                  <stop offset="0%" stop-color="#60A5FA"/>
                  <stop offset="100%" stop-color="#3B82F6"/>
                </linearGradient>
              </defs>
              <path d="M12,0.5 L13.4,3.3 L16,4.5 L13.4,5.7 L12,8.5 L10.6,5.7 L8,4.5 L10.6,3.3 Z" fill="url(#chlg2)"/>
              <path d="M12,8.5 L24,26 L20,26 L15.5,18 L8.5,18 L4,26 L0,26 Z" fill="url(#chlg2)"/>
            </svg>
            <span>ApiSpi</span>
          </a>
          <button class="chat-menu-btn" @click="sidebarOpen = true" aria-label="Open menu">
            <span></span><span></span><span></span>
          </button>
        </div>
        <div class="chat-topbar-right">
          <div v-if="authStore.user?.avatar" class="chat-avatar chat-avatar-sm">
            <img :src="authStore.user.avatar" :alt="authStore.user.name" class="chat-avatar-photo" referrerpolicy="no-referrer">
          </div>
          <div v-else class="chat-avatar chat-avatar-sm">{{ userInitial }}</div>
        </div>
      </header>

      <main class="chat-content">
        <div class="chat-container">
          <div class="chat-header">
            <h1 class="chat-title">SCX AI Chat</h1>
            <p class="chat-subtitle">Chat with SCX AI using your API key</p>
          </div>

          <div v-if="!hasScxKey" class="chat-setup-card">
            <div class="chat-setup-icon">🔑</div>
            <h2>SCX API Key Required</h2>
            <p>To use the SCX AI Chat, please add your SCX API key in your <a href="/profile">Profile Settings</a>.</p>
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
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useAuthStore } from '../store/auth';
import axios from 'axios';

const authStore = useAuthStore();

const sidebarOpen = ref(false);
const isMobile = ref(false);
let mobileMql = null;

function handleMobileChange(e) { isMobile.value = e.matches; }

onMounted(() => {
  mobileMql = window.matchMedia('(max-width: 768px)');
  isMobile.value = mobileMql.matches;
  mobileMql.addEventListener('change', handleMobileChange);
  loadScxKeyStatus();
});

onUnmounted(() => mobileMql?.removeEventListener('change', handleMobileChange));

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

const handleLogout = async () => {
  try {
    await axios.post('/logout');
    await authStore.logout();
    window.location.href = '/';
  } catch (error) {
    window.location.href = '/';
  }
};
</script>

<style scoped>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

.chat-shell {
  display: flex; min-height: 100vh; background: #0a0805;
  font-family: 'Instrument Sans', system-ui, sans-serif;
}

.chat-sidebar {
  width: 240px; flex-shrink: 0;
  background: rgba(14,8,4,0.95);
  border-right: 1px solid rgba(59,130,246,0.1);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; height: 100vh; z-index: 40;
  transition: transform 0.25s ease;
}
.chat-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 39;
}

.chat-sidebar-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.25rem 1rem 1rem; border-bottom: 1px solid rgba(59,130,246,0.08);
}
.chat-logo { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
.chat-logo-icon { width: 22px; height: 25px; }
.chat-logo span { font-size: 1.1rem; font-weight: 700; color: #60A5FA; letter-spacing: -0.01em; }
.chat-sidebar-close {
  display: none; background: none; border: none; color: #6b7280;
  font-size: 1rem; cursor: pointer; padding: 0.25rem;
}

.chat-nav {
  flex: 1; padding: 1rem 0.625rem; overflow-y: auto;
  display: flex; flex-direction: column; gap: 0.125rem;
}
.chat-nav-label {
  font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.1em; color: #4b5563;
  padding: 0.75rem 0.5rem 0.25rem; display: block;
}
.chat-nav-link {
  display: flex; align-items: center; gap: 0.625rem;
  padding: 0.5rem 0.75rem; border-radius: 0.5rem;
  color: #9ca3af; font-size: 0.875rem; font-weight: 500; text-decoration: none;
  transition: all 0.15s;
}
.chat-nav-link:hover { background: rgba(59,130,246,0.06); color: #60A5FA; }
.chat-nav-link.active { background: rgba(59,130,246,0.1); color: #60A5FA; }
.chat-nav-icon { font-size: 0.9rem; width: 18px; text-align: center; flex-shrink: 0; }

.chat-sidebar-footer {
  padding: 0.875rem; border-top: 1px solid rgba(59,130,246,0.08);
  display: flex; flex-direction: column; gap: 0.5rem;
}
.chat-user-row {
  display: flex; align-items: center; gap: 0.625rem;
  padding: 0.5rem 0.625rem; border-radius: 0.5rem;
  cursor: pointer;
}
.chat-user-row:hover { background: rgba(59,130,246,0.06); }
.chat-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: linear-gradient(135deg, #3B82F6, #60A5FA);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; color: #0a0805; flex-shrink: 0;
}
.chat-avatar-sm { width: 28px; height: 28px; font-size: 0.72rem; }
.chat-avatar-photo { object-fit: cover; background: none; width: 100%; height: 100%; border-radius: 50%; }
.chat-user-text { overflow: hidden; }
.chat-user-name { font-size: 0.8rem; font-weight: 600; color: #e5e7eb; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-user-email { font-size: 0.7rem; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-signout {
  width: 100%; padding: 0.5rem; border-radius: 0.4rem;
  background: none; border: 1px solid rgba(59,130,246,0.12);
  color: #6b7280; font-size: 0.78rem; cursor: pointer; font-family: inherit;
  transition: all 0.15s; text-align: center; min-height: 36px;
}
.chat-signout:hover { border-color: rgba(59,130,246,0.3); color: #9ca3af; }

.chat-main { flex: 1; margin-left: 240px; display: flex; flex-direction: column; min-height: 100vh; }

.chat-topbar {
  display: none; align-items: center; justify-content: space-between;
  padding: 0.75rem 1rem; border-bottom: 1px solid rgba(59,130,246,0.08);
  background: rgba(14,8,4,0.9); position: sticky; top: 0; z-index: 30;
}
.chat-menu-btn {
  display: flex; flex-direction: column; gap: 4px;
  background: none; border: none; cursor: pointer; padding: 4px;
}
.chat-menu-btn span { display: block; width: 20px; height: 2px; background: #9ca3af; border-radius: 1px; }
.chat-topbar-left { display: flex; align-items: center; gap: 1rem; }
.chat-topbar-logo { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; }
.chat-topbar-logo span { font-size: 1rem; font-weight: 700; color: #60A5FA; }
.chat-topbar-right { display: flex; align-items: center; gap: 0.75rem; }

.is-mobile .chat-sidebar { transform: translateX(-100%); }
.is-mobile.sidebar-open .chat-sidebar { transform: translateX(0); }
.is-mobile.sidebar-open .chat-overlay { display: block; }
.is-mobile .chat-sidebar-close { display: block; }
.is-mobile .chat-main { margin-left: 0; }
.is-mobile .chat-topbar { display: flex; }

.chat-content { padding: 2rem 2.5rem; display: flex; justify-content: center; }

.chat-container { width: 100%; max-width: 800px; }

.chat-header { margin-bottom: 2rem; }
.chat-title { font-size: 1.75rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem; }
.chat-subtitle { font-size: 0.95rem; color: #6b7280; }

.chat-setup-card {
  background: rgba(8,14,28,0.8); border: 1px solid rgba(59,130,246,0.2);
  border-radius: 1.25rem; padding: 3rem; text-align: center;
}
.chat-setup-icon { font-size: 3rem; margin-bottom: 1rem; }
.chat-setup-card h2 { font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.75rem; }
.chat-setup-card p { font-size: 0.95rem; color: #9ca3af; }
.chat-setup-card a { color: #60A5FA; text-decoration: none; }
.chat-setup-card a:hover { text-decoration: underline; }

.chat-interface {
  background: rgba(8,14,28,0.8); border: 1px solid rgba(59,130,246,0.15);
  border-radius: 1.25rem; overflow: hidden;
}

.chat-messages {
  height: 500px; overflow-y: auto; padding: 1.5rem;
  display: flex; flex-direction: column; gap: 1.25rem;
}

.chat-empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; color: #6b7280;
}
.chat-empty-icon { font-size: 3rem; margin-bottom: 1rem; }
.chat-empty p { font-size: 1rem; margin-bottom: 0.25rem; }
.chat-empty-hint { font-size: 0.85rem; color: #4b5563; }

.chat-message { display: flex; gap: 0.875rem; }
.chat-message.user { flex-direction: row-reverse; }
.chat-message-avatar {
  width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, #3B82F6, #60A5FA);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.75rem; font-weight: 700; color: #0a0805;
}
.chat-message.assistant .chat-message-avatar { background: linear-gradient(135deg, #8B5CF6, #A78BFA); }
.chat-message-content { max-width: 75%; }
.chat-message.user .chat-message-content { text-align: right; }
.chat-message-text {
  background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.15);
  border-radius: 1rem; padding: 0.875rem 1.125rem; font-size: 0.95rem;
  color: #e5e7eb; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;
}
.chat-message.user .chat-message-text {
  background: rgba(59,130,246,0.2); border-color: rgba(59,130,246,0.3);
}
.chat-message-time { font-size: 0.7rem; color: #4b5563; margin-top: 0.375rem; }

.chat-message.typing .chat-message-text {
  display: flex; gap: 4px; align-items: center; padding: 1rem 1.25rem;
}
.chat-message.typing .chat-message-text span {
  width: 8px; height: 8px; background: #60A5FA; border-radius: 50%;
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
  border-top: 1px solid rgba(59,130,246,0.1);
  background: rgba(14,8,4,0.5);
}
.chat-input {
  flex: 1; padding: 0.875rem 1.25rem;
  background: rgba(10,8,5,0.9); border: 1px solid rgba(59,130,246,0.25);
  border-radius: 0.75rem; color: #f1f5f9; font-size: 0.95rem; font-family: inherit;
  transition: border-color 0.18s;
}
.chat-input:focus { outline: none; border-color: #60A5FA; }
.chat-input::placeholder { color: #4b5563; }
.chat-input:disabled { opacity: 0.6; }

.chat-send-btn {
  padding: 0.875rem; background: rgba(59,130,246,0.2); border: 1px solid rgba(59,130,246,0.45);
  border-radius: 0.75rem; color: #60A5FA; cursor: pointer; transition: all 0.18s;
  display: flex; align-items: center; justify-content: center;
}
.chat-send-btn:hover:not(:disabled) { background: rgba(59,130,246,0.35); }
.chat-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 640px) {
  .chat-content { padding: 1rem; }
  .chat-messages { height: 400px; }
  .chat-message-content { max-width: 85%; }
}
</style>