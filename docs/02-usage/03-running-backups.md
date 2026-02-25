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

The tool checks whether each message's target directory already exists before downloading.
Existing messages are skipped automatically, making repeated runs safe and efficient.

To force re-download of a specific message, delete its directory:

```bash
rm -rf data/{boxId}/{received|sent}/{year}/{month}/{YYYYMMDD}_{messageId}
```

## Exit Codes

| Code | Meaning                                                       |
|------|---------------------------------------------------------------|
| `0`  | All databoxes backed up successfully                          |
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
