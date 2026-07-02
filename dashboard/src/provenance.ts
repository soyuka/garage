// Provenance mark (C3): the agent signs what it writes (Shape-of-AI "Identifier"). The real backend
// `source` enums (LogMaintenanceInput / PlanMaintenanceInput / SetMaintenanceScheduleInput) are
// 'user' | 'web' | 'llm' | 'partsapi' | 'siv', plus VehicleFactsProjector's own 'log' constant for a
// fact projected from a fitted part. Only 'llm'/'web' are the agent's own composed writes — the
// projector's precedence docblock literally names that unverified tier "an unverified attribute
// (agent web/llm)". 'user' is the owner's hand; 'partsapi'/'siv' are catalog lookups (already carry
// the ✓ verified badge, not agent-composed); 'log' is ambiguous (the visit that fitted the part could
// itself have been logged by the owner or the agent) so it stays unmarked rather than guess.
export function isAgentAuthored(source: string): boolean {
  return source === 'llm' || source === 'web';
}
