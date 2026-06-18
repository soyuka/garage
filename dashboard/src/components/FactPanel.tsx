import { Card } from '../design/components/Card';
import type { FactPanel as FactPanelState, Vehicle } from '../types';
import { FactRow, factLabel } from './facts';
import { InlineEdit } from './InlineEdit';

/**
 * The agent's focused answer to a spec question ("capacité huile moteur"): a transient card showing
 * ONLY the requested fact keys of the selected vehicle, drawn from its already-loaded facts (the
 * item GET). "Tout afficher" widens it to the full list — no extra fetch, the facts are in state.
 *
 * A requested key with no stored value is not a dead end: its row is click-to-edit, so the user can
 * fill the gap in place (onFillFact → POST .../facts, recorded as a source "user" fact).
 */
export function FactPanel({
  panel,
  vehicle,
  onExpand,
  onDismiss,
  onFillFact,
}: {
  panel: FactPanelState;
  vehicle: Vehicle;
  onExpand: () => void;
  onDismiss: () => void;
  onFillFact: (key: string, value: string) => void;
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
              <span className="fact-value">
                <InlineEdit
                  value=""
                  placeholder="non renseignée — cliquer pour saisir"
                  ariaLabel={`Saisir ${factLabel(k)}`}
                  className="fact-fill"
                  onCommit={(value) => onFillFact(k, value)}
                />
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
