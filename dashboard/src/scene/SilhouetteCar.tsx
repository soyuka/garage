import type { BodyClass } from './CarProxy';
import { useT } from '../i18n/LocaleProvider';

// Rung c — a flat SVG car silhouette in the resolved paint color, a clean side profile per body-class.
// The lowest VISUAL rung before the flat list (rung d): no WebGL, still recognizably the vehicle.
// NOTE (B.2 deferral): the on-body hotspot/health overlay for this rung is NOT yet built — the 3D
// hotspots/glow/rim ride rungs a/b only. On rung c the telemetry lives in the truth-panel + cardinals.
// A 2D anchors-projected signal grid here is a tracked follow-up.

const PROFILE: Record<BodyClass, string> = {
  // side profiles, viewBox 0 0 400 180, baseline y≈150
  hatchback: 'M30,150 L30,118 Q40,86 92,80 L150,52 Q172,40 210,42 L300,52 Q336,58 350,86 L368,110 L368,150 Z',
  berline: 'M24,150 L24,116 Q34,88 86,82 L150,52 Q176,38 232,40 L312,52 Q352,60 366,88 L380,112 L380,150 Z',
  suv: 'M28,150 L28,104 Q38,72 96,66 L150,42 Q176,30 232,32 L320,44 Q360,52 372,80 L384,104 L384,150 Z',
  utilitaire: 'M22,150 L22,70 Q26,48 54,44 L150,40 Q176,36 196,40 L200,96 L378,96 L378,150 Z',
  moto: 'M70,150 Q60,118 96,110 L150,104 Q170,84 210,92 L250,104 Q286,108 300,128 L300,150 Z',
};

const WHEELS: Record<BodyClass, [number, number][]> = {
  hatchback: [[110, 150], [300, 150]],
  berline: [[104, 150], [312, 150]],
  suv: [[112, 150], [320, 150]],
  utilitaire: [[80, 150], [330, 150]],
  moto: [[96, 150], [288, 150]],
};

export function SilhouetteCar({ bodyClass, paint }: { bodyClass: BodyClass; paint: string }) {
  const t = useT();
  return (
    <svg className="at-silhouette" viewBox="0 0 400 180" role="img" aria-label={t.misc.vehicleSilhouette}>
      <path d={PROFILE[bodyClass]} fill={paint} stroke="rgba(255,255,255,0.12)" strokeWidth={1.5} />
      {WHEELS[bodyClass].map(([cx, cy], i) => (
        <g key={i}>
          <circle cx={cx} cy={cy} r={24} fill="#15171a" stroke="rgba(255,255,255,0.14)" strokeWidth={2} />
          <circle cx={cx} cy={cy} r={9} fill="#23272d" />
        </g>
      ))}
    </svg>
  );
}
