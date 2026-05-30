import { OfferedService, Plan } from '@/types';

type TranslateFn = (key: string, params?: Record<string, string | number>) => string;

export function serviceByKey(services: OfferedService[], key: string) {
  return services.find((service) => service.key === key);
}

export function servicesForPlan(services: OfferedService[], plan?: Plan | null) {
  const resolved = plan?.services;
  if (resolved?.length) return resolved;

  const serviceKeys = plan?.service_keys ?? [];

  return serviceKeys
    .map((key) => serviceByKey(services, key))
    .filter(Boolean) as OfferedService[];
}

export function planHasService(plan: Plan | undefined | null, serviceKey: string) {
  return Boolean(plan?.service_keys?.includes(serviceKey));
}

export function serviceIsLive(service?: OfferedService | null) {
  return service?.implementation_status === 'live';
}

export function serviceIsPlanned(service?: OfferedService | null) {
  return service?.implementation_status === 'planned';
}

export function serviceStatusLabel(service: OfferedService, t: TranslateFn) {
  return serviceIsLive(service) ? t('available') : t('planned');
}

export function serviceEntitlementLabel(service: OfferedService, plan: Plan | undefined | null, t: TranslateFn) {
  return planHasService(plan, service.key) ? t('includedInPlan') : t('requiresUpgrade');
}

export function integrationStatusLabel(service: OfferedService, plan: Plan | undefined | null, t: TranslateFn) {
  const included = planHasService(plan, service.key);

  if (serviceIsLive(service) && included) return t('available');
  if (serviceIsLive(service)) return t('requiresUpgrade');
  if (included) return t('planned');

  return t('requiresUpgrade');
}
