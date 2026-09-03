<template>
  <div class="docs-page">
    <!-- Top bar -->
    <header class="docs-topbar">
      <router-link to="/" class="docs-brand">
        <svg viewBox="0 0 24 24" width="22" height="22" stroke="currentColor" stroke-width="2" fill="none"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        <span>Spi Docs</span>
      </router-link>
      <div class="docs-search">
        <input v-model="query" type="search" placeholder="Search the docs…" aria-label="Search documentation">
      </div>
      <router-link to="/developers" class="docs-topbar-link">API Reference →</router-link>
    </header>

    <div class="docs-body">
      <!-- Sidebar -->
      <aside class="docs-sidebar">
        <nav>
          <div v-for="cat in categories" :key="cat.id" class="docs-nav-group">
            <div class="docs-nav-cat">{{ cat.label }}</div>
            <router-link
              v-for="doc in docsInCategory(cat.id)"
              :key="doc.slug"
              :to="`/docs/${doc.slug}`"
              class="docs-nav-link"
              :class="{ active: doc.slug === activeSlug }"
            >{{ doc.title }}</router-link>
          </div>
        </nav>
      </aside>

      <!-- Content -->
      <main class="docs-content" @click="onContentClick">
        <!-- Search results -->
        <template v-if="query.trim()">
          <h1 class="docs-title">Search</h1>
          <p class="docs-lead">{{ results.length }} result{{ results.length === 1 ? '' : 's' }} for “{{ query.trim() }}”.</p>
          <ul class="docs-index-list">
            <li v-for="doc in results" :key="doc.slug">
              <router-link :to="`/docs/${doc.slug}`" class="docs-index-card">
                <span class="docs-index-cat">{{ categoryLabel(doc.category) }}</span>
                <span class="docs-index-name">{{ doc.title }}</span>
                <span class="docs-index-sum">{{ doc.summary }}</span>
              </router-link>
            </li>
          </ul>
          <p v-if="!results.length" class="docs-empty">No matching articles. Try another term.</p>
        </template>

        <!-- Single article -->
        <template v-else-if="activeDoc">
          <div class="docs-crumb">
            <router-link to="/docs">Docs</router-link>
            <span>/</span>
            <span>{{ categoryLabel(activeDoc.category) }}</span>
          </div>
          <h1 class="docs-title">{{ activeDoc.title }}</h1>
          <p class="docs-lead">{{ activeDoc.summary }}</p>
          <DocBlocks :blocks="activeDoc.body" />

          <nav class="docs-pager">
            <router-link v-if="prevDoc" :to="`/docs/${prevDoc.slug}`" class="docs-pager-link prev">
              <span class="docs-pager-dir">← Previous</span>
              <span class="docs-pager-title">{{ prevDoc.title }}</span>
            </router-link>
            <span v-else></span>
            <router-link v-if="nextDoc" :to="`/docs/${nextDoc.slug}`" class="docs-pager-link next">
              <span class="docs-pager-dir">Next →</span>
              <span class="docs-pager-title">{{ nextDoc.title }}</span>
            </router-link>
          </nav>
        </template>

        <!-- Missing slug -->
        <template v-else-if="activeSlug">
          <h1 class="docs-title">Not found</h1>
          <p class="docs-lead">That document doesn’t exist. <router-link class="doc-link" to="/docs">Back to all docs</router-link>.</p>
        </template>

        <!-- Index landing -->
        <template v-else>
          <h1 class="docs-title">Spi documentation</h1>
          <p class="docs-lead">Everything you need to test, monitor, and automate APIs and MCP agents with Spi. Pick a topic below or search above.</p>
          <div v-for="cat in categories" :key="cat.id" class="docs-index-group">
            <h2 class="docs-index-heading">{{ cat.label }}</h2>
            <ul class="docs-index-list">
              <li v-for="doc in docsInCategory(cat.id)" :key="doc.slug">
                <router-link :to="`/docs/${doc.slug}`" class="docs-index-card">
                  <span class="docs-index-name">{{ doc.title }}</span>
                  <span class="docs-index-sum">{{ doc.summary }}</span>
                </router-link>
              </li>
            </ul>
          </div>
        </template>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DOCS, CATEGORIES } from '../docs/content';
import DocBlocks from '../components/DocBlocks.vue';
import { ref } from 'vue';

const route = useRoute();
const router = useRouter();
const query = ref('');

const categories = CATEGORIES;
const activeSlug = computed(() => route.params.slug || '');
const activeDoc = computed(() => DOCS.find((d) => d.slug === activeSlug.value) || null);

const docsInCategory = (id) => DOCS.filter((d) => d.category === id);
const categoryLabel = (id) => CATEGORIES.find((c) => c.id === id)?.label || id;

// Flat order (as declared) drives prev/next paging.
const orderedIndex = computed(() => DOCS.findIndex((d) => d.slug === activeSlug.value));
const prevDoc = computed(() => (orderedIndex.value > 0 ? DOCS[orderedIndex.value - 1] : null));
const nextDoc = computed(() => (orderedIndex.value >= 0 && orderedIndex.value < DOCS.length - 1 ? DOCS[orderedIndex.value + 1] : null));

