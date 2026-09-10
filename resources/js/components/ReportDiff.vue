<template>
  <div class="rd">
    <div v-if="headline" class="rd-headline">{{ headline }}</div>
    <div class="rd-summary">
      <span class="rd-chip" :class="diff.regressed_count ? 'bad' : 'muted'">{{ diff.regressed_count }} regressed</span>
      <span class="rd-chip" :class="diff.fixed_count ? 'ok' : 'muted'">{{ diff.fixed_count }} fixed</span>
      <span class="rd-chip muted">{{ fmtMs(diff.time_a_ms) }} → {{ fmtMs(diff.time_b_ms) }}</span>
    </div>
    <table class="rd-table">
      <thead><tr><th>Step</th><th>A</th><th>B</th><th>Δ time</th><th></th></tr></thead>
      <tbody>
        <tr v-for="s in diff.steps" :key="s.index" :class="'v-' + s.verdict">
          <td>{{ s.name }}</td>
          <td><span v-if="s.status_a != null" class="rd-st" :class="s.status_a < 400 ? 'ok' : 'bad'">{{ s.status_a }}</span><span v-else>—</span></td>
          <td><span v-if="s.status_b != null" class="rd-st" :class="s.status_b < 400 ? 'ok' : 'bad'">{{ s.status_b }}</span><span v-else>—</span></td>
          <td class="rd-delta" :class="deltaClass(s.time_delta_ms)">{{ s.time_delta_ms != null ? signed(s.time_delta_ms) : '' }}</td>
          <td><span class="rd-verdict" :class="s.verdict">{{ verdictLabel(s.verdict) }}</span></td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
defineProps({
  diff: { type: Object, required: true },
  headline: { type: String, default: '' },
});

const fmtMs = (ms) => (ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : (ms || 0) + 'ms');
const signed = (ms) => (ms > 0 ? '+' : '') + fmtMs(ms);
const deltaClass = (ms) => (ms > 20 ? 'slower' : ms < -20 ? 'faster' : '');
const verdictLabel = (v) => ({ regressed: 'Regressed', fixed: 'Fixed', unchanged: '—', added: 'Added', removed: 'Removed' }[v] || v);
</script>

<style scoped>
.rd-headline { font-weight: 700; color: var(--text-primary); margin-bottom: 10px; }
.rd-summary { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.rd-chip { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: rgba(127,127,127,.14); color: var(--text-secondary); }
.rd-chip.bad { background: rgba(248,81,73,.16); color: #f85149; }
.rd-chip.ok { background: rgba(63,185,80,.16); color: #3fb950; }
.rd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rd-table th { text-align: left; color: var(--text-secondary); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; padding: 6px 10px; border-bottom: 1px solid var(--border-color); }
.rd-table td { padding: 8px 10px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); }
.rd-table tr.v-regressed { background: rgba(248,81,73,.06); }
.rd-table tr.v-fixed { background: rgba(63,185,80,.06); }
.rd-st { font-family: 'Courier New', monospace; font-weight: 700; }
.rd-st.ok { color: #3fb950; } .rd-st.bad { color: #f85149; }
.rd-delta { font-family: 'Courier New', monospace; color: var(--text-secondary); }
.rd-delta.slower { color: #d29922; } .rd-delta.faster { color: #3fb950; }
.rd-verdict { font-size: 10.5px; font-weight: 700; text-transform: uppercase; padding: 2px 7px; border-radius: 5px; background: rgba(127,127,127,.14); color: var(--text-secondary); }
.rd-verdict.regressed { background: rgba(248,81,73,.16); color: #f85149; }
.rd-verdict.fixed { background: rgba(63,185,80,.16); color: #3fb950; }
.rd-verdict.added, .rd-verdict.removed { background: rgba(210,153,34,.16); color: #d29922; }
</style>
