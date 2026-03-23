# InvoicePlane Docker Image Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the Docker image from Ubuntu 18.04/PHP 7.2/InvoicePlane 1.5.9 to Ubuntu 24.04/PHP 8.3/InvoicePlane 1.7.1, maintaining full volume and env-var compatibility with existing deployments.

**Architecture:** Single Docker image serving multiple roles via entrypoint dispatch (app:invoiceplane, app:nginx, app:backup:*). The upgrade touches the build layer (Dockerfile, install.sh), config templates (ipconfig.php), and runtime logic (functions, env-defaults, entrypoint.sh). A migration function handles transparent upgrade of existing configs on mounted volumes.

**Tech Stack:** Docker, Ubuntu 24.04, PHP 8.3-FPM, Nginx, MySQL 5.7 (client tools only), Bash

**Spec:** `docs/superpowers/specs/2026-03-23-docker-invoiceplane-upgrade-design.md`

---

### Task 1: Investigate InvoicePlane 1.7.1 release zip structure

This is a **blocking investigation** — its findings determine the exact code for Tasks 2-3.

**Files:**
- None modified — investigation only

- [ ] **Step 1: Download the v1.7.1 release zip**

```bash
cd /tmp
wget -nv "https://github.com/InvoicePlane/InvoicePlane/releases/download/v1.7.1/v1.7.1.zip" -O v1.7.1.zip
```

- [ ] **Step 2: Inspect zip top-level structure**

```bash
unzip -l /tmp/v1.7.1.zip | head -40
```

Determine: does it extract to an `ip/` subdirectory (like v1.5.9) or directly to the current directory? Record the answer — this determines the `mv` command in `install.sh`.

- [ ] **Step 3: Check for key directories and files**

```bash
# Extract to temp dir for inspection
mkdir -p /tmp/ip-inspect && cd /tmp/ip-inspect
unzip -q /tmp/v1.7.1.zip

# Check top-level directory name
ls -la

# Check for storage/ directory
ls -la */storage/ 2>/dev/null || ls -la storage/ 2>/dev/null || echo "No storage/ directory"

# Check mpdf tmp path
find . -path "*/mpdf/tmp" -type d 2>/dev/null || echo "No mpdf/tmp directory"

# Check if ipconfig.php.example exists and its first line
head -5 */ipconfig.php.example 2>/dev/null || head -5 ipconfig.php.example 2>/dev/null

# Check application/config/config.php for proxy_ips format
grep -n "proxy_ips" */application/config/config.php 2>/dev/null || grep -n "proxy_ips" application/config/config.php 2>/dev/null

# Check if git is used at runtime (composer.json scripts, etc.)
grep -r "exec.*git" */composer.json 2>/dev/null || echo "No git runtime dependency"

# Check uploads/ directory structure
ls -la */uploads/ 2>/dev/null || ls -la uploads/ 2>/dev/null
```

- [ ] **Step 4: Record findings**

Document the following in a comment at the top of this task (or inline notes for the implementer):
1. Zip extracts to: `ip/` or `.` or other directory name
2. `storage/` directory: exists at path ___
3. mpdf tmp path: ___
4. ipconfig.php.example first line format: ___
5. proxy_ips format in config.php: ___
6. git needed at runtime: yes/no
7. uploads/ structure: ___

These findings feed directly into Tasks 2, 3, and 7.

- [ ] **Step 5: Clean up**

```bash
rm -rf /tmp/v1.7.1.zip /tmp/ip-inspect
```

---

### Task 2: Update Dockerfile

**Files:**
- Modify: `Dockerfile`

**Depends on:** Task 1 findings (PHP-FPM config path confirmed, package names validated)

- [ ] **Step 1: Rewrite the Dockerfile**

Replace the full content of `Dockerfile` with:

