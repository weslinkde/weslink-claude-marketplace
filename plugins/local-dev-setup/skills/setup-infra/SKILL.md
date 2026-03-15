---
name: setup-infra
description: "Set up the Weslink local development environment with shared Docker infrastructure (Traefik, PostgreSQL, Redis, MeiliSearch, Mailpit, MinIO), native PHP-FPM + nginx, HTTPS via mkcert, and shell aliases. Use when a developer says 'setup local dev', 'einrichten', 'install infra', or 'ich will das lokale Setup'."
---

# Weslink Local Development Setup

This skill guides the installation of the complete local development stack. Read project config from `references/projects.json` using the Read tool.

## Architecture

```
Browser (HTTPS)
  -> Traefik (Docker, SSL) :443
    -> nginx (local) :8000-8003 -> PHP-FPM (local) :9090
    -> soketi (Docker) :6001       # WebSockets (kibi only)
    -> livekit (Docker) :7880      # Video Calls (kibi only)

Shared Docker Services:
  PostgreSQL :5432 | Redis :6379 | MeiliSearch :7700
  Mailpit :8025    | MinIO :9000 | Traefik Dashboard :8080
```

## Prerequisites

- macOS with Homebrew
- Docker (OrbStack recommended, or Docker Desktop)
- Git
- The project repositories already cloned

## Installation Steps

Execute each step, verify it works, then move on. Ask the user before running destructive commands.

### Step 1: Homebrew Packages

```bash
brew install php@8.4 nginx dnsmasq mkcert
```

### Step 2: Remove Valet (if installed)

Check first:
```bash
which valet 2>/dev/null && echo "Valet installed" || echo "No Valet"
```

If installed:
```bash
valet stop 2>/dev/null
composer global remove laravel/valet
sudo brew services stop nginx
sudo brew services stop php
sudo killall php-fpm 2>/dev/null
sudo killall nginx 2>/dev/null
```

### Step 3: mkcert Root CA

```bash
mkcert -install
```

Only needed once. Installs the local CA in the system keychain.

### Step 4: dnsmasq

```bash
mkdir -p $(brew --prefix)/etc/dnsmasq.d
echo "address=/.test/127.0.0.1" > $(brew --prefix)/etc/dnsmasq.d/test.conf
echo "listen-address=127.0.0.1" >> $(brew --prefix)/etc/dnsmasq.d/test.conf
grep -q "conf-dir=$(brew --prefix)/etc/dnsmasq.d" $(brew --prefix)/etc/dnsmasq.conf 2>/dev/null || \
    echo "conf-dir=$(brew --prefix)/etc/dnsmasq.d/,*.conf" >> $(brew --prefix)/etc/dnsmasq.conf
sudo mkdir -p /etc/resolver
echo "nameserver 127.0.0.1" | sudo tee /etc/resolver/test
sudo brew services start dnsmasq
```

Verify: `dig demo.kibi.test @127.0.0.1 +short` should return `127.0.0.1`.

### Step 5: Infrastructure Directory

Create `~/dev/infrastructure/` with the following structure. Read `references/projects.json` for project details and ports.

**Files to create:**

1. `~/dev/infrastructure/docker-compose.yml` - All shared services (Traefik, PostgreSQL, Redis, MeiliSearch, Mailpit, MinIO) on the `shared-infra` Docker network
2. `~/dev/infrastructure/pgsql/init/01-create-databases.sh` - Creates all project databases on first PostgreSQL start
3. `~/dev/infrastructure/traefik/dynamic/tls.yml` - Points to the wildcard TLS certificate
4. `~/dev/infrastructure/traefik/dynamic/<project>.yml` - One routing config per project (routes domain to `host.docker.internal:<port>`)
5. `~/dev/infrastructure/bin/infra` - CLI helper script (up, down, status, logs, cert-regen)
6. `~/dev/infrastructure/bin/wt` - Global worktree management script

**Key details:**
- PostgreSQL uses `pgvector/pgvector:pg16` (superset, works for all projects)
- All services are on the `shared-infra` Docker network
- Traefik uses file-based provider for routing (watches `traefik/dynamic/` directory)
- Traefik redirects HTTP to HTTPS automatically
- The `infra` and `wt` scripts must be `chmod +x`

**Generate TLS certificates:**
```bash
cd ~/dev/infrastructure/certs
mkcert -cert-file _wildcard.test.pem -key-file _wildcard.test-key.pem \
    "*.test" \
    "kibi.test" "*.kibi.test" \
    "bessler.test" "*.bessler.test" \
    "scada.test" "*.scada.test" \
    "contradoo.test" "*.contradoo.test"
```

**Traefik routing for projects with tenant subdomains (kibi):**
- Soketi WebSocket: `HostRegexp + PathPrefix(/app)` -> soketi:6001 (priority 100)
- MinIO proxy: `HostRegexp + PathPrefix(/storage/minio)` -> minio:9000 with path rewrite (priority 100)
- LiveKit: `Host(livekit.kibi.test)` -> livekit:7880
- Catch-all: `HostRegexp(^(.+\.)?kibi\.test$)` -> host.docker.internal:8000 (priority 10)

**Traefik routing for simple projects (bessler, scada, contradoo):**
- Single router: `Host(project.test)` -> host.docker.internal:<port>

### Step 6: PHP-FPM Configuration

