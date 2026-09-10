<template>
  <div class="sk" :aria-busy="true" aria-label="Loading">
    <div v-for="n in rows" :key="n" class="sk-row" :style="{ width: widthFor(n) }"></div>
  </div>
</template>

<script setup>
const props = defineProps({
  rows: { type: Number, default: 4 },
});

// Slightly varied widths so the placeholder reads as content, not a table.
const WIDTHS = ['100%', '92%', '96%', '85%', '98%', '90%'];
const widthFor = (n) => WIDTHS[(n - 1) % WIDTHS.length];
</script>

<style scoped>
.sk { display: flex; flex-direction: column; gap: 10px; padding: 4px 0; }
.sk-row {
  height: 44px; border-radius: 10px;
  background: var(--panel-bg); /* fallback if color-mix is unsupported */
  background: linear-gradient(90deg,
    var(--panel-bg) 25%,
    color-mix(in srgb, var(--panel-bg) 60%, var(--border-color)) 37%,
    var(--panel-bg) 63%);
  background-size: 400% 100%;
  animation: sk-shimmer 1.3s ease-in-out infinite;
}
@keyframes sk-shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}
@media (prefers-reduced-motion: reduce) {
  .sk-row { animation: none; opacity: .7; }
}
</style>
