# Backup and recovery

**If you are here because something has gone wrong, skip to [Restoring](#restoring).**

---

## What is at risk

Every figure in the business lives in one MySQL database on one machine. There is no
replica. If the disk fails, or somebody runs the wrong command, what is lost is the
ledger — and the ledger is the business's memory of who is owed what.

Two things are needed to bring the application back. **Both.**

| | Where it lives | Lost if |
|---|---|---|
| The database | MySQL, database `finance` | The disk fails, or a `migrate:fresh` |
| `.env` | The project root, **not in git** | The machine is lost |

`.env` matters more than it looks. It holds `APP_KEY`, which signs sessions and
password-reset links. Nothing in this application encrypts a column today — so a
database restored without the original `APP_KEY` is complete and readable — but that
is true of the schema as it stands, not a promise about the future. Keep both.

## Taking a backup

```bash
php artisan db:backup --verify
```

Writes `storage/backups/finance-YYYY-MM-DD-HHMMSS.sql.gz`, readable only by you, and
keeps the newest fourteen.

`--verify` is the part that matters. It restores the backup it just took into a
scratch database and runs `ledger:verify` against it, so what you are told is not
"a file was written" but **"this file becomes a ledger that balances"**. A backup
nobody has restored is a hope.

It costs a few seconds on a database this size. Use it.

A backup that fails to verify is **kept**, and the command says where. A broken
backup is evidence about what went wrong; deleting it destroys that.

### Every day, without remembering

**On this machine (macOS), use launchd — not cron.**

cron does not run a job it slept through, and this is a laptop that is asleep at two in
the morning. A cron entry would look installed and never fire once. launchd runs a
missed job when the machine next wakes, which is the behaviour actually wanted.

```bash
cp deploy/com.finance.backup.plist ~/Library/LaunchAgents/
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.finance.backup.plist
```

Then **prove it works now**, rather than finding out tomorrow:

```bash
launchctl kickstart -k gui/$(id -u)/com.finance.backup
```

```bash
tail storage/logs/backup.log
```

You are looking for `Verified: the backup restores and the ledger balances.`

To stop it: `launchctl bootout gui/$(id -u)/com.finance.backup`

#### On a server that is always on

There, cron is fine. `crontab -e` opens your personal schedule file; each line is one
job. Note the `cd` — cron does not start in the project directory, and without it the
job fails every night with `Could not open input file: artisan`:

```bash
0 2 * * * cd /Users/omarelsayed/Finance && /opt/homebrew/bin/php artisan db:backup --verify >> storage/logs/backup.log 2>&1
```

#### Either way, check the log sometimes

A backup job that has been failing quietly for three months is worse than no backup
job, because you believed in it. `tail storage/logs/backup.log` occasionally.

### Getting the backups off this machine

The command writes to `storage/backups`, which is on the same disk as the database it
is protecting. A disk failure takes both.

Copy them somewhere else — an external drive, another machine, a cloud folder:

```bash
rsync -av storage/backups/ /Volumes/Backup/finance/
```

These files contain every customer name, balance and margin in the business. Treat a
copy on a USB stick the way you would treat the safe's contents.

## Restoring

**This replaces everything in the database.** It is the most destructive command here.

```bash
php artisan db:restore storage/backups/finance-2026-08-20-020000.sql.gz
```

It will:

1. Tell you which database it is about to replace, and wait for you to confirm.
2. **Back up the current database first**, and print where — so restoring the wrong
   file costs minutes rather than the year.
3. Replay the backup.
4. Run `ledger:verify` and tell you whether the books balance.

If the restore fails partway, the safety backup printed in step 2 is the way back.

A backup taken from a different database (say, production restored onto a laptop) is
restored into whichever database is configured, and the command says so before doing
it. It cannot silently recreate the database it came from and leave yours untouched.

### Checking a backup without touching anything

To confirm an old backup is still good, restore it into a scratch database rather than
over the top of the real one:

```bash
mysql -e "CREATE DATABASE finance_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
DB_DATABASE=finance_drill php artisan db:restore storage/backups/finance-2026-08-01-020000.sql.gz --force --skip-safety-backup
mysql -e "DROP DATABASE finance_drill"
```

Worth doing once a quarter. A restore you have practised is a procedure; one you have
not is an experiment, and you will be running it on the worst day of the year.

## What a backup contains

- Every table, with its data
- **The triggers** — the append-only enforcement on ledger entries, audit logs and
  reconciliations. Without them a restored database comes back silently editable,
  which is the kind of loss nobody notices until somebody edits something.
- Routines and events
- `CREATE DATABASE`, so the file names its own origin

Taken with `--single-transaction`, so it is a consistent snapshot and does not lock
the tables. Nobody at the counter waits for it.

## What it does not contain

- `.env`, including `APP_KEY` — copy it separately, and never into git
- Uploaded files — there are none; the application stores no user files
- The application code — that is in git

## If the worst happens

1. Get a machine with PHP 8.3+, MySQL 9 and Node.
2. `git clone` the repository.
3. Restore `.env` from wherever you kept it.
4. `composer install && npm install && npm run build`
5. `mysql -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"`
6. `php artisan db:restore <the newest backup that verifies>`
7. `php artisan ledger:verify --transactions` — it should say every transaction
   balances. If it does not, **stop and read the output**; do not record anything new
   on top of a ledger that does not balance.

## What this does not protect against

Being honest about the edges:

- **A mistake you do not notice for a month.** Fourteen daily backups reach back two
  weeks. Keep a monthly copy elsewhere if that matters.
- **The building.** Backups on the same disk, or in the same room, share the disk's
  and the room's fate.
- **Ransomware reaching the backup directory.** A copy that the machine cannot write
  to — an external drive that is unplugged, or a cloud folder with versioning — is the
  only real answer.
