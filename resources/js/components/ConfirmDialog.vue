<template>
  <transition name="cd-fade">
    <div v-if="confirmState.open" class="cd-overlay" @click.self="cancel" @keydown.esc="cancel">
      <div class="cd-box" role="alertdialog" aria-modal="true" :aria-label="confirmState.title">
        <h2 class="cd-title">{{ confirmState.title }}</h2>
        <p class="cd-message">{{ confirmState.message }}</p>
        <div class="cd-actions">
          <button ref="cancelBtn" class="cd-btn cancel" @click="cancel">{{ confirmState.cancelText }}</button>
          <button class="cd-btn confirm" :class="{ danger: confirmState.danger }" @click="ok">{{ confirmState.confirmText }}</button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { watch, nextTick, ref, onMounted, onUnmounted } from 'vue';
import { confirmState, resolveConfirm } from '../confirm';

const cancelBtn = ref(null);

const ok = () => resolveConfirm(true);
const cancel = () => resolveConfirm(false);

// Focus the safe (cancel) button when the dialog opens; Enter confirms.
watch(() => confirmState.open, (open) => {
  if (open) nextTick(() => cancelBtn.value?.focus());
});

const onKey = (e) => {
  if (!confirmState.open) return;
  if (e.key === 'Enter') { e.preventDefault(); ok(); }
  else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
};

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.cd-overlay {
  position: fixed; inset: 0; z-index: 12000;
  display: flex; align-items: center; justify-content: center; padding: 20px;
  background: rgba(0, 0, 0, 0.55);
}
.cd-box {
  width: 100%; max-width: 420px; padding: 22px 22px 18px;
  background: var(--bg-elevated, #1c2129); border: 1px solid var(--border-color);
  border-radius: 14px; box-shadow: 0 20px 60px var(--shadow-color, rgba(0,0,0,.5));
}
.cd-title { font-size: 17px; font-weight: 700; margin: 0 0 8px; color: var(--text-primary); }
.cd-message { font-size: 14px; line-height: 1.6; color: var(--text-secondary); margin: 0 0 20px; white-space: pre-line; }
.cd-actions { display: flex; justify-content: flex-end; gap: 10px; }
.cd-btn {
  padding: 8px 18px; border-radius: 8px; font-size: 14px; font-weight: 600;
  cursor: pointer; font-family: inherit; border: 1px solid var(--border-color);
}
.cd-btn.cancel { background: transparent; color: var(--text-primary); }
.cd-btn.cancel:hover { border-color: var(--text-secondary); }
.cd-btn.confirm { background: var(--accent-color); border-color: var(--accent-color); color: #fff; }
.cd-btn.confirm:hover { background: var(--accent-hover); }
.cd-btn.confirm.danger { background: var(--error-color); border-color: var(--error-color); }
.cd-btn.confirm.danger:hover { filter: brightness(1.08); }

.cd-fade-enter-active, .cd-fade-leave-active { transition: opacity .18s ease; }
.cd-fade-enter-from, .cd-fade-leave-to { opacity: 0; }
</style>
