import { useEffect, useMemo, useRef, useState } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { tickMarks } from './gauge';
import type { NoyauState } from './state';
import './noyau.css';

const VIEW = 132;
const C = VIEW / 2;


/**
 * Le Noyau — the agent embodied as a diagnostic instrument: a machined lens/iris that READS the car.
 * The aperture (pupil) dilates when listening, contracts to a focused point when working, and settles
 * to the result color when done — so the four states differ by APERTURE (a shape cue), not color alone.
 * Pure presentation: it renders whatever NoyauState says. Hide it and not one value is lost — the orb
 * conveys urgency, the truth-panel/plan-list carry the data. Visual only — narration/steps live in
 * `NoyauVoice`, a separate mount sharing this component's `.noyau-stage` CSS root.
 */
export function Noyau({
  state,
  reduced = false,
  onClearEmber,
  onEmberActivate,
}: {
  state: NoyauState;
  reduced?: boolean;
  onClearEmber?: () => void;
  /** C2: clicking the ember OPENS the à-décider inbox (which also mutes the badge). When absent (the
   *  Stage-A playground has no inbox) the click falls back to a bare badge acknowledge. */
  onEmberActivate?: () => void;
}) {
  const t = useT();
  const ticks = useMemo(() => tickMarks(60, C, C, 53, 59, 5), []);
  const [rings, setRings] = useState<number[]>([]);
  const prevPulse = useRef(0);

  useEffect(() => {
    if (!reduced && state.pulseCount > prevPulse.current) {
      const id = state.pulseCount;
      setRings((r) => [...r, id]);
      window.setTimeout(() => setRings((r) => r.filter((x) => x !== id)), 1000);
    }
    prevPulse.current = state.pulseCount;
  }, [state.pulseCount, reduced]);

  const stop = (color: string) => ({ stopColor: color });

  return (
    <div
      className="noyau-stage noyau-socket"
      data-phase={state.phase}
      data-result={state.result ?? undefined}
      data-driver={state.driver ?? undefined}
      data-quiet={state.quiet ? 'true' : undefined}
      data-reduced={reduced ? 'true' : undefined}
    >
      {/* orb + ember are SIBLINGS in a positioned wrap: the ember is a real <button> (keyboard-reachable,
          opens the à-décider inbox) and must NOT nest inside the orb (a non-interactive status graphic). */}
      <div className="noyau-orb-wrap">
      <div
        className="noyau-orb"
        role="img"
        aria-label={t.noyau.orbAria(state.phase, `${state.driver === 'pilot' ? ` · ${t.noyau.pilot}${state.quiet ? ` ${t.noyau.pilotWaiting}` : ''}` : ''}${state.result ? ` · ${t.noyau.signal[state.result]}` : ''}`)}
      >
        <svg className="noyau-lens" viewBox={`0 0 ${VIEW} ${VIEW}`} aria-hidden="true">
          <defs>
            <radialGradient id="nx-bezel" cx="38%" cy="30%" r="80%">
              <stop offset="0%" style={stop('var(--nx-chrome-hi)')} />
              <stop offset="60%" style={stop('var(--nx-chrome)')} />
              <stop offset="100%" style={stop('#0a0b0e')} />
            </radialGradient>
            <radialGradient id="nx-pupil" cx="50%" cy="50%" r="50%">
              <stop offset="0%" style={stop('var(--nx-fil-core)')} />
              <stop offset="70%" style={stop('var(--nx-fil-edge)')} />
              <stop offset="100%" style={{ stopColor: 'var(--nx-fil-edge)', stopOpacity: 0 }} />
            </radialGradient>
            <filter id="nx-blur">
              <feGaussianBlur stdDeviation="4" />
            </filter>
          </defs>

          {/* housing + lens well */}
          <circle cx={C} cy={C} r={61} fill="url(#nx-bezel)" />
          <circle cx={C} cy={C} r={53} fill="#0b0c0f" stroke="var(--nx-seam)" strokeWidth={0.75} />

          {/* gauge ticks — the instrument bezel */}
          <g className="noyau-ticks">
            {ticks.map((t, i) => (
              <line
                key={i}
                className={t.major ? 'noyau-tick noyau-tick--major' : 'noyau-tick'}
                x1={t.x1}
                y1={t.y1}
                x2={t.x2}
                y2={t.y2}
              />
            ))}
          </g>

          {/* lens refraction rings + faint reticle crosshair */}
          <circle className="noyau-lens-ring" cx={C} cy={C} r={44} fill="none" />
          <circle className="noyau-lens-ring" cx={C} cy={C} r={33} fill="none" />
          <line className="noyau-cross" x1={C - 50} y1={C} x2={C + 50} y2={C} />
          <line className="noyau-cross" x1={C} y1={C - 50} x2={C} y2={C + 50} />

          {/* scanning reticle — drifts at rest, locks/spins while working */}
          <circle className="noyau-scan" cx={C} cy={C} r={40} fill="none" strokeDasharray="2 9" />

          {/* the glint sweeps across the glass */}
          <ellipse className="noyau-glint" cx={C} cy={C} rx={12} ry={42} fill="rgba(255,255,255,0.4)" filter="url(#nx-blur)" />

          {/* the iris aperture + glowing pupil — dilates/contracts per state */}
          <circle className="noyau-iris" cx={C} cy={C} r={20} fill="none" stroke="var(--nx-emissive)" strokeWidth={1.25} />
          <circle className="noyau-pupil" cx={C} cy={C} r={15} fill="url(#nx-pupil)" filter="url(#nx-blur)" />

          {/* listening: a ring traces the circumference (a signal being received) */}
          <circle className="noyau-rim" cx={C} cy={C} r={48} fill="none" stroke="var(--nx-cyan-hi)" strokeWidth={1.2} strokeDasharray="3 5" />

          {/* red done: the reticle flares as a fault lamp */}
          <circle className="noyau-fault" cx={C} cy={C} r={50} fill="none" stroke="var(--nx-red-hi)" strokeWidth={1.5} />
        </svg>

        <div className="noyau-pulse-layer" aria-hidden="true">
          {rings.map((id) => (
            <span className="noyau-pulse-ring" key={id} />
          ))}
          {state.phase === 'done' && <span className="noyau-exhale" />}
        </div>
      </div>

        {/* The re-entry affordance is gated on the QUEUE, not the ember badge: once the inbox is opened the
            badge mutes (ember → 0, emberSeen) but the decisions remain, so closing it (Escape/blur) without
            resolving everything must NOT strand the queue. A muted variant reads as acknowledged (no alarm
            glow); a new decision un-mutes it (emberSeen → false). It unmounts only when the queue drains. */}
        {state.queue.length > 0 && (
          <button
            type="button"
            className="noyau-ember"
            data-muted={state.emberSeen ? 'true' : undefined}
            aria-label={t.noyau.pendingDecisions(state.queue.length)}
            aria-live="polite"
            onClick={(e) => {
              e.stopPropagation();
              (onEmberActivate ?? onClearEmber)?.();
            }}
          >
            {state.queue.length}
          </button>
        )}
      </div>

      <svg className="noyau-cradle" viewBox="0 0 120 26" aria-hidden="true">
        <ellipse cx="60" cy="8" rx="46" ry="7" fill="#1c1e22" stroke="rgba(180,196,210,0.18)" />
        <rect x="30" y="9" width="60" height="11" rx="3" fill="#16181c" stroke="rgba(180,196,210,0.12)" />
        <ellipse cx="60" cy="8" rx="30" ry="4" fill="var(--nx-emissive)" opacity="0.12" />
      </svg>
    </div>
  );
}
