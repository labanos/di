// ─── Shared Components ───────────────────────────────────────────────────────

function Spinner() {
  return (
    <div className="flex justify-center items-center py-16">
      <div className="spin w-8 h-8 border-4 border-slate-200 border-t-slate-600 rounded-full" />
    </div>
  );
}

function ErrorMsg({ msg }) {
  return (
    <div className="m-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
      {msg || 'Something went wrong.'}
    </div>
  );
}

function TeamBadge({ team }) {
  const t = TEAM[team];
  if (!t) return null;
  return (
    <span className={`inline-block px-2 py-0.5 rounded text-xs font-bold text-white ${t.bg}`}>
      {t.label}
    </span>
  );
}

function ScoreBadge({ points }) {
  if (points === 2) return <span className="font-bold text-green-600">W</span>;
  if (points === 1) return <span className="font-bold text-amber-500">H</span>;
  return <span className="text-slate-400">L</span>;
}
