import './bootstrap';
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

createInertiaApp({
  title: (title) => (title ? `${title} - YouGo` : 'YouGo'),
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
    return pages[`./Pages/${name}.tsx`] as any;
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
