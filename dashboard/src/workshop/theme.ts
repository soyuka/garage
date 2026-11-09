export type Theme = 'dark' | 'light';

export const THEME_KEY = 'mpa.theme';

type Store = Pick<Storage, 'getItem' | 'setItem'>;

export function resolveTheme(stored: string | null): Theme {
  return stored === 'light' ? 'light' : 'dark';
}

export function toggleTheme(theme: Theme): Theme {
  return theme === 'dark' ? 'light' : 'dark';
}

export function readTheme(storage: Store | null): Theme {
  try {
    return resolveTheme(storage?.getItem(THEME_KEY) ?? null);
  } catch {
    return 'dark';
  }
}

export function writeTheme(theme: Theme, storage: Store | null): void {
  try {
    storage?.setItem(THEME_KEY, theme);
  } catch {
    /* the choice simply does not persist */
  }
}

function localStore(): Store | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

/** Stamp the theme on <html>; the tokens key off `:root[data-theme]`. */
export function applyTheme(theme: Theme): void {
  document.documentElement.dataset.theme = theme;
}

export function bootTheme(): Theme {
  const theme = readTheme(localStore());
  applyTheme(theme);
  return theme;
}

export function persistTheme(theme: Theme): void {
  writeTheme(theme, localStore());
  applyTheme(theme);
}
