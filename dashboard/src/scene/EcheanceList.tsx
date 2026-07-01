import './echeances.css';
import type { BodyRegion } from './anchors';
import type { Echeance } from './echeances';
import type { ForecastDue } from '../types';

// The échéances as a VERTICAL LIST at the top of the persistent task dock (Option A) — a flat
// severity-sorted list, worst first, laid out as flush rows above the checklist. Replaces the outer-ring
// layout (EcheanceRing); the item markup (mark/svc/horizon classes) is unchanged so it restyles cleanly.
// A task item is a checkbox (○ / ✓ → onToggle); an unplanned due is a ○ plan affordance; a gap is a
// dashed "non suivi". The label lights the item's car region on click.

function markGlyph(e: Echeance): string {
  if (e.kind === 'task') return e.done ? '✓' : '○';
  return '○';
}

export function EcheanceList({
  items,
  onToggle,
  onPlan,
  onFocusRegion,
}: {
  items: Echeance[];
  onToggle?: (taskId: string, done: boolean) => void;
  onPlan?: (due: ForecastDue) => void;
  onFocusRegion?: (region: BodyRegion) => void;
}) {
  if (items.length === 0) return null;
  return (
    <section className="at-ech-list" aria-label="Échéances">
      <div className="at-ech-list-head">
        <h3 className="at-ech-list-title">Échéances</h3>
      </div>
      {items.map((e) => {
        const signal = e.kind === 'gap' ? 'unknown' : e.signal;
        const canToggle = e.kind === 'task' && !!onToggle;
        const canPlan = e.kind === 'due' && !!onPlan;
        return (
          <div key={e.id} className="at-ech-row" data-signal={signal}>
            <button
              type="button"
              className={`at-ech-mark${e.done ? ' at-ech-mark--done' : ''}${e.kind === 'gap' ? ' at-ech-mark--gap' : ''}`}
              role={e.kind === 'task' ? 'checkbox' : undefined}
              aria-checked={e.kind === 'task' ? !!e.done : undefined}
              aria-label={e.kind === 'task' ? `${e.label} — ${e.done ? 'terminé' : 'à faire'}` : e.kind === 'due' ? `${e.label} — planifier` : `${e.label} — non suivi`}
              disabled={!canToggle && !canPlan}
              onClick={() => {
                if (e.kind === 'task' && onToggle) onToggle(e.taskId!, !!e.done);
                else if (e.kind === 'due' && e.due && onPlan) onPlan(e.due);
              }}
            >
              <span aria-hidden="true">{markGlyph(e)}</span>
            </button>
            <button type="button" className="at-ech-label" onClick={() => onFocusRegion?.(e.region)} title={`${e.label} — voir la zone`}>
              <span className="at-ech-svc" data-done={e.done ? 'true' : undefined}>{e.label}</span>
              {e.kind === 'gap' ? (
                <span className="at-ech-horizon at-ech-horizon--gap">non suivi</span>
              ) : e.horizon ? (
                <span className="at-ech-horizon">{e.horizon}</span>
              ) : null}
            </button>
          </div>
        );
      })}
    </section>
  );
}
