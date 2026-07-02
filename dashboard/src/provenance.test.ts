import { describe, expect, it } from 'vitest';
import { isAgentAuthored } from './provenance';

// Provenance mark (C3): the agent signs what it writes. The real backend sources on
// VehicleFact / MaintenanceRecord / ServiceRule are 'user' | 'log' | 'web' | 'llm' | 'partsapi' | 'siv'
// (see LogMaintenanceInput/PlanMaintenanceInput/SetMaintenanceScheduleInput $source enums + the
// VehicleFactsProjector precedence docblock, which literally names tier 4 "an unverified attribute
// (agent web/llm)"). 'llm'/'web' are the agent's own tool calls; 'user' is the owner's hand; 'partsapi'/
// 'siv' are catalog lookups (already marked by `verified`, not agent-composed); 'log' is a part
// projected from a logged visit — its own provenance is ambiguous, so it is NOT marked agent.

describe('isAgentAuthored', () => {
  it('marks an llm-sourced value', () => {
    expect(isAgentAuthored('llm')).toBe(true);
  });
  it('marks a web-sourced value', () => {
    expect(isAgentAuthored('web')).toBe(true);
  });
  it('does not mark a user-entered value', () => {
    expect(isAgentAuthored('user')).toBe(false);
  });
  it('does not mark a catalog value (partsapi/siv)', () => {
    expect(isAgentAuthored('partsapi')).toBe(false);
    expect(isAgentAuthored('siv')).toBe(false);
  });
  it('does not mark an ambiguous logged-part value', () => {
    expect(isAgentAuthored('log')).toBe(false);
  });
  it('does not mark an unknown source', () => {
    expect(isAgentAuthored('mystery')).toBe(false);
  });
});
