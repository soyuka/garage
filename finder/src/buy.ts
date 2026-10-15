export interface BuyLink {
  shop: string;
  url: string;
}

export interface BuyReference {
  brand: string | null;
  number: string;
}

export function buyLinks({ brand, number }: BuyReference): BuyLink[] {
  const q = encodeURIComponent(number);
  const web = [brand, number].filter((s): s is string => s !== null && s.trim() !== '').map(encodeURIComponent).join('+');
  return [
    { shop: 'Oscaro', url: `https://www.oscaro.com/fr/search?q=${q}` },
    { shop: 'Autodoc', url: `https://www.auto-doc.fr/search?keyword=${q}` },
    { shop: 'Rechercher sur le web', url: `https://www.google.com/search?q=${web}` },
  ];
}
