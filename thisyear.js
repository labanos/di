// ─── This Year View ───────────────────────────────────────────────────────────

// MatchRow: single match inside a round card
// points===null  → upcoming (players set, not played)
// blue.length===0 → no players assigned yet
function MatchRow({ match }) {
  const blue = match.blue || [];
  const red  = match.red  || [];
  const bp   = blue.length > 0 ? blue[0].points : undefined;
  const ups  = blue.length > 0 ? Math.abs(blue[0].ups || 0) : 0;

  let badge = null;
  if (bp === null) {
    // Players assigned, not played yet
    badge = <span className="shrink-0 text-xs text-slate-400 italic whitespace-nowrap">Upcoming</span>;
  } else if (bp === 2) {
    badge = <span className="shrink-0 text-xs font-bold text-blue-600 whitespace-nowrap">{ups ? `Blue ${ups}UP` : 'Blue W'}</span>;
  } else if (bp === 1) {
    badge = <span className="shrink-0 text-xs font-bold text-amber-500 whitespace-nowrap">Halved</span>;
  } else if (bp === 0) {
    badge = <span className="shrink-0 text-xs font-bold text-red-600 whitespace-nowrap">{ups ? `Red ${ups}UP` : 'Red W'}</span>;
  }

  return (
    <div className="flex items-center gap-2 py-1.5 text-xs border-t border-slate-50">
      <span className="text-slate-300 mono w-4 shrink-0 text-right">{match.match_number}</span>
      <span className={`flex-1 min-w-0 truncate font-medium ${bp === null ? 'text-slate-500' : 'text-blue-700'}`}>
        {blue.length > 0 ? blue.map(p => p.name).join(' & ') : '—'}
      </span>
      <span className="text-slate-300 shrink-0">vs</span>
      <span className={`flex-1 min-w-0 truncate text-right font-medium ${bp === null ? 'text-slate-500' : 'text-red-700'}`}>
        {red.length > 0 ? red.map(p => p.name).join(' & ') : '—'}
      </span>
      {badge}
    </div>
  );
}

// RoundCard: one round with its matches (or empty state)
function RoundCard({ round }) {
  const matches = round.matches || [];
  const played  = matches.filter(m => m.blue.length > 0 && m.blue[0].points !== null).length;
  const total   = matches.length;
  return (
    <div className="px-4 py-3">
      <div className="flex items-center gap-2 mb-1">
        <span className="text-sm font-semibold text-slate-700">Round {round.round_number}</span>
        <span className="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 font-medium">
          {round.format}
        </span>
        {total > 0 && (
          <span className="ml-auto text-xs text-slate-400">
            {played}/{total} played
          </span>
        )}
      </div>
      {matches.length === 0
        ? <p className="text-xs text-slate-400 italic mt-1">No matches set up yet</p>
        : <div className="mt-1">{matches.map(m => <MatchRow key={m.match_id} match={m} />)}</div>
      }
    </div>
  );
}

