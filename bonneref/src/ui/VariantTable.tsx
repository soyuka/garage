import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { formatCount } from '../format';
import type { Narrowing } from '../types';
import { hiddenVariantIds, orderedRows, othersLabel, variantMark, variantRows, type Selection, type VariantRow } from '../variants';
import { CopyRef } from './CopyRef';

interface Props {
  narrowing: Narrowing;
  make: string | null;
  selection: Selection;
  onPick: (clusterId: string) => void;
}

const STATUS: Record<Selection['status'], { text: string; tone: '' | 'ok' } | null> = {
  open: { text: 'à confirmer', tone: '' },
  user: { text: 'choisie par vous', tone: '' },
  answer: { text: 'confirmé par la cote', tone: 'ok' },
  agent: null,
  single: null,
};

// The drawing's variant table: one row per distinct part, the row picks the part the sheet draws.
export function VariantTable({ narrowing, make, selection, onPick }: Props) {
  const rows = variantRows(narrowing, make);
  const uid = useId();
  const group = useRef<HTMLDivElement>(null);
  const differs = rows[0]?.differs ?? [];
  const status = STATUS[selection.status];
  const cols = ['vt-row', differs.length === 0 && 'vt-row--nodiff'].filter(Boolean).join(' ');

  const hidden = hiddenVariantIds(rows, selection);
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set());
  const toggleApplicability = (id: string) =>
    setExpanded((prev) => {
      const next = new Set(prev);
      if (!next.delete(id)) next.add(id);
      return next;
    });

  const root = useRef<HTMLDivElement>(null);
  const checkedId = selection.cluster?.id;
  const mounted = useRef(false);
  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;
      return;
    }
    const reduce = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    root.current?.scrollIntoView?.({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
  }, [checkedId]);
  const ordered = orderedRows(rows, selection);
  const shown = ordered.filter((r) => !hidden.includes(r.id));
  const folded = ordered.filter((r) => hidden.includes(r.id));
  const reachable = open ? [...shown, ...folded] : shown;

  const move = (e: KeyboardEvent, id: string) => {
    const step = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0;
    if (step === 0) return;
    e.preventDefault();
    const at = reachable.findIndex((r) => r.id === id);
    const next = (at + step + reachable.length) % reachable.length;
    onPick(reachable[next]!.id);
    group.current?.querySelectorAll<HTMLButtonElement>('[role="radio"]:not([inert] *)')[next]?.focus();
  };

  const renderRow = (r: VariantRow) => {
    const checked = r.id === selection.cluster?.id;
    const ref = r.oe ?? r.representative;
    const mark = variantMark(selection, r.id);
    const count = r.breakpoints.length + r.variantNotes.length;
    const panelId = `${uid}-app-${r.id}`;
    const isOpen = expanded.has(r.id);
    return (
      <div key={r.id} className={`vt-item ${cols}`} data-checked={checked || undefined} data-mark={mark ?? undefined}>
        <span className="vt-ref">
          <span className="vt-oe">
            <button type="button" role="radio" aria-checked={checked} aria-describedby={`${uid}-${r.id}`} tabIndex={checked ? 0 : -1} className="vt-pick" onClick={() => onPick(r.id)} onKeyDown={(e) => move(e, r.id)}>
              <b className="m">{ref.number}</b>
            </button>
            <CopyRef text={ref.number} />
          </span>
          <small id={`${uid}-${r.id}`}>
            {r.representative.brand} <span className="m">{r.representative.number}</span> · {formatCount(r.articleCount)} réf.
          </small>
          {count > 0 && (
            <button type="button" className="vt-app-toggle" aria-expanded={isOpen} aria-controls={panelId} onClick={() => toggleApplicability(r.id)}>
              Applicabilité · {count}
            </button>
          )}
        </span>
        {differs.length > 0 && (
          <span className="vt-diff m">
            {r.differs.map((d) => (
              <span key={d.key}>
                <span className="vt-k">{d.label} </span>
                {d.text || '—'}
              </span>
            ))}
          </span>
        )}
        {count > 0 && (
          <div className="vt-app" id={panelId} hidden={!isOpen}>
            <span className="vt-app-scope">{narrowing.applicabilityScope === 'vehicle' ? 'Ce véhicule' : 'Par article'}</span>
            {r.breakpoints.map((b) => (
              <span key={b.label}>
                {b.label} <span className="m">{b.values.join(' · ')}</span>
              </span>
            ))}
            {r.variantNotes.map((note) => (
              <span key={note.ref}>
                <span className="m">{note.ref}</span> : {note.text}
              </span>
            ))}
          </div>
        )}
        {mark === 'retained' && (
          <span className="vt-verdict">
            <span className="tag agent">Votre véhicule</span>
            {selection.verdict?.basis && <span className="vt-why">d’après {selection.verdict.basis}</span>}
          </span>
        )}
        {mark === 'aside' && <span className="vt-verdict vt-why">Ne correspond pas{selection.verdict?.setAside ? ` : ${selection.verdict.setAside}` : ''}</span>}
      </div>
    );
  };

  return (
    <div ref={root} className="c s3 vt">
      <div className="vt-top">
        <span className="l" id="vt-title">
          Variantes · {formatCount(rows.length)} pièces distinctes
        </span>
        {status && <span className={status.tone ? `tag ${status.tone}` : 'tag'}>{status.text}</span>}
      </div>
      <div className={`${cols} vt-head`} aria-hidden="true">
        <span>Réf. OE</span>
        {differs.length > 0 && <span>{differs.map((d) => d.label).join(' · ')}</span>}
      </div>
      <div ref={group} role="radiogroup" aria-labelledby="vt-title" className="vt-rows">
        {shown.map(renderRow)}
        {folded.length > 0 && (
          <div className="vt-more" id={`${uid}-more`} data-open={open} {...(open ? {} : { inert: '' })}>
            <div className="vt-more-in">{folded.map(renderRow)}</div>
          </div>
        )}
      </div>
      {hidden.length > 0 && (
        <button type="button" className="vt-toggle" aria-expanded={open} aria-controls={`${uid}-more`} onClick={() => setOpen(!open)}>
          {othersLabel(hidden.length, open)}
        </button>
      )}
    </div>
  );
}