```dockerfile
FROM ubuntu:noble-20250127
LABEL maintainer="sameer@damagehead.com"

ENV PHP_VERSION=8.3 \
    INVOICEPLANE_VERSION=1.7.1 \
    INVOICEPLANE_USER=www-data \
    INVOICEPLANE_INSTALL_DIR=/var/www/invoiceplane \
    INVOICEPLANE_DATA_DIR=/var/lib/invoiceplane \
    INVOICEPLANE_CACHE_DIR=/etc/docker-invoiceplane

ENV INVOICEPLANE_BUILD_DIR=${INVOICEPLANE_CACHE_DIR}/build \
    INVOICEPLANE_RUNTIME_DIR=${INVOICEPLANE_CACHE_DIR}/runtime

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y wget sudo unzip \
      php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-mysql \
      php${PHP_VERSION}-gd php${PHP_VERSION}-mbstring \
      php${PHP_VERSION}-bcmath php${PHP_VERSION}-xml php${PHP_VERSION}-intl \
      php${PHP_VERSION}-curl \
      default-mysql-client nginx gettext-base \
 && sed -i 's/^listen = .*/listen = 0.0.0.0:9000/' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
 && rm -rf /var/lib/apt/lists/*

COPY assets/build/ ${INVOICEPLANE_BUILD_DIR}/

RUN bash ${INVOICEPLANE_BUILD_DIR}/install.sh

COPY assets/runtime/ ${INVOICEPLANE_RUNTIME_DIR}/

COPY assets/tools/ /usr/bin/

COPY entrypoint.sh /sbin/entrypoint.sh

RUN chmod 755 /sbin/entrypoint.sh

WORKDIR ${INVOICEPLANE_INSTALL_DIR}

ENTRYPOINT ["/sbin/entrypoint.sh"]

CMD ["app:invoiceplane"]

EXPOSE 80/tcp 9000/tcp
```

Key changes from original:
- `ubuntu:noble-20250127` replaces `ubuntu:bionic-20190612`
- `PHP_VERSION=8.3` replaces `7.2`
- `INVOICEPLANE_VERSION=1.7.1` replaces `1.5.9`
- Removed: `php-recode`, `php-json`, `php-xmlrpc`, `git`
- Added: `php-bcmath`, `php-xml`, `php-intl`, `php-curl`
- `mysql-client` replaced with `default-mysql-client`

**Note:** If Task 1 reveals `git` is needed at runtime, add it back to the package list.

- [ ] **Step 2: Verify Dockerfile syntax**

```bash
docker build --check -f Dockerfile . 2>&1 || echo "No --check support, will verify on full build"
```

- [ ] **Step 3: Commit**

```bash
git add Dockerfile
git commit -m "feat: upgrade Dockerfile to Ubuntu 24.04 + PHP 8.3 + IP 1.7.1

Base image: ubuntu:noble (24.04 LTS)
PHP: 8.3 (native in Ubuntu 24.04)
InvoicePlane: 1.7.1 (latest stable)

Removed EOL PHP extensions (recode, json, xmlrpc).
Added new requirements (bcmath, xml, intl, curl).
Replaced mysql-client with default-mysql-client."
```

---

### Task 3: Update install.sh

**Files:**
- Modify: `assets/build/install.sh`

**Depends on:** Task 1 findings (zip structure, mpdf path, storage dir, ipconfig.php.example format)

- [ ] **Step 1: Rewrite install.sh**

Adapt the script based on Task 1 findings. The template below assumes the zip extracts to an `ip/` directory — **adjust the extraction/move command if Task 1 showed otherwise**.

