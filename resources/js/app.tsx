import './bootstrap';
import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

createInertiaApp({
  title: (title) => (title ? `${title} - YouGo` : 'YouGo'),
  resolve: (name) => {
    const pages = import.meta.glob<{ default: ResolvedComponent }>('./Pages/**/*.tsx');
    const page = pages[`./Pages/${name}.tsx`];

    if (!page) {
      throw new Error(`Page not found: ${name}`);
    }

    return page().then((module) => module.default);
  },
  setup({ el, App, props }) {
    createRoot(el).render(
      <>
        <App {...props} />
        <Toaster richColors position="top-center" />
      </>,
    );
  },
  progress: {
    color: '#4f46e5',
  },
});
