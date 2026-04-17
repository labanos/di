// ─── Main App ─────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'leaderboard', label: 'Leaderboard' },
  { id: 'history',     label: 'History'     },
];

function App() {
  const [tab, setTab]           = React.useState('leaderboard');
  const [playerView, setPlayer] = React.useState(null);

  React.useEffect(() => {
    function onNav(e) {
      const { view, id } = e.detail;
      if (view === 'player') setPlayer({ id });
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
              onClick={() => { setTab('leaderboard'); setPlayer(null); }}
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
          ? <PlayerView playerId={playerView.id} onBack={() => setPlayer(null)} />
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
