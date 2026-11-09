import type { ReactNode } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatEuros } from '../format';
import { factLabel } from '../labels';
import type { PlannedPart, SpecNode, VehicleFact } from '../types';
import { factValues } from './factValue';
import { meshForPart } from './partMeshes';
import { PartThumb } from './PartView';
import { partDisplay } from './shelf';

export function Module({ title, tone, className = '', children, aside, labelledBy }: { title: string; tone?: 'blue'; className?: string; children: ReactNode; aside?: ReactNode; labelledBy?: string }) {
  return (
    <section className={`w-mod ${className}`} aria-labelledby={labelledBy}>
      <header className="w-mod-head">
        <h2 id={labelledBy} className={`w-mod-title${tone ? ` w-mod-title--${tone}` : ''}`}>
          {title}
        </h2>
        {aside}
      </header>
      <div className="w-mod-body">{children}</div>
    </section>
  );
}

export function PartRows({ parts, itemType, thumbs = false, tone, onHover }: { parts: PlannedPart[]; itemType?: string; thumbs?: boolean; tone?: 'over'; onHover?: (part: PlannedPart | null) => void }) {
  const [locale] = useLocale();
  return (
    <ul className={`w-rows${thumbs ? ' w-rows--parts' : ''}`}>
      {parts.map((p, i) => (
        <li
          key={p.id ?? `${p.ref}-${i}`}
          className="w-row"
          onMouseEnter={onHover ? () => onHover(p) : undefined}
          onMouseLeave={onHover ? () => onHover(null) : undefined}
          onFocus={onHover ? () => onHover(p) : undefined}
          onBlur={onHover ? () => onHover(null) : undefined}
        >
          {thumbs && <PartThumb tone={tone} file={meshForPart(p, itemType)} label={[p.brand, p.label, p.ref].filter(Boolean).join(' ')} />}
          <span className="w-row-main" title={partDisplay(p, itemType, locale).name}>{partDisplay(p, itemType, locale).name}</span>
          <span className="w-data w-row-ref">{p.ref}</span>
          {p.qty != null && p.qty !== 1 && <span className="w-data w-row-dim">×{p.qty}</span>}
          {p.priceCents != null && <span className="w-data w-row-end">{formatEuros(p.priceCents * (p.qty ?? 1), locale)}</span>}
        </li>
      ))}
    </ul>
  );
}

export function FactRows({ facts, spotlit = [] }: { facts: VehicleFact[]; spotlit?: string[] }) {
  const t = useT();
  const [locale] = useLocale();
  const lit = new Set(spotlit);
  return (
    <dl className="w-kv">
      {facts.map((f) => (
        <div key={f.key} className={`w-kv-row${lit.has(f.key) ? ' is-lit' : ''}`}>
          <dt>{factLabel(f.key, locale)}</dt>
          <dd>
            {factValues(f.value, f.unit, locale).map((v, k) => (
              <span key={k} className="w-data w-fact-tag">
                {v}
              </span>
            ))}
            {f.verified && <span className="w-verified">{t.workshop.facts.verified}</span>}
          </dd>
        </div>
      ))}
    </dl>
  );
}

export function NodeRows({ nodes }: { nodes: SpecNode[] }) {
  const t = useT();
  return (
    <dl className="w-kv">
      {nodes.map((n) => (
        <div key={n.key} className="w-kv-row">
          <dt>{n.label}</dt>
          <dd>
            <span className="w-data">{n.value}</span>
            {n.info && <span className="w-kv-info">{n.info}</span>}
            {n.verified && <span className="w-verified">{t.workshop.facts.verified}</span>}
          </dd>
        </div>
      ))}
    </dl>
  );
}
