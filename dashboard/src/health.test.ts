import { describe, expect, it } from 'vitest';
import { healthBadge, type HealthBadge } from './health';
import type { MaintenanceHealth } from './types';

/** Assert a badge was produced and narrow away the null the listing path can return. */
function badge(h: MaintenanceHealth): HealthBadge {
  const b = healthBadge(h);
  expect(b).not.toBeNull();
  return b as HealthBadge;
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
