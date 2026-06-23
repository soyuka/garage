import { describe, expect, it } from 'vitest';
import { healthBadge, healthView, type HealthBadge, type HealthView } from './health';
import type { MaintenanceHealth } from './types';

/** Assert a badge was produced and narrow away the null the listing path can return. */
function badge(h: MaintenanceHealth): HealthBadge {
  const b = healthBadge(h);
  expect(b).not.toBeNull();
  return b as HealthBadge;
}

/** Same narrowing for the richer card view-model. */
function view(h: MaintenanceHealth): HealthView {
  const v = healthView(h);
  expect(v).not.toBeNull();
  return v as HealthView;
}

describe('healthBadge', () => {
  it('renders a green badge with score and "à jour" when all tracked & current', () => {
    const b = badge({ status: 'green', score: 100, overdue: 0, dueSoon: 0, tracked: 4, gaps: 0 });
    expect(b.status).toBe('ok');
    expect(b.score).toBe(100);
    expect(b.label).toBe('À jour');
  });

  it('renders red when something is overdue, leading with the overdue count', () => {
    const b = badge({ status: 'red', score: 40, overdue: 1, dueSoon: 2, tracked: 5, gaps: 0 });
    expect(b.status).toBe('overdue');
    expect(b.score).toBe(40);
    expect(b.label).toBe('1 en retard · 2 bientôt');
  });

  it('renders amber for a due-soon item', () => {
    const b = badge({ status: 'amber', score: 75, overdue: 0, dueSoon: 2, tracked: 3, gaps: 0 });
    expect(b.status).toBe('soon');
    expect(b.label).toBe('2 bientôt');
  });

  it('renders amber for a coverage gap (recurring service with no logged baseline)', () => {
    const b = badge({ status: 'amber', score: 80, overdue: 0, dueSoon: 0, tracked: 2, gaps: 3 });
    expect(b.status).toBe('soon');
    expect(b.label).toBe('3 sans historique');
  });

  it('combines overdue, due-soon and gaps into one breakdown', () => {
    const b = badge({ status: 'red', score: 30, overdue: 2, dueSoon: 1, tracked: 6, gaps: 1 });
    expect(b.label).toBe('2 en retard · 1 bientôt · 1 sans historique');
  });

  it('renders a neutral/grey "unknown" badge with no score when nothing is tracked', () => {
    const b = badge({ status: 'unknown', score: null, overdue: 0, dueSoon: 0, tracked: 0, gaps: 0 });
    // Must NOT read as a healthy/green state.
    expect(b.status).toBe('info');
    expect(b.score).toBeNull();
    expect(b.label).toBe('Aucun historique');
  });

  it('returns null when there is no health object (collection listing, not the item GET)', () => {
    expect(healthBadge(undefined)).toBeNull();
  });
});

describe('healthView', () => {
  it('returns null without a health object (the listing path)', () => {
    expect(healthView(undefined)).toBeNull();
  });

  it('reads green/up-to-date with a single "à jour" chip and the alive ring', () => {
    const v = view({ status: 'green', score: 100, overdue: 0, dueSoon: 0, tracked: 4, gaps: 0 });
    expect(v.status).toBe('ok');
    expect(v.score).toBe(100);
    expect(v.verdict).toBe('Bon état général');
    expect(v.chips).toEqual([{ tone: 'ok', label: 'À jour' }]);
  });

  it('leads the verdict with the overdue count and emits a red chip first', () => {
    const v = view({ status: 'red', score: 40, overdue: 2, dueSoon: 1, tracked: 5, gaps: 1 });
    expect(v.status).toBe('overdue');
    expect(v.verdict).toBe('Entretien en retard');
    expect(v.chips).toEqual([
      { tone: 'overdue', label: '2 en retard' },
      { tone: 'soon', label: '1 bientôt' },
      { tone: 'gap', label: '1 sans historique' },
    ]);
  });

  it('reads "bientôt dû" for an amber due-soon vehicle', () => {
    const v = view({ status: 'amber', score: 80, overdue: 0, dueSoon: 2, tracked: 3, gaps: 0 });
    expect(v.verdict).toBe('Entretien bientôt dû');
    expect(v.chips).toEqual([{ tone: 'soon', label: '2 bientôt' }]);
  });

  it('reads "suivi incomplet" when the only amber driver is coverage gaps', () => {
    const v = view({ status: 'amber', score: 80, overdue: 0, dueSoon: 0, tracked: 2, gaps: 3 });
    expect(v.verdict).toBe('Suivi incomplet');
    expect(v.chips).toEqual([{ tone: 'gap', label: '3 sans historique' }]);
  });

  it('reads honestly unknown — no score, neutral status — when nothing is tracked', () => {
    const v = view({ status: 'unknown', score: null, overdue: 0, dueSoon: 0, tracked: 0, gaps: 4 });
    expect(v.status).toBe('info');
    expect(v.score).toBeNull();
    expect(v.verdict).toBe('Aucun suivi');
    // Even with nothing tracked, the gap count is still surfaced so the user knows what to log.
    expect(v.chips).toEqual([{ tone: 'gap', label: '4 sans historique' }]);
  });
});
