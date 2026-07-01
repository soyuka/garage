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
  pinnedKeys,
  onTogglePin,
  edit,
  reduced = false,
}: {
  open: boolean;
  onClose: () => void;
  facts: VehicleFact[];
  pinnedKeys: string[];
  onTogglePin: (key: string) => void;
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

  const pinned = new Set(pinnedKeys);
  // Pinned first, stable within each group — what to focus on reads before the rest of the sheet.
  const ordered = [...facts].sort((a, b) => Number(pinned.has(b.key)) - Number(pinned.has(a.key)));

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
                const isPinned = pinned.has(f.key);
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
                    <button
                      type="button"
                      className="at-facts-pin"
                      data-pinned={isPinned ? 'true' : undefined}
                      onClick={() => onTogglePin(f.key)}
                      aria-pressed={isPinned}
                      aria-label={isPinned ? `Désépingler ${factLabel(f.key)}` : `Épingler ${factLabel(f.key)}`}
                    >
                      <svg width="13" height="13" viewBox="0 0 16 16" fill={isPinned ? 'currentColor' : 'none'} aria-hidden="true">
                        <path
                          d="M8 1.5c-1.4 0-2.5 1.1-2.5 2.5 0 .7.3 1.4.8 1.9L5 9.5h6l-1.3-3.6c.5-.5.8-1.2.8-1.9C10.5 2.6 9.4 1.5 8 1.5Z"
                          stroke="currentColor"
                          strokeWidth="1.3"
                          strokeLinejoin="round"
                        />
                        <path d="M8 9.5V14.5" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" />
                      </svg>
                    </button>
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
