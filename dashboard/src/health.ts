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

/** A breakdown chip: a coloured count driving the health-card's at-a-glance summary. */
export interface HealthChip {
  /** Maps onto StatusPill tones — overdue→red, soon→amber, gap→neutral-cyan, ok→green. */
  tone: 'overdue' | 'soon' | 'gap' | 'ok';
  label: string;
}

/** The richer view-model behind the explicit health card (ring + verdict + breakdown chips). */
export interface HealthView {
  status: HealthBadge['status'];
  /** 0–100, or null when nothing is tracked (status "unknown"). */
  score: number | null;
  /** A short headline verdict — "Bon état général", "Entretien en retard"… */
  verdict: string;
  /** One sentence expanding the verdict in plain language. */
  detail: string;
  /** The non-zero counts, most-urgent first; a lone "À jour" chip when green and clean. */
  chips: HealthChip[];
}

/**
 * Projects the health rollup onto the explicit card view-model. Unlike {@see healthBadge} (a single
 * cramped pill string), this splits the verdict, a plain-language sentence, and the individual
 * coloured counts so the card can render a ring + breakdown instead of "92 · 5 sans historique".
 */
export function healthView(health: MaintenanceHealth | undefined): HealthView | null {
  if (!health) return null;

  const chips: HealthChip[] = [];
  if (health.overdue > 0) chips.push({ tone: 'overdue', label: `${health.overdue} en retard` });
  if (health.dueSoon > 0) chips.push({ tone: 'soon', label: `${health.dueSoon} bientôt` });
  if (health.gaps > 0) chips.push({ tone: 'gap', label: `${health.gaps} sans historique` });

  if (health.status === 'unknown') {
    return {
      status: 'info',
      score: null,
      verdict: 'Aucun suivi',
      detail: 'Aucun entretien consigné. Renseignez une première intervention pour activer la prévision.',
      chips,
    };
  }

  const { verdict, detail } = verdictFor(health);
  return {
    status: STATUS_TO_PILL[health.status],
    score: health.score,
    verdict,
    detail,
    chips: chips.length > 0 ? chips : [{ tone: 'ok', label: 'À jour' }],
  };
}

/** The headline + sentence for a scored (non-unknown) vehicle, severity-ordered like the status. */
function verdictFor(health: MaintenanceHealth): { verdict: string; detail: string } {
  if (health.overdue > 0) {
    return { verdict: 'Entretien en retard', detail: 'Un ou plusieurs services ont dépassé leur échéance.' };
  }
  if (health.dueSoon > 0) {
    return { verdict: 'Entretien bientôt dû', detail: 'Une échéance approche dans les 30 prochains jours.' };
  }
  if (health.gaps > 0) {
    return {
      verdict: 'Suivi incomplet',
      detail: 'Des services planifiés n’ont aucune date de référence : leur prochaine échéance reste imprévisible.',
    };
  }
  return { verdict: 'Bon état général', detail: 'Tout l’entretien suivi est à jour.' };
}
