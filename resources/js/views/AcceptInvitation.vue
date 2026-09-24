<template>
  <main class="inv">
    <div class="inv-card">
      <p v-if="loading" class="inv-muted">Checking this invitation…</p>

      <template v-else-if="error">
        <h1 class="inv-title">This invitation is not valid</h1>
        <p class="inv-muted">{{ error }}</p>
        <router-link to="/dashboard" class="inv-btn inv-btn-quiet">Go to Spi</router-link>
      </template>

      <template v-else-if="joined">
        <h1 class="inv-title">You have joined {{ invitation.workspace }}</h1>
        <p class="inv-muted">
          You can now see the workspace's shared saved requests, collections, environments and monitors —
          and the other members can see yours.
        </p>
        <router-link to="/dashboard" class="inv-btn">Open Spi</router-link>
      </template>

      <template v-else>
        <h1 class="inv-title">Join {{ invitation.workspace }}</h1>
        <p class="inv-lead">
          <strong>{{ invitation.invited_by }}</strong> invited
          <strong>{{ invitation.email }}</strong> to a shared workspace
          of {{ invitation.member_count }} {{ invitation.member_count === 1 ? 'person' : 'people' }}.
        </p>

        <!-- The part people skip past everywhere else, so it is the main event
             here rather than a footnote. -->
        <div class="inv-warn">
          <h2 class="inv-warn-title">What joining shares</h2>
          <ul>
            <li>You will be able to see and edit the workspace's saved requests, collections, environments, monitors and reports.</li>
            <li>The other members will be able to see and edit <strong>yours</strong>, including the values of your secret environment variables.</li>
          </ul>
        </div>

        <template v-if="!authStore.user">
          <p class="inv-muted">Sign in as {{ invitation.email }} to accept.</p>
          <router-link :to="{ name: 'login', query: { redirect: route.fullPath } }" class="inv-btn">Sign in</router-link>
        </template>

        <template v-else-if="!emailMatches">
          <p class="inv-muted">
            You are signed in as <strong>{{ authStore.user.email }}</strong>, but this invitation was sent to
            <strong>{{ invitation.email }}</strong>. Sign in with that account to accept it.
          </p>
          <router-link to="/dashboard" class="inv-btn inv-btn-quiet">Go to Spi</router-link>
        </template>

        <template v-else>
          <button class="inv-btn" :disabled="busy" @click="accept">
            {{ busy ? 'Joining…' : 'Accept and join' }}
          </button>
          <router-link to="/dashboard" class="inv-btn inv-btn-quiet">Not now</router-link>
        </template>

        <p v-if="actionError" class="inv-error">{{ actionError }}</p>
      </template>
    </div>
  </main>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../store/auth';

const route = useRoute();
const authStore = useAuthStore();

const loading = ref(true);
const busy = ref(false);
const joined = ref(false);
const error = ref('');
const actionError = ref('');
const invitation = ref({});

const emailMatches = computed(() =>
  (authStore.user?.email || '').toLowerCase() === (invitation.value.email || '').toLowerCase());

onMounted(async () => {
  try {
    const res = await axios.get(`/api/invitations/${route.params.token}`);
    invitation.value = res.data;
  } catch (e) {
    error.value = e.response?.data?.message || 'This invitation is no longer valid.';
  } finally {
    loading.value = false;
  }
});

async function accept() {
  busy.value = true;
  actionError.value = '';
  try {
    await axios.post(`/api/workspace/invitations/${route.params.token}/accept`);
    joined.value = true;
    // The workspace changed what this account can see, so re-read the session.
    await authStore.fetchUser?.();
  } catch (e) {
    actionError.value = e.response?.data?.message || 'Could not accept this invitation.';
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.inv { min-height: 70vh; display: flex; align-items: center; justify-content: center; padding: 32px 20px; }
.inv-card {
  max-width: 560px; width: 100%; padding: 28px 30px;
  border: 1px solid var(--border-color); border-radius: 14px;
  background: var(--panel-bg, var(--bg-secondary));
}
.inv-title { font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin: 0 0 10px; }
.inv-lead { color: var(--text-primary); line-height: 1.6; margin: 0 0 18px; }
.inv-muted { color: var(--text-secondary); line-height: 1.6; margin: 0 0 18px; }

.inv-warn {
  border: 1px solid var(--border-color); border-left: 3px solid var(--accent-color);
  border-radius: 9px; padding: 14px 16px; margin-bottom: 22px;
}
.inv-warn-title { font-size: 0.82rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; color: var(--text-secondary); margin: 0 0 8px; }
.inv-warn ul { margin: 0; padding-left: 18px; color: var(--text-secondary); line-height: 1.6; font-size: 0.9rem; }
.inv-warn li + li { margin-top: 6px; }

.inv-btn {
  display: inline-block; padding: 9px 18px; border-radius: 8px; font-weight: 600; cursor: pointer;
  background: var(--accent-color); border: 1px solid var(--accent-color); color: #fff; text-decoration: none;
}
.inv-btn:disabled { opacity: .55; cursor: default; }
.inv-btn-quiet { background: transparent; border-color: var(--border-color); color: var(--text-secondary); margin-left: 10px; }
.inv-error { color: #f85149; margin: 14px 0 0; }
</style>