```bash
#!/bin/bash
set -e

if [[ ! -f ${INVOICEPLANE_BUILD_DIR}/v${INVOICEPLANE_VERSION}.zip ]]; then
  echo "Downloading InvoicePlane ${INVOICEPLANE_VERSION}..."
  wget -nv "https://github.com/InvoicePlane/InvoicePlane/releases/download/v${INVOICEPLANE_VERSION}/v${INVOICEPLANE_VERSION}.zip" \
    -O ${INVOICEPLANE_BUILD_DIR}/v${INVOICEPLANE_VERSION}.zip
fi

echo "Extracting InvoicePlane ${INVOICEPLANE_VERSION}..."
unzip ${INVOICEPLANE_BUILD_DIR}/v${INVOICEPLANE_VERSION}.zip
# ADJUST THIS LINE based on Task 1 findings:
# If zip extracts to 'ip/': mv ip ${INVOICEPLANE_INSTALL_DIR}
# If zip extracts to current dir: mkdir -p ${INVOICEPLANE_INSTALL_DIR} && mv ./* ${INVOICEPLANE_INSTALL_DIR}/
# If zip extracts to other dir: mv <dirname> ${INVOICEPLANE_INSTALL_DIR}
mv ip ${INVOICEPLANE_INSTALL_DIR}

mv ${INVOICEPLANE_INSTALL_DIR}/uploads ${INVOICEPLANE_INSTALL_DIR}/uploads.template
rm -rf ${INVOICEPLANE_BUILD_DIR}/v${INVOICEPLANE_VERSION}.zip

(
  echo "default_charset = 'UTF-8'"
  echo "output_buffering = off"
  echo "date.timezone = {{INVOICEPLANE_TIMEZONE}}"
) > ${INVOICEPLANE_INSTALL_DIR}/.user.ini

mkdir -p /run/php/

# remove default nginx virtualhost
rm -rf /etc/nginx/sites-enabled/default

# set directory permissions
cp ${INVOICEPLANE_INSTALL_DIR}/ipconfig.php.example ${INVOICEPLANE_INSTALL_DIR}/ipconfig.php
find ${INVOICEPLANE_INSTALL_DIR}/ -type f -print0 | xargs -0 chmod 0640
find ${INVOICEPLANE_INSTALL_DIR}/ -type d -print0 | xargs -0 chmod 0750
chown -R root:${INVOICEPLANE_USER} ${INVOICEPLANE_INSTALL_DIR}/
chown -R ${INVOICEPLANE_USER}: ${INVOICEPLANE_INSTALL_DIR}/application/config/
chown -R ${INVOICEPLANE_USER}: ${INVOICEPLANE_INSTALL_DIR}/application/logs/
chown root:${INVOICEPLANE_USER} ${INVOICEPLANE_INSTALL_DIR}/.user.ini
chmod 0644 ${INVOICEPLANE_INSTALL_DIR}/.user.ini
chmod 0660 ${INVOICEPLANE_INSTALL_DIR}/ipconfig.php

# ADJUST THIS LINE based on Task 1 findings:
# If vendor/mpdf/mpdf/tmp/ exists: chmod 1777 ${INVOICEPLANE_INSTALL_DIR}/vendor/mpdf/mpdf/tmp/
# If path changed: use the new path
# If no such directory: remove this line
chmod 1777 ${INVOICEPLANE_INSTALL_DIR}/vendor/mpdf/mpdf/tmp/

# Create storage directories if they exist in 1.7.1 (per Task 1 findings)
# ADJUST: only include if Task 1 confirmed storage/ exists
# mkdir -p ${INVOICEPLANE_INSTALL_DIR}/storage/framework
# mkdir -p ${INVOICEPLANE_INSTALL_DIR}/storage/logs
# chown -R ${INVOICEPLANE_USER}: ${INVOICEPLANE_INSTALL_DIR}/storage/
```

- [ ] **Step 2: Commit**

```bash
git add assets/build/install.sh
git commit -m "feat: update install.sh for InvoicePlane 1.7.1

Adapt zip extraction for v1.7.1 structure.
Update mpdf temp directory path.
Add storage directories if present in 1.7.1."
```

---

### Task 4: Update ipconfig.php template

**Files:**
- Modify: `assets/runtime/config/invoiceplane/ipconfig.php`

**Depends on:** Task 1 (ipconfig.php.example first line format)

- [ ] **Step 1: Update the template**

The template should match the v1.7.1 ipconfig.php.example format. **Verify the first line against Task 1 findings** — v1.6+ may require `#` before the PHP tag for phpdotenv compatibility.

