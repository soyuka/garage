import type { CategoryPicto as Picto, PictoId } from '../picto';

const MARKUP = import.meta.glob<string>('../pictos/*.svg', { query: '?raw', import: 'default', eager: true });

const markup = (id: PictoId): string => MARKUP[`../pictos/${id}.svg`] ?? '';

export function categoryPictoCaption(label: string): string {
  return `Famille : ${label}, pas la pièce`;
}

export function CategoryPicto({ picto }: { picto: Picto }) {
  const caption = categoryPictoCaption(picto.label);
  return (
    <div className="sheet-picto" role="img" aria-label={caption}>
      <div className="sheet-picto-art" aria-hidden="true" dangerouslySetInnerHTML={{ __html: markup(picto.id) }} />
      <p className="sheet-picto-cap m" aria-hidden="true">{caption}</p>
    </div>
  );
}