**6.1 Disable Xdebug** (3-5x performance impact):
```bash
mv /opt/homebrew/etc/php/8.4/conf.d/20-xdebug.ini \
   /opt/homebrew/etc/php/8.4/conf.d/20-xdebug.ini.disabled 2>/dev/null
```

**6.2 Disable Valet FPM pool** (if exists):
```bash
mv /opt/homebrew/etc/php/8.4/php-fpm.d/valet-fpm.conf \
   /opt/homebrew/etc/php/8.4/php-fpm.d/valet-fpm.conf.disabled 2>/dev/null
```

**6.3 Install Redis extension:**
```bash
/opt/homebrew/opt/php@8.4/bin/pecl install redis
```

**6.4 Create performance config** at `/opt/homebrew/etc/php/8.4/conf.d/99-performance.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.revalidate_freq=0
opcache.validate_timestamps=1
opcache.enable_file_override=1
opcache.jit=1255
opcache.jit_buffer_size=128M
realpath_cache_size=4096K
realpath_cache_ttl=600
memory_limit=512M
```

**6.5 Change FPM listen port** (9000 is used by MinIO):
```bash
sed -i '' 's/listen = 127.0.0.1:9000/listen = 127.0.0.1:9090/' \
    /opt/homebrew/etc/php/8.4/php-fpm.d/www.conf
```

**6.6 Fix FPM user:**
```bash
sed -i '' "s/^user = _www/user = $(whoami)/" /opt/homebrew/etc/php/8.4/php-fpm.d/www.conf
sed -i '' "s/^group = _www/group = staff/" /opt/homebrew/etc/php/8.4/php-fpm.d/www.conf
```

**6.7 Start PHP-FPM:**
```bash
/opt/homebrew/opt/php@8.4/sbin/php-fpm \
    --daemonize \
    --fpm-config /opt/homebrew/etc/php/8.4/php-fpm.conf
```

Verify: `lsof -i :9090 -P | head -3` should show php-fpm.

### Step 7: nginx Configuration

**7.1 Replace nginx.conf** at `/opt/homebrew/etc/nginx/nginx.conf`:
- Remove all Valet includes
- Set `error_log /tmp/nginx-error.log` and `pid /tmp/nginx.pid` (avoids permission issues)
- Set `access_log /tmp/nginx-access.log` in the http block
- Remove/comment the `user` directive
- Keep `include servers/*`

**7.2 Create server configs** in `/opt/homebrew/etc/nginx/servers/`:
One config per project. Each listens on its port, serves static files directly, proxies PHP to `127.0.0.1:9090` via FastCGI.

Important FastCGI params to include:
```nginx
fastcgi_param HTTPS "on";
fastcgi_param HTTP_X_FORWARDED_PROTO "https";
```

**7.3 Start nginx:**
```bash
nginx -t && brew services start nginx
```

### Step 8: Project .env Files

Update each project's `.env`:
```
APP_URL=https://<domain>
DB_HOST=127.0.0.1
DB_DATABASE=<project_db_name>
DB_USERNAME=sail
DB_PASSWORD=password
REDIS_HOST=127.0.0.1
MAIL_HOST=127.0.0.1
MEILISEARCH_HOST=http://127.0.0.1:7700
```

### Step 9: Kibi docker-compose.override.yml

Kibi needs Soketi and LiveKit in Docker. Create `docker-compose.override.yml` (gitignored) that:
- Replaces all shared services with `busybox:latest` no-ops (entrypoint: ["true"], ports: !override [], volumes: !override [])
- Keeps soketi and livekit but connects them to `shared-infra` network
- Points soketi's Redis to `infra-redis`

### Step 10: Start and Verify

```bash
# Start infrastructure
~/dev/infrastructure/bin/infra up

# Start kibi Docker services
cd ~/dev/projects/kibi-connect/kibi && ./vendor/bin/sail up -d

# Migrate (first time only)
php artisan migrate --seed
php artisan kibi:create-demo-tenant

# Build caches for performance
php -d memory_limit=512M artisan optimize

# Test
curl -sk -o /dev/null -w "%{http_code} %{time_total}s\n" https://demo.kibi.test/login
# Expected: 200 ~0.09s
```

### Step 11: Shell Aliases (Optional)

Add to `.zshrc` or `.aliases`:
- `export PATH="$HOME/dev/infrastructure/bin:$PATH"` for `infra` and `wt` commands
- Project navigation aliases (`kibi`, `bessler`, `scada`, `ctr`)
- `dev` / `fast` / `fresh` functions for toggling OPcache and Laravel caches
- `kibi:up` / `kibi:down` for complete start/stop including Horizon and Scheduler
- `wt:create`, `wt:remove`, `wt:list`, `wt:run` aliases for worktree management

## Verification Checklist

After setup, all of these should work:
- [ ] `dig demo.kibi.test @127.0.0.1 +short` returns `127.0.0.1`
- [ ] `curl -sk https://demo.kibi.test/login` returns HTTP 200
- [ ] `curl -sk https://kibi.test/admin` returns HTTP 200 or 302
- [ ] `curl -sk https://bessler.test/` returns HTTP 200 or 302
- [ ] `curl -sk https://contradoo.test/` returns HTTP 200 or 302
- [ ] Traefik dashboard at `http://localhost:8080` shows all routers
- [ ] Mailpit at `http://localhost:8025` is accessible
- [ ] `psql -h 127.0.0.1 -U sail -d kibi -c "SELECT 1"` works
