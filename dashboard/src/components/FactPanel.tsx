import { Card } from '../design/components/Card';
import type { FactPanel as FactPanelState, Vehicle } from '../types';
import { FactRow, factLabel } from './facts';

/**
 * The agent's focused answer to a spec question ("capacité huile moteur"): a transient card showing
 * ONLY the requested fact keys of the selected vehicle, drawn from its already-loaded facts (the
 * item GET). "Tout afficher" widens it to the full list — no extra fetch, the facts are in state.
 */
export function FactPanel({
  panel,
  vehicle,
  onExpand,
  onDismiss,
}: {
  panel: FactPanelState;
  vehicle: Vehicle;
  onExpand: () => void;
  onDismiss: () => void;
}) {
  const all = vehicle.facts ?? [];
  const facts = panel.expanded ? all : all.filter((f) => panel.keys.includes(f.key));
  // Keys the agent asked for that the vehicle has no stored fact for — surfaced so the answer is honest.
  const missing = panel.expanded ? [] : panel.keys.filter((k) => !all.some((f) => f.key === k));

  return (
    <Card className="fact-panel">
      <div className="panel-title">
        <span className="t">{panel.title ?? 'Données'}</span>
        <button type="button" className="fact-panel-close" aria-label="Fermer" onClick={onDismiss}>
          ×
        </button>
      </div>

      {facts.length === 0 && missing.length === 0 ? (
        <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>Aucune donnée enregistrée.</div>
      ) : (
        <div className="facts">
          {facts.map((f) => (
            <FactRow key={f.key} fact={f} />
          ))}
          {missing.map((k) => (
            <div key={k} className="fact-row fact-row-missing">
              <span className="fact-key">{factLabel(k)}</span>
              <span className="fact-value" style={{ color: 'var(--mpa-faint)' }}>
                non renseignée
              </span>
            </div>
          ))}
        </div>
      )}

      {!panel.expanded && all.length > facts.length ? (
        <button type="button" className="fact-panel-expand" onClick={onExpand}>
          Tout afficher ({all.length})
        </button>
      ) : null}
    </Card>
  );
}
