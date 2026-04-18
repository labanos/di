// ─── Main App ──────────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'thisyear',    label: String(THIS_YEAR) },
  { id: 'leaderboard', label: 'Leaderboard'     },
  { id: 'history',     label: 'History'         },
];

// Parse a player ID from the URL hash (#player/42), or null
function playerIdFromHash() {
  const m = window.location.hash.match(/^#player\/(\d+)$/);
  return m ? parseInt(m[1], 10) : null;
}

function App() {
  const [tab, setTab]           = React.useState('thisyear');
  const [playerView, setPlayer] = React.useState(() => {
    const id = playerIdFromHash();
    return id ? { id } : null;
  });

  // Navigate to a player: update state + push hash
  function openPlayer(id) {
    window.history.pushState(null, '', `#player/${id}`);
    setPlayer({ id });
  }

  // Go back from player: clear hash
  function closePlayer() {
    window.history.pushState(null, '', window.location.pathname + window.location.search);
    setPlayer(null);
  }

  // Browser back/forward button support
  React.useEffect(() => {
    function onPop() {
      const id = playerIdFromHash();
      setPlayer(id ? { id } : null);
    }
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  // Custom navigate events fired by child views (leaderboard, etc.)
  React.useEffect(() => {
    function onNav(e) {
      const { view, id } = e.detail;
      if (view === 'player') openPlayer(id);
    }
    window.addEventListener('navigate', onNav);
    return () => window.removeEventListener('navigate', onNav);
  }, []);

  return (
    <div className="min-h-screen bg-slate-100">
      <div className="bg-slate-900 text-white">
        <div className="max-w-3xl mx-auto px-4">
          <div className="flex items-center justify-between py-3">
            <button
              onClick={() => { closePlayer(); setTab('thisyear'); }}
              className="block">
              <img
                src="damsgaard-invitational-logo.jpeg"
                alt="Damsgaard Invitational"
                className="h-10 rounded"
              />
            </button>
          </div>
          {!playerView && (
            <div className="flex">
              {TABS.map(t => (
                <button key={t.id} onClick={() => setTab(t.id)}
                  className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                    tab === t.id
                      ? 'border-white text-white'
                      : 'border-transparent text-slate-400 hover:text-slate-200'
                  }`}>
                  {t.label}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="py-4">
        {playerView
          ? <PlayerView playerId={playerView.id} onBack={closePlayer} />
          : tab === 'thisyear'    ? <ThisYearView />
          : tab === 'leaderboard' ? <LeaderboardView />
          : tab === 'history'     ? <HistoryView />
          : null
        }
      </div>
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(<App />);
