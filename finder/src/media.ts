// Photos and videos are reduced on the device: a photo is re-encoded as a JPEG of at most 1600 px,
// a video becomes three sampled frames. No video ever leaves the browser.

export const MAX_SIDE = 1600;
const JPEG_QUALITY = 0.85;
const PROBE_SIDE = 160;

export function fitWithin(width: number, height: number, max = MAX_SIDE): { width: number; height: number } {
  const scale = Math.min(1, max / Math.max(width, height));
  return { width: Math.round(width * scale), height: Math.round(height * scale) };
}

export function sampleTimes(duration: number): number[] {
  if (!Number.isFinite(duration) || duration <= 0) return [0];
  return [0.25, 0.5, 0.75].map((f) => f * duration);
}

// Variance of a 4-neighbour Laplacian over a grayscale frame: a blurred frame has few edges, so a low
// variance. Cheap enough to run on a 160 px probe of each sampled frame.
export function sharpness(gray: Uint8ClampedArray, width: number, height: number): number {
  let sum = 0;
  let sumSq = 0;
  let n = 0;
  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < width - 1; x++) {
      const i = y * width + x;
      const lap = gray[i - width]! + gray[i + width]! + gray[i - 1]! + gray[i + 1]! - 4 * gray[i]!;
      sum += lap;
      sumSq += lap * lap;
      n++;
    }
  }
  if (n === 0) return 0;
  const mean = sum / n;
  return sumSq / n - mean * mean;
}

export function pickSharpest(scores: readonly number[]): number {
  let best = 0;
  scores.forEach((s, i) => {
    if (s > (scores[best] ?? -Infinity)) best = i;
  });
  return best;
}

export interface Frame {
  blob: Blob;
  dataUrl: string;
  score: number;
}

type Drawable = HTMLImageElement | HTMLVideoElement | ImageBitmap;

function sizeOf(source: Drawable): { width: number; height: number } {
  if (source instanceof HTMLVideoElement) return { width: source.videoWidth, height: source.videoHeight };
  if (source instanceof HTMLImageElement) return { width: source.naturalWidth, height: source.naturalHeight };
  return { width: source.width, height: source.height };
}

function probeScore(source: Drawable, width: number, height: number): number {
  const probe = fitWithin(width, height, PROBE_SIDE);
  const canvas = document.createElement('canvas');
  canvas.width = probe.width;
  canvas.height = probe.height;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) return 0;
  ctx.drawImage(source, 0, 0, probe.width, probe.height);
  const { data } = ctx.getImageData(0, 0, probe.width, probe.height);
  const gray = new Uint8ClampedArray(probe.width * probe.height);
  for (let i = 0; i < gray.length; i++) gray[i] = 0.299 * data[i * 4]! + 0.587 * data[i * 4 + 1]! + 0.114 * data[i * 4 + 2]!;
  return sharpness(gray, probe.width, probe.height);
}

async function encode(source: Drawable): Promise<Frame> {
  const natural = sizeOf(source);
  if (natural.width === 0 || natural.height === 0) throw new MediaError('decode');
  const { width, height } = fitWithin(natural.width, natural.height);
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new MediaError('decode');
  ctx.drawImage(source, 0, 0, width, height);
  const dataUrl = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
  const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new MediaError('decode'))), 'image/jpeg', JPEG_QUALITY));
  return { blob, dataUrl, score: probeScore(source, natural.width, natural.height) };
}

export class MediaError extends Error {
  constructor(public readonly kind: 'decode' | 'video') {
    super(kind === 'video' ? 'Vidéo illisible sur cet appareil : prenez plutôt une photo.' : 'Image illisible : essayez une photo JPEG ou PNG.');
  }
}

export async function prepareImage(file: Blob): Promise<Frame> {
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    img.decoding = 'async';
    img.src = url;
    await img.decode().catch(() => {
      throw new MediaError('decode');
    });
    return await encode(img);
  } finally {
    URL.revokeObjectURL(url);
  }
}

function once(target: HTMLVideoElement, event: string, timeoutMs = 8000): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = window.setTimeout(() => reject(new MediaError('video')), timeoutMs);
    target.addEventListener(event, () => (window.clearTimeout(timer), resolve()), { once: true });
    target.addEventListener('error', () => (window.clearTimeout(timer), reject(new MediaError('video'))), { once: true });
  });
}

export async function sampleVideo(file: Blob): Promise<Frame[]> {
  const url = URL.createObjectURL(file);
  const video = document.createElement('video');
  video.muted = true;
  video.playsInline = true;
  video.preload = 'auto';
  video.src = url;
  try {
    await once(video, 'loadeddata');
    const frames: Frame[] = [];
    for (const t of sampleTimes(video.duration)) {
      video.currentTime = t;
      await once(video, 'seeked');
      frames.push(await encode(video));
    }
    return frames;
  } finally {
    video.removeAttribute('src');
    video.load();
    URL.revokeObjectURL(url);
  }
}
