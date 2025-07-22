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
Global defaults are stored in `config/global-default.yml`. You may create a
`config/global.yml` file to override specific values locally. Per-domain
configurations are located in `config/interfaces/`. Only files prefixed with
`example` are tracked in Git to avoid leaking secrets.

Set `debug: true` in `global.yml` to include verbose logs about API calls in the
JSON responses returned by `api.php`. When enabled, storage actions (`storage_up`
and `storage_down`) also return the full request and response details.

## Data sources

`public/api.php` exposes various data points used by the interfaces. Each source
is configured under the `data` section of the merged `global-default.yml`/`global.yml`
configuration with a refresh
interval declared in `ajax:`. Responses are cached in the SQLite database.

Available sources:

- **batterie** – current battery level read from Home&nbsp;Assistant.
- **production_solaire** – live solar production value from Home&nbsp;Assistant.
- **production_solaire_estimation** – forecast from Solcast. Only the portion
  between now and the next sunset is returned. If the request happens at night,
  the period between the next sunrise and sunset is used instead.

Each entry defines the API URL, bearer token and cache lifetime (TTL). When the
cache is older than the TTL the API is queried again; otherwise the stored value

is returned. Invalid responses (for example when the API is unreachable) are not
stored, so the next request will retry immediately until a valid value is
obtained. A `debug` flag can be set in `global.yml` to attach verbose
information about the API requests to the JSON response.

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

## Post-up redirection override

You can override the URL displayed after the storage is started by providing a
`post_up` query parameter. Only the page URL is replaced; the display method
(iframe or full redirect) remains the one configured for the interface.

Example:

```
http://localhost:8000/?post_up=https://example.com/path
```
