import { photoPath, sheetPhoto } from './photo';
import type { Cluster, Narrowing } from './types';

const cluster = (artId: number, brand: string, number: string): Cluster => ({
  id: `c${artId}`,
  dimensions: [],
  oeNumbers: [],
  representative: { artId, brand, number },
  variants: [],
  articleCount: 3,
  applicability: [],
});

const narrowing = { carId: 16182, categoryId: 100198 } as Narrowing;

describe('photoPath', () => {
  it('addresses the article inside the listing it was shown from, never a URL', () => {
    expect(photoPath(16182, 'PC', 100198, 112767557)).toBe('/cars/16182/part-categories/100198/articles/112767557/photo?carType=PC');
  });
});

describe('sheetPhoto', () => {
  it('shows the representative of the shown cluster, captioned with its brand and reference', () => {
    expect(sheetPhoto(narrowing, cluster(112767557, 'BOSCH', '0 986 141 133'), 'PC')).toEqual({
      src: '/cars/16182/part-categories/100198/articles/112767557/photo?carType=PC',
      reference: 'BOSCH 0 986 141 133',
      alt: 'Photo catalogue de la pièce BOSCH 0 986 141 133',
    });
  });

  it('follows the variant: another cluster loads its own photo', () => {
    expect(sheetPhoto(narrowing, cluster(112767504, 'BOSCH', '0 986 141 079'), 'PC')?.src).toContain('/articles/112767504/photo');
  });

  it('asks for nothing until a cluster is shown', () => {
    expect(sheetPhoto(narrowing, null, 'PC')).toBeNull();
    expect(sheetPhoto(null, cluster(1, 'X', '1'), 'PC')).toBeNull();
  });
});
