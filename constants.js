// ─── Constants ───────────────────────────────────────────────────────────────
const API = 'https://labanos.dk/di';

const TEAM = {
  blue: { label: 'Blue', bg: 'bg-blue-600', text: 'text-blue-600', border: 'border-blue-600', hex: '#2563eb' },
  red:  { label: 'Red',  bg: 'bg-red-600',  text: 'text-red-600',  border: 'border-red-600',  hex: '#dc2626' },
};

const FORMAT_LABEL = {
  fourball:  'Fourball',
  greensome: 'Greensome',
  foursome:  'Foursome',
  singles:   'Singles',
};

// Format match-play points: 1=win, ½=halved, 0=loss
// Input is already divided by 2 from the API (e.g. 3.5 → "3½", 0.5 → "½", 4 → "4")
function fmtPts(p) {
  const n = parseFloat(p);
  if (isNaN(n)) return '—';
  const whole = Math.floor(n);
  const half  = (n - whole) >= 0.4; // 0.5 with float tolerance
  if (half) return whole === 0 ? '½' : `${whole}½`;
  return String(whole);
}

// Format average points to 2 decimal places
function fmtAvg(p) {
  const n = parseFloat(p);
  if (isNaN(n)) return '—';
  return n.toFixed(2);
}
