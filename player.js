// ─── Player Profile View ──────────────────────────────────────────────

const FORMAT_ABBR = { fourball: '4B', greensome: 'GS', foursome: 'FS', singles: 'S' };

function ResultBadge({ points }) {
  if (points === 2) return <span className="inline-block px-1.5 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">W</span>;
  if (points === 1) return <span className="inline-block px-1.5 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-700">½</span>;
  return <span className="inline-block px-1.5 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-500">L</span>;
}

function UpsTag({ ups }) {
  if (!ups) return null;
  return <span className={`text-xs ml-1 font-mono ${ups > 0 ? 'text-green-600' : 'text-red-400'}`}>{ups > 0 ? `+${ups}` : ups}</span>;
}

function MatchDetailsTable({ details }) {
  if (!details || details.length === 0) {
    return <p className="text-xs text-slate-400 py-2 text-center">No match details available.</p>;
  }
  return (
    <table className="w-full text-xs">
      <thead>
        <tr className="text-slate-400 border-b border-slate-200">
          <th className="py-1.5 text-left pr-3 font-medium">Rnd</th>
          <th className="py-1.5 text-left pr-3 font-medium">Fmt</th>
          <th className="py-1.5 text-left pr-3 font-medium">Partner</th>
          <th className="py-1.5 text-left font-medium">Opponents</th>
          <th className="py-1.5 text-right font-medium">Result</th>
        </tr>
      </thead>
      <tbody>
        {details.map(md => (
          <tr key={md.match_id} className="border-t border-slate-100">
            <td className="py-1.5 pr-3 text-slate-400 font-mono">{md.round_number}</td>
            <td className="py-1.5 pr-3 text-slate-500">{FORMAT_ABBR[md.format] || md.format}</td>
            <td className="py-1.5 pr-3 text-slate-600">
              {md.partners && md.partners.length > 0
                ? md.partners.join(' & ')
                : <span className="text-slate-300">—</span>}
            </td>
            <td className="py-1.5 text-slate-600">
              {md.opponents && md.opponents.length > 0
                ? md.opponents.join(' & ')
                : <span className="text-slate-300">—</span>}
            </td>
            <td className="py-1.5 text-right whitespace-nowrap">
              <ResultBadge points={md.points} />
              <UpsTag ups={md.ups} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

// ─── Share button ──────────────────────────────────────────────────────

function ShareButton({ playerId, playerName }) {
  const [state, setState] = React.useState('idle'); // idle | copied | error

  function share() {
    const url = `${window.location.origin}${window.location.pathname}#player/${playerId}`;
    if (navigator.share) {
      // Native share sheet (mobile)
      navigator.share({ title: `${playerName} — Damsgaard Invitational`, url })
        .catch(() => {}); // user cancelled — ignore
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(url)
        .then(() => { setState('copied'); setTimeout(() => setState('idle'), 2000); })
        .catch(() => { setState('error'); setTimeout(() => setState('idle'), 2000); });
    }
  }

  return (
    <button
      onClick={share}
      title="Share player profile"
      className="flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-700 transition-colors"
    >
      {state === 'copied' ? (
        <span className="text-green-600 text-xs font-medium">✓ Link copied</span>
      ) : state === 'error' ? (
        <span className="text-red-400 text-xs">Could not copy</span>
      ) : (
        <>
          {/* Share / chain-link icon */}
          <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round"
              d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
          </svg>
          <span className="text-xs">Share</span>
        </>
      )}
    </button>
  );
}

// ─── Opponent spotlight row ─────────────────────────────────────────────

function OpponentStat({ label, opp, highlight }) {
  const color = highlight === 'wins' ? 'text-green-600' : highlight === 'losses' ? 'text-red-500' : 'text-slate-700';
  return (
    <div className="flex items-center justify-between py-2">
      <div className="flex items-center gap-2 min-w-0">
        <span className="text-xs text-slate-400 w-24 shrink-0">{label}</span>
        <span className="font-medium text-slate-700 text-sm truncate">{opp.opponent_name}</span>
      </div>
      <div className="text-xs mono ml-2 whitespace-nowrap flex items-center gap-1">
        <span className={`font-bold text-sm ${color}`}>{opp[highlight]}</span>
        <span className="text-slate-300">|</span>
        <span className="text-green-600">{opp.wins}W</span>
        <span className="text-amber-500">{opp.halves}H</span>
        <span className="text-slate-400">{opp.losses}L</span>
        <span className="text-slate-300">of</span>
        <span className="text-slate-500">{opp.played}</span>
      </div>
    </div>
  );
}

// ─── Stats card (format record + opponent spotlights) ──────────────────────

function StatsCard({ data }) {
  const fmt = data.format_record || [];
  const h2h = data.head_to_head  || [];

  const mostPlayed = h2h.length > 0
    ? [...h2h].sort((a, b) => b.played - a.played)[0]
    : null;
  const mostBeaten = h2h.filter(o => o.wins > 0).sort((a, b) => b.wins - a.wins)[0]       || null;
  const mostLostTo = h2h.filter(o => o.losses > 0).sort((a, b) => b.losses - a.losses)[0] || null;

  if (fmt.length === 0 && h2h.length === 0) return null;

  return (
    <div className="bg-white rounded-2xl shadow-sm overflow-hidden mt-4">
      <div className="px-4 py-3 border-b border-slate-100 text-xs font-bold uppercase tracking-wide text-slate-400">
        More Stats
      </div>

      {/* Record by format */}
      {fmt.length > 0 && (
        <div className="px-4 py-3 border-b border-slate-100">
          <div className="text-xs font-bold uppercase tracking-wide text-slate-400 mb-2">Record by Format</div>
          <table className="w-full text-xs">
            <thead>
              <tr className="text-slate-400 border-b border-slate-100">
                <th className="py-1 text-left font-medium">Format</th>
                <th className="py-1 text-right font-medium">M</th>
                <th className="py-1 text-right font-medium text-green-600">W</th>
                <th className="py-1 text-right font-medium text-amber-500">H</th>
                <th className="py-1 text-right font-medium text-slate-400">L</th>
                <th className="py-1 text-right font-medium">Pts</th>
              </tr>
            </thead>
            <tbody>
              {fmt.map(f => {
                const pts = (f.wins * 2 + f.halves) / 2;
                return (
                  <tr key={f.format} className="border-t border-slate-50">
                    <td className="py-1.5 text-slate-600 capitalize">{f.format}</td>
                    <td className="py-1.5 text-right mono text-slate-500">{f.matches}</td>
                    <td className="py-1.5 text-right mono text-green-600">{f.wins}</td>
                    <td className="py-1.5 text-right mono text-amber-500">{f.halves}</td>
                    <td className="py-1.5 text-right mono text-slate-400">{f.losses}</td>
                    <td className="py-1.5 text-right mono text-slate-500">{fmtPts(pts)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Opponent spotlights */}
      {(mostPlayed || mostBeaten || mostLostTo) && (
        <div className="px-4 py-3">
          <div className="text-xs font-bold uppercase tracking-wide text-slate-400 mb-1">Opponents</div>
          <div className="divide-y divide-slate-50">
            {mostPlayed && <OpponentStat label="Most played" opp={mostPlayed} highlight="played" />}
            {mostBeaten && <OpponentStat label="Most beaten" opp={mostBeaten} highlight="wins" />}
            {mostLostTo && <OpponentStat label="Most losses to" opp={mostLostTo} highlight="losses" />}
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Main player view ───────────────────────────────────────────────────────

function PlayerView({ playerId, onBack }) {
  const [data, setData]         = React.useState(null);
  const [error, setError]       = React.useState(null);
  const [expandedYear, setExpY] = React.useState(null);

  React.useEffect(() => {
    if (!playerId) return;
    setData(null);
    setExpY(null);
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

      {/* Top bar: back + share */}
      <div className="mb-4 flex items-center justify-between">
        <button onClick={onBack} className="text-sm text-slate-500 hover:text-slate-800">← Back</button>
        <ShareButton playerId={playerId} playerName={data.name} />
      </div>

      {/* Team header card */}
      <div className={`rounded-2xl p-6 mb-4 text-white ${t.bg}`}>
        <div className="text-sm font-medium opacity-80 mb-1">{t.label} Team</div>
        <div className="text-2xl font-black">{data.name}</div>
        <div className="mt-4 grid grid-cols-4 gap-4 text-center">
          <div><div className="text-2xl font-black mono">{fmtPts(data.total_points)}</div><div className="text-xs opacity-70">Points</div></div>
          <div><div className="text-2xl font-black mono">{data.matches_played}</div><div className="text-xs opacity-70">Matches</div></div>
          <div><div className="text-2xl font-black mono">{data.total_ups}</div><div className="text-xs opacity-70">UPs</div></div>
          <div><div className="text-2xl font-black mono">{fmtAvg(data.avg_points)}</div><div className="text-xs opacity-70">Avg</div></div>
        </div>
      </div>

      {/* W/H/L summary */}
      <div className="bg-white rounded-2xl shadow-sm p-4 mb-4 flex justify-around text-center">
        <div><div className="text-xl font-bold text-green-600 mono">{data.wins}</div><div className="text-xs text-slate-400">Wins</div></div>
        <div><div className="text-xl font-bold text-amber-500 mono">{data.halves}</div><div className="text-xs text-slate-400">Halved</div></div>
        <div><div className="text-xl font-bold text-slate-400 mono">{data.losses}</div><div className="text-xs text-slate-400">Losses</div></div>
      </div>

      {/* Year by year — expandable */}
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
            {(data.years || []).map(y => {
              const isOpen = expandedYear === y.year;
              return (
                <React.Fragment key={y.year}>
                  {/* Summary row — click to expand */}
                  <tr
                    className={`border-b border-slate-50 text-right cursor-pointer select-none transition-colors ${isOpen ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                    onClick={() => setExpY(isOpen ? null : y.year)}
                  >
                    <td className="px-4 py-2 text-left font-medium text-slate-700">
                      <span className="inline-flex items-center gap-1.5">
                        <span className="text-slate-400 text-xs w-3">{isOpen ? '▾' : '▸'}</span>
                        {y.year}
                      </span>
                    </td>
                    <td className="px-4 py-2 mono text-slate-500">{y.matches}</td>
                    <td className="px-4 py-2 mono font-bold">{fmtPts(y.points)}</td>
                    <td className="px-4 py-2 mono text-green-600">{y.wins}</td>
                    <td className="px-4 py-2 mono text-amber-500">{y.halves}</td>
                    <td className="px-4 py-2 mono text-slate-400">{y.losses}</td>
                    <td className="px-4 py-2 mono text-slate-500">{y.ups}</td>
                  </tr>
                  {/* Expanded match details */}
                  {isOpen && (
                    <tr>
                      <td colSpan="7" className="p-0 bg-slate-50 border-b border-slate-100">
                        <div className="px-5 py-3">
                          <MatchDetailsTable details={y.match_details} />
                        </div>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Additional stats card */}
      <StatsCard data={data} />
    </div>
  );
}
