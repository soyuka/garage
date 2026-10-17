import { isBusy } from './agent';
import type { ReactNode } from 'react';
import type { TurnState } from './types';

// The agent's latest sentence, only while its turn runs.
export const narration = (state: TurnState): string | null => (isBusy(state) ? state.status : null);

export interface SheetLineInput {
  narration: string | null;
  status: ReactNode;
  searching: boolean;
  hint: string | null;
}

export type SheetLine = { kind: 'narration'; text: ReactNode } | { kind: 'hint'; text: string };

// The one line under the drawing: the live sentence, else the static searching line, else the refine hint.
export function sheetLine({ narration: live, status, searching, hint }: SheetLineInput): SheetLine | null {
  if (live !== null) return { kind: 'narration', text: live };
  if (searching && status) return { kind: 'narration', text: status };
  return hint !== null ? { kind: 'hint', text: hint } : null;
}
