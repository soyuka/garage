import type { CategoryPicto as Picto, PictoId } from '../picto';

const MARKUP = import.meta.glob<string>('../pictos/*.svg', { query: '?raw', import: 'default', eager: true });

const markup = (id: PictoId): string => MARKUP[`../pictos/${id}.svg`] ?? '';

export function CategoryPicto({ picto }: { picto: Picto }) {
  return (
    <div className="sheet-picto" role="img" aria-label={picto.label}>
      <div className="sheet-picto-art" aria-hidden="true" dangerouslySetInnerHTML={{ __html: markup(picto.id) }} />
    </div>
  );
}
