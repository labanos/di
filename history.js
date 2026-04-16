// ─── History View (year-by-year results) ─────────────────────────────────────

function HistoryView() {
  const [summary, setSummary] = React.useState(null);
  const [detail, setDetail]   = React.useState(null);
  const [selYear, setSelYear] = React.useState(null);
  const [error, setError]     = React.useState(null);

  React.useEffect(() => {
    fetch(`${API}/results.php`)
      .then(r => r.json())
      .then(setSummary)
      .catch(() => setError('Could not load results.'));
  }, []);

  function loadYear(year) {
    if (selYear === year) { setSelYear(null); setDetail(null); return; }
    setSelYear(year);
    setDetail(null);
    fetch(`${API}/results.php?year=${year}`)
      .then(r => r.json())
      .then(setDetail)
      .catch(() => setError('Could not load year detail.'));
  }

  if (error)    return <ErrorMsg msg={error} />;
  if (!summary) return <Spinner />;

  const years = Object.keys(summary).sort((a, b) => b - a);

  return (
    <div className="max-w-3xl mx-auto p-4 space-y-3">
      {years.map(year => {
        const blue = summary[year]?.blue || {};
        const red  = summary[year]?.red  || {};
        const bp = (blue.points || 0) / 2;
        const rp = (red.points  || 0) / 2;
        const winner = bp > rp ? 'blue' : rp > bp ? 'red' : null;
        return (
          <div key={year} className="bg-white rounded-2xl shadow-sm overflow-hidden">
            <button className="w-full px-6 py-4 flex items-center justify-between hover:bg-slate-50 transition-colors"
                    onClick={() => loadYear(year)}>
              <span className="text-lg font-bold text-slate-800">{year}</span>
              <div className="flex items-center gap-4">
                <span className={`text-xl font-black mono ${winner === 'blue' ? 'text-blue-600' : 'text-slate-400'}`}>{bp}</span>
                <span className="text-slate-300">–</span>
                <span className={`text-xl font-black mono ${winner === 'red'  ? 'text-red-600'  : 'text-slate-400'}`}>{rp}</span>
                {winner && (
                  <span className={`text-xs font-bold px-2 py-0.5 rounded ${
                    winner === 'blue' ? 'bg-blue-100 text-blue-700' : 'bg-red-100 text-red-700'
                  }`}>{TEAM[winner].label} wins</span>
                )}
                <span className="text-slate-300 text-xs">{selYear === year ? '▲' : '▼'}</span>
              </div>
            </button>
            {selYear === year && (
              <div className="border-t border-slate-100 px-6 py-4">
                {!detail ? <Spinner /> : <YearDetail rows={detail} />}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

function YearDetail({ rows }) {
  const rounds = {};
  rows.forEach(r => {
    const key = r.round_number;
    if (!rounds[key]) rounds[key] = { format: r.format, matches: {} };
    if (!rounds[key].matches[r.match_number]) rounds[key].matches[r.match_number] = [];
    rounds[key].matches[r.match_number].push(r);
  });

  return (
    <div className="space-y-5">
      {Object.keys(rounds).sort((a,b) => a-b).map(rn => {
        const round = rounds[rn];
        return (
          <div key={rn}>
            <h4 className="text-xs font-bold uppercase tracking-wide text-slate-400 mb-2">
              Round {rn} — {FORMAT_LABEL[round.format] || round.format}
            </h4>
            <div className="space-y-1">
              {Object.keys(round.matches).sort((a,b) => a-b).map(mn => {
                const players = round.matches[mn];
                const blue    = players.filter(p => p.team === 'blue');
                const red     = players.filter(p => p.team === 'red');
                const blueWon = blue[0]?.points === 2;
                const halved  = blue[0]?.points === 1;
                const ups     = Math.abs(blue[0]?.ups || 0);
                return (
                  <div key={mn} className="flex items-center gap-2 text-sm py-1">
                    <div className={`flex-1 text-right ${blueWon ? 'font-semibold text-blue-700' : 'text-slate-500'}`}>
                      {blue.map(p => p.player_name).join(' / ')}
                    </div>
                    <div className="w-12 text-center">
                      {halved
                        ? <span className="text-xs text-amber-500 font-bold">½</span>
                        : <span className={`text-xs font-bold ${blueWon ? 'text-blue-600' : 'text-red-600'}`}>
                            {ups > 0 ? `${ups}up` : 'AS'}
                          </span>
                      }
                    </div>
                    <div className={`flex-1 ${!blueWon && !halved ? 'font-semibold text-red-700' : 'text-slate-500'}`}>
                      {red.map(p => p.player_name).join(' / ')}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
