// pdf.js is ~1 MB with its worker: loaded on the first document open, never in the main bundle.
type PdfJs = typeof import('pdfjs-dist');

let loading: Promise<PdfJs> | null = null;

export function loadPdfJs(): Promise<PdfJs> {
  loading ??= Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')])
    .then(([lib, worker]) => {
      lib.GlobalWorkerOptions.workerSrc = worker.default;
      return lib;
    })
    .catch((e: unknown) => {
      loading = null;
      throw e;
    });
  return loading;
}

export const PDFJS_ASSET_DIRS = ['wasm', 'standard_fonts', 'iccs', 'cmaps'] as const;

export function documentParams(data: Uint8Array, base: string = import.meta.env.BASE_URL) {
  const dir = (name: (typeof PDFJS_ASSET_DIRS)[number]) => `${base}pdfjs/${name}/`;
  return {
    data,
    wasmUrl: dir('wasm'),
    standardFontDataUrl: dir('standard_fonts'),
    iccUrl: dir('iccs'),
    cMapUrl: dir('cmaps'),
    cMapPacked: true,
  };
}
