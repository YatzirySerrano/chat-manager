import { defineConfig } from 'vite-plus';

/**
 * Build of the embeddable web chat loader: one dependency-free IIFE that
 * exposes window.ChatManager, at a stable, unhashed URL
 * (public/widget/v1/chat-manager.js) that host sites reference from their
 * snippet. No Laravel, Inertia, Wayfinder or Vue plugins: the visitor's UI
 * lives in the iframe (resources/js/widget/frame, built by vite.config.ts).
 * The /v1/ path only changes with incompatible API changes.
 */
export default defineConfig({
    publicDir: false,
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        target: 'es2019',
        outDir: 'public/widget/v1',
        emptyOutDir: true,
        copyPublicDir: false,
        sourcemap: false,
        minify: true,
        lib: {
            entry: 'resources/js/widget/loader.ts',
            formats: ['iife'],
            name: 'ChatManager',
            fileName: () => 'chat-manager.js',
        },
    },
});
