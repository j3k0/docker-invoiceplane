# Docker InvoicePlane Upgrade: 1.5.9 to 1.7.1

## Summary

Upgrade the Docker image from Ubuntu 18.04 + PHP 7.2 + InvoicePlane 1.5.9 to Ubuntu 24.04 + PHP 8.3 + InvoicePlane 1.7.1 in a single jump. The upgraded image must be volume-compatible and env-var-compatible with existing 1.5.9 deployments: users swap the image tag, restart, visit `/setup` once, and they're done.

## Context

The current image is built on entirely EOL components:
- Ubuntu 18.04 (EOL April 2023)
- PHP 7.2 (EOL November 2020)
- InvoicePlane 1.5.9 (April 2018)

The production deployment runs two instances (EUR and USD) with separate databases, each using the three-container pattern (db + app + nginx). MySQL 5.7 remains unchanged.

## Constraints

- **Volume-compatible**: Existing `/var/lib/invoiceplane` volumes (configs, uploads, backups) must work after upgrade
- **Env-var-compatible**: All current environment variables (`DB_HOST`, `INVOICEPLANE_URL`, `TZ`, etc.) continue to work unchanged
- **Same container interface**: `app:invoiceplane`, `app:nginx`, `app:backup:create`, `app:backup:restore` commands unchanged
- **MySQL 5.7 stays**: Not upgrading the database server

## Design

### 1. Dockerfile & Base Image

**Base image**: `ubuntu:noble` (24.04 LTS, supported until 2029)

**PHP**: 8.3 (ships natively in Ubuntu 24.04, no PPA needed; security support until Nov 2027)

**InvoicePlane**: 1.7.1 (Feb 2026, latest stable, PHP 8.1-8.4 support)

Package changes:

| Remove (gone in PHP 8) | Add (new requirements) | Keep (rename to 8.3) |
|---|---|---|
| `php7.2-recode` | `php8.3-bcmath` | `php8.3-fpm` |
| `php7.2-json` (bundled) | `php8.3-xml` | `php8.3-cli` |
| `php7.2-xmlrpc` | `php8.3-intl` | `php8.3-mysql` |
| | `php8.3-curl` | `php8.3-gd` |
| | | `php8.3-mbstring` |
| | | `nginx`, `default-mysql-client` (renamed from `mysql-client`, which does not exist in Ubuntu 24.04) |
| | | `wget`, `sudo`, `unzip`, `gettext-base` |

Note: `git` is currently installed but only needed if InvoicePlane 1.7.1 requires it at runtime (e.g. Composer). Verify during implementation; drop if unused.

Note: `php8.3-xml` is a genuine new requirement (provides DOM/SimpleXML/XSL for invoice generation), not a replacement for `xmlrpc`. The `xmlrpc` extension was removed from PHP 8.0 entirely; InvoicePlane does not depend on XML-RPC functionality, so dropping it is safe.

Note: `default-mysql-client` in Ubuntu 24.04 provides MySQL 8.0 client tools. These are backward-compatible with MySQL 5.7 servers for `mysqladmin` and `mysqldump` operations used in backup/restore.

PHP-FPM config path: `/etc/php/8.3/fpm/pool.d/www.conf`

### 2. install.sh

- Download InvoicePlane 1.7.1 release zip from GitHub releases
- **Zip structure**: Must be verified during implementation by inspecting the actual v1.7.1 release zip (`zipinfo` or `unzip -l`). The current `install.sh` does `unzip ... && mv ip ${INVOICEPLANE_INSTALL_DIR}` — if 1.7.1 extracts to a different directory name (or to the current directory), this command must be adapted. This is a **blocking verification** before `install.sh` can be written.
- Create `storage/framework/` and `storage/logs/` directories with write permissions for `www-data`
- Adapt `vendor/mpdf/mpdf/tmp/` chmod if the path changed in mpdf v8
- Keep the `.user.ini` generation with timezone placeholder
- Keep the `uploads.template` pattern for fresh installs

### 3. ipconfig.php Template

Updated template with all 1.7.1 parameters. New parameters added (all with safe defaults):

- `CI_ENV=production`
- `X_FRAME_OPTIONS=SAMEORIGIN`
- `ENABLE_X_CONTENT_TYPE_OPTIONS=true`
- `SESS_REGENERATE_DESTROY=false`
- `SESS_MATCH_IP=true`
- `LEGACY_CALCULATION=true`
- `PASSWORD_RESET_IP_MAX_ATTEMPTS=5`
- `PASSWORD_RESET_IP_WINDOW_MINUTES=60`
- `PASSWORD_RESET_EMAIL_MAX_ATTEMPTS=3`
- `PASSWORD_RESET_EMAIL_WINDOW_HOURS=1`
- `SUMEX_SETTINGS=false`
- `SUMEX_URL=`

No existing parameters are removed — only additions.

### 4. Config Migration (ipconfig.php)

When upgrading from 1.5.9, the existing `/var/lib/invoiceplane/configs/ipconfig.php` on the volume is an old-format file. The entrypoint handles this transparently.

