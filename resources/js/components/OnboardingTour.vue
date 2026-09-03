<template>
  <transition name="tour-fade">
    <div v-if="tour.active && tour.step" class="tour-root" role="dialog" aria-modal="true" aria-label="Product tour">
      <!-- Dimmer + spotlight. A single element whose huge box-shadow darkens
           everything except the cut-out over the target. -->
      <div
        class="tour-spotlight"
        :class="{ 'no-target': !rect }"
        :style="spotlightStyle"
        @click="skip"
      ></div>

      <!-- Thought bubble -->
      <div ref="bubble" class="tour-bubble" :class="placementClass" :style="bubbleStyle">
        <div class="tour-progress">Step {{ tour.index + 1 }} of {{ tour.total }}</div>
        <h3 class="tour-title">{{ tour.step.title }}</h3>
        <p class="tour-body" v-html="renderBody(tour.step.body)"></p>

        <div class="tour-dots">
          <button
            v-for="(s, i) in tour.total"
            :key="i"
            class="tour-dot"
            :class="{ active: i === tour.index }"
            :aria-label="`Go to step ${i + 1}`"
            @click="tour.goTo(i)"
          ></button>
        </div>

        <div class="tour-actions">
          <button class="tour-skip" @click="skip">Skip</button>
          <div class="tour-nav">
            <button v-if="!tour.isFirst" class="tour-btn ghost" @click="tour.prev()">Back</button>
            <button class="tour-btn primary" @click="tour.next()">{{ tour.isLast ? 'Finish' : 'Next' }}</button>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { tour } from '../onboarding';

const rect = ref(null);          // target element's bounding rect (or null)
const bubble = ref(null);
const placement = ref('center'); // 'center' | 'right' | 'below' | 'above'
const GAP = 14;                  // px between target and bubble
const PAD = 8;                   // spotlight padding around target

const measure = async () => {
  const step = tour.step;
  if (!step) return;
  if (!step.target) {
    rect.value = null;
    placement.value = 'center';
    return;
  }
  await nextTick();
  const el = document.querySelector(step.target);
  if (!el) {
    // Target missing (e.g. collapsed sidebar on mobile) — fall back to centre.
    rect.value = null;
    placement.value = 'center';
    return;
  }
  el.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
  const r = el.getBoundingClientRect();
  rect.value = { top: r.top, left: r.left, width: r.width, height: r.height, bottom: r.bottom, right: r.right };
  // Sidebar items sit on the left → bubble to their right; topbar items sit
  // near the top → bubble below; otherwise below.
  if (r.left < 260 && r.top > 80) placement.value = 'right';
  else if (r.top < 90) placement.value = 'below';
  else placement.value = 'below';
};

const spotlightStyle = computed(() => {
  if (!rect.value) return {};
  return {
    top: `${rect.value.top - PAD}px`,
    left: `${rect.value.left - PAD}px`,
    width: `${rect.value.width + PAD * 2}px`,
    height: `${rect.value.height + PAD * 2}px`,
  };
});

const bubbleStyle = computed(() => {
  const W = 340;
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  if (!rect.value) {
    // Centred card.
    return { top: '50%', left: '50%', transform: 'translate(-50%, -50%)', width: `${W}px` };
  }
  const r = rect.value;
  let top;
  let left;
  if (placement.value === 'right') {
    left = r.right + GAP;
    top = Math.max(12, r.top - 8);
  } else if (placement.value === 'above') {
    left = Math.min(Math.max(12, r.left), vw - W - 12);
    top = r.top - GAP - 180;
  } else {
    // below
    left = Math.min(Math.max(12, r.left), vw - W - 12);
    top = r.bottom + GAP;
  }
  // Clamp horizontally so a right-placed bubble never overflows.
  if (left + W > vw - 12) {
    left = Math.max(12, r.left - W - GAP); // flip to the left of the target
  }
  // Clamp vertically.
  if (top + 220 > vh) top = Math.max(12, vh - 240);
  return { top: `${top}px`, left: `${left}px`, width: `${W}px` };
});

const placementClass = computed(() => `place-${rect.value ? placement.value : 'center'}`);

