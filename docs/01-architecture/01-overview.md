# Overview

The backup tool connects to the Czech ISDS API, iterates through all messages in configured
databoxes, and downloads them to local filesystem storage.

## Components

The codebase consists of three files:

- **`bin/backup.php`** — Entry point, wires dependencies, iterates databoxes
- **`src/Config.php`** (`App\Config`) — Loads and validates NEON configuration
- **`src/BackupService.php`** (`App\BackupService`) — Core backup logic

### Entry Point (`bin/backup.php`)

Bootstraps the application:

1. Parses CLI flags (`-v` for verbose output)
2. Loads configuration via `Config::load()`
3. Creates the ISDS `Connector` (serializer + HTTP client)
4. Instantiates `BackupService` with the connector and data directory
5. Iterates through configured databoxes and calls `backup()` on each

### Config (`src/Config.php`)

Immutable configuration holder with a static `load()` factory method. Parses NEON format
using `nette/neon` and validates all required fields. Private constructor enforces creation
through `load()` only.

### BackupService (`src/BackupService.php`)

Handles the core backup workflow for a single databox:

1. Creates an `Account` from credentials (login + password, name/password auth type)
2. Processes received messages, then sent messages
3. For each direction, paginates through the message list (1000 per page)
4. For each message, checks if already downloaded (directory exists), and if not, downloads and saves it

## ISDS API Communication

The tool uses the `tomas-kulhanek/czech-data-box` library. Key types:

| Type                          | Purpose                                       |
|-------------------------------|-----------------------------------------------|
| `Connector`                   | Main API client                               |
| `Account`                     | Credentials holder                            |
| `GetListOfReceivedMessages`   | Request DTO for listing received messages     |
| `GetListOfSentMessages`       | Request DTO for listing sent messages         |
| `MessageDownload`             | Request DTO for downloading a received message|
| `SignedSentMessageDownload`   | Request DTO for downloading a sent message    |
| `FilterEnum::ALL`             | Status filter to include all messages         |

## Data Flow

```text
config.neon
    |
    v
Config::load() --> Config { production, databoxes[] }
    |
    v
For each databox:
    |
    v
BackupService::backup(databox, production)
    |
    +--> createAccount() --> Account
    |
    +--> backupMessages(account, boxId, "received")
    |       |
    |       +--> fetchMessageList() -- paginated, 1000/page
    |       |       |
    |       |       v
    |       |   ISDS API: GetListOfReceivedMessages
    |       |
    |       +--> For each message:
    |               |
    |               +--> Skip if directory exists (incremental)
    |               |
    |               +--> downloadAndSave()
    |                       |
    |                       +--> Save envelope.json
    |                       +--> MessageDownload --> individual attachments
    |
    +--> backupMessages(account, boxId, "sent")
            |
            +--> (same pagination)
            |
            +--> downloadAndSave()
                    |
                    +--> Save envelope.json
                    +--> SignedSentMessageDownload --> message.zfo
```

## Received vs Sent Messages

The ISDS API treats received and sent messages differently:

- **Received messages**: Full download via `MessageDownload`. Returns the message with
  individual attachments that can be extracted and saved as separate files.
- **Sent messages**: Only available via `SignedSentMessageDownload`. Returns the entire
  message as a PKCS#7 signed blob, saved as `message.zfo`.

## Error Handling

- Per-databox errors are caught at the top level — one failing databox does not stop others
- Per-message errors are caught and logged, allowing the backup to continue with remaining messages
- Errors are written to both stderr (colored output) and `data/error.log` (timestamped)
- The process exits with code 1 if any databox encountered a fatal error

## Next Steps

- [Storage Layout](02-storage-layout.md) — how downloaded data is organized on disk
- [Running Backups](../02-usage/03-running-backups.md) — how to execute the tool
