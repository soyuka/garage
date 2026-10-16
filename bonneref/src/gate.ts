export type GateView = 'app' | 'gate' | 'wait';

export function gateView({ authed, callback }: { authed: boolean; callback: boolean }): GateView {
  if (callback) return 'wait';
  return authed ? 'app' : 'gate';
}
