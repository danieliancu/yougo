import { Head } from '@inertiajs/react';
import { Toaster } from 'sonner';
import { AssistantWidget } from '@/Components/AssistantWidget';
import { Salon } from '@/types';

export default function WidgetShow({ salon, locale = 'ro', chatEndpoint }: { salon: Salon; locale?: string; chatEndpoint: string }) {
  const name = salon.ai_assistant_name?.trim() || 'Bella';

  return (
    <main className="h-screen overflow-hidden app-bg">
      <Head title={`${name} - ${salon.name}`} />
      <AssistantWidget
        salon={salon}
        locale={locale}
        chatEndpoint={chatEndpoint}
        storageKey={`widget:${salon.widget_key ?? salon.id}`}
        compact
        primaryColor={salon.widget_primary_color}
      />
      <Toaster richColors position="top-center" />
    </main>
  );
}
