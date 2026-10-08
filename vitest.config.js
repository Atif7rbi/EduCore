import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        react(),
        tailwindcss(),
    ],
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: [
            './resources/frontend/test/setup.ts',
        ],
    },
});
