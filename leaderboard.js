// ─── Leaderboard View ────────────────────────────────────────────────────────

function LeaderboardView() {
  const [years, setYears]         = React.useState([]);
  const [data, setData]           = React.useState(null);
  const [error, setError]         = React.useState(null);
  const [teamFilter, setTeamFilter] = React.useState('all');
  const [year, setYear]           = React.useState('all');
  const [sortCol, setSortCol]     = React.useState('total_points');
  const [sortDir, setSortDir]     = React.useState('desc');

  // Fetch available years from the DB on mount
  React.useEffect(() => {
    fetch(`${API}/admin.php?action=years`)
      .then(r => r.json())
      .then(setYears)
      .catch(() => {}); // silently ignore; dropdown just shows "All time"
  }, []);

  // Fetch leaderboard whenever year changes
  React.useEffect(() => {
    setData(null);
    const url = year === 'all'
      ? `${API}/leaderboard.php`
      : `${API}/leaderboard.php?year=${year}`;
    fetch(url)
      .then(r => r.json())
      .then(setData)
      .catch(() => setError('Could not load leaderboard.'));
  }, [year]);

  function handleSort(col) {
    if (sortCol === col) {
      setSortDir(d => d === 'desc' ? 'asc' : 'desc');
    } else {
      setSortCol(col);
      setSortDir('desc');
    }
  }

  const rows = React.useMemo(() => {
    if (!data) return [];
    let r = teamFilter === 'all' ? [...data] : data.filter(p => p.team === teamFilter);
    r.sort((a, b) => {
      if (sortCol === 'name') {
        return sortDir === 'asc'
          ? a.name.localeCompare(b.name)
          : b.name.localeCompare(a.name);
      }
      const av = parseFloat(a[sortCol]) || 0;
      const bv = parseFloat(b[sortCol]) || 0;
      return sortDir === 'desc' ? bv - av : av - bv;
    });
    return r;
  }, [data, teamFilter, sortCol, sortDir]);

  if (error) return <ErrorMsg msg={error} />;

  // Sortable header cell
  function Th({ col, label, right = true }) {
    const active = sortCol === col;
    const arrow  = active ? (sortDir === 'desc' ? ' ↓' : ' ↑') : ' ↕';
    return (
      <th onClick={() => handleSort(col)}
        className={`px-4 py-3 cursor-pointer select-none whitespace-nowrap text-xs uppercase tracking-wide
          ${right ? 'text-right' : 'text-left'}
          ${active ? 'text-slate-600' : 'text-slate-400 hover:text-slate-500'}`}>
        {label}<span className="opacity-40 ml-0.5">{arrow}</span>
      </th>
    );
  }

  return (
    <div className="max-w-3xl mx-auto p-4">

      {/* Controls row */}
      <div className="flex flex-wrap gap-3 mb-4 items-center">

        {/* Year selector */}
        <div className="flex items-center gap-2">
          <span className="text-xs text-slate-500 font-semibold uppercase tracking-wide">Year</span>
          <select value={year} onChange={e => setYear(e.target.value)}
            className="border border-slate-200 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="all">All time</option>
            {years.map(y => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>

        {/* Team filter */}
        <div className="flex gap-1 ml-auto">
          {['all','blue','red'].map(f => (
            <button key={f} onClick={() => setTeamFilter(f)}
              className={`px-3 py-1 rounded-full text-sm font-medium transition-colors ${
                teamFilter === f
                  ? f === 'blue' ? 'bg-blue-600 text-white'
                  : f === 'red'  ? 'bg-red-600 text-white'
                  : 'bg-slate-700 text-white'
                  : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-400'
              }`}>
              {f === 'all' ? 'All' : f.charAt(0).toUpperCase() + f.slice(1)}
            </button>
          ))}
        </div>
      </div>

      <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
        {!data ? (
          <div className="py-12 flex justify-center"><Spinner /></div>
        ) : data.length === 0 ? (
          <div className="py-12 text-center text-slate-400">
            <div className="text-3xl mb-2">🏌️</div>
            <p className="text-sm">No matches played{year !== 'all' ? ` in ${year}` : ''} yet.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100">
                  <th className="px-4 py-3 text-left text-xs text-slate-400 uppercase tracking-wide w-8">#</th>
                  <Th col="name"           label="Player"  right={false} />
                  <Th col="matches_played" label="M" />
                  <Th col="total_points"   label="Pts" />
                  <Th col="wins"           label="W" />
                  <Th col="halves"         label="H" />
                  <Th col="losses"         label="L" />
                  <Th col="total_ups"      label="UPs" />
                  <Th col="avg_points"     label="Avg" />
                </tr>
              </thead>
              <tbody>
                {rows.map((p, i) => (
                  <tr key={p.id}
                    className="border-b border-slate-50 hover:bg-slate-50 cursor-pointer"
                    onClick={() => window.dispatchEvent(new CustomEvent('navigate', { detail: { view: 'player', id: p.id } }))}>
                    <td className="px-4 py-3 text-slate-400 mono">{i + 1}</td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <span className={`w-2 h-2 rounded-full flex-shrink-0 ${p.team === 'blue' ? 'bg-blue-500' : 'bg-red-500'}`} />
                        <span className="font-medium text-slate-800">{p.name}</span>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right mono text-slate-500">{p.matches_played}</td>
                    <td className="px-4 py-3 text-right mono font-bold text-slate-800">{fmtPts(p.total_points)}</td>
                    <td className="px-4 py-3 text-right mono text-green-600">{p.wins}</td>
                    <td className="px-4 py-3 text-right mono text-amber-500">{p.halves}</td>
                    <td className="px-4 py-3 text-right mono text-slate-400">{p.losses}</td>
                    <td className="px-4 py-3 text-right mono text-slate-500">{p.total_ups}</td>
                    <td className="px-4 py-3 text-right mono text-slate-600">{fmtAvg(p.avg_points)}</td>
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
