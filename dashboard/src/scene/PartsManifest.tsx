import './partsmanifest.css';
import { useLocale, useT } from '../i18n/LocaleProvider';
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
  onAdd?: (patch: { id: Iri; parts: PlannedPart[] }) => void;
  onDismiss: () => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  const total = manifestTotal(model.parts);
  return (
    <aside className="at-parts" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={t.proj.partsFor(model.service)}>
      <div className="at-proj-head">
        <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
        <div className="at-parts-head">{t.proj.partsHead(model.service)}</div>
        <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label={t.proj.closeParts}>
          ✕
        </button>
      </div>
      {model.finding && <div className="at-parts-finding">{model.finding}</div>}
      {model.canAdd && <div className="at-parts-rows">
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
                {p.priceCents != null && <span className="at-parts-price">{formatEuros(lineCents, locale)}</span>}
              </div>
              <div className="at-parts-meta">
                {[p.brand, p.ref].filter(Boolean).join(' ')}
              </div>
            </div>
          );
        })}
      </div>}
      {model.canAdd && onAdd && <div className="at-parts-foot">
        <span className="at-parts-total">{formatEuros(total, locale)}</span>
        <div className="at-parts-actions">
          <button
            type="button"
            className="at-parts-add"
            onClick={() => onAdd({ id: model.taskId, parts: model.parts })}
          >
            {t.proj.addToTask}
          </button>
        </div>
      </div>}
      <span className="at-parts-lead" aria-hidden="true" />
    </aside>
  );
}
