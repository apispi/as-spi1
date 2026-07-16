<template>
  <main class="cat-content">
    <div class="cat-header">
      <h1 class="cat-title">{{ sectionLabel }}</h1>
      <p class="cat-sub">{{ sectionSub }}</p>
    </div>

    <div class="cat-tabs">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        :class="['cat-tab', { active: activeTab === tab.key }]"
        @click="activeTab = tab.key"
      >
        <span class="cat-tab-icon">{{ tab.icon }}</span>
        {{ sectionLabel }} {{ tab.label }}
        <span class="cat-tab-count">{{ counts[tab.key] ?? 0 }}</span>
      </button>
    </div>

    <div class="cat-panel">
      <div class="cat-panel-head">
        <div>
          <h2 class="cat-panel-title">{{ sectionLabel }} {{ currentTab.label }}</h2>
          <p class="cat-panel-sub">{{ currentTab.description }}</p>
        </div>
      </div>

      <div class="cat-empty">
        <div class="cat-empty-icon">{{ currentTab.icon }}</div>
        <p class="cat-empty-title">No {{ currentTab.label.toLowerCase() }} {{ mode === 'active' ? 'active' : 'in the catalog' }} yet</p>
        <p class="cat-empty-hint">{{ emptyHint }}</p>
      </div>
    </div>
  </main>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();

// The section (catalog | active) comes from the route meta so a single
// component backs both routes.
const mode = computed(() => route.meta.section || 'catalog');

const tabs = [
  { key: 'agents', label: 'Agents', icon: '◆', description: 'Autonomous agents that orchestrate tools and skills to complete tasks.' },
  { key: 'skills', label: 'Skills', icon: '✦', description: 'Reusable capabilities an agent can invoke.' },
  { key: 'connectors', label: 'Connectors', icon: '⇄', description: 'Integrations that link agents to external systems and data.' },
  { key: 'tools', label: 'Tools', icon: '⚙', description: 'Individual MCP/A2A tools callable by agents.' },
  { key: 'prompts', label: 'Prompts', icon: '❝', description: 'Prompt templates available to agents and skills.' },
];

const activeTab = ref('agents');

// Counts are placeholders until backing data exists.
const counts = ref({ agents: 0, skills: 0, connectors: 0, tools: 0, prompts: 0 });

const sectionLabel = computed(() => (mode.value === 'active' ? 'Active' : 'Catalog'));

const sectionSub = computed(() =>
  mode.value === 'active'
    ? 'Everything currently enabled in your workspace.'
    : 'Browse everything available to add to your workspace.'
);

const currentTab = computed(() => tabs.find((t) => t.key === activeTab.value) || tabs[0]);

const emptyHint = computed(() =>
  mode.value === 'active'
    ? `Activate ${currentTab.value.label.toLowerCase()} from the Catalog to see them here.`
    : `${currentTab.value.label} added to the catalog will appear here.`
);
</script>

<style scoped>
.cat-content { padding: 2rem 2.5rem; }

.cat-header { margin-bottom: 1.5rem; }
.cat-title { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); }
.cat-sub { font-size: 0.9rem; color: var(--text-secondary); margin-top: 0.25rem; }

.cat-tabs {
  display: flex; gap: 0.35rem; flex-wrap: wrap; margin-bottom: 1.5rem;
}
.cat-tab {
  display: flex; align-items: center; gap: 0.45rem;
  padding: 0.55rem 1rem; border-radius: 0.5rem;
  border: 1px solid var(--border-color);
  background: var(--panel-bg); cursor: pointer;
  font-family: inherit; font-size: 0.82rem; font-weight: 600;
  color: var(--text-secondary); transition: all 0.15s; white-space: nowrap;
}
.cat-tab:hover { color: var(--text-primary); border-color: var(--accent-color); }
.cat-tab.active { color: var(--accent-color); border-color: var(--accent-color); background: rgba(88, 166, 255, 0.1); }
.cat-tab-icon { font-size: 0.9rem; }
.cat-tab-count {
  font-size: 0.7rem; font-weight: 700; padding: 0.05rem 0.4rem;
  border-radius: 999px; background: var(--bg-color); color: var(--text-secondary);
}

.cat-panel {
  background: var(--panel-bg); border: 1px solid var(--border-color);
  border-radius: 1rem; padding: 1.5rem;
}
.cat-panel-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color);
}
.cat-panel-title { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); }
.cat-panel-sub { font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.25rem; }

.cat-empty { text-align: center; padding: 3rem 1rem; }
.cat-empty-icon { font-size: 2.5rem; opacity: 0.4; margin-bottom: 0.75rem; }
.cat-empty-title { font-size: 0.95rem; font-weight: 600; color: var(--text-primary); }
.cat-empty-hint { font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.35rem; }

@media (max-width: 640px) {
  .cat-content { padding: 1rem; }
}
</style>
