import { useEffect, useRef, type ReactNode } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { whenLabel } from './age';
import type { Messages } from '../i18n/messages';
import type { DecisionItem, DecisionKind } from './state';
import type { Iri, PlannedPart } from '../types';
import './ambientInbox.css';

// The "à décider" leaf (C2) — the surface the ember opens. A compact QUEUE of decision items anchored
// near Le Noyau (NOT a modal, NOT a drawer): the LangChain ambient triad, human ON the loop. Rows resolve
// DETERMINISTICALLY where possible — a review row replays its CTA actions (patchTask = no turn), a notify
// row offers "vu" (dismiss) + "planifier ○" (promote to a task). No transcript, ever. Cyan is rationed to
// the leaf's hairline attribution (Le Noyau surfaced these); the rows themselves stay mono.

interface AmbientInboxProps {
  open: boolean;
  queue: DecisionItem[];
  reduced?: boolean;
  onClose: () => void;
  /** Remove a decided item from the queue (backs every "vu" / dismiss + follows every action). */
  onResolve: (id: string) => void;
  /** A review action carrying an NL follow-up → run a fresh agent turn (reuses the CTA instruction path). */
  onReviewInstruction?: (instruction: string) => void;
  /** A review action carrying a deterministic parts merge → PATCH, no turn (reuses the CTA patch path). */
  onReviewPatch?: (patch: { id: Iri; parts: PlannedPart[] }) => void;
  /** Promote a notify item (a due service) to a planned task on its vehicle. */
  onSchedule?: (item: DecisionItem) => void;
}

// Kind glyphs differ by SHAPE (never colour alone — colour-blind safe, matches the hotspot rule): a bell
// for a notify, a speech-bubble for a question, a clipboard for a review. All hairline `currentColor`.
function KindGlyph({ kind }: { kind: DecisionKind }) {
  const paths: Record<DecisionKind, ReactNode> = {
    notify: <path d="M4 6.5a4 4 0 0 1 8 0c0 3 1 4 1.5 4.5h-11C3 10.5 4 9.5 4 6.5zM6.6 13.2a1.6 1.6 0 0 0 2.8 0" />, // bell
    question: (
      <>
        <path d="M2.4 3.2h11.2v7.4H8.6L5.4 13.4V10.6H2.4z" />
        <path d="M6.5 5.9a1.6 1.6 0 1 1 2.3 1.5c-.5.3-.8.6-.8 1.1" />
        <path d="M8 10.1v.05" />
      </>
    ), // speech bubble with a ?
    review: (
      <>
        <rect x="3.4" y="2.6" width="9.2" height="10.8" rx="1" />
        <path d="M6 2.6a2 2 0 0 1 4 0" />
        <path d="M5.6 8l1.4 1.4L10.4 6" />
      </>
    ), // clipboard with a check
  };
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {paths[kind]}
    </svg>
  );
}

// Speaker with/without waves — SHAPE carries the state, never colour alone (the hotspot rule).
const kindLabel = (kind: DecisionKind, t: Messages): string =>
  'notify' === kind ? t.inbox.kindNotify : 'question' === kind ? t.inbox.kindQuestion : t.inbox.kindReview;