const renderBody = (text) => {
  const esc = String(text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return esc
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a class="tour-link" href="$2">$1</a>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
};

const skip = () => tour.finish();

const onKey = (e) => {
  if (!tour.active) return;
  if (e.key === 'Escape') skip();
  else if (e.key === 'ArrowRight' || e.key === 'Enter') tour.next();
  else if (e.key === 'ArrowLeft') tour.prev();
};

const reflow = () => measure();

watch(() => tour.index, measure);
watch(() => tour.active, (v) => { if (v) measure(); });

onMounted(() => {
  window.addEventListener('resize', reflow);
  window.addEventListener('scroll', reflow, true);
  window.addEventListener('keydown', onKey);
  if (tour.active) measure();
});
onUnmounted(() => {
  window.removeEventListener('resize', reflow);
  window.removeEventListener('scroll', reflow, true);
  window.removeEventListener('keydown', onKey);
});
</script>

<style scoped>
.tour-root { position: fixed; inset: 0; z-index: 10000; }

/* Spotlight: the box-shadow spread dims the whole viewport; the element itself
   is the transparent cut-out over the highlighted target. */
.tour-spotlight {
  position: fixed;
  border-radius: 10px;
  box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.62);
  outline: 2px solid var(--accent-color);
  outline-offset: 2px;
  transition: top 0.25s ease, left 0.25s ease, width 0.25s ease, height 0.25s ease;
  pointer-events: auto;
}
/* No target → dim the whole screen with a plain scrim. */
.tour-spotlight.no-target {
  inset: 0; width: 100%; height: 100%; border-radius: 0;
  box-shadow: none; background: rgba(0, 0, 0, 0.62); outline: none;
}

.tour-bubble {
  position: fixed;
  background: var(--bg-elevated, #1c2129);
  color: var(--text-primary);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 18px 18px 14px;
  box-shadow: 0 18px 48px var(--shadow-color, rgba(0,0,0,0.5));
  animation: tour-pop 0.22s ease;
}
@keyframes tour-pop { from { opacity: 0; transform: translateY(6px) scale(0.98); } }
.place-center { animation: none; }

/* Thought-bubble tail */
.tour-bubble::before,
.tour-bubble::after { content: ''; position: absolute; width: 0; height: 0; }
.place-right::before {
  left: -9px; top: 22px;
  border-top: 9px solid transparent; border-bottom: 9px solid transparent;
  border-right: 9px solid var(--border-color);
}
.place-right::after {
  left: -7px; top: 23px;
  border-top: 8px solid transparent; border-bottom: 8px solid transparent;
  border-right: 8px solid var(--bg-elevated, #1c2129);
}
.place-below::before {
  top: -9px; left: 26px;
  border-left: 9px solid transparent; border-right: 9px solid transparent;
  border-bottom: 9px solid var(--border-color);
}
.place-below::after {
  top: -7px; left: 27px;
  border-left: 8px solid transparent; border-right: 8px solid transparent;
  border-bottom: 8px solid var(--bg-elevated, #1c2129);
}

.tour-progress { font-size: 11px; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-secondary); margin-bottom: 6px; }
.tour-title { font-size: 17px; font-weight: 700; margin: 0 0 6px; color: var(--text-primary); }
.tour-body { font-size: 14px; line-height: 1.6; color: var(--text-secondary); margin: 0 0 14px; }
.tour-body :deep(strong) { color: var(--text-primary); }
.tour-link { color: var(--accent-color); text-decoration: none; }

.tour-dots { display: flex; gap: 6px; margin-bottom: 14px; }
.tour-dot { width: 7px; height: 7px; padding: 0; border-radius: 50%; border: none; background: var(--border-color); cursor: pointer; }
.tour-dot.active { background: var(--accent-color); }

.tour-actions { display: flex; align-items: center; justify-content: space-between; }
.tour-nav { display: flex; gap: 8px; }
.tour-skip { background: none; border: none; color: var(--text-secondary); font-size: 13px; cursor: pointer; padding: 6px 4px; }
.tour-skip:hover { color: var(--text-primary); }
.tour-btn { padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.tour-btn.ghost { background: transparent; border-color: var(--border-color); color: var(--text-primary); }
.tour-btn.ghost:hover { border-color: var(--text-secondary); }
.tour-btn.primary { background: var(--accent-color); color: #fff; }
.tour-btn.primary:hover { background: var(--accent-hover); }

.tour-fade-enter-active, .tour-fade-leave-active { transition: opacity 0.2s ease; }
.tour-fade-enter-from, .tour-fade-leave-to { opacity: 0; }
</style>
