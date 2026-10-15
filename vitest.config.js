import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

/**
 * Front-end tests, kept separate from the Vite build config so the two cannot
 * interfere. The PHP suite covers the server; this covers the browser logic
 * that had no coverage at all — and where the mistakes this file exists to
 * catch were previously only found by reading the diff.
 */
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        // Vue's SFC compiler needs this when components are mounted.
        globals: true,
    },
});
