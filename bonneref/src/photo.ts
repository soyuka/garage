import type { CarType, Cluster, Narrowing } from './types';

export interface SheetPhoto {
  src: string;
  reference: string;
  alt: string;
}

// The API proxies the image and only for an article of a listing it already holds: the client never
// names an image URL.
export const photoPath = (carId: number, carType: CarType, categoryId: number, artId: number): string =>
  `/cars/${carId}/part-categories/${categoryId}/articles/${artId}/photo?carType=${carType}`;

export function sheetPhoto(narrowing: Narrowing | null, cluster: Cluster | null, carType: CarType): SheetPhoto | null {
  if (!narrowing || !cluster) return null;
  const { artId, brand, number } = cluster.representative;
  const reference = `${brand} ${number}`;
  return { src: photoPath(narrowing.carId, carType, narrowing.categoryId, artId), reference, alt: `Photo catalogue de la pièce ${reference}` };
}
