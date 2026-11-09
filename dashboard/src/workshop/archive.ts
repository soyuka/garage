import type { VehicleSummary } from '../types';

export function isArchived(vehicle: Pick<VehicleSummary, 'archivedAt'>): boolean {
  return !!vehicle.archivedAt;
}

export function splitArchived<V extends Pick<VehicleSummary, 'archivedAt'>>(vehicles: V[]): { active: V[]; archived: V[] } {
  const active: V[] = [];
  const archived: V[] = [];
  for (const v of vehicles) (isArchived(v) ? archived : active).push(v);
  return { active, archived };
}
