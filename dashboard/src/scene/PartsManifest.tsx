import './partsmanifest.css';
import { formatEuros } from '../format';
import type { Iri, PlannedPart } from '../types';
import { manifestTotal, type PartsManifestModel } from './parts';

// The parts manifest (option 2a) — an on-demand agent surface projected to the car's RIGHT: the itemised,
// priced form of a parts-bearing proposal. Each row unfurls staggered; the primary "Ajouter à la tâche"
// fires the DETERMINISTIC patchTask merge (no agent turn — the known dead-button path, wired here). The
// same numbers live on the task in the checklist/truth-panel, so hiding the canvas loses nothing.

export function PartsManifest({
  model,
  reduced = false,
  onAdd,
  onDismiss,
}: {
  model: PartsManifestModel;
  reduced?: boolean;
  onAdd: (patch: { id: Iri; parts: PlannedPart[] }) => void;
  onDismiss: () => void;
}) {
  const total = manifestTotal(model.parts);
  return (
    <aside className="at-parts" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={`Pièces — ${model.service}`}>
      <div className="at-parts-head">◇ PIÈCES · {model.service}</div>
      {model.finding && <div className="at-parts-finding">{model.finding}</div>}
      <div className="at-parts-rows">
        {model.parts.map((p, i) => {
          const qty = p.qty ?? 1;
          const lineCents = (p.priceCents ?? 0) * qty;
          return (
            <div
              key={p.id ?? `${p.ref}-${i}`}
              className="at-parts-row"
              style={reduced ? undefined : { animationDelay: `${Math.min(i, 4) * 70 + 60}ms` }}
            >
              <div className="at-parts-line">
                <span className="at-parts-label">
                  {p.label ?? p.ref}
                  {qty > 1 ? ` · ${qty}×` : ''}
                </span>
                {p.priceCents != null && <span className="at-parts-price">{formatEuros(lineCents)}</span>}
              </div>
              <div className="at-parts-meta">
                {[p.brand, p.ref].filter(Boolean).join(' ')}
              </div>
            </div>
          );
        })}
      </div>
      <div className="at-parts-foot">
        <span className="at-parts-total">{formatEuros(total)}</span>
        <div className="at-parts-actions">
          <button type="button" className="at-parts-later" onClick={onDismiss}>
            Plus tard
          </button>
          <button
            type="button"
            className="at-parts-add"
            onClick={() => onAdd({ id: model.taskId, parts: model.parts })}
          >
            Ajouter à la tâche →
          </button>
        </div>
      </div>
      <span className="at-parts-lead" aria-hidden="true" />
    </aside>
  );
}