const results = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return [];
  return DOCS.filter((d) => {
    const hay = (d.title + ' ' + d.summary + ' ' + JSON.stringify(d.body)).toLowerCase();
    return hay.includes(q);
  });
});

// Scroll to top when navigating between articles.
watch(activeSlug, () => {
  query.value = '';
  window.scrollTo?.({ top: 0 });
});

// Intercept internal links inside rendered doc content so they route via
// vue-router instead of doing a full page reload.
const onContentClick = (e) => {
  const a = e.target.closest?.('a.doc-link');
  if (!a) return;
  const href = a.getAttribute('href') || '';
  if (href.startsWith('/') && !a.getAttribute('target')) {
    e.preventDefault();
    router.push(href);
  }
};
</script>

<style scoped>
.docs-page { min-height: 100%; background: var(--bg-color); color: var(--text-primary); display: flex; flex-direction: column; }

.docs-topbar {
  display: flex; align-items: center; gap: 24px;
  padding: 14px 24px; border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.1));
  position: sticky; top: 0; background: var(--bg-color); z-index: 10;
}
.docs-brand { display: flex; align-items: center; gap: 8px; color: var(--text-primary); text-decoration: none; font-weight: 700; font-size: 16px; }
.docs-brand svg { color: var(--accent-color); }
.docs-search { flex: 1; max-width: 480px; }
.docs-search input {
  width: 100%; padding: 8px 14px; border-radius: 8px;
  background: rgba(0,0,0,0.25); border: 1px solid var(--border-color, rgba(255,255,255,0.12));
  color: var(--text-primary); font-size: 14px; font-family: inherit;
}
.docs-topbar-link { color: var(--accent-color); text-decoration: none; font-size: 14px; white-space: nowrap; }

.docs-body { display: flex; flex: 1; max-width: 1180px; width: 100%; margin: 0 auto; }

.docs-sidebar {
  width: 240px; flex-shrink: 0; padding: 28px 16px 60px;
  border-right: 1px solid var(--border-color, rgba(255,255,255,0.08));
  position: sticky; top: 61px; align-self: flex-start; max-height: calc(100vh - 61px); overflow-y: auto;
}
.docs-nav-group { margin-bottom: 22px; }
.docs-nav-cat { font-size: 11px; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-secondary); margin-bottom: 8px; padding-left: 10px; }
.docs-nav-link {
  display: block; padding: 6px 10px; border-radius: 6px;
  color: var(--text-secondary); text-decoration: none; font-size: 14px; line-height: 1.4;
}
.docs-nav-link:hover { color: var(--text-primary); background: rgba(255,255,255,0.04); }
.docs-nav-link.active { color: var(--accent-color); background: rgba(96,165,250,0.1); font-weight: 600; }

.docs-content { flex: 1; min-width: 0; padding: 32px 40px 100px; max-width: 800px; }

.docs-crumb { display: flex; gap: 8px; align-items: center; font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; }
.docs-crumb a { color: var(--accent-color); text-decoration: none; }
.docs-title { font-size: 32px; font-weight: 700; margin: 0 0 8px; }
.docs-lead { font-size: 17px; color: var(--text-secondary); line-height: 1.6; margin: 0 0 24px; }
.docs-empty { color: var(--text-secondary); }

.docs-index-group { margin-bottom: 34px; }
.docs-index-heading { font-size: 18px; font-weight: 600; margin: 0 0 14px; }
.docs-index-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.docs-index-card {
  display: flex; flex-direction: column; gap: 4px; height: 100%;
  padding: 14px 16px; border-radius: 10px; text-decoration: none;
  border: 1px solid var(--border-color, rgba(255,255,255,0.1));
  background: rgba(255,255,255,0.02); transition: border-color 0.15s, background 0.15s;
}
.docs-index-card:hover { border-color: var(--accent-color); background: rgba(96,165,250,0.06); }
.docs-index-cat { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-secondary); }
.docs-index-name { font-size: 15px; font-weight: 600; color: var(--text-primary); }
.docs-index-sum { font-size: 13px; color: var(--text-secondary); line-height: 1.5; }

.docs-pager { display: flex; justify-content: space-between; gap: 16px; margin-top: 48px; padding-top: 24px; border-top: 1px solid var(--border-color, rgba(255,255,255,0.1)); }
.docs-pager-link { display: flex; flex-direction: column; gap: 4px; text-decoration: none; padding: 12px 16px; border-radius: 10px; border: 1px solid var(--border-color, rgba(255,255,255,0.1)); max-width: 48%; }
.docs-pager-link.next { text-align: right; margin-left: auto; }
.docs-pager-link:hover { border-color: var(--accent-color); }
.docs-pager-dir { font-size: 12px; color: var(--text-secondary); }
.docs-pager-title { font-size: 14px; font-weight: 600; color: var(--text-primary); }

.doc-link { color: var(--accent-color); text-decoration: none; }

@media (max-width: 820px) {
  .docs-sidebar { display: none; }
  .docs-content { padding: 24px 20px 80px; }
  .docs-search { max-width: none; }
}
</style>
