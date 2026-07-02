import './cockpit.css';
import { formatHealthPct, formatReading } from '../format';
import { emptyToNull } from '../edit';
import { InlineEdit } from '../floor/InlineEdit';
import { UsageEdit } from '../floor/UsageEdit';
import type { MaintenanceHealth, Signal, VehicleSummary } from '../types';

// The cockpit — the ALWAYS-ON chrome over the 3D stage: a full-width top band carrying identity
// (make/model/plate/engine) + editable kilométrage. Unlike the on-demand overlays (spec constellation,
// échéance ring), this never etches out — the facts an operator needs at a glance on every visit, not
// just when the agent surfaces them. The parent (SceneView) owns placement; this component owns only
// internal styling.

/** "1.6 HDi · 9HZ", or just the one part present, or null when neither engine field is known. */
export function engineBadge(vehicle: { engineVariant?: string | null; engineCode?: string | null }): string | null {
  const parts = [vehicle.engineVariant, vehicle.engineCode].filter((p): p is string => !!p && p.trim() !== '');
  return parts.length > 0 ? parts.join(' · ') : null;
}

/** The SANTÉ stat integrated into the cockpit slab (was a floating top-right HUD): rounded % (§5, no
 * false precision) + the signal that drives its glow. Null when the rollup is absent or scoreless —
 * the cockpit then renders no stat at all. */
export function healthStat(health: MaintenanceHealth | undefined | null): { text: string; status: Signal | 'unknown' } | null {
  if (!health || health.score == null) return null;
  return { text: formatHealthPct(health.score), status: health.status };
}

/** The patch fields Cockpit ever writes — a narrow view of VehiclePatch (make/model/plate/engine/usage). */
export interface CockpitPatch {
  make?: string;
  model?: string;
  plate?: string | null;
  engineVariant?: string | null;
  engineCode?: string | null;
  usageValue?: number;
  usageUnit?: string;
}

/** SANTÉ beside the kilométrage — the number captioning the rim gauge below it, glowing its signal. */
function HealthStat({ health }: { health: MaintenanceHealth | undefined | null }) {
  const stat = healthStat(health);
  if (!stat) return null;
  return (
    <div className="at-cockpit-sante" data-signal={stat.status}>
      <span className="at-cockpit-sante-value">{stat.text}</span>
      <span className="at-label at-cockpit-stat-label">Santé</span>
    </div>
  );
}

export function Cockpit({
  vehicle,
  edit,
}: {
  vehicle: VehicleSummary;
  edit?: { onPatch: (patch: CockpitPatch) => void };
}) {
  const badge = engineBadge(vehicle);

  if (!edit) {
    return (
      <div className="at-cockpit">
        <p className="at-cockpit-name">
          {vehicle.make} {vehicle.model}
        </p>
        {(vehicle.plate || badge) && (
          <p className="at-cockpit-sub">
            {vehicle.plate}
            {vehicle.plate && badge && <span className="at-cockpit-sep"> · </span>}
            {badge}
          </p>
        )}
        <div className="at-cockpit-stats">
          <div className="at-cockpit-km">
            <span className="at-cockpit-km-value">
              {vehicle.usageValue != null ? formatReading(vehicle.usageValue, vehicle.usageUnit) : '—'}
            </span>
          </div>
          <HealthStat health={vehicle.health} />
        </div>
      </div>
    );
  }

  return (
    <div className="at-cockpit at-cockpit--edit">
      <div className="at-cockpit-identity">
        <p className="at-cockpit-name">
          <InlineEdit
            value={vehicle.make}
            ariaLabel="Modifier la marque"
            className="at-cockpit-name--edit"
            onCommit={(next) => edit.onPatch({ make: next })}
          />{' '}
          <InlineEdit
            value={vehicle.model}
            ariaLabel="Modifier le modèle"
            className="at-cockpit-name--edit"
            onCommit={(next) => edit.onPatch({ model: next })}
          />
        </p>
        <p className="at-cockpit-sub">
          <InlineEdit
            value={vehicle.plate ?? ''}
            placeholder="+ plaque"
            ariaLabel="Modifier la plaque d'immatriculation"
            className="at-cockpit-sub--edit"
            onCommit={(next) => edit.onPatch({ plate: emptyToNull(next) })}
          />
          <span className="at-cockpit-sep"> · </span>
          <InlineEdit
            value={vehicle.engineVariant ?? ''}
            placeholder="+ variante"
            ariaLabel="Modifier la variante moteur"
            className="at-cockpit-sub--edit"
            onCommit={(next) => edit.onPatch({ engineVariant: emptyToNull(next) })}
          />
          <span className="at-cockpit-sep"> · </span>
          <InlineEdit
            value={vehicle.engineCode ?? ''}
            placeholder="+ code moteur"
            ariaLabel="Modifier le code moteur"
            className="at-cockpit-sub--edit"
            onCommit={(next) => edit.onPatch({ engineCode: emptyToNull(next) })}
          />
        </p>
      </div>
      <div className="at-cockpit-stats">
        <div className="at-cockpit-km">
          <UsageEdit
            value={vehicle.usageValue ?? null}
            unit={vehicle.usageUnit ?? null}
            vehicleType={vehicle.vehicleType}
            onCommit={(value, unit) => edit.onPatch({ usageValue: value, usageUnit: unit })}
          />
        </div>
        <HealthStat health={vehicle.health} />
      </div>
    </div>
  );
}
