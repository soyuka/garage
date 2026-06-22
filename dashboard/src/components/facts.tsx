import { Badge } from '../design/components/Badge';
import type { VehicleFact } from '../types';
import { InlineEdit } from './InlineEdit';
import { safeHref } from './safeHref';

// French labels for the known fact keys; unknown keys fall back to a humanised form. The `{type}_ref`
// family mirrors the part-derived facts the backend projects from logged maintenance items.
const FACT_LABELS: Record<string, string> = {
  oil_type: 'Type d’huile',
  oil_capacity: 'Capacité d’huile',
  coolant: 'Liquide de refroidissement',
  brake_fluid: 'Liquide de frein',
  spark_plug_ref: 'Référence bougie',
  oil_filter_ref: 'Référence filtre à huile',
  air_filter_ref: 'Référence filtre à air',
  fuel_filter_ref: 'Référence filtre à carburant',
  cabin_filter_ref: 'Référence filtre habitacle',
  timing_belt_ref: 'Référence courroie de distribution',
  battery_ref: 'Référence batterie',
  brakes_ref: 'Référence freins',
  tires_ref: 'Référence pneus',
  gearbox_oil_ref: 'Référence huile de boîte',
};

export function factLabel(key: string): string {
  return FACT_LABELS[key] ?? key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function FactRow({
  fact,
  onDelete,
  onEdit,
}: {
  fact: VehicleFact;
  onDelete?: (key: string) => void;
  // Commit an edited value; the existing unit is carried so an in-place edit never drops it. The
  // write lands as a source "user" fact, which the projection ranks above catalog/log — so editing
  // works uniformly, even over a verified spec or a part-derived value.
  onEdit?: (key: string, value: string, unit?: string | null) => void;
}) {
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
        {onEdit ? (
          <InlineEdit
            value={fact.value}
            ariaLabel={`Modifier ${factLabel(fact.key)}`}
            className="fact-value-edit"
            onCommit={(v) => onEdit(fact.key, v, fact.unit)}
          />
        ) : (
          fact.value
        )}
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
