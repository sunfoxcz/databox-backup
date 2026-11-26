# Running Backups

How to execute the backup tool and what to expect.

## Basic Usage

```bash
./docker-php bin/backup.php
```

This runs silently, producing output only on errors.

## Verbose Mode

Add `-v` for detailed progress output:

```bash
./docker-php bin/backup.php -v
```

Verbose output shows:

- Which databox is being processed
- Each direction (received/sent) as a section
- Each newly downloaded message with its ID and subject
- Summary counts: downloaded, skipped, and errors per direction

## Docker Wrapper

The `docker-php` script runs PHP inside a Docker container:

```bash
docker run --rm -i \
    -v "$(pwd)":/app \
    --workdir /app \
    --user "$(id -u)":"$(id -g)" \
    sunfoxcz/php:8.4-cli php "$@"
```

It maps the current directory into the container and runs as your user, so files are created with your ownership.

## Incremental Behavior

The tool skips messages whose final directory already exists. New downloads are
first written to a hidden staging directory alongside the destination. The final
directory appears only after the envelope and payload are saved. If a download
fails, the next run can retry it while the message is still available in ISDS.

This does not change existing directories, including incomplete backups made by
older versions. If you identify an incomplete backup and the message is still
available, move that message's directory outside the backup tree before rerunning.
Keep the old copy until you have verified the replacement.

To run the offline retry regression test after installing Composer dependencies:

```bash
./docker-php tests/backup-retry.php
```

## Exit Codes

| Code | Meaning                                                       |
|------|---------------------------------------------------------------|
| `0`  | No fatal error; individual messages may still have failed                          |
| `1`  | Configuration error or at least one databox had a fatal error |

Individual message errors do not cause a non-zero exit code — they are logged and the backup continues.

## Error Logging

Errors are written to two destinations:

- **stderr** — Colored error messages shown in the terminal
- **`data/error.log`** — Timestamped log file for persistent error tracking

## Scheduling

For automated backups, add a cron job:

```bash
# Daily backup at 2:00 AM
0 2 * * * cd /path/to/databox-backup && ./docker-php bin/backup.php 2>> data/cron-error.log
```

## Next Steps

- [Configuration](02-configuration.md) — adjusting databox credentials and environment
- [Storage Layout](../01-architecture/02-storage-layout.md) — where files are saved
