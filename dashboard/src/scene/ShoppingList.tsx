import './shopping.css';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatEuros } from '../format';
import { safeHref } from '../safeHref';
import { partShopLinks, type ShoppingList } from './shopping';

// « Pièces à commander » — every reference chosen across the open checklist, grouped by the job it
// belongs to.
//
// It is a CENTRED surface, not a projection-column card, and that is a deliberate exception to the
// one-right-edge-slot rule: the column is ~270px wide, and a shopping list is a working document —
// reference, brand, description, price and two shop links per line — that the operator reads across and
// acts on, not a glance-answer. At column width the references wrapped to unreadable stubs. It closes
// like the other centred surfaces: scrim click, its ✕, or Escape (owned by SceneView, since this is a
// `focus` and the existing handler already steps back through one).
//
// Read-only: each line is editable in the rail. No primary "add" button either — these refs are already
// on their tasks, so merging them again is the no-op the old parts manifest offered.

export function ShoppingListOverlay({
  model,
  reduced = false,
  onDismiss,
}: {
  model: ShoppingList;
  reduced?: boolean;
  onDismiss?: () => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  if (model.groups.length === 0) return null;

  return (
    <div className="at-shop-overlay" data-reduced={reduced ? 'true' : undefined} onClick={onDismiss}>
      <section
        className="at-shop glass"
        role="dialog"
        aria-modal="true"
        aria-label={t.proj.shopping}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="at-shop-head">
          <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
          <h2>{t.proj.shopping}</h2>
          <span className="at-shop-count">
            {t.proj.refAndLineCount(model.partCount, model.taskCount)}
          </span>
          {onDismiss && (
            <button type="button" className="at-shop-close" onClick={onDismiss} aria-label="Fermer">
              ✕
            </button>
          )}
        </header>

        <div className="at-shop-body">
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
                      {p.priceCents == null ? '—' : formatEuros(p.priceCents, locale)}
                    </span>
                    {/* Restored from 44cb4fb: a reference you cannot go and BUY is one you retype into a
                        search box. Untrusted-URL discipline still applies — these are built locally, but
                        they go through safeHref like every other outbound link. */}
                    <span className="at-shop-shops">
                      {partShopLinks(p).map((link) => (
                        <a
                          key={link.name}
                          className="at-shop-shop"
                          href={safeHref(link.url)}
                          target="_blank"
                          rel="noreferrer noopener"
                          title={t.proj.searchOn(p.ref, link.name)}
                        >
                          {link.name}
                        </a>
                      ))}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <footer className="at-shop-foot">
          <span className="at-shop-total">{model.totalCents > 0 ? formatEuros(model.totalCents, locale) : '—'}</span>
          {/* Honesty seam: a total over priced lines only must say so, or an unpriced list reads as free. */}
          {model.unpricedCount > 0 && (
            <span className="at-shop-partial">
              {t.proj.unpriced(model.unpricedCount)}
            </span>
          )}
        </footer>
      </section>
    </div>
  );
}
