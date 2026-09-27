import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatReading } from '../format';
import { serviceLabel } from '../labels';
import { SilhouetteCar } from './SilhouetteCar';
import { bodyClassFor } from './CarProxy';
import { parcSignal, tagFor, paintFor, horizonFor, type DepartureOrigin } from './ParcLot';
import { departureTransform } from './departure';
import { MOTION } from '../tokens/motion';
import './parclot.css';

// CHOREOGRAPHY § "One stage — the lot and the podium", row 1: the departure ghost. A `position: fixed`
// clone of the clicked card, fixed at its OWN rect, whose silhouette drives to the hero podium while
// growing (~1.6x) and fading to 0. Rendered at the Atelier root — independent of which route is
// currently mounted, so it survives the hash change that swaps the lot for the hero underneath it.
//
// It renders the flat SVG silhouette (never the live spinning WebGL thumb): the thumb only exists
// inside drei <View>s that render through ParcLot's ONE shared <Canvas> — hosting a <View> here, at the
// Atelier root, detached from that canvas, is not how drei <View> works (it tracks a div that must be a
// descendant of the same <Canvas>'s tree). The silhouette is the documented fallback rung (rung c) and
// reads identically at a glance — a spinning thumb mid-flight would be lost motion anyway.

export function Departure({
  origin,
  reduced,
  onDone,
}: {
  origin: DepartureOrigin | null;
  reduced: boolean;
  onDone: () => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  const [driven, setDriven] = useState(false);
  const doneRef = useRef(onDone);
  doneRef.current = onDone;

  useEffect(() => {
    if (!origin || reduced) return;
    setDriven(false);
    const raf = requestAnimationFrame(() => setDriven(true));
    // Fallback in case transitionend never fires (a hidden tab, a dropped frame) — never strand the
    // ghost on screen forever.
    const fallback = window.setTimeout(() => doneRef.current(), MOTION.etch + 200);
    return () => {
      cancelAnimationFrame(raf);
      window.clearTimeout(fallback);
    };
  }, [origin, reduced]);

  if (!origin || reduced) return null;
  const { rect, vehicle: v } = origin;

  const signal = parcSignal(v);
  const tag = tagFor(signal, locale);
  const bodyClass = bodyClassFor(v.vehicleType);
  const paint = paintFor(v.make);
  const sub = [v.year, v.engineVariant].filter(Boolean).join(' · ');
  const horizon = v.nextDue ? horizonFor(v.nextDue, locale) : null;
  const name = [v.make, v.model].filter(Boolean).join(' ') || t.parc.unnamed;

  const xform = departureTransform(rect, { width: window.innerWidth, height: window.innerHeight });
  const style: CSSProperties = {
    position: 'fixed',
    top: rect.top,
    left: rect.left,
    width: rect.width,
    height: rect.height,
    margin: 0,
    zIndex: 60,
    pointerEvents: 'none',
    transform: driven ? `translate(${xform.translateX}px, ${xform.translateY}px) scale(${xform.scale})` : 'translate(0, 0) scale(1)',
    opacity: driven ? 0 : 1,
    transition: `transform var(--at-dur-etch) var(--at-ease-out, ease-out), opacity var(--at-dur-etch) var(--at-ease-out, ease-out)`,
  };

  return (
    <div
      className="at-parc-card glass"
      style={style}
      data-departing="true"
      data-driven={driven ? 'true' : undefined}
      onTransitionEnd={(event) => {
        if (driven && event.target === event.currentTarget && event.propertyName === 'transform') onDone();
      }}
      aria-hidden="true"
    >
      <div className="at-parc-stage">
        <span className="at-parc-ring" aria-hidden="true" />
        <SilhouetteCar bodyClass={bodyClass} paint={paint} />
      </div>
      <div className="at-parc-head">
        {v.plate && <span className="at-parc-plate">{v.plate}</span>}
        <span className="at-parc-name">{name}</span>
        {sub && <span className="at-parc-sub">{sub}</span>}
      </div>
      <div className="at-parc-meta">
        <span className="at-label at-parc-meta-label">{t.parc.usage}</span>
        <span className="at-parc-mileage">{v.usageValue != null ? formatReading(v.usageValue, v.usageUnit, locale) : '—'}</span>
      </div>
      <div className="at-parc-due">
        <span className="at-parc-dot at-dot" aria-hidden="true" />
        <span className="at-parc-due-text">
          {v.nextDue ? (
            <>
              <span className="at-parc-due-label">{serviceLabel(v.nextDue.type, locale)}</span>
              {horizon && <span className="at-parc-due-horizon">{horizon}</span>}
            </>
          ) : (
            <span className="at-parc-due-label at-parc-due-none">{t.parc.nothingDue}</span>
          )}
        </span>
        <span className={`at-tag at-tag--${tag.tone}`}>{tag.text}</span>
      </div>
    </div>
  );
}
