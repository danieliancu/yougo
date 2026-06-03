import { Head } from '@inertiajs/react';
import { IssueList, PageHeader, PhoneAiPlannedNotice, PlatformAdminLayout } from './Components';

type Props = {
  payload: Record<string, any>;
};

export default function Issues({ payload }: Props) {
  return (
    <PlatformAdminLayout page="issues">
      <Head title="Platform Admin Issues" />
      <PageHeader title="Issues" subtitle="Read-only operational queues for onboarding gaps, delivery failures, missing emails, usage warnings, and failed jobs." />
      <IssueList title="WhatsApp activation requested" items={payload.whatsapp_requested} />
      <IssueList title="WhatsApp active but AI disabled" items={payload.active_ai_disabled} />
      <IssueList title="WhatsApp active but no sender" items={payload.active_missing_sender} />
      <IssueList title="Recent WhatsApp failed or undelivered messages" items={payload.failed_whatsapp_messages} />
      <IssueList title="Business has no notification email" items={payload.missing_notification_email} />
      <IssueList title="Usage over 80% or limit reached" items={payload.usage_near_limits} />
      <IssueList title="Failed queue jobs" items={payload.failed_jobs} />
      <PhoneAiPlannedNotice />
    </PlatformAdminLayout>
  );
}
