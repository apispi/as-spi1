<template>
  <transition name="cd-fade">
    <div v-if="confirmState.open" class="cd-overlay" @click.self="cancel">
      <div
        ref="box"
        class="cd-box"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="cd-title"
        aria-describedby="cd-message"
      >
        <h2 id="cd-title" class="cd-title">{{ confirmState.title }}</h2>
        <p id="cd-message" class="cd-message">{{ confirmState.message }}</p>
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
const box = ref(null);

// Where focus was before the dialog took it, so it can be handed back.
let previouslyFocused = null;

const ok = () => resolveConfirm(true);
const cancel = () => resolveConfirm(false);

// Focus the safe (cancel) button on open, and return focus to whatever opened
// the dialog on close — otherwise a keyboard user is dropped at the top of the
// document after every confirmation.
watch(() => confirmState.open, (open) => {
  if (open) {
    previouslyFocused = document.activeElement;
    nextTick(() => cancelBtn.value?.focus());

    return;
  }

  const target = previouslyFocused;
  previouslyFocused = null;
  if (target && typeof target.focus === 'function' && document.contains(target)) {
    target.focus();
  }
});

/** The dialog's own focusable controls, in tab order. */
const focusables = () =>
  Array.from(box.value?.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])') ?? []);

const onKey = (e) => {
  if (!confirmState.open) return;

  if (e.key === 'Escape') {
    e.preventDefault();
    cancel();

    return;
  }

  // Deliberately no Enter handler. Enter used to confirm regardless of what
  // was focused, with preventDefault stopping the focused button activating —
  // so on a delete prompt the dialog showed Cancel focused while Enter
  // deleted. A button activates on Enter natively, so letting that through
  // means Enter does what the focused control says it does.

  if (e.key !== 'Tab') return;

  // Keep Tab inside the dialog. Without this, focus walks out into the page
  // behind an aria-modal dialog, which is both confusing and a way to click
  // things that are supposed to be blocked.
  const items = focusables();
  if (items.length === 0) return;

  const first = items[0];
  const last = items[items.length - 1];

  if (e.shiftKey && document.activeElement === first) {
    e.preventDefault();
    last.focus();
  } else if (! e.shiftKey && document.activeElement === last) {
    e.preventDefault();
    first.focus();
  }
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
