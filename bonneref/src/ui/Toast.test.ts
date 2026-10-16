import { linkTarget } from './Toast';

describe('toast link', () => {
  it('follows an in-app hash link in the same tab, an outside one in a new tab', () => {
    expect(linkTarget('#/garage/01M4X5D9R4FV02S6Q9XW30PBZK')).toEqual({});
    expect(linkTarget('https://www.example.org/')).toEqual({ target: '_blank', rel: 'noopener' });
  });
});