export function AmbientInbox({ open, queue, reduced, onClose, onResolve, onReviewInstruction, onReviewPatch, onSchedule }: AmbientInboxProps) {
  const t = useT();
  const ref = useRef<HTMLDivElement>(null);
  // Move focus into the leaf when it opens so Escape/Tab reach it, and it reads as a dialog to AT.
  useEffect(() => {
    if (open) ref.current?.focus();
  }, [open]);
  if (!open) return null;

  // Resolve an item but keep the leaf open when others remain: refocus the dialog synchronously so the
  // clicked button unmounting doesn't blur focus OUT of the leaf (which would spuriously close it). When
  // it was the last item the parent drains the queue → the leaf auto-closes.
  const resolveKeepingFocus = (id: string) => {
    onResolve(id);
    if (queue.length > 1) ref.current?.focus();
  };

  return (
    <div
      ref={ref}
      className="at-inbox"
      role="dialog"
      aria-label={t.inbox.title}
      data-reduced={reduced ? 'true' : undefined}
      tabIndex={-1}
      onKeyDown={(e) => {
        if (e.key === 'Escape') {
          e.stopPropagation();
          onClose();
          return;
        }
        // Focus trap: the leaf closes on blur-to-outside (below), so a plain Tab/Shift+Tab would leak focus
        // to a sibling (Le Noyau's controls) and self-dismiss the dialog before its rows can be reverse-
        // traversed. Cycle Tab within the leaf's own buttons instead; genuine outside clicks still close it.
        if (e.key === 'Tab') {
          const focusables = Array.from(ref.current?.querySelectorAll<HTMLElement>('button:not([disabled])') ?? []);
          if (focusables.length === 0) {
            e.preventDefault(); // empty leaf (draining) — keep focus on the container, don't leak out
            return;
          }
          const first = focusables[0];
          const last = focusables[focusables.length - 1];
          const active = document.activeElement;
          if (e.shiftKey && (active === first || active === ref.current)) {
            e.preventDefault();
            last.focus();
          } else if (!e.shiftKey && active === last) {
            e.preventDefault();
            first.focus();
          }
        }
      }}
      onBlur={(e) => {
        // close when focus leaves the leaf entirely (blur to an element outside it)
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) onClose();
      }}
    >
      {/* the attribution hairline — Le Noyau surfaced these; cyan is rationed to THIS line only */}
      <div className="at-inbox-head">
        <span className="at-inbox-title">{t.inbox.title}</span>
        <span className="at-inbox-count">{queue.length}</span>
      </div>

      {queue.length === 0 ? (
        <p className="at-inbox-empty">{t.inbox.empty}</p>
      ) : (
        <ul className="at-inbox-list">
          {queue.map((item) => (
            <li className="at-inbox-row" key={item.id} data-kind={item.kind}>
              <span className="at-inbox-glyph" aria-hidden="true">
                <KindGlyph kind={item.kind} />
              </span>
              <div className="at-inbox-main">
                <p className="at-inbox-text">
                  <span className="at-inbox-kind">{kindLabel(item.kind, t)}</span>
                  {item.text}
                </p>
                {item.finding && <p className="at-inbox-finding">{item.finding}</p>}
                <div className="at-inbox-actions">
                  {item.kind === 'review' &&
                    (item.actions ?? []).map((a, i) => (
                      <button
                        key={i}
                        type="button"
                        className={`at-inbox-btn${a.patchTask ? ' at-inbox-btn--do' : ''}`}
                        onClick={() => {
                          // exactly one payload per the CtaAction contract; the deterministic patch wins if both.
                          if (a.patchTask) {
                            onReviewPatch?.(a.patchTask);
                            resolveKeepingFocus(item.id);
                          } else if (a.instruction) {
                            onReviewInstruction?.(a.instruction);
                            onResolve(item.id);
                            onClose(); // a fresh turn takes the stage
                          } else {
                            resolveKeepingFocus(item.id);
                          }
                        }}
                      >
                        {a.label}
                      </button>
                    ))}
                  {item.kind === 'notify' && item.serviceType && item.vehicleIri && onSchedule && (
                    <button
                      type="button"
                      className="at-inbox-btn at-inbox-btn--do"
                      onClick={() => {
                        onSchedule(item);
                        resolveKeepingFocus(item.id);
                      }}
                    >
                      {t.inbox.plan}
                    </button>
                  )}
                  <button type="button" className="at-inbox-btn at-inbox-btn--dismiss" onClick={() => resolveKeepingFocus(item.id)}>
                    vu
                  </button>
                </div>
              </div>
              <span className="at-inbox-when">{whenLabel(item.at, t)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
