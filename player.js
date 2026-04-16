// ─── Player Profile View ──────────────────────────────────────────────────────

function PlayerView({ playerId, onBack }) {
  const [data, setData]   = React.useState(null);
  const [error, setError] = React.useState(null);

  React.useEffect(() => {
    if (!playerId) return;
    fetch(`${API}/player_stats.php?id=${playerId}`)
      .then(r => r.json())
      .then(setData)
      .catch(() => setError('Could not load player.'));
  }, [playerId]);

  if (error) return <ErrorMsg msg={error} />;
  if (!data)  return <Spinner />;

  const t = TEAM[data.team];

  return (
    <div className="max-w-xl mx-auto p-4">
      <button onClick={onBack} className="mb-4 text-sm text-slate-500 hover:text-slate-800">← Back</button>

      <div className={`rounded-2xl p-6 mb-4 text-white ${t.bg}`}>
        <div className="text-sm font-medium opacity-80 mb-1">{t.label} Team</div>
        <div className="text-2xl font-black">{data.name}</div>
        <div className="mt-4 grid grid-cols-4 gap-4 text-center">
          <div><div className="text-2xl font-black mono">{data.total_points}</div><div className="text-xs opacity-70">Points</div></div>
          <div><div className="text-2xl font-black mono">{data.matches_played}</div><div className="text-xs opacity-70">Matches</div></div>
          <div><div className="text-2xl font-black mono">{data.total_ups}</div><div className="text-xs opacity-70">UPs</div></div>
          <div><div className="text-2xl font-black mono">{data.avg_points}</div><div className="text-xs opacity-70">Avg</div></div>
        </div>
      </div>

      <div className="bg-white rounded-2xl shadow-sm p-4 mb-4 flex justify-around text-center">
        <div><div className="text-xl font-bold text-green-600 mono">{data.wins}</div><div className="text-xs text-slate-400">Wins</div></div>
        <div><div className="text-xl font-bold text-amber-500 mono">{data.halves}</div><div className="text-xs text-slate-400">Halved</div></div>
        <div><div className="text-xl font-bold text-slate-400 mono">{data.losses}</div><div className="text-xs text-slate-400">Losses</div></div>
      </div>

      <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-100 text-xs font-bold uppercase tracking-wide text-slate-400">Year by Year</div>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-right text-xs text-slate-400 border-b border-slate-50">
              <th className="px-4 py-2 text-left">Year</th>
              <th className="px-4 py-2">M</th>
              <th className="px-4 py-2">Pts</th>
              <th className="px-4 py-2">W</th>
              <th className="px-4 py-2">H</th>
              <th className="px-4 py-2">L</th>
              <th className="px-4 py-2">UPs</th>
            </tr>
          </thead>
          <tbody>
            {(data.years || []).map(y => (
              <tr key={y.year} className="border-b border-slate-50 text-right">
                <td className="px-4 py-2 text-left font-medium text-slate-700">{y.year}</td>
                <td className="px-4 py-2 mono text-slate-500">{y.matches}</td>
                <td className="px-4 py-2 mono font-bold">{y.points}</td>
                <td className="px-4 py-2 mono text-green-600">{y.wins}</td>
                <td className="px-4 py-2 mono text-amber-500">{y.halves}</td>
                <td className="px-4 py-2 mono text-slate-400">{y.losses}</td>
                <td className="px-4 py-2 mono text-slate-500">{y.ups}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
