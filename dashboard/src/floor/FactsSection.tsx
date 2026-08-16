import { useState } from 'react';
import { AgentMark } from '../AgentMark';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { factLabel } from '../labels';
import { isAgentAuthored } from '../provenance';
import { safeHref } from '../safeHref';
import type { VehicleFact } from '../types';
import { InlineEdit } from './InlineEdit';

// The vehicle facts/specs surface on the truth-panel (#9). Facts ARE truth (oil grade, capacities,
// part references), so they live on the authoritative panel — always shown read-only, and gaining an
// add-form + per-row remove/edit when the live hero passes edit handlers. A verified fact (catalog
// source) carries a check; a web-sourced fact links to its citation through safeHref (untrusted url).
// Rebuilt fresh in the Atelier idiom (the old facts.tsx is reference only).

export interface FactsEdit {
  onAdd: (fact: { key: string; value: string; unit?: string | null }) => void;
  onRemove: (key: string) => void;
  /** Edit a value in place — re-records the key as a source "user" fact (the projection ranks it top). */
  onEditValue: (key: string, value: string, unit?: string | null) => void;
}

export function FactsSection({ facts, edit }: { facts: VehicleFact[]; edit?: FactsEdit }) {
  const t = useT();
  // No facts and no way to add → render nothing (keeps the read-only floor/playground panel clean).
  if (facts.length === 0 && !edit) return null;
  return (
    <div className="at-truth-block at-facts">
      <span className="at-label">{t.facts.title}</span>
      <ul className="at-facts-list">
        {facts.map((f) => (
          <FactRow key={f.key} fact={f} edit={edit} />
        ))}
        {facts.length === 0 && <li className="at-facts-empty">{t.facts.empty}</li>}
      </ul>
      {edit && <FactAdd onAdd={edit.onAdd} />}
    </div>
  );
}

function FactRow({ fact, edit }: { fact: VehicleFact; edit?: FactsEdit }) {
  const [locale] = useLocale();
  const t = useT();
  const href = safeHref(fact.url);
  const badge = (
    <span className={`at-fact-source${fact.verified ? ' at-fact-source--verified' : ''}`} title={fact.verified ? t.facts.verifiedSource : t.facts.source(fact.source)}>
      {fact.verified ? '✓ ' : ''}
      {fact.source}
    </span>
  );
  return (
    <li className="at-fact-row">
      <span className="at-fact-key">{factLabel(fact.key, locale)}</span>
      <span className="at-fact-value">
        {edit ? (
          <InlineEdit value={fact.value} ariaLabel={t.facts.edit(factLabel(fact.key, locale))} onCommit={(v) => edit.onEditValue(fact.key, v, fact.unit)} />
        ) : (
          fact.value
        )}
        {fact.unit ? <span className="at-fact-unit">{fact.unit}</span> : null}
      </span>
      <span className="at-fact-source-wrap">
        {href ? (
          // A web-sourced fact links to the page it was cited from; safeHref permits only http(s).
          <a className="at-fact-link" href={href} target="_blank" rel="noreferrer" title={href}>
            {badge}
          </a>
        ) : (
          badge
        )}
        {isAgentAuthored(fact.source) && <AgentMark />}
      </span>
      {edit && (
        <button type="button" className="at-fact-del" aria-label={t.facts.remove(factLabel(fact.key, locale))} title={t.facts.removeShort} onClick={() => edit.onRemove(fact.key)}>
          ×
        </button>
      )}
    </li>
  );
}

function FactAdd({ onAdd }: { onAdd: FactsEdit['onAdd'] }) {
  const t = useT();
  const [key, setKey] = useState('');
  const [value, setValue] = useState('');
  const [unit, setUnit] = useState('');
  const canAdd = key.trim() !== '' && value.trim() !== '';
  const submit = () => {
    if (!canAdd) return;
    onAdd({ key: key.trim(), value: value.trim(), unit: unit.trim() || null });
    setKey('');
    setValue('');
    setUnit('');
  };
  const onEnter = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter') submit();
  };
  return (
    <div className="at-fact-add">
      <input className="at-fact-add-key" placeholder={t.facts.keyPlaceholder} aria-label={t.facts.keyLabel} value={key} onChange={(e) => setKey(e.target.value)} onKeyDown={onEnter} />
      <input className="at-fact-add-val" placeholder={t.facts.valuePlaceholder} aria-label={t.facts.valueLabel} value={value} onChange={(e) => setValue(e.target.value)} onKeyDown={onEnter} />
      <input className="at-fact-add-unit" placeholder={t.facts.unitPlaceholder} aria-label={t.facts.unitLabel} value={unit} onChange={(e) => setUnit(e.target.value)} onKeyDown={onEnter} />
      <button type="button" className="at-fact-add-btn" disabled={!canAdd} onClick={submit}>
        {t.facts.add}
      </button>
    </div>
  );
}
