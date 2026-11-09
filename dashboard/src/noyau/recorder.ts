// The impure half of dictation: a microphone in, one audio blob out. Every rule it leans on — when
// the mic may open, when it closes itself, what the transcript does to the composer — is pure and
// tested in dictation.ts; what is left here is the getUserMedia / MediaRecorder wiring a repo with no
// browser cannot test. Same split, and the same honesty about it, as pcm.ts / speaker.ts.
//
// Push-to-talk only: there is no VAD here, no wake word and no way to
// start recording without a press. The track is stopped the moment the recording ends, so the
// browser's own recording indicator goes out with it — the operator can see the mic is shut.

/** What a browser will actually encode, best first. Chrome/Firefox take the first, Safari the last. */
const PREFERRED_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];

export interface Recording {
  /** The captured audio, in whatever container the browser chose. Empty when nothing was captured. */
  blob: Blob;
}

export interface Recorder {
  /** Stop, release the microphone, and resolve with what was captured. */
  stop(): Promise<Recording>;
}

/** The subset of the permission failure that matters: refused for good, vs. merely failed this time. */
export class MicrophoneRefused extends Error {}
export class RecordingCancelled extends Error {}

function pickType(): string | undefined {
  if ('undefined' === typeof MediaRecorder) return undefined;

  return PREFERRED_TYPES.find((type) => MediaRecorder.isTypeSupported(type));
}

/**
 * Open the microphone and start recording. Must be called inside the press gesture: a browser only
 * grants capture from a user activation, and the press is also the gesture that unlocks the
 * AudioContext for the voice.
 *
 * @throws MicrophoneRefused when the operator declined, or the page cannot ask at all (no secure
 *                           context, no device, no MediaRecorder). All of them mean the same thing to
 *                           the caller: stop offering the control this session.
 */
export async function record(beforeStart?: () => Promise<void>, signal?: AbortSignal): Promise<Recorder> {
  if (!navigator.mediaDevices?.getUserMedia || 'undefined' === typeof MediaRecorder) {
    throw new MicrophoneRefused('This browser cannot capture audio.');
  }

  let stream: MediaStream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch (e) {
    // NotAllowedError (declined, or an insecure origin), NotFoundError (no device) — one outcome.
    throw new MicrophoneRefused(e instanceof Error ? e.message : 'The microphone is unavailable.');
  }

  const release = (): void => {
    for (const track of stream.getTracks()) track.stop();
  };
  if (signal?.aborted) {
    release();
    throw new RecordingCancelled('Microphone arming was cancelled.');
  }
  if (beforeStart) {
    let onAbort!: () => void;
    await Promise.race([
      beforeStart().catch(() => undefined),
      new Promise<void>((resolve) => {
        onAbort = resolve;
        signal?.addEventListener('abort', onAbort, { once: true });
      }),
    ]);
    if (onAbort) signal?.removeEventListener('abort', onAbort);
  }
  if (signal?.aborted) {
    release();
    throw new RecordingCancelled('Microphone arming was cancelled.');
  }

  const type = pickType();
  let recorder: MediaRecorder;
  try {
    recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
  } catch (e) {
    release();
    throw new MicrophoneRefused(e instanceof Error ? e.message : 'The microphone is unavailable.');
  }
  const chunks: Blob[] = [];
  recorder.addEventListener('dataavailable', (e) => {
    if (e.data.size > 0) chunks.push(e.data);
  });
  try {
    recorder.start();
  } catch (e) {
    release();
    throw new MicrophoneRefused(e instanceof Error ? e.message : 'The microphone is unavailable.');
  }

  return {
    stop: () =>
      new Promise<Recording>((resolve) => {
        if ('inactive' === recorder.state) {
          release();
          resolve({ blob: new Blob(chunks, { type: recorder.mimeType }) });
          return;
        }
        // `stop` fires after the last dataavailable, so this is the first moment the blob is whole.
        recorder.addEventListener('stop', () => {
          release();
          resolve({ blob: new Blob(chunks, { type: recorder.mimeType }) });
        });
        recorder.stop();
      }),
  };
}
