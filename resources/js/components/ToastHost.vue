<template>
  <div class="toast-host" aria-live="polite" aria-atomic="false">
    <transition-group name="toast">
      <div
        v-for="t in toastState.items"
        :key="t.id"
        class="toast"
        :class="t.type"
        role="status"
        @click="dismiss(t.id)"
      >
        <span class="toast-icon">{{ icon(t.type) }}</span>
        <span class="toast-msg">{{ t.message }}</span>
        <button class="toast-x" aria-label="Dismiss" @click.stop="dismiss(t.id)">×</button>
      </div>
    </transition-group>
  </div>
</template>

<script setup>
import { toastState, dismiss } from '../toast';

const icon = (type) => ({ success: '✓', error: '!', info: 'i' }[type] || 'i');
</script>

<style scoped>
.toast-host {
  position: fixed; top: 16px; right: 16px; z-index: 11000;
  display: flex; flex-direction: column; gap: 8px; max-width: 90vw; pointer-events: none;
}
.toast {
  pointer-events: auto; display: flex; align-items: center; gap: 10px;
  min-width: 260px; max-width: 380px; padding: 11px 12px;
  border-radius: 10px; cursor: pointer;
  background: var(--bg-elevated, #1c2129); border: 1px solid var(--border-color);
  box-shadow: 0 12px 32px var(--shadow-color, rgba(0,0,0,.4));
  border-left-width: 3px;
}
.toast.success { border-left-color: var(--success-color); }
.toast.error { border-left-color: var(--error-color); }
.toast.info { border-left-color: var(--accent-color); }
.toast-icon {
  flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 800; color: #fff;
}
.toast.success .toast-icon { background: var(--success-color); }
.toast.error .toast-icon { background: var(--error-color); }
.toast.info .toast-icon { background: var(--accent-color); }
.toast-msg { flex: 1; font-size: 13px; line-height: 1.4; color: var(--text-primary); }
.toast-x { flex-shrink: 0; background: none; border: none; color: var(--text-secondary); font-size: 18px; line-height: 1; cursor: pointer; padding: 0 2px; }
.toast-x:hover { color: var(--text-primary); }

.toast-enter-active, .toast-leave-active { transition: opacity .2s ease, transform .2s ease; }
.toast-enter-from { opacity: 0; transform: translateX(20px); }
.toast-leave-to { opacity: 0; transform: translateX(20px); }
.toast-move { transition: transform .2s ease; }
</style>
