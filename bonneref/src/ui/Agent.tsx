import { safeHref } from '../safeHref';
import { isBusy } from '../agent';
import { askChoices } from '../question';
import type { AgentAsk, AgentPart, TurnState, Vehicle, WebPart } from '../types';
import { CopyRef } from './CopyRef';
import { vehicleEngine, vehicleName } from './VehicleChip';

interface StatusProps {
  state: TurnState;
  // The sheet shows the agent's live sentence: the dock keeps the question, the cancel button and the outcome.
  narrated: boolean;
  needsLogin: string | null;
  onCancel: () => void;
  onRetry: () => void;
  onSignIn: () => void;
}

// Above the composer: what was asked and how it ended, the cancel button while busy. What the agent is doing
// is told under the drawing when the sheet is up (`narrated`).
export function AgentStatus({ state, narrated, needsLogin, onCancel, onRetry, onSignIn }: StatusProps) {
  if (needsLogin !== null) {
    return (
      <div className="dock-status">
        <p className="dock-line">
          <span className="dock-q">« {needsLogin} »</span>
          <span>Connexion requise. La recherche reprend au retour.</span>
        </p>
        <button type="button" className="btn pri" onClick={onSignIn}>
          Se connecter
        </button>
      </div>
    );
  }
  if (state.phase === 'idle') return null;
  const busy = isBusy(state);
  return (
    <div className="dock-status" aria-busy={busy}>
      <p className="dock-line">
        <span className="dock-q">« {state.instruction} »</span>
        {busy && !narrated && (
          <span className="agent-status" role="status">
            <span className="agent-dot" aria-hidden="true" />
            {state.status ?? 'Lecture de la demande…'}
          </span>
        )}
        {!busy && state.notice && (
          <span className={state.phase === 'failed' ? 'agent-note agent-note--error' : 'agent-note'} role={state.phase === 'failed' ? 'alert' : 'status'}>
            {state.notice}
          </span>
        )}
      </p>
      {busy && (
        <button type="button" className="btn btn--quiet" onClick={onCancel}>
          Interrompre
        </button>
      )}
      {state.phase === 'failed' && (
        <button type="button" className="btn btn--quiet" onClick={onRetry}>
          Réessayer
        </button>
      )}
    </div>
  );
}

export function AgentQuestion({ ask, disabled, onAnswer, onPlate, onLabel }: { ask: AgentAsk; disabled: boolean; onAnswer: (value: string) => void; onPlate: () => void; onLabel: () => void }) {
  return (
    <section className="ask" aria-labelledby="agent-ask-title">
      <h2 id="agent-ask-title" className="sr-only">
        Question
      </h2>
      <p className="ask-q">{ask.question}</p>
      <p className="ask-hint">Ou répondez dans le champ.</p>
      <div className="ask-answers" role="group" aria-label="Réponses">
        {askChoices(ask.options, ask.question).map((c) =>
          c.kind !== 'agent' ? (
            <button key={c.kind} type="button" className="btn" disabled={disabled} onClick={c.kind === 'plate' ? onPlate : onLabel}>
              {c.label}
            </button>
          ) : (
            <button key={c.value} type="button" className="btn" disabled={disabled} onClick={() => onAnswer(c.value)}>
              {c.label}
            </button>
          ),
        )}
      </div>
    </section>
  );
}

export function PartSwitcher({ parts, selected, onSelect }: { parts: AgentPart[]; selected: string; onSelect: (key: string) => void }) {
  return (
    <div className="parts" role="tablist" aria-label="Pièces trouvées">
      {parts.map((p, i) => (
        <button
          key={p.key}
          type="button"
          role="tab"
          aria-selected={p.key === selected}
          className="part-tab"
          onClick={() => onSelect(p.key)}
          onKeyDown={(e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const next = parts[(i + (e.key === 'ArrowRight' ? 1 : parts.length - 1)) % parts.length];
            if (next) onSelect(next.key);
          }}
        >
          <span className="part-n m">{String(i + 1).padStart(2, '0')}</span>
          {p.label}
          {p.kind === 'web' && <span className="tag">web</span>}
        </button>
      ))}
    </div>
  );
}

const hostOf = (url: string): string => {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return url;
  }
};

export function WebTitleBlock({ vehicle, part }: { vehicle: Vehicle | null; part: WebPart }) {
  return (
    <div className="tb" aria-label="Cartouche du résultat">
      <div className="c s3">
        <span className="l">Désignation</span>
        <b className="tb-title">{part.label}</b>
      </div>
      <div className="c s3">
        <span className="l">{part.brand ? `Réf. · ${part.brand}` : 'Référence'}</span>
        <div className="oe-line">
          <div className="oe m">{part.reference}</div>
          <CopyRef text={part.reference} />
        </div>
      </div>
      {vehicle ? (
        <>
          <div className="c s2">
            <span className="l">Véhicule</span>
            {vehicle.plate && <><span className="m tb-plate">{vehicle.plate}</span> · </>}{vehicleName(vehicle)} {vehicleEngine(vehicle) && <span className="tb-muted">· {vehicleEngine(vehicle)}</span>}
          </div>
          <div className="c r">
            <span className="l">kType</span>
            <span className="m tb-ktype">{vehicle.carId}</span>
          </div>
        </>
      ) : (
        <div className="c s3">
          <span className="l">Véhicule</span>
          <span className="tb-muted">Non identifié. Vérifiez la compatibilité avant de commander.</span>
        </div>
      )}
      <div className="c s3">
        <span className="l">Source : web</span>
        <a className="tb-source" href={safeHref(part.sourceUrl)} target="_blank" rel="noopener noreferrer">
          {part.sourceTitle || hostOf(part.sourceUrl)}
          <span className="m">{hostOf(part.sourceUrl)}</span>
        </a>
      </div>
      <div className="c s3 why">
        <span className="l">Pourquoi cette pièce</span>
        {part.rationale && <span className="why-agent">{part.rationale}</span>}
        <span className="why-degraded">Hors catalogue TecDoc. Vérifiez la réf. sur la source avant de commander.</span>
      </div>
    </div>
  );
}
