<template>
  <div class="spark">
    <svg v-if="points.length > 1" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="spark-svg" role="img" :aria-label="ariaLabel">
      <!-- Area under the line -->
      <path :d="areaPath" class="spark-area" />
      <!-- The line -->
      <polyline :points="linePoints" class="spark-line" />
      <!-- Run markers, coloured by pass/fail -->
      <circle
        v-for="(p, i) in coords"
        :key="i"
        :cx="p.x" :cy="p.y" :r="i === coords.length - 1 ? 2.6 : 1.6"
        :class="['spark-dot', marks[i] === false ? 'fail' : 'ok']"
      />
    </svg>
    <div v-else class="spark-empty">Not enough runs to chart yet.</div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  values: { type: Array, default: () => [] },      // numeric series, oldest → newest
  marks: { type: Array, default: () => [] },        // per-point booleans: false = failed run
  ariaLabel: { type: String, default: 'Trend' },
});

const W = 300;
const H = 56;
const PAD = 4;

const points = computed(() => props.values.map((v) => Number(v) || 0));

const coords = computed(() => {
  const vals = points.value;
  if (vals.length < 2) return [];
  const min = Math.min(...vals);
  const max = Math.max(...vals);
  const span = max - min || 1;
  const stepX = (W - PAD * 2) / (vals.length - 1);
  return vals.map((v, i) => ({
    x: PAD + i * stepX,
    // Invert Y (SVG origin top-left); keep a little headroom.
    y: PAD + (H - PAD * 2) * (1 - (v - min) / span),
  }));
});

const linePoints = computed(() => coords.value.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));

const areaPath = computed(() => {
  const c = coords.value;
  if (!c.length) return '';
  const line = c.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
  return `${line} L${c[c.length - 1].x.toFixed(1)},${H} L${c[0].x.toFixed(1)},${H} Z`;
});
</script>

<style scoped>
.spark { width: 100%; }
.spark-svg { width: 100%; height: 56px; display: block; overflow: visible; }
.spark-line { fill: none; stroke: var(--accent-color); stroke-width: 1.5; vector-effect: non-scaling-stroke; }
.spark-area { fill: var(--accent-color); opacity: 0.1; stroke: none; }
.spark-dot.ok { fill: var(--accent-color); }
.spark-dot.fail { fill: var(--error-color); }
.spark-empty { font-size: 12px; color: var(--text-secondary); padding: 8px 0; }
</style>
