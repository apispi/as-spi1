import { ref } from 'vue';

// Central light/dark theme control. The chosen theme is written as a
// `data-theme` attribute on <html>, which app.css keys its light-mode token
// overrides off. The choice persists in localStorage; with no stored choice we
// follow the OS preference so first-time visitors get their expected theme.

const STORAGE_KEY = 'spi-theme';

const systemPrefersLight = () =>
  typeof window !== 'undefined'
  && window.matchMedia
  && window.matchMedia('(prefers-color-scheme: light)').matches;

const stored = () => {
  try {
    return localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
};

// Reactive current theme, shared across every component that imports this.
export const theme = ref('dark');

function apply(next) {
  theme.value = next;
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('data-theme', next);
  }
}

// Resolve and apply the initial theme. Call once, as early as possible, to
// avoid a flash of the wrong theme on load.
export function initTheme() {
  const choice = stored() || (systemPrefersLight() ? 'light' : 'dark');
  apply(choice);
}

export function setTheme(next) {
  apply(next);
  try {
    localStorage.setItem(STORAGE_KEY, next);
  } catch {
    /* storage unavailable — the in-memory choice still applies */
  }
}

export function toggleTheme() {
  setTheme(theme.value === 'light' ? 'dark' : 'light');
}
