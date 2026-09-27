// ONE `AudioContext` for the tab's life, shared by everything that makes a sound.
//
// It used to be a ref inside `useVoice`, which was right while speech was the only sound. The room bed
// (CHOREOGRAPHY § "Audition — the room bed") made it wrong twice over: a second context is a second
// hardware stream to burn a core on, and the bed's duck is scheduled against the SAME clock as the line
// it ducks under — two contexts have two `currentTime`s and no way to place one against the other.
//
// Creation stays lazy and caller-driven: constructing one outside a gesture yields a suspended context
// that stays so (§ "The AudioContext trap"), so the first caller is always inside the unlock handler.

let shared: AudioContext | null = null;
let holds = 0;

/** The context, made on first ask. Callers resume it; this never does — that needs a gesture. */
export function audioContext(): AudioContext {
  return (shared ??= new AudioContext());
}

/** Resume from a trusted gesture; a fulfilled promise alone is not proof the browser started audio. */
export function resumeAudioFromGesture(): Promise<boolean> {
  const ctx = audioContext();
  try {
    return ctx.resume().then(() => ctx.state === 'running').catch(() => false);
  } catch {
    return Promise.resolve(false);
  }
}

/** Whatever exists, without making one. For teardown paths that must not resurrect it. */
export function peekAudio(): AudioContext | null {
  return shared;
}

/**
 * Claim the context as in-use. The bed holds it for as long as it is audible, and nothing else does
 * yet — speech is bursty and has no interest in the idle state between lines.
 */
export function holdAudio(): void {
  holds += 1;
}

/** Drop a claim. Never below zero: a stray release must not leave the context pinned awake forever. */
export function releaseAudio(): void {
  holds = Math.max(0, holds - 1);
}

/**
 * Suspend — but only if nobody is holding it.
 *
 * `useVoice` suspends whenever the audition goes off, and the audition goes off on **every mic press**
 * (`Atelier.tsx`: `audition && !listening`). Before the bed that was free; with a shared context an
 * unguarded `suspend()` would stop the bed on every press and bring it back only on the next spoken
 * line — which on a quiet screen is never.
 */
export async function suspendIfIdle(): Promise<void> {
  if (holds > 0 || !shared) return;
  await shared.suspend().catch(() => undefined);
}

/** Close and forget. The Atelier unmounting is the only caller. */
export async function closeAudio(): Promise<void> {
  const ctx = shared;
  shared = null;
  holds = 0;
  await ctx?.close().catch(() => undefined);
}

/** Tests only: the module-level context is deliberately process-wide, so a suite has to reset it. */
export function __resetAudio(): void {
  shared = null;
  holds = 0;
}
