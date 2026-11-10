import { describe, expect, it } from 'vitest';
import { documentParams } from './pdfjs';

describe('documentParams', () => {
  it('points pdf.js at the served wasm, font, icc and cmap directories', () => {
    const data = new Uint8Array([1]);
    expect(documentParams(data, '/')).toEqual({
      data,
      wasmUrl: '/pdfjs/wasm/',
      standardFontDataUrl: '/pdfjs/standard_fonts/',
      iccUrl: '/pdfjs/iccs/',
      cMapUrl: '/pdfjs/cmaps/',
      cMapPacked: true,
    });
  });

  it('honours a sub-path base', () => {
    expect(documentParams(new Uint8Array(), '/app/').wasmUrl).toBe('/app/pdfjs/wasm/');
  });
});
