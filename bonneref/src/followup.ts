import type { OnScreen } from './types';
import type { Selection } from './variants';

// `selection` comes from selectVariant: an agent verdict is only there once the server vouched for it, and
// the server rebuilds an agent choice from its stored id, so without one the choice is not sent at all.
export function onScreenOf(categoryId: number, productGroupId: number | null, selection: Selection | null): OnScreen {
  const base: OnScreen = { categoryId, productGroupId };
  const cluster = selection?.cluster;
  if (!selection || !cluster) return base;
  switch (selection.status) {
    case 'answer':
    case 'user':
      return { ...base, clusterId: cluster.id, chosenBy: selection.status };
    case 'agent':
      return selection.verdict ? { ...base, clusterId: cluster.id, chosenBy: 'agent', selectionId: selection.verdict.id } : base;
    default:
      return base;
  }
}
