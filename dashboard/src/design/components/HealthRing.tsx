export interface HealthRingProps {
  /** Score 0–100. */
  value?: number;
  /** Pixel diameter. */
  size?: number;
  /** Arc thickness. */
  stroke?: number;
  /** Caption under the numeral; pass "" to hide. */
  label?: string;
  tone?: 'ok' | 'warn' | 'alarm';
  /** Draw the instrument tick-ring + dashed outer track (auto-off below 80px). */
  ticks?: boolean;
}

const TONE = { ok: 'var(--mpa-ok)', warn: 'var(--mpa-warn)', alarm: 'var(--mpa-alarm)' };

/** Circular score gauge (0–100), instrument style: a dashed outer track, a tick-ring, the value
 * arc (glowing) and a concentric inner hairline around a centred numeral. */
export function HealthRing({ value = 0, size = 148, stroke = 10, label = 'Indice de santé', tone = 'ok', ticks = true }: HealthRingProps) {
  const v = Math.max(0, Math.min(100, value));
  const cx = size / 2;
  const r = (size - stroke) / 2 - 6;        // value-arc radius
  const c = 2 * Math.PI * r;
  const off = c * (1 - v / 100);
  const color = TONE[tone];
  const showTicks = ticks && size >= 80;
  const rOuter = size / 2 - 2;              // dashed track radius
  const rTick = r + stroke / 2 + 4;         // tick ring radius
  const rInner = r - stroke / 2 - 5;        // inner hairline radius

  // 60 ticks around the dial; every 5th is a longer/brighter major tick.
  const tickNodes = showTicks
    ? Array.from({ length: 60 }, (_, i) => {
        const a = (i / 60) * 2 * Math.PI - Math.PI / 2;
        const major = i % 5 === 0;
        const len = major ? 6 : 3;
        const x1 = cx + Math.cos(a) * rTick;
        const y1 = cx + Math.sin(a) * rTick;
        const x2 = cx + Math.cos(a) * (rTick - len);
        const y2 = cx + Math.sin(a) * (rTick - len);
        return (
          <line key={i} x1={x1} y1={y1} x2={x2} y2={y2}
            stroke={major ? 'rgba(25,224,240,0.55)' : 'rgba(25,224,240,0.22)'}
            strokeWidth={major ? 1.5 : 1} />
        );
      })
    : null;

  return (
    <div style={{ position: 'relative', width: size, height: size, fontFamily: 'var(--mpa-font)' }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
        <defs>
          <filter id={`hud-glow-${tone}`} x="-30%" y="-30%" width="160%" height="160%">
            <feDropShadow dx="0" dy="0" stdDeviation="3" floodColor={color} floodOpacity="0.7" />
          </filter>
        </defs>
        {showTicks ? (
          <circle cx={cx} cy={cx} r={rOuter} fill="none" stroke="rgba(25,224,240,0.18)" strokeWidth={1}
            strokeDasharray="2 5" />
        ) : null}
        {tickNodes}
        <circle cx={cx} cy={cx} r={r} fill="none" stroke="rgba(25,224,240,0.10)" strokeWidth={stroke} />
        <circle cx={cx} cy={cx} r={r} fill="none" stroke={color} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={off} transform={`rotate(-90 ${cx} ${cx})`}
          filter={`url(#hud-glow-${tone})`} />
        {showTicks && rInner > 0 ? (
          <circle cx={cx} cy={cx} r={rInner} fill="none" stroke="rgba(25,224,240,0.16)" strokeWidth={1} />
        ) : null}
      </svg>
      <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
        <span style={{ fontSize: Math.round(size * 0.27), fontWeight: 600, letterSpacing: '-0.02em', lineHeight: 1, color: 'var(--mpa-text)', fontVariantNumeric: 'tabular-nums' }}>{v}</span>
        {label ? <span style={{ fontSize: 12, color: 'var(--mpa-muted)', marginTop: 4 }}>{label}</span> : null}
      </div>
    </div>
  );
}
