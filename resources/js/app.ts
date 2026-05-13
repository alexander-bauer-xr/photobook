import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    strictMode: true,
    pages: {
        path: './pages',
        extension: '.tsx',
    },
    title: (title) => title ? `${title} - Photobook` : 'Photobook',
});
