import { useId, useRef, type KeyboardEvent } from 'react';
import { formatCount } from '../format';
import type { Narrowing } from '../types';
import { variantMark, variantRows, type Selection } from '../variants';
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
  const withApplicability = rows.some((r) => r.breakpoints.length > 0);
  const status = STATUS[selection.status];
  const cols = ['vt-row', differs.length === 0 && 'vt-row--nodiff', !withApplicability && 'vt-row--noapp'].filter(Boolean).join(' ');

  const move = (e: KeyboardEvent, index: number) => {
    const step = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1 : e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0;
    if (step === 0) return;
    e.preventDefault();
    const next = (index + step + rows.length) % rows.length;
    onPick(rows[next]!.id);
    group.current?.querySelectorAll<HTMLButtonElement>('[role="radio"]')[next]?.focus();
  };

  return (
    <div className="c s3 vt">
      <div className="vt-top">
        <span className="l" id="vt-title">
          Variantes · {formatCount(rows.length)} pièces distinctes
        </span>
        {status && <span className={status.tone ? `tag ${status.tone}` : 'tag'}>{status.text}</span>}
      </div>
      <div className={`${cols} vt-head`} aria-hidden="true">
        <span>Var.</span>
        <span>Réf. OE</span>
        {differs.length > 0 && <span>{differs.map((d) => d.label).join(' · ')}</span>}
        {withApplicability && <span>Applicabilité (par article)</span>}
      </div>
      <div ref={group} role="radiogroup" aria-labelledby="vt-title" className="vt-rows">
        {rows.map((r, i) => {
          const checked = i === selection.index;
          const ref = r.oe ?? r.representative;
          const mark = variantMark(selection, r.id);
          return (
            <div key={r.id} className={`vt-item ${cols}`} data-checked={checked || undefined} data-mark={mark ?? undefined}>
              <button type="button" role="radio" aria-checked={checked} aria-describedby={`${uid}-${r.id}`} tabIndex={checked ? 0 : -1} className="vt-pick" onClick={() => onPick(r.id)} onKeyDown={(e) => move(e, i)}>
                <span className="vt-letter m">
                  <span className="sr-only">Variante </span>
                  {r.letter}
                </span>
              </button>
              <span className="vt-ref" id={`${uid}-${r.id}`}>
                <span className="vt-oe">
                  <b className="m">{ref.number}</b>
                  <CopyRef text={ref.number} />
                </span>
                <small>
                  {r.representative.brand} <span className="m">{r.representative.number}</span> · {formatCount(r.articleCount)} réf.
                </small>
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
              {withApplicability && (
                <span className="vt-app">
                  {r.breakpoints.length === 0
                    ? '—'
                    : r.breakpoints.map((b) => (
                        <span key={b.label}>
                          {b.label} <span className="m">{b.values.join(' · ')}</span>
                        </span>
                      ))}
                </span>
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
        })}
      </div>
      <p className="vt-note">Applicabilité du catalogue, à vérifier sur la pièce démontée.</p>
    </div>
  );
}