```php
<?php exit('No direct script access allowed'); ?>
# InvoicePlane Configuration File

# Environment (production, development, testing)
CI_ENV=production

# Set your URL without trailing slash here, e.g. http://your-domain.com
# If you use a subdomain, use http://subdomain.your-domain.com
# If you use a subfolder, use http://your-domain.com/subfolder
IP_URL=

# Having problems? Enable debug by changing the value to 'true' to enable advanced logging
ENABLE_DEBUG=false

# Set this setting to 'true' if you want to disable the setup for security purposes
DISABLE_SETUP=false

# To remove index.php from the URL, set this setting to 'true'.
# Please notice the additional instructions in the htaccess file!
REMOVE_INDEXPHP=false

# These database settings are set during the initial setup
DB_HOSTNAME=
DB_USERNAME=
DB_PASSWORD=
DB_DATABASE=
DB_PORT=

# If you want to be logged out after closing your browser window, set this setting to 0 (ZERO).
# The number represents the amount of minutes after that IP will automatically log out users,
# the default is 10 days.
SESS_EXPIRATION=864000

# Enable the deletion of invoices
ENABLE_INVOICE_DELETION=false

# Disable the read-only mode for invoices
DISABLE_READ_ONLY=false

# Security: X-Frame-Options header (SAMEORIGIN, DENY, or ALLOW-FROM uri)
X_FRAME_OPTIONS=SAMEORIGIN

# Security: X-Content-Type-Options header
ENABLE_X_CONTENT_TYPE_OPTIONS=true

# Session: regenerate session ID and destroy old session
SESS_REGENERATE_DESTROY=false

# Session: match IP address for session validation
SESS_MATCH_IP=true

# Use legacy calculation method (for backward compatibility with existing invoices)
LEGACY_CALCULATION=true

# Password reset rate limiting
PASSWORD_RESET_IP_MAX_ATTEMPTS=5
PASSWORD_RESET_IP_WINDOW_MINUTES=60
PASSWORD_RESET_EMAIL_MAX_ATTEMPTS=3
PASSWORD_RESET_EMAIL_WINDOW_HOURS=1

# Sumex e-invoicing settings
SUMEX_SETTINGS=false
SUMEX_URL=

##
## DO NOT CHANGE ANY CONFIGURATION VALUES BELOW THIS LINE!
## =======================================================
##

# This key is automatically set after the first setup. Do not change it manually!
ENCRYPTION_KEY=
ENCRYPTION_CIPHER=AES-256

# Set to true after the initial setup
SETUP_COMPLETED=false
```

**IMPORTANT:** Cross-check this template against the actual `ipconfig.php.example` from the v1.7.1 zip (inspected in Task 1). The template above is based on research — the actual file is the source of truth. Adjust parameter names, order, and first-line format to match exactly.

**FIRST LINE DECISION (from Task 1):** If `ipconfig.php.example` in v1.7.1 starts with `#<?php ...` (with a `#` prefix for phpdotenv compatibility), update the first line of this template to match. The migration function in Task 6 adds `#` to old configs — the fresh-install template and migration must agree on the target format.

- [ ] **Step 2: Commit**

```bash
git add assets/runtime/config/invoiceplane/ipconfig.php
git commit -m "feat: update ipconfig.php template for InvoicePlane 1.7.1

Add new parameters: CI_ENV, X_FRAME_OPTIONS, ENABLE_X_CONTENT_TYPE_OPTIONS,
SESS_REGENERATE_DESTROY, SESS_MATCH_IP, LEGACY_CALCULATION, password reset
rate limiting, and SUMEX settings."
```

---

### Task 5: Update env-defaults

**Files:**
- Modify: `assets/runtime/env-defaults`

- [ ] **Step 1: Add new environment variables**

Append the following to `assets/runtime/env-defaults` after the existing `INVOICEPLANE_BACKUPS_EXPIRY` line:

```bash
## SECURITY
SESS_MATCH_IP=${SESS_MATCH_IP:-true}
X_FRAME_OPTIONS=${X_FRAME_OPTIONS:-SAMEORIGIN}
ENABLE_X_CONTENT_TYPE_OPTIONS=${ENABLE_X_CONTENT_TYPE_OPTIONS:-true}

## CALCULATION
LEGACY_CALCULATION=${LEGACY_CALCULATION:-true}
```

- [ ] **Step 2: Commit**

```bash
git add assets/runtime/env-defaults
git commit -m "feat: add new env vars for security and calculation settings

SESS_MATCH_IP, X_FRAME_OPTIONS, ENABLE_X_CONTENT_TYPE_OPTIONS,
LEGACY_CALCULATION — all with backward-compatible defaults."
```

---

### Task 6: Update functions — config migration logic

**Files:**
- Modify: `assets/runtime/functions` (lines 337-395: `initialize_datadir` and `initialize_system`)

This is the most critical task — it makes the upgrade transparent for existing volumes.

- [ ] **Step 1: Add the `migrate_ipconfig()` function**

Add this function in `assets/runtime/functions` **before** `initialize_datadir()` (before line 337):

