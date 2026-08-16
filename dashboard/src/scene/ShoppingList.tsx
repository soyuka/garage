import './shopping.css';
import { formatEuros } from '../format';
import type { ShoppingList } from './shopping';

// « Pièces à commander » — every reference chosen across the open checklist, in the shared projection
// column at the car's RIGHT, grouped by the job it belongs to.
//
// Read-only on purpose: each of these lines is editable in the rail, which stays lit beside it. This is
// the cut, not a second editor — and there is no primary "add" button, because these references are
// already on their tasks. Adding them again is the no-op the parts manifest used to offer.

export function ShoppingListPanel({
  model,
  reduced = false,
  onDismiss,
}: {
  model: ShoppingList;
  reduced?: boolean;
  onDismiss?: () => void;
}) {
  if (model.groups.length === 0) return null;

  return (
    <aside className="at-shop" data-reduced={reduced ? 'true' : undefined} role="group" aria-label="Pièces à commander">
      <div className="at-proj-head">
        <span className="at-proj-tick" aria-label="placé par Le Noyau" role="img" />
        <div className="at-shop-head">PIÈCES À COMMANDER</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label="Fermer la liste">
            ✕
          </button>
        )}
      </div>

      {model.groups.map((group, gi) => (
        <div
          key={group.taskId}
          className="at-shop-group"
          style={reduced ? undefined : { animationDelay: `${Math.min(gi, 4) * 70 + 50}ms` }}
        >
          <div className="at-shop-job">{group.title}</div>
          <ul className="at-shop-rows" role="list">
            {group.parts.map((p, i) => (
              <li className="at-shop-row" key={p.id ?? `${p.ref}-${i}`}>
                <span className="at-shop-ref">{p.ref}</span>
                {p.brand && <span className="at-shop-brand">{p.brand}</span>}
                {p.label && <span className="at-shop-label">{p.label}</span>}
                {p.qty != null && p.qty > 1 && <span className="at-shop-qty">×{p.qty}</span>}
                <span className="at-shop-price" data-unknown={p.priceCents == null ? 'true' : undefined}>
                  {p.priceCents == null ? '—' : formatEuros(p.priceCents)}
                </span>
              </li>
            ))}
          </ul>
        </div>
      ))}

      <div className="at-shop-foot">
        <span className="at-shop-total">{model.totalCents > 0 ? formatEuros(model.totalCents) : '—'}</span>
        <span className="at-shop-count">
          {model.partCount} référence{model.partCount > 1 ? 's' : ''} · {model.taskCount} poste
          {model.taskCount > 1 ? 's' : ''}
        </span>
      </div>

      {/* Honesty seam: a total computed over priced lines only must say so, or an unpriced list reads as
          free. The live Mazda's seven references carry no price at all. */}
      {model.unpricedCount > 0 && (
        <p className="at-shop-partial">
          {model.unpricedCount} référence{model.unpricedCount > 1 ? 's' : ''} sans prix connu
        </p>
      )}

      <span className="at-shop-lead" aria-hidden="true" />
    </aside>
  );
}
