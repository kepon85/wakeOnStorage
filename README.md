# WakeOnStorage

This project provides a simple PHP interface for managing on-demand storage
access. Each virtual host can load its own YAML configuration to customize the
interface and authentication.

## Authentication methods

Authentication is configured per-interface under the `auth:` section. Several
methods can be combined in the `method` list. The first successful method will
allow access.

### none
No authentication. Users are granted access automatically.

### uniq
A single password (no username). For security, store a `password_hash` generated
with PHP's `password_hash` function:

```bash
php -r "echo password_hash('mysecret', PASSWORD_DEFAULT), PHP_EOL;"
```

Example:

```yaml
auth:
  method:
    - uniq
  uniq:
    password_hash: "$2y$12$examplehashedpassword"
```

### file
Credentials are read from a flat file containing `user:password` pairs, one per
line. The password value can be stored in plain text or as a hash generated with
`password_hash`. Hashed values are checked with `password_verify`.

```yaml
auth:
  method:
    - file
  file:
    path: /path/to/passwd.txt
    # each line: "user:password" where password may be a hash
```

### imap
Users authenticate against an IMAP server. Ensure the PHP IMAP extension is
installed.

```yaml
auth:
  method:
    - imap
  imap:
    server: imap.example.com
    port: 993
    secure: ssl
```

Combine methods by listing multiple entries in `method`. For example, to allow
both IMAP and file-based authentication:

```yaml
auth:
  method:
    - imap
    - file
  imap:
    server: imap.example.com
    port: 993
    secure: ssl
  file:
    path: /path/to/passwd.txt
```

## Configuration
Global settings are stored in `config/global.yml`. Per-domain configurations are
located in `config/interfaces/`. Only files prefixed with `example` are tracked
in Git to avoid leaking secrets.

## Running
Use PHP's built-in server for local testing:

```bash
php -S localhost:8000 -t public
```

Navigate to `http://localhost:8000` or the appropriate virtual host to access
the interface.

## Development

Install dependencies with Composer:

```bash
composer install
```

Utility scripts are provided in `bin/`:

- `bin/generate-password` creates a random password and prints the YAML line to
  add under `auth.uniq.password_hash`.
- `bin/add-user <file> <username> [password]` hashes the password and appends it
  to the specified credentials file.
