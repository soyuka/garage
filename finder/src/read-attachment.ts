import type { FinderApi } from './client';
import { MAX_ATTACHMENTS } from './attachments';
import { pickSharpest, prepareImage, sampleVideo, type Frame } from './media';
import { routeFile } from './route-input';
import type { Reading } from './submit';

// One intake per attachment, run only when the user submits.
export async function readAttachment(api: Pick<FinderApi, 'extractRegistration' | 'photoIntake'>, file: File): Promise<Reading> {
  const kind = routeFile(file);
  if (kind.kind === 'pdf') return { kind: 'carte_grise', registration: await api.extractRegistration(file) };

  const frames: Frame[] = kind.kind === 'video' ? (await sampleVideo(file)).slice(0, MAX_ATTACHMENTS) : [await prepareImage(file)];
  const sharpest = frames[pickSharpest(frames.map((f) => f.score))]!;
  const intake = await api.photoIntake(sharpest.blob);
  switch (intake.kind) {
    case 'carte_grise':
      return { kind: 'carte_grise', registration: intake.registration };
    case 'part':
      return { kind: 'part', part: intake.part, images: frames.map((f) => f.dataUrl) };
    case 'build_label':
      return { kind: 'build_label', buildLabel: intake.buildLabel };
    default:
      return { kind: 'other' };
  }
}