```bash
migrate_ipconfig() {
  local old_version=${1}
  local new_version=${INVOICEPLANE_VERSION}

  # Strip any Docker revision suffix (e.g. "1.5.9-3" -> "1.5.9") for vercmp compatibility.
  # Note: the current Dockerfile writes INVOICEPLANE_VERSION (without suffix) to the data-dir
  # VERSION file, so this should already be clean. The strip is a safety measure.
  old_version=${old_version%%-*}

  if [[ -z ${old_version} ]] || [[ $(vercmp ${old_version} ${new_version}) -ge 0 ]]; then
    return 0  # no migration needed (fresh install or same/newer version)
  fi

  echo "Migrating ipconfig.php from v${old_version} to v${new_version}..."

  local config=${INVOICEPLANE_CONFIGS_DIR}/ipconfig.php
  if [[ ! -f ${config} ]]; then
    return 0  # no existing config to migrate
  fi

  # backup existing config
  cp ${config} ${config}.pre-${new_version}-backup
  echo "  Backed up existing config to ipconfig.php.pre-${new_version}-backup"

  # fix first line for phpdotenv compatibility if needed
  # (check if first line starts with <?php and prepend # if so)
  local first_line=$(head -1 ${config})
  if [[ ${first_line} == "<?php"* ]] && [[ ${first_line} != "#<?php"* ]]; then
    sed -i '1s/^/#/' ${config}
    echo "  Fixed first line for phpdotenv compatibility"
  fi

  # append missing parameters with defaults
  local -A new_params=(
    ["CI_ENV"]="production"
    ["X_FRAME_OPTIONS"]="SAMEORIGIN"
    ["ENABLE_X_CONTENT_TYPE_OPTIONS"]="true"
    ["SESS_REGENERATE_DESTROY"]="false"
    ["SESS_MATCH_IP"]="true"
    ["LEGACY_CALCULATION"]="true"
    ["PASSWORD_RESET_IP_MAX_ATTEMPTS"]="5"
    ["PASSWORD_RESET_IP_WINDOW_MINUTES"]="60"
    ["PASSWORD_RESET_EMAIL_MAX_ATTEMPTS"]="3"
    ["PASSWORD_RESET_EMAIL_WINDOW_HOURS"]="1"
    ["SUMEX_SETTINGS"]="false"
    ["SUMEX_URL"]=""
  )

  for param in "${!new_params[@]}"; do
    if ! grep -q "^${param}=" ${config}; then
      echo "${param}=${new_params[$param]}" >> ${config}
      echo "  Added ${param}=${new_params[$param]}"
    fi
  done

  chown ${INVOICEPLANE_USER}: ${config}
  chmod 0640 ${config}

  echo ""
  echo "=================================================="
  echo "  UPGRADE NOTICE: InvoicePlane upgraded from"
  echo "  v${old_version} to v${new_version}."
  echo ""
  echo "  Visit /index.php/setup to complete the database"
  echo "  migration. This is required for the upgrade."
  echo "=================================================="
  echo ""
}
```

- [ ] **Step 2: Restructure `initialize_datadir()` to separate VERSION reading from writing**

The current `initialize_datadir()` reads the old VERSION and immediately overwrites it (lines 362-366). We need to split this so `migrate_ipconfig()` runs between the read and the write.

Replace the VERSION block in `initialize_datadir()` (lines 362-366):

```bash
  # OLD CODE:
  # CURRENT_VERSION=
  # [[ -f ${INVOICEPLANE_DATA_DIR}/VERSION ]] && CURRENT_VERSION=$(cat ${INVOICEPLANE_DATA_DIR}/VERSION)
  # if [[ ${INVOICEPLANE_VERSION} != ${CURRENT_VERSION} ]]; then
  #   echo -n "${INVOICEPLANE_VERSION}" > ${INVOICEPLANE_DATA_DIR}/VERSION
  # fi

  # NEW CODE: read version but don't write yet — migration runs first
  CURRENT_VERSION=
  [[ -f ${INVOICEPLANE_DATA_DIR}/VERSION ]] && CURRENT_VERSION=$(cat ${INVOICEPLANE_DATA_DIR}/VERSION)
```

- [ ] **Step 3: Add `update_version()` function**

Add after `initialize_datadir()`:

```bash
update_version() {
  if [[ ${INVOICEPLANE_VERSION} != ${CURRENT_VERSION} ]]; then
    echo -n "${INVOICEPLANE_VERSION}" > ${INVOICEPLANE_DATA_DIR}/VERSION
  fi
}
```

- [ ] **Step 4: Update `initialize_system()` to call migration in the right order**