// Main view
function ThisYearView() {
  const [schedule,  setSchedule]  = React.useState(null);
  const [standings, setStandings] = React.useState(null);
  const [error,     setError]     = React.useState(null);

  React.useEffect(() => {
    Promise.all([
      fetch(`${API}/year_schedule.php?year=${THIS_YEAR}`).then(r => r.json()),
      fetch(`${API}/leaderboard.php?year=${THIS_YEAR}`).then(r => r.json()),
    ])
    .then(([sched, board]) => { setSchedule(sched); setStandings(board); })
    .catch(() => setError('Could not load data.'));
  }, []);

  if (error)                   return <ErrorMsg msg={error} />;
  if (!schedule || !standings) return <div className="py-12 flex justify-center"><Spinner /></div>;

  const rounds = schedule.rounds || [];

  // Ryder Cup score — only count played matches (points !== null)
  let blueScore = 0, redScore = 0;
  for (const round of rounds) {
    for (const match of round.matches) {
      const bp = match.blue[0]?.points ?? undefined;
      if      (bp === 2) { blueScore += 1; }
      else if (bp === 1) { blueScore += 0.5; redScore += 0.5; }
      else if (bp === 0) { redScore  += 1; }
    }
  }
  const hasResults = blueScore + redScore > 0;

  return (
    <div className="max-w-3xl mx-auto p-4 space-y-4">

      {/* ── Score banner (only shown once matches are recorded) ── */}
      {hasResults && (
        <div className="bg-slate-800 rounded-2xl p-5 flex items-center justify-center gap-10 text-white">
          <div className="text-center">
            <div className="text-xs text-blue-300 font-semibold uppercase tracking-widest mb-1">Blue</div>
            <div className="text-5xl font-black mono text-blue-400">{fmtPts(blueScore)}</div>
          </div>
          <div className="text-slate-600 text-2xl font-light">–</div>
          <div className="text-center">
            <div className="text-xs text-red-300 font-semibold uppercase tracking-widest mb-1">Red</div>
            <div className="text-5xl font-black mono text-red-400">{fmtPts(redScore)}</div>
          </div>
        </div>
      )}

      {/* ── Rounds panel ── */}
      <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-100 text-xs font-bold uppercase tracking-wide text-slate-400">
          {THIS_YEAR} Rounds
        </div>
        {rounds.length === 0 ? (
          <div className="px-4 py-10 text-center text-slate-400">
            <div className="text-4xl mb-3">⛳</div>
            <p className="text-sm">No rounds set up for {THIS_YEAR} yet.</p>
            <p className="text-xs mt-1 text-slate-300">Use the admin to create the year first.</p>
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {rounds.map(r => <RoundCard key={r.id} round={r} />)}
          </div>
        )}
      </div>

      {/* ── Standings panel ── */}
      <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-100 text-xs font-bold uppercase tracking-wide text-slate-400">
          {THIS_YEAR} Standings
        </div>
        {standings.length === 0 ? (
          <div className="px-4 py-10 text-center text-slate-400">
            <div className="text-4xl mb-3">🏆</div>
            <p className="text-sm">No matches played yet — check back after Round 1.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-right text-xs text-slate-400 border-b border-slate-50">
                  <th className="px-4 py-2 text-left w-8">#</th>
                  <th className="px-4 py-2 text-left">Player</th>
                  <th className="px-4 py-2">M</th>
                  <th className="px-4 py-2">Pts</th>
                  <th className="px-4 py-2">W</th>
                  <th className="px-4 py-2">H</th>
                  <th className="px-4 py-2">L</th>
                </tr>
              </thead>
              <tbody>
                {[...standings]
                  .sort((a, b) => parseFloat(b.total_points) - parseFloat(a.total_points))
                  .map((p, i) => (
                  <tr key={p.id}
                    className="border-b border-slate-50 hover:bg-slate-50 cursor-pointer"
                    onClick={() => window.dispatchEvent(new CustomEvent('navigate', { detail: { view: 'player', id: p.id } }))}>
                    <td className="px-4 py-2 text-slate-400 mono">{i + 1}</td>
                    <td className="px-4 py-2 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <span className={`w-2 h-2 rounded-full shrink-0 ${p.team === 'blue' ? 'bg-blue-500' : 'bg-red-500'}`} />
                        <span className="font-medium text-slate-800">{p.name}</span>
                      </div>
                    </td>
                    <td className="px-4 py-2 text-right mono text-slate-500">{p.matches_played}</td>
                    <td className="px-4 py-2 text-right mono font-bold">{fmtPts(p.total_points)}</td>
                    <td className="px-4 py-2 text-right mono text-green-600">{p.wins}</td>
                    <td className="px-4 py-2 text-right mono text-amber-500">{p.halves}</td>
                    <td className="px-4 py-2 text-right mono text-slate-400">{p.losses}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
