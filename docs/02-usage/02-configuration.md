# Configuration

The tool is configured via a single `config.neon` file in the project root.

## Config File

Copy `config.neon.dist` to `config.neon`:

```bash
cp config.neon.dist config.neon
```

## Format

The config uses [NEON format](https://ne-on.org/) (parsed by `nette/neon`). NEON is similar
to YAML but with some differences in syntax.

## Fields

### production

| Property | Value  |
|----------|--------|
| Type     | `bool` |
| Required | Yes    |

Controls which ISDS environment to connect to:

- `true` — Production environment at `mojedatovaschranka.cz`
- `false` — Test environment at `czebox.cz`

### databoxes

| Property | Value              |
|----------|--------------------|
| Type     | `array` of objects |
| Required | Yes                |

List of databox accounts to back up. Each entry has three required fields:

| Field      | Type     | Description                                      |
|------------|----------|--------------------------------------------------|
| `id`       | `string` | Databox ID (used in storage path and log output) |
| `login`    | `string` | ISDS login name                                  |
| `password` | `string` | ISDS password                                    |

## Example

Single databox:

```text
production: true

databoxes:
    -
        id: abc123
        login: mylogin
        password: mypassword
```

Multiple databoxes:

```text
production: true

databoxes:
    -
        id: abc123
        login: login1
        password: password1
    -
        id: xyz789
        login: login2
        password: password2
```

## Validation

The `Config::load()` method validates all fields at startup:

- `production` must be a boolean
- `databoxes` must be a non-empty array
- Each databox must have `id`, `login`, and `password` as strings

Invalid configuration produces a clear error message and exits with code 1.

## Security

The `config.neon` file contains credentials and is listed in `.gitignore`. Never commit it
to version control. Only `config.neon.dist` (the template) is tracked.

## Next Steps

- [Getting Started](01-getting-started.md) — initial setup
- [Running Backups](03-running-backups.md) — executing the tool