Replace `initialize_system()` (lines 392-395):

```bash
initialize_system() {
  initialize_datadir
  install_configuration_templates
  migrate_ipconfig "${CURRENT_VERSION}"
  update_version
}
```

Order is:
1. `initialize_datadir` — creates dirs, reads old VERSION (but no longer writes it)
2. `install_configuration_templates` — sets up config files/symlinks
3. `migrate_ipconfig` — migrates old config if upgrading (uses old VERSION to gate)
4. `update_version` — writes new VERSION (so migration doesn't run again)

- [ ] **Step 5: Commit**

```bash
git add assets/runtime/functions
git commit -m "feat: add ipconfig.php migration for 1.5.9 -> 1.7.1 upgrade

Adds migrate_ipconfig() that detects old config files and:
- Backs up existing config
- Fixes first-line format for phpdotenv compatibility
- Appends missing parameters with safe defaults
- Prints upgrade notice with /setup reminder

Restructures initialize_system() to run migration between
VERSION read and VERSION write, preventing the race condition."
```

---

### Task 7: Update functions — new configure functions and proxy_ips

**Files:**
- Modify: `assets/runtime/functions` (around lines 186-217 and 397-404)

**Depends on:** Task 1 (proxy_ips format in v1.7.1 config.php)

- [ ] **Step 1: Add new configure functions**

Add these functions after `invoiceplane_configure_timezone()` (after line 217):

```bash
invoiceplane_configure_sess_match_ip() {
  echo "Configuring InvoicePlane::Session Match IP"
  invoiceplane_set_param "SESS_MATCH_IP" "${SESS_MATCH_IP}"
}

invoiceplane_configure_security_headers() {
  echo "Configuring InvoicePlane::Security Headers"
  invoiceplane_set_param "X_FRAME_OPTIONS" "${X_FRAME_OPTIONS}"
  invoiceplane_set_param "ENABLE_X_CONTENT_TYPE_OPTIONS" "${ENABLE_X_CONTENT_TYPE_OPTIONS}"
}

invoiceplane_configure_legacy_calculation() {
  echo "Configuring InvoicePlane::Legacy Calculation"
  invoiceplane_set_param "LEGACY_CALCULATION" "${LEGACY_CALCULATION}"
}
```

- [ ] **Step 2: Update `invoiceplane_configure_proxy_ips()` if needed**

**Check Task 1 findings** for the proxy_ips format in v1.7.1's `application/config/config.php`.

- If the format is still `$config['proxy_ips'] = '...';` — no change needed.
- If v1.7.1 uses `env()` helpers — adapt the sed pattern or switch to setting via ipconfig.php.

The current function (line 199-202):
```bash
invoiceplane_configure_proxy_ips() {
  echo "Configuring InvoicePlane::Proxy IPS"
  sed -i "s|^\$config\['proxy_ips'\][ ]*=.*;|\$config\['proxy_ips'\] = '"$INVOICEPLANE_PROXY_IPS"';|" ${INVOICEPLANE_CODEIGNITER_CONFIG}
}
```

Adjust based on findings.

- [ ] **Step 3: Update `configure_invoiceplane()`**

Replace `configure_invoiceplane()`:

```bash
configure_invoiceplane() {
  echo "Configuring InvoicePlane..."
  invoiceplane_configure_debugging
  invoiceplane_configure_url
  invoiceplane_configure_proxy_ips
  invoiceplane_configure_database
  invoiceplane_configure_timezone
  invoiceplane_configure_sess_match_ip
  invoiceplane_configure_security_headers
  invoiceplane_configure_legacy_calculation
}
```

- [ ] **Step 4: Commit**

```bash
git add assets/runtime/functions
git commit -m "feat: add configure functions for new 1.7.1 params

Wire SESS_MATCH_IP, X_FRAME_OPTIONS, ENABLE_X_CONTENT_TYPE_OPTIONS,
and LEGACY_CALCULATION from env vars to ipconfig.php.
Update proxy_ips handling for 1.7.1 config.php format."
```

---

### Task 8: Update entrypoint.sh

**Files:**
- Modify: `entrypoint.sh`

- [ ] **Step 1: Fix the help text**

Change line 35 from:
```
    echo " app:invoiceplane     - Starts the InvoicePlane php5-fpm server (default)"
```
To:
```
    echo " app:invoiceplane     - Starts the InvoicePlane php-fpm server (default)"
```

- [ ] **Step 2: Commit**

```bash
git add entrypoint.sh
git commit -m "fix: correct stale php5-fpm reference in help text"
```

---

### Task 9: Update docker-compose.yml, VERSION, Makefile

**Files:**
- Modify: `docker-compose.yml`
- Modify: `VERSION`
- Modify: `Makefile` (no changes expected, just verify)

- [ ] **Step 1: Rewrite docker-compose.yml**

```yaml
services:
  mysql:
    restart: always
    image: mysql:5.7
    environment:
    - MYSQL_ROOT_PASSWORD=password
    - MYSQL_USER=invoiceplane
    - MYSQL_PASSWORD=password
    - MYSQL_DATABASE=invoiceplane_db
    volumes:
    - /srv/docker/invoiceplane/mysql:/var/lib/mysql

  invoiceplane:
    restart: always
    image: sameersbn/invoiceplane:1.7.1-1
    command: app:invoiceplane
    environment:
    - DEBUG=false
    - TZ=Asia/Kolkata

    - DB_TYPE=mysqli
    - DB_HOST=mysql
    - DB_USER=invoiceplane
    - DB_PASS=password
    - DB_NAME=invoiceplane_db

    - INVOICEPLANE_URL=http://localhost:10080
    - INVOICEPLANE_PROXY_IPS=
    - INVOICEPLANE_BACKUPS_EXPIRY=0
    depends_on:
    - mysql
    volumes:
    - /srv/docker/invoiceplane/invoiceplane:/var/lib/invoiceplane

  nginx:
    restart: always
    image: sameersbn/invoiceplane:1.7.1-1
    command: app:nginx
    environment:
    - INVOICEPLANE_PHP_FPM_HOST=invoiceplane
    - INVOICEPLANE_PHP_FPM_PORT=9000
    depends_on:
    - invoiceplane
    ports:
    - "10080:80"
    volumes:
    - /srv/docker/invoiceplane/invoiceplane:/var/lib/invoiceplane
```

Key changes:
- Removed `version: '2'`
- `sameersbn/mysql:5.2.26` → `mysql:5.7`
- Image tag `1.5.9-3` → `1.7.1-1`
- `volumes_from: - invoiceplane` replaced with explicit volume mount matching the invoiceplane service
- MySQL env vars updated to official mysql image format (`MYSQL_ROOT_PASSWORD`, etc.)

- [ ] **Step 2: Update VERSION file**

Write `1.7.1-1` to `VERSION`:

```bash
echo -n "1.7.1-1" > VERSION
```

- [ ] **Step 3: Verify Makefile**

Read `Makefile` — it uses `$(shell cat VERSION)` for the release tag. This continues to work with `1.7.1-1`. No changes needed.

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml VERSION
git commit -m "feat: update docker-compose.yml and VERSION for 1.7.1-1

Modernize compose file: drop version key, replace volumes_from with
explicit mount, switch to official mysql:5.7 image, bump image tag."
```

---

### Task 10: Build and verify fresh install

**Files:**
- None modified — verification only

- [ ] **Step 1: Build the image**

```bash
cd /home/jeko/Documents/docker-invoiceplane
make build
```

Expected: image builds successfully, InvoicePlane 1.7.1 zip downloads and extracts, all PHP packages install.

If it fails: read the error, fix the relevant file (Dockerfile or install.sh), rebuild.

- [ ] **Step 2: Verify the image runs**

```bash
# Start fresh (clean volumes)
docker compose up -d

# Check containers are running
docker compose ps

# Check app container logs for errors
docker compose logs invoiceplane

# Check nginx container logs
docker compose logs nginx
```

Expected: all three containers running, no PHP errors in logs, InvoicePlane setup wizard accessible at `http://localhost:10080`.

- [ ] **Step 3: Walk through setup wizard**

Open `http://localhost:10080/index.php/setup` in a browser. Complete the setup wizard:
1. Database connection test passes
2. Database tables created
3. Admin user created
4. Login works

- [ ] **Step 4: Verify PDF generation (exercises PHP extensions)**

Create a test invoice and download as PDF. This exercises `php-gd`, `php-mbstring`, `php-bcmath`, and mpdf.

- [ ] **Step 5: Clean up**

```bash
docker compose down -v
```

- [ ] **Step 6: Commit (if any fixes were needed)**

```bash
# Only if files were changed during verification — review git status first
git status
# Stage only the specific files that were fixed
git add <fixed-files>
git commit -m "fix: address issues found during build verification"
```

---

### Task 11: Test upgrade path from 1.5.9

**Files:**
- None modified — verification only

This is the critical compatibility test. Uses a dedicated Docker network (not deprecated `--link`) and a temporary compose file for a clean test environment.

- [ ] **Step 1: Create test environment with old 1.5.9-3 image**

Create a temporary compose file for the upgrade test:

```bash
mkdir -p /tmp/upgrade-test && cat > /tmp/upgrade-test/docker-compose.yml << 'COMPOSE'
services:
  mysql:
    image: mysql:5.7
    environment:
    - MYSQL_ROOT_PASSWORD=password
    - MYSQL_USER=invoiceplane
    - MYSQL_PASSWORD=password
    - MYSQL_DATABASE=invoiceplane_db
    volumes:
    - ./db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 3s
      retries: 10

  invoiceplane:
    image: sameersbn/invoiceplane:1.5.9-3
    command: app:invoiceplane
    environment:
    - DB_HOST=mysql
    - DB_USER=invoiceplane
    - DB_PASS=password
    - DB_NAME=invoiceplane_db
    - INVOICEPLANE_URL=http://localhost:10080
    depends_on:
      mysql:
        condition: service_healthy
    volumes:
    - ./ip-data:/var/lib/invoiceplane

  nginx:
    image: sameersbn/invoiceplane:1.5.9-3
    command: app:nginx
    environment:
    - INVOICEPLANE_PHP_FPM_HOST=invoiceplane
    - INVOICEPLANE_PHP_FPM_PORT=9000
    depends_on:
    - invoiceplane
    ports:
    - "10080:80"
    volumes:
    - ./ip-data:/var/lib/invoiceplane
COMPOSE

cd /tmp/upgrade-test && docker compose up -d
```

- [ ] **Step 2: Complete initial setup on 1.5.9**

Open `http://localhost:10080/index.php/setup` in a browser. Complete the setup wizard:
1. Database connection test passes
2. Database tables created
3. Admin user created

Log in and create at least one test invoice with line items to have real data.

- [ ] **Step 3: Stop old containers (keep volumes)**

```bash
cd /tmp/upgrade-test && docker compose down
```

This stops and removes containers but preserves `./ip-data` and `./db-data` volumes.

- [ ] **Step 4: Swap to the new image and restart**

```bash
cd /tmp/upgrade-test

# Update compose file to use new image
sed -i 's|sameersbn/invoiceplane:1.5.9-3|sameersbn/invoiceplane:latest|g' docker-compose.yml

# Start with new image
docker compose up -d
```

- [ ] **Step 5: Verify migration ran**

```bash
cd /tmp/upgrade-test

# Check logs for migration messages
docker compose logs invoiceplane 2>&1 | grep -A 10 "Migrating ipconfig"

# Verify backup was created
ls -la ./ip-data/configs/ipconfig.php.pre-*

# Verify new params were added
grep "SESS_MATCH_IP" ./ip-data/configs/ipconfig.php
grep "X_FRAME_OPTIONS" ./ip-data/configs/ipconfig.php
grep "LEGACY_CALCULATION" ./ip-data/configs/ipconfig.php

# Verify VERSION was updated
cat ./ip-data/VERSION
```

Expected:
- Migration log messages present
- Backup file `ipconfig.php.pre-1.7.1-backup` exists
- New parameters present in ipconfig.php
- VERSION shows `1.7.1`

- [ ] **Step 6: Run database migration**

Visit `http://localhost:10080/index.php/setup` — the upgrade wizard should detect the old database schema and run migrations 033 through 041.

- [ ] **Step 7: Verify existing data is intact**

- Log in with the old admin credentials
- Previously created invoices are visible and correct
- Can create new invoices
- PDF generation works

- [ ] **Step 8: Clean up**

```bash
cd /tmp/upgrade-test && docker compose down -v
rm -rf /tmp/upgrade-test
```

- [ ] **Step 9: Final commit if any fixes were needed**

```bash
# Only if files were changed during upgrade testing — review git status first
git status
git add <fixed-files>
git commit -m "fix: address issues found during upgrade path testing"
```
