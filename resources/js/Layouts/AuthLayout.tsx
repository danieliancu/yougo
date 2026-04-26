import { Head, Link } from '@inertiajs/react';
import { ReactNode } from 'react';
import { Sparkles } from 'lucide-react';
import { ThemeToggle } from '@/Components/Ui';

export default function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <main className="flex min-h-screen items-center justify-center p-6 app-bg">
      <Head title={title} />
      <section className="w-full max-w-md rounded-lg border p-8 shadow-sm app-panel">
        <div className="mb-8 flex items-center justify-between">
          <Link href="/" className="flex items-center">
            <img src="/images/logo-white.png" className="h-12 w-auto dark:hidden" alt="YouGo" />
            <img src="/images/logo-dark.png" className="hidden h-12 w-auto dark:block" alt="YouGo" />
          </Link>
          <ThemeToggle />
        </div>
        {children}
      </section>
    </main>
  );
}
