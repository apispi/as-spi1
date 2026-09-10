import { reactive } from 'vue';

// A promise-based confirm dialog replacing the native, un-themed confirm().
// Usage:  if (!(await confirmDialog('Delete this?'))) return;
// or with options: confirmDialog('Delete this?', { confirmText: 'Delete', danger: true })

export const confirmState = reactive({
  open: false,
  title: 'Please confirm',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  danger: true, // these prompts are almost always destructive
  _resolve: null,
});

export function confirmDialog(message, opts = {}) {
  confirmState.message = message;
  confirmState.title = opts.title ?? 'Please confirm';
  confirmState.confirmText = opts.confirmText ?? 'Confirm';
  confirmState.cancelText = opts.cancelText ?? 'Cancel';
  confirmState.danger = opts.danger ?? true;
  confirmState.open = true;

  return new Promise((resolve) => { confirmState._resolve = resolve; });
}

export function resolveConfirm(result) {
  confirmState.open = false;
  const r = confirmState._resolve;
  confirmState._resolve = null;
  if (r) r(result);
}
