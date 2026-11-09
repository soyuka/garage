/**
 * Visibility + lock decision for an agent-backed quick-action chip. Shares its data prerequisite with the
 * deterministic history/carnet chips — at least one logged visit — so the two families never
 * disagree about whether there is something to summarise; `locked` mirrors the one-driver-at-a-time
 * guard the composer and deterministic chips already share (`busy(state)`).
 */
export function agentChipState(recordCount: number, locked: boolean): { visible: boolean; disabled: boolean } {
  return { visible: recordCount > 0, disabled: locked };
}
