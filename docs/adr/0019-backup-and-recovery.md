# ADR 0019 — Backup and Recovery

- **Status:** Accepted
- **Date:** 2026-08-21
- **Context:** Phase 7, Step 7.2

## The risk

Every figure in the business lives in one MySQL database on one laptop, with no replica
and — until this — no backup. The ledger is the business's memory of who is owed what,
and it was one disk failure from gone. It had already been destroyed twice during
development by a `migrate:fresh` aimed at the wrong environment.

## Decision 1 — A backup is not a backup until it has been restored

`db:backup --verify` restores the archive it has just written into a scratch database
and runs `ledger:verify --transactions` against it. What it reports is not "a file was
written" but **"this file becomes a ledger that balances"**.

This paid for itself on the first run. mysqldump writes `SET @@GLOBAL.GTID_PURGED`, and
replaying that on the server it came from fails outright — *"the added gtid set must not
overlap with @@GLOBAL.GTID_EXECUTED"*. Every backup taken before that flag was disabled
would have been unrestorable, and nothing except an attempted restore would ever have
said so. The file existed, the command exited zero, and it was worthless.

A backup that fails to verify is **kept**, and the command says where. A broken backup
is evidence about what went wrong; deleting it destroys that.

## Decision 2 — Restore backs up what it is about to destroy

`db:restore` names the database, waits for confirmation, and takes a backup of the
current contents *before* replacing them. `--force` skips the asking; nothing skips the
safety copy except an explicit `--skip-safety-backup`.

Restoring the wrong file is a mistake made at two in the morning by somebody already
upset. It should cost minutes, not the year's records.

The dump names its own database, so a backup from `finance` replayed against a machine
configured for `finance_test` would otherwise recreate `finance` and leave the target
untouched — appearing to succeed while changing nothing anybody was looking at. The
command rewrites the name and says so.

## Decision 3 — Triggers are part of the backup

`--routines --triggers --events`. The append-only enforcement on ledger entries, audit
logs and reconciliations lives in database triggers. A restore without them comes back
**silently editable** — the worst kind of loss, because everything looks right and the
guarantee is gone. A test asserts the dump contains them.

## Decision 4 — The password never reaches a command line

`MysqlCredentials` writes a `--defaults-extra-file` at mode 0600 and deletes it when it
goes out of scope. Passing `--password=` would put the database password in the argument
list, where anyone who can run `ps` can read it while a backup happens to be running.

## Decision 5 — Filenames cannot collide

Names are built from the second, and two backups can happen inside one: a restore takes
its safety copy immediately after somebody has taken a manual backup. Both landed on the
same filename, the second silently replacing the first.

Found by a test that expected the count to go up and watched it stay flat. Now a
collision appends `-2`, `-3`. Losing a backup to a name collision is a quiet way to lose
the one that mattered.

## Consequences

- `storage/backups` is gitignored. These files contain every customer name, balance and
  margin in the business; one committed to a repository is a breach.
- Backups are written 0600, and land on **the same disk as the database they protect**.
  `docs/BACKUP.md` says so plainly and tells the owner to copy them off.
- `docs/BACKUP.md` is written to be followed by somebody who is already having a bad
  day: recovery first, prose second, and an explicit section on what this does *not*
  protect against — a mistake noticed a month later, the building, ransomware that can
  reach the backup directory.
- Scheduling is a documented crontab line rather than something automatic. The
  documentation is blunt that a backup job failing quietly for three months is worse
  than no job at all, because you believed in it.