New function `migrate_ipconfig()` in `assets/runtime/functions`:
1. Called from `initialize_system()` **before** `initialize_datadir()` writes the VERSION file. Currently `initialize_datadir()` unconditionally writes `INVOICEPLANE_VERSION` to the VERSION file — the migration must read the old VERSION value first, then the VERSION file is updated afterward. Concretely: `initialize_system()` will call `migrate_ipconfig()` between reading the old version and writing the new one.
2. Creates a timestamped backup: `ipconfig.php.pre-1.7.1-backup`
3. Fixes the first line format if needed (phpdotenv compatibility)
4. Appends missing parameters with their defaults
5. Runs once per upgrade (VERSION file updated after migration)
6. Logs: `"Upgraded from X to 1.7.1 — visit /setup to complete database migration"`

**Version numbering**: `INVOICEPLANE_VERSION` in the Dockerfile is set to `1.7.1` (the InvoicePlane application version, without Docker image revision suffix). This matches the existing convention where `INVOICEPLANE_VERSION=1.5.9` in the current Dockerfile while the VERSION file/image tag is `1.5.9-3`. The `vercmp()` function compares InvoicePlane versions only (e.g. `1.5.9` vs `1.7.1`) and works correctly for these values. The VERSION file and image tag use `1.7.1-1` (with Docker revision suffix) but `vercmp()` is only called with `INVOICEPLANE_VERSION` values.

### 5. Environment Variables

All existing env vars remain unchanged. New env vars added to `env-defaults`:

| Variable | Default | Maps to ipconfig.php |
|---|---|---|
| `SESS_MATCH_IP` | `true` | `SESS_MATCH_IP` |
| `X_FRAME_OPTIONS` | `SAMEORIGIN` | `X_FRAME_OPTIONS` |
| `ENABLE_X_CONTENT_TYPE_OPTIONS` | `true` | `ENABLE_X_CONTENT_TYPE_OPTIONS` |
| `LEGACY_CALCULATION` | `true` | `LEGACY_CALCULATION` |

Other new ipconfig.php params use their built-in defaults and don't need env var overrides (password reset limits, SUMEX, etc.).

### 6. Entrypoint & Functions

**entrypoint.sh**: Minimal changes — fix stale "php5-fpm" help text. PHP version adapts via `PHP_VERSION` env var.

**assets/runtime/functions**:
- New `migrate_ipconfig()` function (see section 4)
- New `invoiceplane_configure_sess_match_ip()` — wires `SESS_MATCH_IP` env var via `invoiceplane_set_param`
- New `invoiceplane_configure_security_headers()` — wires `X_FRAME_OPTIONS`, `ENABLE_X_CONTENT_TYPE_OPTIONS`
- `configure_invoiceplane()` — add calls to new configure functions
- Legacy Docker links code in `invoiceplane_finalize_database_parameters()` and `invoiceplane_finalize_php_fpm_parameters()` is kept (backward compatibility)
- **`invoiceplane_configure_proxy_ips()`**: This function does a `sed` substitution on `application/config/config.php` (not `ipconfig.php`). InvoicePlane 1.7.1's `config.php` may use a different format (e.g. `env()` helper instead of direct values). Must verify the `config.php` structure during implementation and adapt the `sed` pattern if needed. The `INVOICEPLANE_PROXY_IPS` env var continues to be handled here (not in `env-defaults`).

**Nginx config template**: No changes needed — generic enough to work as-is.

### 7. docker-compose.yml

Updated as reference example:
- Drop `version: '2'` key (ignored in modern Docker Compose)
- Update image references to new tag (e.g. `1.7.1-1`)
- Switch from `sameersbn/mysql:5.2.26` to `mysql:5.7`
- **Replace `volumes_from`** with explicit volume mounts. The current nginx service uses `volumes_from: - invoiceplane` which is a Compose v2 feature not supported when the `version` key is dropped. Replace with an explicit named volume or bind mount matching the invoiceplane service's volume.
- Three-container architecture unchanged

**VERSION file**: Bumped to `1.7.1-1`

### 8. Upgrade Procedure

For existing deployments:

```
1. Backup:
   docker compose exec app-eur app:backup:create
   docker compose exec app-usd app:backup:create

2. Swap image tag in compose file:
   sameersbn/invoiceplane:1.5.9-3 -> new-image:1.7.1-1

3. Pull and restart:
   docker compose pull && docker compose up -d

4. Run DB migrations (once per instance):
   Visit https://<domain>/index.php/setup

5. Verify.
```

The entrypoint logs a message reminding the user to visit `/setup`.

### 9. Testing & Verification

**Build-time**: `make build` succeeds, zip downloads and extracts correctly.

**Fresh install**: `docker compose up` on clean volumes, setup wizard completes, can create invoice and generate PDF.

**Upgrade path**: Start with 1.5.9-3, create data, swap to new image, confirm ipconfig.php migration, run `/setup`, verify data intact.

## What Does NOT Change

- Backup/restore logic (same tar-based approach, same paths)
- Volume mount points (`/var/lib/invoiceplane` with `configs/`, `uploads/`, `backups/`)
- Container command interface (`app:invoiceplane`, `app:nginx`, `app:backup:*`)
- Nginx vhost template
- Legacy Docker links detection code
- MySQL version (stays at 5.7)
