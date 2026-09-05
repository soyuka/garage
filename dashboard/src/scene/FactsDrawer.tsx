import { useEffect, useRef } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import './factsdrawer.css';
import { NBSP } from '../format';
import { factLabel } from '../labels';
import { InlineEdit } from '../floor/InlineEdit';
import { groupFactsByRegion } from './factGroups';
import type { VehicleFact } from '../types';

// The facts drawer — replaces the old always-on MOTEUR fact cards with an on-demand, full-sheet
// glass drawer docked to the bottom edge. Opens on request (the "Fiche technique" chip / cta), never
// stays mounted over the car. Pinning here feeds the resting cockpit readout (SpecConstellation's
// MOTEUR_REST_KEYS sibling) — pinned facts are what stays legible once the drawer closes.

export function FactsDrawer({
  open,
  onClose,
  facts,
  edit,
  reduced = false,
  onHoverFact,
  focusKey = null,
}: {
  open: boolean;
  onClose: () => void;
  facts: VehicleFact[];
  /** Live-hero editing seam: present → each row is click-to-edit + removable. Absent → read-only. */
  edit?: { onEditValue: (key: string, value: string, unit?: string | null) => void; onRemove: (key: string) => void };
  reduced?: boolean;
  /** A row hovered/focused marks its constellation node in the operator hue. Absent → the constellation
   * wires to no ledger (the fixture playground). */
  onHoverFact?: (key: string | null) => void;
  /** A constellation node picked on the stage — that row scrolls into view and wears the hover hairline
   * for as long as the pick stands (§ "Picking — the body points back"). Absent/null → no row is picked. */
  focusKey?: string | null;
}) {
  const t = useT();
  const [locale] = useLocale();
  const bodyRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  useEffect(() => {
    if (!focusKey) return;
    const el = bodyRef.current?.querySelector<HTMLElement>(`[data-key="${CSS.escape(focusKey)}"]`);
    el?.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
  }, [focusKey, reduced]);

  if (!open) return null;

  // Grouped by region, in anchor order (CHOREOGRAPHY § "The legend is grouped like the body") — arrival
  // order is kept inside each group, so within a group this is still backend order.
  const groups = groupFactsByRegion(facts);

  return (
    <div className="at-facts-overlay" data-reduced={reduced ? 'true' : undefined} onClick={onClose}>
      <section
        className="at-facts-drawer glass"
        role="dialog"
        aria-modal="true"
        aria-label={t.scene.data}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="at-facts-head">
          <h2>{t.scene.data}</h2>
          <button type="button" className="at-facts-close" onClick={onClose} aria-label={t.scene.close}>
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
              <path d="M1 1L13 13M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </button>
        </header>

        <div className="at-facts-body" ref={bodyRef}>
          {facts.length === 0 ? (
            <p className="at-facts-empty">{t.misc.noData}</p>
          ) : (
            groups.map(({ region, facts: groupFacts }) => (
              <section key={region} className="at-facts-group">
                <p className="at-facts-group-caption">
                  {region === 'unplaced' ? t.facts.unplaced : t.facts.region[region]}
                </p>
                <ul className="at-facts-list">
                  {groupFacts.map((f) => (
                    <li
                      key={f.key}
                      className="at-facts-row"
                      data-key={f.key}
                      data-picked={focusKey === f.key ? 'true' : undefined}
                      tabIndex={onHoverFact ? 0 : undefined}
                      onMouseEnter={onHoverFact ? () => onHoverFact(f.key) : undefined}
                      onMouseLeave={onHoverFact ? () => onHoverFact(null) : undefined}
                      onFocusCapture={onHoverFact ? () => onHoverFact(f.key) : undefined}
                      onBlurCapture={onHoverFact ? () => onHoverFact(null) : undefined}
                    >
                      <span className="at-facts-label">{factLabel(f.key, locale)}</span>
                      <span className="at-facts-value">
                        {edit ? (
                          <InlineEdit
                            value={f.value}
                            ariaLabel={`Modifier ${factLabel(f.key, locale)}`}
                            onCommit={(next) => edit.onEditValue(f.key, next, f.unit)}
                          />
                        ) : (
                          f.value
                        )}
                        {f.unit ? `${NBSP}${f.unit}` : ''}
                      </span>
                      <span className="at-facts-status">
                        {f.verified ? (
                          <span className="at-facts-ok" aria-label={t.proj.verified}>
                            ✓
                          </span>
                        ) : (
                          <span className="at-facts-unver">{t.proj.unverified}</span>
                        )}
                      </span>
                      {edit && (
                        <button
                          type="button"
                          className="at-facts-remove"
                          onClick={() => edit.onRemove(f.key)}
                          aria-label={t.facts.remove(factLabel(f.key, locale))}
                        >
                          <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                            <path d="M1 1L13 13M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                          </svg>
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              </section>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
