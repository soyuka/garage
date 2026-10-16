export const NNBSP = ' ';

const PLATE = /^([A-Z]{2})(\d{3})([A-Z]{2})$/;
const VIN = /^[A-HJ-NPR-Z0-9]{17}$/;

const compact = (raw: string): string => raw.toUpperCase().replace(/[^A-Z0-9]/g, '');

export function normalisePlate(raw: string): string | null {
  const m = PLATE.exec(compact(raw));
  return m ? `${m[1]}-${m[2]}-${m[3]}` : null;
}

const FNI = /^(\d{1,4})([A-Z]{2,3})(\d{2}|2A|2B)$/;

export function normaliseFni(raw: string): string | null {
  const m = FNI.exec(compact(raw));
  return m ? `${m[1]} ${m[2]} ${m[3]}` : null;
}

export const isPlate = (value: string): boolean => /^[A-Z]{2}-\d{3}-[A-Z]{2}$/.test(value);

export function formatPlateInput(raw: string): string {
  const c = compact(raw);
  const letters = (c.match(/^[A-Z]{0,2}/)?.[0] ?? '');
  const rest = c.slice(letters.length);
  const digits = letters.length === 2 ? (rest.match(/^\d{0,3}/)?.[0] ?? '') : '';
  const tail = digits.length === 3 ? (rest.slice(3).match(/^[A-Z]{0,2}/)?.[0] ?? '') : '';
  return [letters, digits, tail].filter((part) => part !== '').join('-');
}

export function normaliseVin(raw: string): string {
  return compact(raw).replace(/I/g, '1').replace(/[OQ]/g, '0');
}

export const isVinShape = (value: string): boolean => VIN.test(value);

const grouping = new Intl.NumberFormat('fr-FR', { useGrouping: true, maximumFractionDigits: 3 });

export function formatNumber(n: number): string {
  return grouping.format(n).replace(/[  \s]/g, NNBSP);
}

export const formatCount = formatNumber;

export function formatValue(value: string): string {
  return /^-?\d+(\.\d+)?$/.test(value) ? formatNumber(Number(value)) : value;
}

export function formatRange(min: number, max: number): string {
  return min === max ? formatNumber(min) : `${formatNumber(min)}–${formatNumber(max)}`;
}

export function formatMeasure(value: string, unit: string | null): string {
  const v = formatValue(value);
  return unit ? `${v}${NNBSP}${unit}` : v;
}

const DAY = /^\d{4}-\d{2}-\d{2}$/;
const longDate = (timeZone: string) => new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone });
const PARIS = longDate('Europe/Paris');
const CALENDAR = longDate('UTC');

// A bare YYYY-MM-DD is a calendar day as written (UTC); a timestamp is read in Paris time.
export function formatDate(value: string): string {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return (DAY.test(value) ? CALENDAR : PARIS).format(d);
}
