# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CLI tool for backing up messages (received and sent) from Czech ISDS (Datové schránky / Data Boxes) to local filesystem. Uses `tomas-kulhanek/czech-data-box` library for ISDS API communication.

## Commands

PHP runs via Docker — there is no local PHP installation. Use the `docker-php` wrapper or the full Docker command:

```bash
# Run PHP
./docker-php <args>

# Run Composer
docker run --rm -i -v "$(pwd)":/app --workdir /app --user "$(id -u)":"$(id -g)" sunfoxcz/php:8.4-cli composer <args>

# Run the backup
./docker-php bin/backup.php

# Syntax check
./docker-php -l src/BackupService.php
```

There are no tests, linter, or static analysis configured yet.

## Architecture

**Entry point:** `bin/backup.php` — wires up dependencies and iterates configured databoxes.

**Two source files:**
- `src/Config.php` — loads and validates NEON config (`config.neon`). Private constructor with static `load()` factory method.
- `src/BackupService.php` — core backup logic. Takes a `Connector` and data directory path. For each databox: authenticates via login+password, paginates through received/sent message lists (1000/page), downloads each message, saves `envelope.json` metadata + attachment files.

**Key library types used from `tomas-kulhanek/czech-data-box`:**
- `Connector` — ISDS API client (constructed with `SerializerFactory::create()` + `GuzzleClientProvider::create()`)
- `Account` — credentials holder (login, password, LoginTypeEnum, production flag)
- Request DTOs: `GetListOfReceivedMessages`, `GetListOfSentMessages`, `MessageDownload`
- `FilterEnum::ALL` for status filter

**Storage layout:** `data/{boxId}/{received|sent}/{year}/{month}/{yyyymmdd}_{messageId}/envelope.json` + attachment files.

**Incremental strategy:** skips download if target directory already exists — no state file needed.

## Configuration

Config file: `config.neon` (copy from `config.neon.dist`). NEON format (parsed by `nette/neon`).

Fields: `production` (bool — true for mojedatovaschranka.cz, false for czebox test env), `databoxes` (array of `{id, login, password}`).

## Conventions

- PHP 8.4, `declare(strict_types=1)` in all files
- PSR-4 autoloading under `App\` namespace → `src/`
- All code, comments, and variable names in English
- Final classes, typed properties, PHPDoc for array shapes
