# Getting Started

Set up and run your first ISDS databox backup.

## Prerequisites

- Docker installed and running
- ISDS databox credentials (login + password)

No local PHP installation is needed — everything runs inside Docker containers.

## Installation

Clone the repository and install Composer dependencies:

```bash
git clone <repository-url>
cd databox-backup

docker run --rm -i -v "$(pwd)":/app --workdir /app --user "$(id -u)":"$(id -g)" \
    sunfoxcz/php:8.4-cli composer install
```

## Configuration

Copy the example config and fill in your credentials:

```bash
cp config.neon.dist config.neon
```

Edit `config.neon`:

```text
production: true

databoxes:
    -
        id: your-databox-id
        login: your_login
        password: your_password
```

Set `production: true` for the real ISDS environment (`mojedatovaschranka.cz`) or `false` for the test environment (`czebox.cz`).

See [Configuration](02-configuration.md) for full reference.

## Running the Backup

```bash
./docker-php bin/backup.php
```

Add `-v` for verbose output showing each downloaded message:

```bash
./docker-php bin/backup.php -v
```

Messages are saved to the `data/` directory.
See [Storage Layout](../01-architecture/02-storage-layout.md) for the directory structure.

## Next Steps

- [Configuration Reference](02-configuration.md) — all config options
- [Running Backups](03-running-backups.md) — CLI details and incremental behavior
- [Architecture Overview](../01-architecture/01-overview.md) — how the tool works internally
