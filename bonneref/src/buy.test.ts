import { buyLinks } from './buy';

describe('buyLinks', () => {
  it('builds the verified retailer searches with the reference encoded, then a web search', () => {
    expect(buyLinks({ brand: 'MANN-FILTER', number: 'W 712/73' })).toEqual([
      { shop: 'Oscaro', url: 'https://www.oscaro.com/fr/search?q=W%20712%2F73' },
      { shop: 'Autodoc', url: 'https://www.auto-doc.fr/search?keyword=W%20712%2F73' },
      { shop: 'Mister-Auto', url: 'https://www.mister-auto.com/rechercher/?q=W%20712%2F73' },
      { shop: 'Recherche Google', url: 'https://www.google.com/search?q=MANN-FILTER+W%20712%2F73' },
    ]);
  });

  it('searches the web on the reference alone when the brand is unknown', () => {
    expect(buyLinks({ brand: null, number: 'LF10-14-302' }).at(-1)).toEqual({
      shop: 'Recherche Google',
      url: 'https://www.google.com/search?q=LF10-14-302',
    });
  });
});
