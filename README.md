# Databox Backup

CLI tool for backing up messages from Czech ISDS (Datove schranky / Data Boxes) to local filesystem.

Downloads all received and sent messages from configured databoxes, saving envelope metadata
as JSON and attachments as individual files. Supports incremental backups — existing messages
are skipped automatically.

## Features

- Downloads both received and sent messages
- Saves envelope metadata (`envelope.json`) with sender, recipient, timestamps, and status
- Extracts individual attachments from received messages
- Saves sent messages as signed `.zfo` files (PKCS#7)
- Incremental backups — skips already downloaded messages
- Supports multiple databox accounts
- Works with both production and test (czebox) environments
- Runs via Docker — no local PHP installation needed

## Requirements

- Docker

## Quick Start

```bash
# Install dependencies
docker run --rm -i -v "$(pwd)":/app --workdir /app --user "$(id -u)":"$(id -g)" \
    sunfoxcz/php:8.4-cli composer install

# Create config
cp config.neon.dist config.neon
# Edit config.neon with your ISDS credentials

# Run backup
./docker-php bin/backup.php
```

## Documentation

See [docs/README.md](docs/README.md) for full documentation:

- [Architecture](docs/01-architecture/README.md) — system design and storage layout
- [Usage](docs/02-usage/README.md) — setup, configuration, and running backups

## License

[MIT](LICENSE)
