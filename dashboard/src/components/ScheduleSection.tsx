import { useState } from 'react';
import { Badge } from '../design/components/Badge';
import type { ServiceRuleInput } from '../garage';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import { actionLabel, formatCadence, ruleTitle } from '../schedule';
import type { ServiceRule } from '../types';
import { safeHref } from './safeHref';

const ACTIONS: ServiceRule['action'][] = ['change', 'replace', 'clean', 'inspect'];
const USAGE_UNITS: NonNullable<ServiceRuleInput['everyUsageUnit']>[] = ['km', 'mi', 'hours'];

/**
 * "Programme d'entretien" — the editable manual counterpart to the read-only ForecastSection (the
 * rules are the input, the forecast is the output). Mirrors the facts card: an add-row form, rows
 * that delete on hover, and a source/verified badge. Editing a rule re-opens the form prefilled and
 * re-POSTs the same identity (type, action, component), correcting the cadence in place (upsert).
 */
export function ScheduleSection({
  rules,
  loading = false,
  onSave,
  onDelete,
}: {
  rules: ServiceRule[] | undefined;
  loading?: boolean;
  onSave: (input: ServiceRuleInput) => void;
  onDelete: (ruleId: string) => void;
}) {
  const list = rules ?? [];
  const [adding, setAdding] = useState(false);
  // The rule being edited in the form, or null when adding fresh. Editing prefills + closes its row.
  const [editing, setEditing] = useState<ServiceRule | null>(null);
  const formOpen = adding || editing != null;

  return (
    <div className="schedule">
      <div className="panel-title">
        <span className="t">Programme d'entretien</span>
        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--mpa-space-3)' }}>
          <button
            type="button"
            className="task-clean"
            aria-expanded={formOpen}
            onClick={() => {
              if (formOpen) {
                setAdding(false);
                setEditing(null);
              } else {
                setAdding(true);
              }
            }}
          >
            {formOpen ? 'Annuler' : '+ Ajouter une règle'}
          </button>
          <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
            {list.length} règle{list.length > 1 ? 's' : ''}
          </span>
        </span>
      </div>

      {formOpen ? (
        <RuleForm
          rule={editing}
          onSubmit={(input) => {
            onSave(input);
            setAdding(false);
            setEditing(null);
          }}
        />
      ) : null}

      {loading ? (
        <div className="schedule-rows" aria-hidden="true">
          {[0, 1].map((i) => (
            <div key={i} className="schedule-row">
              <span className="skel" style={{ width: '45%', height: 13 }} />
              <span className="skel" style={{ width: '30%', height: 11 }} />
            </div>
          ))}
        </div>
      ) : list.length === 0 ? (
        <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>
          Aucune règle d'entretien. Ajoutez-en une pour piloter les prévisions.
        </div>
      ) : (
        <div className="schedule-rows">
          {list.map((r) => (
            <RuleRow key={r.id} rule={r} onEdit={() => setEditing(r)} onDelete={() => onDelete(r.id)} />
          ))}
        </div>
      )}
    </div>
  );
}

function RuleRow({ rule, onEdit, onDelete }: { rule: ServiceRule; onEdit: () => void; onDelete: () => void }) {
  const badge = rule.verified ? (
    <Badge variant="verified" check>
      {rule.source}
    </Badge>
  ) : (
    <Badge>{rule.source}</Badge>
  );
  const href = safeHref(rule.url);

  return (
    <div className="schedule-row">
      <button type="button" className="schedule-rule-main" onClick={onEdit} aria-label={`Modifier ${ruleTitle(rule)}`}>
        <span className="schedule-rule-title">{ruleTitle(rule)}</span>
        <span className="schedule-rule-cadence">{formatCadence(rule)}</span>
      </button>
      {href ? (
        <a className="fact-source-link" href={href} target="_blank" rel="noreferrer" title={href}>
          {badge}
        </a>
      ) : (
        badge
      )}
      <button type="button" className="fact-delete" aria-label={`Retirer ${ruleTitle(rule)}`} title="Retirer" onClick={onDelete}>
        ×
      </button>
    </div>
  );
}

