import { reactive } from 'vue';

// A lightweight guided product tour ("coach marks"). Each step points at a real
// element in the app shell (by CSS selector via a data-tour attribute) and shows
// a thought bubble explaining it. Steps with target: null render centred.
//
// Completion is stored per-user in localStorage so the tour shows once; the
// Profile page can clear it to replay the tour on demand.

export const TOUR_STEPS = [
  {
    target: null,
    title: 'Welcome to Spi 👋',
    body: 'Spi is your workbench for testing and monitoring APIs and AI agents across REST, GraphQL, MCP, A2A, gRPC, MQTT, AMQP and more. Take this 60-second tour of the essentials — you can skip any time.',
  },
  {
    target: '[data-tour="nav-tester"]',
    title: 'The Tester',
    body: 'Compose and send a single request over any protocol, then inspect the status, timing, headers and body that come back. This is where most work starts.',
  },
  {
    target: '[data-tour="nav-collections"]',
    title: 'Collections',
    body: 'Group saved requests into runnable suites. Chain steps, pass values between them, and run the whole flow with assertions in one click.',
  },
  {
    target: '[data-tour="nav-monitors"]',
    title: 'Monitors',
    body: 'Schedule a request or collection to run on a cadence, and get alerted when it breaks or an endpoint drifts from its contract.',
  },
  {
    target: '[data-tour="nav-reports"]',
    title: 'Reports',
    body: 'Every run, scan and check becomes an inspection report you can review — and share with a private link when you need a second pair of eyes.',
  },
  {
    target: '[data-tour="nav-chat"]',
    title: 'The Spi assistant',
    body: 'Stuck? Ask the built-in AI assistant for help composing requests, understanding responses, or figuring out a protocol.',
  },
  {
    target: '[data-tour="cmdk"]',
    title: 'Jump anywhere fast',
    body: 'Press ⌘K (Ctrl+K) any time to search and jump straight to a page, saved request or collection.',
  },
  {
    target: '[data-tour="theme"]',
    title: 'Light or dark',
    body: 'Toggle between light and dark themes here — your choice is remembered on this device.',
  },
  {
    target: '[data-tour="account"]',
    title: 'Your account',
    body: 'Manage your profile, create API keys for programmatic access, and — if you ever want to see this tour again — replay it from Profile → Personalisation.',
  },
  {
    target: null,
    title: 'You’re all set 🚀',
    body: 'That’s the tour. Full written guides live in the [documentation](/docs). Have fun building!',
  },
];

const keyFor = (userId) => `spi-onboarding-${userId ?? 'anon'}`;

export function hasCompletedTour(userId) {
  try {
    return localStorage.getItem(keyFor(userId)) === 'done';
  } catch {
    // If storage is unavailable, treat as completed so we never trap the user
    // in a tour that cannot be dismissed permanently.
    return true;
  }
}

function markCompleted(userId) {
  try {
    localStorage.setItem(keyFor(userId), 'done');
  } catch {
    /* storage unavailable — nothing to persist */
  }
}

export function clearTour(userId) {
  try {
    localStorage.removeItem(keyFor(userId));
  } catch {
    /* ignore */
  }
}

// Shared reactive controller consumed by OnboardingTour.vue.
export const tour = reactive({
  active: false,
  index: 0,
  userId: null,

  get step() {
    return TOUR_STEPS[this.index] || null;
  },
  get total() {
    return TOUR_STEPS.length;
  },
  get isFirst() {
    return this.index === 0;
  },
  get isLast() {
    return this.index === TOUR_STEPS.length - 1;
  },

  start(userId) {
    this.userId = userId ?? null;
    this.index = 0;
    this.active = true;
  },
  next() {
    if (this.isLast) {
      this.finish();
    } else {
      this.index += 1;
    }
  },
  prev() {
    if (!this.isFirst) this.index -= 1;
  },
  goTo(i) {
    if (i >= 0 && i < TOUR_STEPS.length) this.index = i;
  },
  // Skipping still counts as "seen" so it does not reappear on every load.
  finish() {
    markCompleted(this.userId);
    this.active = false;
    this.index = 0;
  },
});

// Start the tour for a user unless they have already completed it.
export function maybeStartTour(userId) {
  if (!hasCompletedTour(userId)) {
    tour.start(userId);
  }
}

// Force the tour to replay (used by the Profile reset button).
export function restartTour(userId) {
  clearTour(userId);
  tour.start(userId);
}
