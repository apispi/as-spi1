import { reactive } from 'vue';

// A tiny global toast system: non-blocking feedback shown top-right, replacing
// scattered alert() calls and easily-missed inline error strings. Import the
// helpers anywhere; the single ToastHost (mounted in App.vue) renders them.

let nextId = 1;

export const toastState = reactive({ items: [] });

function push(type, message, timeout = 4000) {
  if (!message) return;
  const id = nextId++;
  toastState.items.push({ id, type, message });
  if (timeout > 0) {
    setTimeout(() => dismiss(id), timeout);
  }
  return id;
}

export function dismiss(id) {
  const i = toastState.items.findIndex((t) => t.id === id);
  if (i !== -1) toastState.items.splice(i, 1);
}

export const toast = {
  success: (m, t) => push('success', m, t),
  error: (m, t) => push('error', m, t ?? 6000), // errors linger a little longer
  info: (m, t) => push('info', m, t),
};
