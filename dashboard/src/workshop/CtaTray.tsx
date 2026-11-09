import { useState } from 'react';
import { useT } from '../i18n/LocaleProvider';
import type { CtaAction } from '../types';
import { ChevronDown, ChevronUp, Close } from './Icons';
import type { CtaState } from './view';

/** An agent proposal, attached above the dock until it is acted on or dismissed — on every screen. */
export function CtaTray({ proposal, onAction, onDismiss, disabled }: { proposal: CtaState; onAction: (action: CtaAction) => void; onDismiss: () => void; disabled: boolean }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  return (
    <section className={`w-tray${open ? ' is-expanded' : ''}`} aria-label={t.workshop.command.proposal}>
      <header className="w-tray-head">
        <h2 className="w-tray-headline">{proposal.headline}</h2>
        {proposal.finding && (
          <button type="button" className="w-icon-button w-tray-expand" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-label={open ? t.workshop.command.collapse : t.workshop.command.expand} title={open ? t.workshop.command.collapse : t.workshop.command.expand}>
            {open ? <ChevronUp /> : <ChevronDown />}
          </button>
        )}
        <button type="button" className="w-icon-button w-tray-close" onClick={onDismiss} aria-label={t.workshop.command.dismiss} title={t.workshop.command.dismiss}>
          <Close />
        </button>
      </header>
      {proposal.finding && <p className="w-tray-finding">{proposal.finding}</p>}
      {proposal.actions.length > 0 && (
        <div className="w-actions">
          {proposal.actions.map((a, i) => (
            <button key={`${a.label}-${i}`} type="button" className={`w-button${i === 0 ? ' is-primary' : ''}`} onClick={() => onAction(a)} disabled={disabled && !a.patchTask}>
              {a.label}
            </button>
          ))}
          {disabled && <span className="w-wait">{t.workshop.waitingAgent}</span>}
        </div>
      )}
    </section>
  );
}
