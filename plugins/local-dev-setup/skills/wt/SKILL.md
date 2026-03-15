---
name: wt
description: "Manage git worktrees with automatic HTTPS domain routing for Weslink Laravel projects. Use when a developer says 'worktree erstellen', 'neuen worktree', 'wt create', 'feature branch mit eigener domain', or asks about parallel development on multiple branches."
---

# Worktree Management

Worktrees allow parallel development on multiple branches, each with its own HTTPS domain. The `wt` CLI tool in `~/dev/infrastructure/bin/wt` handles everything automatically.

There are two creation modes: **fast** (default) and **full**.

## How It Works

### Fast Mode (default)

```
wt create calendar-fix
  1. git worktree add .worktrees/calendar-fix -b feature/calendar-fix
  2. Symlinks vendor/, node_modules/, .env to main project
  3. Generates nginx server config (new port)
  4. Generates Traefik routing config
  5. Regenerates TLS certificate with new SAN
  6. Writes .wt-meta (mode=fast, db=shared)
```

Result: `https://demo.kibi--calendar-fix.test` is immediately accessible. Shares database with main project. No install step needed.

Best for: frontend fixes, small bugfixes, CSS changes.

### Full Mode

```
wt create:full new-migration
  1. git worktree add .worktrees/new-migration -b feature/new-migration
  2. Runs composer install + npm install (own copies)
  3. Copies .env, adjusts APP_URL and DB_DATABASE
  4. Creates separate PostgreSQL database (pattern: {project}_wt_{name_underscored})
  5. Runs php artisan migrate --seed
  6. Generates nginx server config (new port)
  7. Generates Traefik routing config
  8. Regenerates TLS certificate with new SAN
  9. Writes .wt-meta (mode=full, db={project}_wt_{name_underscored})
```

Result: `https://demo.kibi--new-migration.test` with its own database, vendor/, and node_modules/.

Best for: new packages, migrations, breaking changes.

## Metadata

Each worktree contains a `.wt-meta` file that stores its configuration:

```
mode=fast|full
db=<database-name-or-shared>
```

This file is used by `wt remove` to determine cleanup steps and by `wt list` to display mode info.

## Domain Pattern

| Project Type | Main Domain | Worktree Domain |
|-------------|-------------|-----------------|
| With tenant subdomains (kibi) | `*.kibi.test` | `*.kibi--<name>.test` |
| Simple (bessler, contradoo, scada) | `project.test` | `project--<name>.test` |

The `--` separator is used because it cannot be a valid tenant name.

## Commands

All commands auto-detect the project based on the current working directory.

```bash
cd ~/dev/projects/kibi-connect/kibi

# Create worktree (fast mode - symlinks vendor/node_modules/.env, shares DB)
wt create <name> [branch] [base]

# Create worktree (full mode - own vendor/node_modules/.env, separate DB)
wt create:full <name> [branch] [base]

# Default branch: feature/<name>
# Default base: origin/develop

# List worktrees (shows mode, vendor status, database)
wt list

# Run command in worktree
wt run <name> artisan migrate
wt run <name> artisan test --filter=MyTest

# Remove worktree (cleans up routing + cert, drops DB for full mode)
wt remove <name>
```

## What the Script Does

### On `wt create` (fast mode):
1. `git fetch origin`
2. `git worktree add .worktrees/<name> -b feature/<name> origin/develop`
3. Symlinks `vendor/` and `node_modules/` to main project (relative symlinks)
4. Symlinks `.env` to main project
5. Generates deterministic port from hash of `project-name` (range 8100-8999)
6. Creates nginx config at `/opt/homebrew/etc/nginx/servers/<project>-wt-<name>.conf`
7. Creates Traefik config at `~/dev/infrastructure/traefik/dynamic/<project>-wt-<name>.yml`
8. Regenerates mkcert certificate with new SAN for the worktree domain
9. Writes `.wt-meta` with `mode=fast`
10. Reloads nginx

### On `wt create:full` (full mode):
1. `git fetch origin`
2. `git worktree add .worktrees/<name> -b feature/<name> origin/develop`
3. Runs `composer install` (own vendor/)
4. Runs `npm install` (own node_modules/)
5. Copies `.env` from main project
6. Adjusts `APP_URL` and `DB_DATABASE` in the copied `.env`
7. Creates a separate PostgreSQL database (`{project}_wt_{name_underscored}`)
8. Runs `php artisan migrate --seed`
9. Generates deterministic port from hash of `project-name` (range 8100-8999)
10. Creates nginx config at `/opt/homebrew/etc/nginx/servers/<project>-wt-<name>.conf`
11. Creates Traefik config at `~/dev/infrastructure/traefik/dynamic/<project>-wt-<name>.yml`
12. Regenerates mkcert certificate with new SAN for the worktree domain
13. Writes `.wt-meta` with `mode=full` and `db={project}_wt_{name_underscored}`
14. Reloads nginx

### On `wt remove`:
1. Reads `.wt-meta` to determine mode
2. Removes nginx config
3. Removes Traefik config
4. Regenerates certificate without the removed SAN
5. For full mode: asks to drop the worktree database
6. `git worktree remove --force`
7. Optionally deletes the branch

### On `wt list`:
Shows all worktrees with mode (fast/full), vendor status (symlinked/independent), and database (shared/own name).

## Known Projects

Read `../setup-infra/references/projects.json` for the full project list with paths, ports, and domain patterns.

## Important Notes

- **Fast mode**: Worktrees share database, vendor/, node_modules/, and .env with the main project (via symlinks)
- **Full mode**: Worktrees have their own database, vendor/, node_modules/, and .env (independent copies)
- Each worktree gets its own nginx server block and Traefik route (both modes)
- PHP-FPM is shared across all worktrees (different document roots, same process pool)
- No extra containers or processes needed per worktree
- The `.worktrees/` directory is gitignored
- The `.wt-meta` file in each worktree tracks its mode and database name

## Troubleshooting

**"Not in a known project directory"**: `cd` into one of the known project directories first.

**502 Bad Gateway on worktree domain**: nginx config may not have reloaded. Run `nginx -s reload`.

**SSL error on worktree domain**: Certificate needs to be regenerated. Run `infra cert-regen "*.kibi--<name>.test"` or re-create the worktree.

**Port conflict**: The port is deterministic based on the project+name hash. If there's a collision, remove and recreate with a different name.
