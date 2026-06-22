import type { MaintenanceHealth } from './types';

/** What the StatusPill needs to render the maintenance-health badge. */
export interface HealthBadge {
  /** Maps onto StatusPill's status: green→ok, amber→soon, red→overdue, unknown→info (neutral, never green). */
  status: 'ok' | 'soon' | 'overdue' | 'info';
  /** The 0–100 score, or null when the status is "unknown" (nothing tracked yet). */
  score: number | null;
  /** A short human breakdown — e.g. "1 en retard · 2 bientôt", or "Aucun historique" for unknown. */
  label: string;
}

const STATUS_TO_PILL: Record<MaintenanceHealth['status'], HealthBadge['status']> = {
  green: 'ok',
  amber: 'soon',
  red: 'overdue',
  // Neutral, NOT green — a fresh/untracked vehicle must not read as healthy.
  unknown: 'info',
};

/**
 * Derives the badge props from the API's maintenance-health rollup. Returns null when the rollup is
 * absent (the collection listing omits it; it rides the item GET only). The breakdown leads with the
 * most urgent count: overdue, then due-soon, then coverage gaps. "unknown" shows no score.
 */
export function healthBadge(health: MaintenanceHealth | undefined): HealthBadge | null {
  if (!health) return null;

  if (health.status === 'unknown') {
    return { status: 'info', score: null, label: 'Aucun historique' };
  }

  const parts: string[] = [];
  if (health.overdue > 0) parts.push(`${health.overdue} en retard`);
  if (health.dueSoon > 0) parts.push(`${health.dueSoon} bientôt`);
  if (health.gaps > 0) parts.push(`${health.gaps} sans historique`);

  return {
    status: STATUS_TO_PILL[health.status],
    score: health.score,
    label: parts.length > 0 ? parts.join(' · ') : 'À jour',
  };
}
