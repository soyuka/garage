import { useEffect } from 'react';
import './factsdrawer.css';
import { NBSP } from '../format';
import { factLabel } from '../labels';
import { InlineEdit } from '../floor/InlineEdit';
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
}: {
  open: boolean;
  onClose: () => void;
  facts: VehicleFact[];
  /** Live-hero editing seam: present → each row is click-to-edit + removable. Absent → read-only. */
  edit?: { onEditValue: (key: string, value: string, unit?: string | null) => void; onRemove: (key: string) => void };
  reduced?: boolean;
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  // Backend order, untouched: pinning is gone, so there is no operator ranking left to honour and any
  // reordering here would be the drawer inventing one.
  const ordered = facts;

  return (
    <div className="at-facts-overlay" data-reduced={reduced ? 'true' : undefined} onClick={onClose}>
      <section
        className="at-facts-drawer glass"
        role="dialog"
        aria-modal="true"
        aria-label="Données"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="at-facts-head">
          <h2>Données</h2>
          <button type="button" className="at-facts-close" onClick={onClose} aria-label="Fermer">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
              <path d="M1 1L13 13M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </button>
        </header>

        <div className="at-facts-body">
          {ordered.length === 0 ? (
            <p className="at-facts-empty">Aucune donnée</p>
          ) : (
            <ul className="at-facts-list">
              {ordered.map((f) => {
                return (
                  <li key={f.key} className="at-facts-row">
                    <span className="at-facts-label">{factLabel(f.key)}</span>
                    <span className="at-facts-value">
                      {edit ? (
                        <InlineEdit
                          value={f.value}
                          ariaLabel={`Modifier ${factLabel(f.key)}`}
                          onCommit={(next) => edit.onEditValue(f.key, next, f.unit)}
                        />
                      ) : (
                        f.value
                      )}
                      {f.unit ? `${NBSP}${f.unit}` : ''}
                    </span>
                    <span className="at-facts-status">
                      {f.verified ? (
                        <span className="at-facts-ok" aria-label="vérifié">
                          ✓
                        </span>
                      ) : (
                        <span className="at-facts-unver">· non vérifié</span>
                      )}
                    </span>
                    {edit && (
                      <button
                        type="button"
                        className="at-facts-remove"
                        onClick={() => edit.onRemove(f.key)}
                        aria-label={`Supprimer ${factLabel(f.key)}`}
                      >
                        <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                          <path d="M1 1L13 13M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                        </svg>
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </section>
    </div>
  );
}
