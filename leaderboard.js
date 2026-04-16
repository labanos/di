// ─── Leaderboard View ────────────────────────────────────────────────────────

function LeaderboardView() {
  const [data, setData]     = React.useState(null);
  const [error, setError]   = React.useState(null);
  const [filter, setFilter] = React.useState('all');

  React.useEffect(() => {
    fetch(`${API}/leaderboard.php`)
      .then(r => r.json())
      .then(setData)
      .catch(() => setError('Could not load leaderboard.'));
  }, []);

  if (error) return <ErrorMsg msg={error} />;
  if (!data) return <Spinner />;

  const rows = filter === 'all' ? data : data.filter(p => p.team === filter);

  return (
    <div className="max-w-3xl mx-auto p-4">
      <div className="flex gap-2 mb-4">
        {['all','blue','red'].map(f => (
          <button key={f} onClick={() => setFilter(f)}
            className={`px-3 py-1 rounded-full text-sm font-medium transition-colors ${
              filter === f
                ? f === 'blue' ? 'bg-blue-600 text-white' : f === 'red' ? 'bg-red-600 text-white' : 'bg-slate-700 text-white'
                : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-400'
            }`}>
            {f === 'all' ? 'All' : f.charAt(0).toUpperCase() + f.slice(1)}
          </button>
        ))}
      </div>

      <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-slate-400 uppercase tracking-wide border-b border-slate-100">
              <th className="px-4 py-3">#</th>
              <th className="px-4 py-3">Player</th>
              <th className="px-4 py-3 text-right">M</th>
              <th className="px-4 py-3 text-right">Pts</th>
              <th className="px-4 py-3 text-right">W</th>
              <th className="px-4 py-3 text-right">H</th>
              <th className="px-4 py-3 text-right">L</th>
              <th className="px-4 py-3 text-right">UPs</th>
              <th className="px-4 py-3 text-right">Avg</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((p, i) => (
              <tr key={p.id}
                className="border-b border-slate-50 hover:bg-slate-50 cursor-pointer"
                onClick={() => window.dispatchEvent(new CustomEvent('navigate', { detail: { view: 'player', id: p.id } }))}>
                <td className="px-4 py-3 text-slate-400 mono">{i + 1}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <span className={`w-2 h-2 rounded-full ${p.team === 'blue' ? 'bg-blue-500' : 'bg-red-500'}`} />
                    <span className="font-medium text-slate-800">{p.name}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-right mono text-slate-500">{p.matches_played}</td>
                <td className="px-4 py-3 text-right mono font-bold text-slate-800">{p.total_points}</td>
                <td className="px-4 py-3 text-right mono text-green-600">{p.wins}</td>
                <td className="px-4 py-3 text-right mono text-amber-500">{p.halves}</td>
                <td className="px-4 py-3 text-right mono text-slate-400">{p.losses}</td>
                <td className="px-4 py-3 text-right mono text-slate-500">{p.total_ups}</td>
                <td className="px-4 py-3 text-right mono text-slate-600">{p.avg_points}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
