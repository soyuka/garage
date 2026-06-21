import { Badge } from '../design/components/Badge';
import type { VehicleFact } from '../types';
import { safeHref } from './safeHref';

// French labels for the known fact keys; unknown keys fall back to a humanised form.
const FACT_LABELS: Record<string, string> = {
  oil_type: 'Type d’huile',
  oil_capacity: 'Capacité d’huile',
  coolant: 'Liquide de refroidissement',
  brake_fluid: 'Liquide de frein',
  spark_plug_ref: 'Référence bougie',
  oil_filter_ref: 'Référence filtre à huile',
};

export function factLabel(key: string): string {
  return FACT_LABELS[key] ?? key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function FactRow({ fact, onDelete }: { fact: VehicleFact; onDelete?: (key: string) => void }) {
  const badge = fact.verified ? (
    <Badge variant="verified" check>
      {fact.source}
    </Badge>
  ) : (
    <Badge>{fact.source}</Badge>
  );

  const href = safeHref(fact.url);

  return (
    <div className="fact-row">
      <span className="fact-key">{factLabel(fact.key)}</span>
      <span className="fact-value">
        {fact.value}
        {fact.unit ? <span className="fact-unit"> {fact.unit}</span> : null}
      </span>
      {href ? (
        // A web-sourced fact links to the page it was cited from. The URL is untrusted (agent /
        // web-search influenced), so only http(s) links are emitted — see safeHref().
        <a className="fact-source-link" href={href} target="_blank" rel="noreferrer" title={href}>
          {badge}
        </a>
      ) : (
        badge
      )}
      {onDelete ? (
        <button type="button" className="fact-delete" aria-label={`Retirer ${factLabel(fact.key)}`} title="Retirer" onClick={() => onDelete(fact.key)}>
          ×
        </button>
      ) : null}
    </div>
  );
}
