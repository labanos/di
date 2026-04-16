#!/usr/bin/env python3
"""
Migrate DI historical data from Excel to MySQL.

Usage:
    pip install pandas openpyxl pymysql
    python migrate_excel.py --host HOST --db DB --user USER --password PASS --file path/to/di.xlsx

The script is idempotent (INSERT IGNORE / INSERT ... ON DUPLICATE KEY UPDATE)
so it is safe to run more than once.
"""

import argparse
import sys
import pandas as pd
import pymysql

YEAR_SHEETS = ['2019', '2020', '2021', '2022', '2023', '2024', '2025']

FORMAT_MAP = {
    'Fourball':  'fourball',
    'Greensome': 'greensome',
    'Foursome':  'foursome',
    'Single':    'singles',
    'Singles':   'singles',
}


def load_players(xlsx_path):
    """Return dict of {cleaned_name: team} from the Spillere sheet."""
    df = pd.read_excel(xlsx_path, sheet_name='Spillere', header=None)
    df.columns = ['name', 'team', 'matches', 'points', 'avg']
    df = df.iloc[1:].copy()

    # Keep only real string rows
    df = df[df['name'].apply(lambda x: isinstance(x, str))]
    df['name'] = df['name'].str.strip()

    # Drop Pedro Lige (dummy par player)
    df = df[df['name'] != 'Pedro Lige']

    # Deduplicate (e.g. 'Kim Behrens Jessen ' trailing-space duplicate)
    df = df.drop_duplicates(subset='name')

    team_map = {}
    for _, row in df.iterrows():
        t = str(row['team'])
        if t.startswith('Bl'):
            team_map[row['name']] = 'blue'
        elif t.startswith('R'):
            team_map[row['name']] = 'red'
        # else: skip unassigned (shouldn't happen after Pedro removed)

    return team_map


def load_year_records(xlsx_path, year_str, valid_players):
    """Return cleaned list of per-player match records for one year."""
    df = pd.read_excel(xlsx_path, sheet_name=year_str, header=None)
    sub = df.iloc[1:, 8:17].copy()
    sub.columns = ['year', 'round', 'match', 'format', 'team_col', 'player', 'points', 'ups', 'partner']

    # Keep only real player rows
    sub = sub[sub['player'].apply(lambda x: isinstance(x, str))]
    sub = sub[sub['player'].astype(str) != 'Spiller']
    sub['player'] = sub['player'].str.strip()
    sub['partner'] = sub['partner'].apply(lambda x: str(x).strip() if isinstance(x, str) else None)

    # Drop Pedro Lige and any player not in our player list
    sub = sub[sub['player'] != 'Pedro Lige']
    sub = sub[sub['player'].isin(valid_players)]

    records = []
    for _, row in sub.iterrows():
        records.append({
            'year':    int(row['year'])    if pd.notna(row['year'])   else int(year_str),
            'round':   str(row['round']),
            'match':   int(row['match'])   if pd.notna(row['match'])  else 0,
            'format':  FORMAT_MAP.get(str(row['format']), 'fourball'),
            'player':  row['player'],
            'points':  int(row['points'])  if pd.notna(row['points']) else 0,
            'ups':     int(row['ups'])     if pd.notna(row['ups'])    else 0,
            'partner': row['partner'] if row['partner'] in valid_players else None,
        })
    return records


def migrate(host, db, user, password, xlsx_path):
    conn = pymysql.connect(
        host=host, database=db, user=user, password=password,
        charset='utf8mb4', autocommit=False
    )
    cur = conn.cursor()

    # ── 1. Players ──────────────────────────────────────────────────────────
    print('Loading players from Spillere sheet...')
    team_map = load_players(xlsx_path)

    player_id = {}
    for name, team in team_map.items():
        cur.execute(
            "INSERT IGNORE INTO players (name, team) VALUES (%s, %s)",
            (name, team)
        )
        cur.execute("SELECT id FROM players WHERE name = %s", (name,))
        player_id[name] = cur.fetchone()[0]
    conn.commit()
    print(f'  {len(player_id)} players ready.')

    valid_players = set(player_id.keys())

    # ── 2. Year sheets ──────────────────────────────────────────────────────
    total_results = 0

    for year_str in YEAR_SHEETS:
        print(f'\nProcessing {year_str}...')
        records = load_year_records(xlsx_path, year_str, valid_players)
        if not records:
            print(f'  No records found, skipping.')
            continue

        year = int(year_str)

        # Tournament
        cur.execute("INSERT IGNORE INTO tournaments (year) VALUES (%s)", (year,))
        cur.execute("SELECT id FROM tournaments WHERE year = %s", (year,))
        tournament_id = cur.fetchone()[0]

        # Rounds: determine format per round_label (use first record seen)
        seen_rounds = {}
        for rec in records:
            rl = rec['round']
            if rl not in seen_rounds:
                seen_rounds[rl] = rec['format']

        round_id = {}
        for round_label, fmt in seen_rounds.items():
            # R1 -> 1, R2 -> 2, etc.
            try:
                round_num = int(round_label[1])
            except (IndexError, ValueError):
                print(f'  Skipping unexpected round label: {round_label}')
                continue
            cur.execute(
                "INSERT IGNORE INTO rounds (tournament_id, round_number, format) VALUES (%s,%s,%s)",
                (tournament_id, round_num, fmt)
            )
            cur.execute(
                "SELECT id FROM rounds WHERE tournament_id=%s AND round_number=%s",
                (tournament_id, round_num)
            )
            round_id[round_label] = cur.fetchone()[0]

        # Matches: one row per (round_label, match_number)
        seen_matches = set()
        match_id = {}
        for rec in records:
            key = (rec['round'], rec['match'])
            if key not in seen_matches:
                seen_matches.add(key)
                rid = round_id.get(rec['round'])
                if not rid:
                    continue
                cur.execute(
                    "INSERT IGNORE INTO matches (round_id, match_number) VALUES (%s,%s)",
                    (rid, rec['match'])
                )
                cur.execute(
                    "SELECT id FROM matches WHERE round_id=%s AND match_number=%s",
                    (rid, rec['match'])
                )
                match_id[key] = cur.fetchone()[0]

        # Match results
        inserted = 0
        for rec in records:
            key      = (rec['round'], rec['match'])
            mid      = match_id.get(key)
            pid      = player_id.get(rec['player'])
            if not mid or not pid:
                continue
            par_id   = player_id.get(rec['partner']) if rec['partner'] else None

            cur.execute("""
                INSERT INTO match_results (match_id, player_id, points, ups, partner_id)
                VALUES (%s,%s,%s,%s,%s)
                ON DUPLICATE KEY UPDATE
                    points=VALUES(points),
                    ups=VALUES(ups),
                    partner_id=VALUES(partner_id)
            """, (mid, pid, rec['points'], rec['ups'], par_id))
            inserted += 1

        conn.commit()
        total_results += inserted
        print(f'  {inserted} player-match results inserted/updated.')

    print(f'\nDone. Total match results: {total_results}')
    conn.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Migrate DI Excel data to MySQL')
    parser.add_argument('--host',     default='localhost', help='MySQL host')
    parser.add_argument('--db',       required=True,       help='Database name')
    parser.add_argument('--user',     required=True,       help='MySQL user')
    parser.add_argument('--password', required=True,       help='MySQL password')
    parser.add_argument('--file',     required=True,       help='Path to Excel file (.xlsx)')
    args = parser.parse_args()

    migrate(args.host, args.db, args.user, args.password, args.file)
