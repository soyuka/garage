import type { ScenarioBundle } from '../fixtures/vehicles';
import type { ForecastDue, MaintenanceHealth } from '../types';

// DESIGN-VISION §9 — "service passant en rouge". On fixtures, simulate the live Mercure domain event
// that tips the soonest-due service past due: the scene re-renders the returned (red) bundle so every
// leaf reacts (hood glow ignites, rim arc burns red, hotspot + panel flip to EN RETARD) and the scene
// docks an ember on Le Noyau. Pure + immutable — FIXTURES is a shared singleton; never mutate it.

/** Index of the soonest non-overdue due (a usage-axis one preferred — a realistic "drive a bit → late"). */
export function firstCrossable(due: ForecastDue[]): number {
  const usageIdx = due.findIndex((d) => !d.overdue && d.usage != null);
  if (usageIdx !== -1) return usageIdx;
  return due.findIndex((d) => !d.overdue);
}

/**
 * The first service `type` that crossed into overdue between two forecasts (a prior not-overdue — or
 * absent — row now overdue). Drives the LIVE §9 wow: a Mercure domain refetch that tips a service past
 * due docks an ember + murmurs on Le Noyau, hands-off. Null when nothing newly crossed.
 */
export function firstNewlyOverdue(prev: ForecastDue[], next: ForecastDue[]): string | null {
  const wasOverdue = new Map(prev.map((d) => [d.type, d.overdue]));
  const crossed = next.find((d) => d.overdue && wasOverdue.get(d.type) !== true);
  return crossed?.type ?? null;
}

export function crossOverdue(bundle: ScenarioBundle): ScenarioBundle {
  const idx = firstCrossable(bundle.forecast.due);
  if (idx === -1) return bundle; // nothing crossable → no-op, same reference

  const crossedType = bundle.forecast.due[idx].type;
  const due = bundle.forecast.due.map((d, i) => {
    if (i !== idx) return d;
    // a small "just tipped over" amount: ~3% of the interval, floored at 100 units
    const usage = d.usage
      ? { ...d.usage, remaining: -Math.max(100, Math.round((d.usage.due - d.usage.last) * 0.03)) }
      : null;
    return { ...d, overdue: true, daysUntilDue: -3, usage };
  });
  // the just-tipped negative remaining, mirrored onto nextDue so the collection summary can't disagree
  const tippedRemaining = due[idx].usage?.remaining ?? null;

  const overdue = due.filter((d) => d.overdue).length;
  const prev = bundle.vehicle.health;
  const health: MaintenanceHealth = {
    status: 'red',
    score: Math.min(prev?.score ?? 38, 38),
    overdue,
    dueSoon: prev?.dueSoon ?? 0,
    tracked: prev?.tracked ?? due.length,
    gaps: prev?.gaps ?? 0,
  };

  const nextDue =
    bundle.vehicle.nextDue && bundle.vehicle.nextDue.type === crossedType
      ? {
          ...bundle.vehicle.nextDue,
          overdue: true,
          daysUntilDue: -3,
          usageRemaining: tippedRemaining ?? bundle.vehicle.nextDue.usageRemaining,
        }
      : bundle.vehicle.nextDue;

  return {
    ...bundle,
    vehicle: { ...bundle.vehicle, health, nextDue },
    forecast: { ...bundle.forecast, due },
  };
}
