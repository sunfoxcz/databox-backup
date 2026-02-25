# Storage Layout

All downloaded messages are stored under the `data/` directory in a structured hierarchy
organized by databox, direction, and date.

## Directory Structure

```text
data/
├── {boxId}/
│   ├── received/
│   │   └── {year}/
│   │       └── {month}/
│   │           └── {YYYYMMDD}_{messageId}/
│   │               ├── envelope.json
│   │               ├── attachment1.pdf
│   │               └── attachment2.xml
│   └── sent/
│       └── {year}/
│           └── {month}/
│               └── {YYYYMMDD}_{messageId}/
│                   ├── envelope.json
│                   └── message.zfo
└── error.log
```

### Path Components

| Component      | Format               | Example    |
|----------------|----------------------|------------|
| `{boxId}`      | Databox ID string    | `abc123`   |
| Direction      | `received` or `sent` | `received` |
| `{year}`       | 4-digit year         | `2024`     |
| `{month}`      | 2-digit month        | `03`       |
| `{YYYYMMDD}`   | Date prefix          | `20240315` |
| `{messageId}`  | ISDS message ID      | `1234567`  |

The date used is the message's delivery time, falling back to acceptance time, falling back to the current date.

## File Formats

### envelope.json

Every message directory contains an `envelope.json` with metadata:

```json
{
    "messageId": "1234567",
    "sender": "Czech Ministry of Finance",
    "senderId": "abc123",
    "recipient": "John Doe",
    "recipientId": "xyz789",
    "subject": "Tax Notice 2024",
    "deliveryTime": "2024-03-15T10:30:00+01:00",
    "acceptanceTime": "2024-03-15T10:35:00+01:00",
    "status": 6,
    "attachmentSize": 45678
}
```

### Received Message Attachments

Attachments from received messages are saved as individual files. Filenames come from the
attachment's description field in ISDS. The tool handles:

- **Sanitization**: Non-word characters (except hyphens, dots, spaces) are replaced with underscores
- **Deduplication**: If multiple attachments share a filename, a counter suffix is added (e.g., `document_1.pdf`)
- **Fallback naming**: Attachments without a description use `attachment_{guid}` or `attachment_{uniqid}`
- **XML content**: Attachments may contain XML data instead of binary content; both are handled

### Sent Message Files

Sent messages are saved as `message.zfo` — a PKCS#7 signed blob from
`SignedSentMessageDownload`. This is the only format ISDS provides for sent message
downloads.

## Incremental Strategy

The tool uses a simple directory-existence check for incremental backups:

1. Before downloading a message, the target directory path is computed
2. If the directory already exists (`is_dir()`), the message is skipped
3. No state file, database, or manifest is needed

This means re-running the backup only downloads new messages. To re-download a specific
message, delete its directory and run the backup again.

## Error Log

The file `data/error.log` accumulates timestamped error entries:

```text
[2024-03-15 10:30:00] abc123/received/1234567: Connection timeout
[2024-03-15 10:30:05] abc123/sent/7654321: No signed message data returned
```

## Next Steps

- [Architecture Overview](01-overview.md) — how the components work together
- [Running Backups](../02-usage/03-running-backups.md) — executing the tool
