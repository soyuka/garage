// Two backends, one path namespace each: the finder API (catalogue, agent, journal, Mercure) at
// VITE_API_BASE, the garage API (/garage/*) at VITE_GARAGE_API_BASE. Both default to same-origin, and the
// garage base defaults to the finder one, so a deployment whose reverse proxy splits /garage itself needs
// neither. Read per call, not at load, so a test can stub the environment.

const trim = (value: string | undefined): string => (value ?? '').replace(/\/+$/, '');

const finderBase = (): string => trim(import.meta.env.VITE_API_BASE);

const garageBase = (): string => trim(import.meta.env.VITE_GARAGE_API_BASE) || finderBase();

const isAbsolute = (path: string): boolean => /^[a-z][a-z0-9+.-]*:\/\//i.test(path);

export const apiUrl = (path: string): string => (isAbsolute(path) ? path : `${finderBase()}${path}`);

export const garageUrl = (path: string): string => (isAbsolute(path) ? path : `${garageBase()}${path}`);

export const apiOrigin = (): string => finderBase() || window.location.origin;

export const hubDefault = (): string => import.meta.env.VITE_MERCURE_URL ?? apiUrl('/.well-known/mercure');
