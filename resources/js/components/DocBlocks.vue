<template>
  <div class="doc-blocks">
    <template v-for="(block, i) in blocks" :key="i">
      <h2 v-if="block.type === 'h2'" :id="anchor(block.text)" class="doc-h2">{{ block.text }}</h2>
      <h3 v-else-if="block.type === 'h3'" :id="anchor(block.text)" class="doc-h3">{{ block.text }}</h3>
      <p v-else-if="block.type === 'p'" class="doc-p" v-html="inline(block.text)"></p>
      <div v-else-if="block.type === 'note'" class="doc-note" v-html="inline(block.text)"></div>
      <ul v-else-if="block.type === 'ul'" class="doc-ul">
        <li v-for="(item, j) in block.items" :key="j" v-html="inline(item)"></li>
      </ul>
      <ol v-else-if="block.type === 'ol'" class="doc-ol">
        <li v-for="(item, j) in block.items" :key="j" v-html="inline(item)"></li>
      </ol>
      <div v-else-if="block.type === 'code'" class="doc-code">
        <div class="doc-code-head">
          <span class="doc-code-lang">{{ block.lang || 'text' }}</span>
          <button class="doc-code-copy" @click="copy(block.code, i)">{{ copied === i ? 'Copied!' : 'Copy' }}</button>
        </div>
        <pre><code>{{ block.code }}</code></pre>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref } from 'vue';

defineProps({
  blocks: { type: Array, default: () => [] },
});

const copied = ref(null);

const copy = async (code, i) => {
  try {
    await navigator.clipboard.writeText(code);
    copied.value = i;
    setTimeout(() => { if (copied.value === i) copied.value = null; }, 1500);
  } catch {
    /* clipboard unavailable — ignore */
  }
};

const anchor = (text) => String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

// Escape HTML, then apply the three supported inline markers:
// **bold**, `code`, and [label](href).
const escapeHtml = (s) => String(s)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;');

const inline = (text) => {
  let out = escapeHtml(text);
  // Links: [label](href) — internal links become router-friendly anchors.
  out = out.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, label, href) => {
    const safeHref = href.replace(/"/g, '&quot;');
    const external = /^https?:\/\//i.test(href);
    const attrs = external ? ' target="_blank" rel="noopener"' : '';
    return `<a class="doc-link" href="${safeHref}"${attrs}>${label}</a>`;
  });
  out = out.replace(/`([^`]+)`/g, '<code class="doc-inline-code">$1</code>');
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  return out;
};
</script>

<style scoped>
.doc-h2 { font-size: 22px; font-weight: 700; margin: 36px 0 12px; scroll-margin-top: 80px; }
.doc-h3 { font-size: 17px; font-weight: 600; margin: 26px 0 10px; scroll-margin-top: 80px; }
.doc-p { line-height: 1.75; color: var(--text-primary); margin: 0 0 14px; }
.doc-ul, .doc-ol { margin: 0 0 16px; padding-left: 24px; }
.doc-ul li, .doc-ol li { line-height: 1.75; margin-bottom: 8px; color: var(--text-primary); }
.doc-note {
  border-left: 3px solid var(--accent-color);
  background: rgba(96, 165, 250, 0.08);
  padding: 12px 16px; border-radius: 0 8px 8px 0; margin: 0 0 16px;
  line-height: 1.7; color: var(--text-secondary); font-size: 14px;
}
.doc-code { margin: 0 0 18px; border: 1px solid var(--border-color, rgba(255,255,255,0.1)); border-radius: 10px; overflow: hidden; }
.doc-code-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 6px 12px; background: rgba(0,0,0,0.25);
  border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.08));
}
.doc-code-lang { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); }
.doc-code-copy {
  background: none; border: none; color: var(--accent-color);
  font-size: 12px; cursor: pointer; font-family: inherit; padding: 2px 6px;
}
.doc-code pre { margin: 0; padding: 14px 16px; overflow-x: auto; }
.doc-code code { font-family: 'Courier New', monospace; font-size: 13px; color: #e5e7eb; white-space: pre; }
.doc-inline-code {
  font-family: monospace; font-size: 0.88em; color: var(--accent-color);
  background: rgba(0,0,0,0.28); padding: 0.1em 0.35em; border-radius: 4px;
}
.doc-link { color: var(--accent-color); text-decoration: none; }
.doc-link:hover { text-decoration: underline; }
</style>
