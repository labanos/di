# DI Data Migration

Migrates 7 years of historical data from the Excel file into MySQL.

## Prerequisites

```bash
pip install pandas openpyxl pymysql
```

## Usage

```bash
python migrate_excel.py \
  --host YOUR_DB_HOST \
  --db   YOUR_DB_NAME \
  --user YOUR_DB_USER \
  --password YOUR_DB_PASS \
  --file path/to/di_data.xlsx
```

The script is **idempotent** — safe to run multiple times. It uses
`INSERT IGNORE` and `ON DUPLICATE KEY UPDATE` throughout.

## What it does

1. Reads all players from the `Spillere` sheet → inserts into `players`
   - Strips trailing spaces (deduplicates Kim Behrens Jessen)
   - Skips Pedro Lige (dummy par player, not a real participant)
2. For each year sheet (2019–2025):
   - Creates a `tournaments` row
   - Creates `rounds` rows (format auto-detected: fourball / greensome / foursome / singles)
   - Creates `matches` rows
   - Inserts `match_results` per player with points, UPs, and partner

## Notes

- The Excel stores UPs as positive for wins, negative for losses (e.g. won 3&2 → ups=3, lost 2&1 → ups=-2)
- Points encoding: 2=win, 1=halved, 0=loss
- The schema must already exist (run the app once to trigger `db_migrate.php`, or apply it manually)
