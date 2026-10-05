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