/**
 * The add/edit form. When `rule` is set the fields are prefilled and the (type, action, component)
 * identity drives an in-place correction on submit (the server upserts on that key). At least one
 * cadence is required — the submit is disabled until one is set, so the 422 is a server-side backstop.
 */
function RuleForm({ rule, onSubmit }: { rule: ServiceRule | null; onSubmit: (input: ServiceRuleInput) => void }) {
  const [type, setType] = useState(rule?.type ?? 'oil_change');
  const [action, setAction] = useState<ServiceRule['action']>(rule?.action ?? 'change');
  const [component, setComponent] = useState(rule?.component ?? '');
  const [usageValue, setUsageValue] = useState(rule?.everyUsage ? String(rule.everyUsage.value) : '');
  const [usageUnit, setUsageUnit] = useState<NonNullable<ServiceRuleInput['everyUsageUnit']>>(rule?.everyUsage?.unit ?? 'km');
  const [months, setMonths] = useState(rule?.everyMonths != null ? String(rule.everyMonths) : '');
  const [first, setFirst] = useState(rule?.firstUsageValue != null ? String(rule.firstUsageValue) : '');

  const parseInt10 = (raw: string): number | null => {
    const v = Number.parseInt(raw.replace(/[^\d]/g, ''), 10);
    return Number.isNaN(v) ? null : v;
  };

  const usageNum = parseInt10(usageValue);
  const monthsNum = parseInt10(months);
  const firstNum = parseInt10(first);
  // The server's invariant: at least one cadence. Mirror it here so the submit is honest.
  const hasCadence = usageNum != null || monthsNum != null || firstNum != null;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!type.trim() || !hasCadence) return;
    onSubmit({
      type: type.trim(),
      action,
      component: component.trim() || null,
      everyUsageValue: usageNum,
      everyUsageUnit: usageNum != null ? usageUnit : null,
      everyMonths: monthsNum,
      firstUsageValue: firstNum,
    });
  };

  return (
    <form className="add-form schedule-form" onSubmit={submit}>
      <select className="add-input" aria-label="Type d'entretien" value={type} onChange={(e) => setType(e.target.value)}>
        {SERVICE_TYPES.map((t) => (
          <option key={t} value={t}>
            {serviceLabel(t)}
          </option>
        ))}
      </select>
      <select
        className="add-input"
        aria-label="Action"
        value={action}
        onChange={(e) => setAction(e.target.value as ServiceRule['action'])}
      >
        {ACTIONS.map((a) => (
          <option key={a} value={a}>
            {actionLabel(a)}
          </option>
        ))}
      </select>
      <input
        className="add-input add-input-grow"
        placeholder="composant (optionnel)"
        value={component}
        onChange={(e) => setComponent(e.target.value)}
      />
      <input
        className="add-input add-input-sm"
        inputMode="numeric"
        placeholder="tous les …"
        aria-label="Intervalle d'usage"
        value={usageValue}
        onChange={(e) => setUsageValue(e.target.value)}
      />
      <select className="add-input add-input-xs" aria-label="Unité d'intervalle" value={usageUnit} onChange={(e) => setUsageUnit(e.target.value as NonNullable<ServiceRuleInput['everyUsageUnit']>)}>
        {USAGE_UNITS.map((u) => (
          <option key={u} value={u}>
            {u === 'hours' ? 'h' : u}
          </option>
        ))}
      </select>
      <input
        className="add-input add-input-sm"
        inputMode="numeric"
        placeholder="… mois"
        aria-label="Intervalle en mois"
        value={months}
        onChange={(e) => setMonths(e.target.value)}
      />
      <input
        className="add-input add-input-sm"
        inputMode="numeric"
        placeholder="1ʳᵉ à …"
        aria-label="Premier intervalle (rodage)"
        value={first}
        onChange={(e) => setFirst(e.target.value)}
      />
      <button type="submit" className="add-submit" disabled={!type.trim() || !hasCadence} title={!hasCadence ? 'Indiquez au moins un intervalle' : undefined}>
        {rule ? 'Corriger' : 'Ajouter'}
      </button>
    </form>
  );
}
