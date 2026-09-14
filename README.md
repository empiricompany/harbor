# Harbor local development

> **Beta:** Harbor is for local development only. It is actively developed and must not be used for production deployments. Commands, generated files, and defaults may change without notice.

Harbor is the Docker Compose launcher and local development stack for Maho projects. Run all commands from the Maho project root. Harbor requires Docker and Docker Compose.

Harbor ships with all the required libraries to use:

- **Accessibility Scan** (Playwright + Chromium): before the first scan, install the scanner runtime with `./vendor/bin/harbor maho accessibility:install`.
- **VIPS image processing**: enable the high-performance driver in a project with `composer require intervention/image-driver-vips`.

## Table of contents

- [Install Harbor](#install-harbor)
- [Install Maho](#install-maho)
  - [Install from scratch](#install-from-scratch)
  - [Import an existing database](#import-an-existing-database)
- [Quick start and base stack](#quick-start-and-base-stack)
- [Main commands](#main-commands)
  - [Lifecycle](#lifecycle)
  - [Application tools](#application-tools)
  - [Shell and service tools](#shell-and-service-tools)
- [Optional services](#optional-services)
  - [How profiles work](#how-profiles-work)
  - [Redis (optional profile: `redis`)](#redis-optional-profile-redis)
  - [Mailpit (base service)](#mailpit-base-service)
  - [Adminer (optional profile: `adminer`)](#adminer-optional-profile-adminer)
  - [phpMyAdmin (optional profile: `phpmyadmin`)](#phpmyadmin-optional-profile-phpmyadmin)
  - [Extending the stack: profiles vs. default services](#extending-the-stack-profiles-vs-default-services)
- [Additional cron jobs](#additional-cron-jobs)
- [Xdebug](#xdebug)
- [Dev Containers and VS Code](#dev-containers-and-vs-code)
- [MCP server](#mcp-server)
- [Customizing the stack](#customizing-the-stack)
- [Customization and generated files](#customization-and-generated-files)
- [Troubleshooting](#troubleshooting)
- [For developers](#for-developers)
- [Package tests](#package-tests)
- [License](#license)

## Install Harbor

Add Harbor to your Maho project as a Composer development dependency:

```bash
composer require --dev empiricompany/harbor
```

> **Tip:** Harbor is under active development and changes frequently. To always use the latest changes, install the `dev-main` branch instead of a tagged release with `composer require --dev empiricompany/harbor:dev-main`.

Generate Harbor's project files in [`.harbor/`](../../.harbor/):

```bash
./vendor/bin/harbor init
```

`init` creates these files:

| File | Purpose |
|---|---|
| `.harbor/.env` | Environment variables for the stack |
| `.harbor/.env.example` | Template of the available environment variables |
| `.harbor/compose.yaml` | The Docker Compose stack |
| `.harbor/docker.override.yaml` | Project and local Compose overrides (starts empty) |
| `.harbor/docker.install.yaml` | Install-specific additions (starts empty) |
| `.harbor/.gitignore` | Ignore rules for the generated files |

Start the stack and check it:

```bash
./vendor/bin/harbor up -d
./vendor/bin/harbor ps
```

Install the project dependencies inside the application container, now that the stack is ready:

```bash
./vendor/bin/harbor composer install
```

Use `./vendor/bin/harbor up -d --build` when the application image must be built. `init` creates `.harbor/` files only when they are absent; `init --force` replaces generated environment and Compose files and can overwrite local settings.

### Shell alias

Composer exposes the Harbor CLI at [`vendor/bin/harbor`](../../vendor/bin/harbor). To invoke it as `harbor` without the `./vendor/bin/` prefix, add a shell alias to `~/.zshrc` or `~/.bashrc` and restart your shell:

```bash
alias harbor='./vendor/bin/harbor'
```

## Install Maho

Depending on the state of the project, choose one of the two options below.

### Install from scratch

If the Maho project is not installed yet, run the installer with `db` as the database host and the credentials from [`.harbor/.env`](../../.harbor/.env):

```bash
./vendor/bin/harbor maho install \
  --license_agreement_accepted yes \
  --locale en_US --timezone UTC --default_currency USD \
  --db_host db --db_name maho --db_user maho --db_pass maho \
  --url "https://localhost:8443/" \
  --use_secure 1 --secure_base_url "https://localhost:8443/" --use_secure_admin 1 \
  --admin_firstname Store --admin_lastname Admin --admin_email admin@example.com \
  --admin_username admin --admin_password veryl0ngpassw0rd \
  --sample_data 1
```

Then reindex and flush the cache:

```bash
./vendor/bin/harbor maho index:reindex:all && ./vendor/bin/harbor maho cache:flush
```

Open the admin panel:

```bash
./vendor/bin/harbor open admin
```

### Import an existing database

If you already have a database dump, Harbor provides two additional commands to move databases in and out of the container:

```bash
# Import a dump into the database
./vendor/bin/harbor maho db:import <file> [--compression=gzip|zstd|none] [--drop-tables]

# Export the current database to a dump file
./vendor/bin/harbor maho db:export <file> [--compression=gzip|zstd|none]
```

Compression is not auto-detected: a gzip or zstd dump (even with a plain `.sql` name) needs `--compression=gzip` or `--compression=zstd`. Use `--drop-tables` to drop and recreate the database before importing, for a clean restore. When `pv` is available in the container, both commands show a progress bar; `zstd` is required only when `--compression=zstd` is requested.

Harbor does not create [`app/etc/local.xml`](../../app/etc/local.xml) or replace the Maho installer. Database defaults exposed to the containers are `db:3306`, database `maho`, user `maho`, and password `maho`; make sure the Maho installation uses the values appropriate for the project.

## Quick start and base stack

The default stack contains:

| Container | Description | Endpoint / notes |
|---|---|---|
| `app` | FrankenPHP (Caddy) + PHP + Node, serving the Maho application | `https://localhost:8443/` (via `HARBOR_APP_URL`) |
| `db` | MySQL 8.4 | `db:3306`, database/user/password `maho` |
| `mailpit` | Mailpit, local SMTP capture and web inbox | Web UI `http://localhost:8025`; SMTP `mailpit:1025` inside the Compose network |
| `cron` | Ofelia, the scheduled-task runner | Watches Docker labels |

The application URL is controlled by `HARBOR_APP_URL` and defaults to `https://localhost:8443/`. The base Mailpit UI defaults to `http://localhost:8025`; applications send SMTP to `mailpit:1025` inside the Compose network.

Project files are generated in [`.harbor/`](../../.harbor/). Do not edit [`.harbor/compose.yaml`](../../.harbor/compose.yaml) directly; see [Customizing the stack](#customizing-the-stack) to override it.

## Main commands

### Lifecycle

```bash
./vendor/bin/harbor init [--force]
./vendor/bin/harbor up -d [service...]
./vendor/bin/harbor down
./vendor/bin/harbor stop [service...]
./vendor/bin/harbor restart [service...]
./vendor/bin/harbor ps [service...]
./vendor/bin/harbor logs [service]
./vendor/bin/harbor logs -f app
./vendor/bin/harbor build [service...]
./vendor/bin/harbor config
./vendor/bin/harbor doctor
```

`down` removes containers and networks and includes disabled profile services as orphans.
`down -v` also removes persistent volumes, including the database volume.

### Application tools

```bash
./vendor/bin/harbor php --version
./vendor/bin/harbor composer install
./vendor/bin/harbor maho cache:flush
./vendor/bin/harbor bin phpunit
./vendor/bin/harbor test
./vendor/bin/harbor phpunit
./vendor/bin/harbor phpstan
```

`php`, `composer`, `maho`, and `bin` execute in `app`. Unknown commands are delegated to Docker Compose.

### Shell and service tools

```bash
./vendor/bin/harbor shell
./vendor/bin/harbor shell --service db
./vendor/bin/harbor root-shell
./vendor/bin/harbor exec --service app php -v
./vendor/bin/harbor exec --user root --service app bash
./vendor/bin/harbor mysql --execute='SELECT 1'
./vendor/bin/harbor redis --raw ping
./vendor/bin/harbor open
./vendor/bin/harbor open admin
./vendor/bin/harbor open mailpit
```

## Optional services

### How profiles work

Base services and optional profile services are separate.
The built-in profiles are `redis`, `adminer`, and `phpmyadmin`.
Harbor also reads any custom service you declare with a `profiles:` key in [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml) or [`.harbor/docker.install.yaml`](../../.harbor/docker.install.yaml) (inline `profiles: [newprofilename]`) and manages it exactly like the built-in ones.
Profiles are stored as a comma-separated `HARBOR_PROFILES` value in [`.harbor/.env`](../../.harbor/.env).

```bash
./vendor/bin/harbor services list
./vendor/bin/harbor services add redis
./vendor/bin/harbor services remove redis
./vendor/bin/harbor up -d
./vendor/bin/harbor ps
```

`services add` and `services remove` update `.harbor/.env`; they do not start or stop containers.
Run `up -d` to apply a profile change and `ps` to verify it.

### Redis (optional profile: `redis`)

| Property | Default |
|---|---|
| Image | `redis:7.4` |
| Endpoint | `redis:6379` (published on host port `6379`) |
| Environment variables | `HARBOR_REDIS_HOST=redis`, `HARBOR_REDIS_PORT=6379`, `HARBOR_REDIS_SESSION_DB=1`, `HARBOR_REDIS_CACHE_DB=2` |

The service uses a temporary `/data` storage with `volatile-lfu` eviction. Connect from containers with `redis:6379`, from the host with `redis-cli -h 127.0.0.1 -p 6379`, or from the CLI with `./vendor/bin/harbor redis ...`.

Configuration example:

```xml
<session_save>redis</session_save>
<redis_session>
  <dsn>redis://redis:6379/1</dsn>
  <key_prefix>maho_session:</key_prefix>
</redis_session>
<cache>
  <lifetime>86400</lifetime>
  <backend>redis</backend>
  <backend_options>
    <dsn>redis://redis:6379/2</dsn>
  </backend_options>
</cache>
```

### Mailpit (base service)

- Web UI: `http://localhost:${HARBOR_MAILPIT_PORT:-8025}`; default `http://localhost:8025`.
- SMTP from the host: `localhost:${HARBOR_MAILPIT_SMTP_PORT:-1025}`; default `localhost:1025`.
- SMTP from containers: `mailpit:1025`.
- `HARBOR_MAILPIT_PORT` and `HARBOR_MAILPIT_SMTP_PORT` can change the published host ports. Mailpit is always part of the base stack.

### Adminer (optional profile: `adminer`)

| Property | Default |
|---|---|
| Image | `adminer:latest` |
| Endpoint | `http://localhost:8082` |
| Environment variables | `HARBOR_ADMINER_PORT=8082` (host port), `ADMINER_DEFAULT_SERVER=db` |

The login server field is pre-filled with `db`; enter user, password, and database manually, using the credentials from [`.harbor/.env`](../../.harbor/.env).

### phpMyAdmin (optional profile: `phpmyadmin`)

| Property | Default |
|---|---|
| Image | `phpmyadmin:latest` |
| Endpoint | `http://localhost:8081` |
| Environment variables | `HARBOR_PHPMYADMIN_PORT=8081` (host port), `PMA_HOST=db`, `PMA_PORT=3306`, `PMA_USER=maho`, `PMA_PASSWORD=maho` |

Connects to the `db` service at `db:3306` with the credentials from [`.harbor/.env`](../../.harbor/.env).

### Extending the stack: profiles vs. default services

When you extend and customize your local Harbor installation in [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml) or [`.harbor/docker.install.yaml`](../../.harbor/docker.install.yaml), the `profiles` key controls how a service is started:

- **Additional (opt-in) service**: declare `profiles: [yourprofile]`. The service starts only when the profile is enabled, with `./vendor/bin/harbor services add yourprofile` (or by passing `--profile yourprofile` to `up`). It is discovered automatically and managed like the built-in profiles.
- **Part of the default stack**: omit the `profiles` key entirely. The service starts on every `./vendor/bin/harbor up`, without needing `--profile`.

```yaml
services:
  phpredisadmin:
    image: erikdubbelboer/phpredisadmin:latest
    profiles: [phpredisadmin]   # remove this line to always start it
    environment:
      REDIS_1_HOST: redis
    ports:
      - "8084:80"
```

## Additional cron jobs

The base `cron` service runs Ofelia. It watches Docker labels for the current Compose project through the read-only Docker socket. The `app` stub defines these Maho jobs:

- `ofelia.job-exec.maho-cron-always`: every minute, runs `./maho cron:run always`;
- `ofelia.job-exec.maho-cron-default`: every five minutes, runs `./maho cron:run default`.

Both jobs run as user `maho` and set `no-overlap: "true"`. Start the service explicitly with:

```bash
./vendor/bin/harbor cron
```

Custom jobs are supported through Compose labels in [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml). Add labels to `app` (or another service) using Ofelia's `ofelia.job-exec.<job-name>.*` format, for example:

```yaml
services:
  app:
    labels:
      ofelia.job-exec.custom.schedule: '@every 10m'
      ofelia.job-exec.custom.command: "sh -c 'cd /app && ./maho your:command'"
      ofelia.job-exec.custom.user: maho
      ofelia.job-exec.custom.no-overlap: "true"
```

Recreate the service after changing labels with `./vendor/bin/harbor up -d` and inspect it with `./vendor/bin/harbor logs cron`.

## Xdebug

Xdebug is included in the application image: the package Dockerfile installs it together with the core PHP extensions.

Enable it by setting `HARBOR_XDEBUG_MODE` in [`.harbor/.env`](../../.harbor/.env):

```ini
HARBOR_XDEBUG_MODE=debug
```

`HARBOR_XDEBUG_MODE` accepts `off` (default), `develop`, `debug`, or `debug,develop,coverage`. Xdebug connects back to the IDE on port `9003` using `host.docker.internal`; override the host with `HARBOR_XDEBUG_CLIENT_HOST` if needed (see below).

Verify Xdebug from the running `app` container:

```bash
./vendor/bin/harbor php -m | grep -i '^xdebug$'
./vendor/bin/harbor php -i | grep -E 'xdebug.mode|xdebug.client'
```

Use the Harbor debug wrapper to run a PHP command or script with Xdebug enabled:

```bash
./vendor/bin/harbor debug script.php
./vendor/bin/harbor debug -r 'var_dump(extension_loaded("xdebug"));'
```

### IDE setup

The container mounts the project at `/app`, so map `/app` to the project root in your IDE.

**VS Code:** install the "PHP Debug" extension (`xdebug.php-debug`) and add this launch configuration (already provided in [`.vscode/launch.json`](../../.vscode/launch.json)):

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": { "/app": "${workspaceFolder}" }
    }
  ]
}
```

Start "Listen for Xdebug" (F5), set a breakpoint, then trigger a request in the browser or run `./vendor/bin/harbor debug <script>`.

**PhpStorm:** enable "Listening for PHP Debug Connections" (port `9003`), and under `Settings > PHP > Servers` map the `/app` directory to the project root.

### Linux and Docker Desktop

`host.docker.internal` normally resolves to the host. On Docker Desktop for Linux it can resolve to an unreachable IPv6 address, and Xdebug logs `Network is unreachable`. In that case set the IPv4 literal in [`.harbor/.env`](../../.harbor/.env):

```ini
HARBOR_XDEBUG_CLIENT_HOST=192.168.65.254
```

> **💡 Want nicer PHP error pages?** Check out [empiricompany/maho-ignition](https://github.com/empiricompany/maho-ignition), which adds Spatie Ignition error pages, optional AI-generated solutions, and Flare reporting (shown in developer mode).

## Dev Containers and VS Code

Generate the Dev Container file with:

```bash
./vendor/bin/harbor devcontainer --force
```

This writes [`.devcontainer/devcontainer.json`](../../.devcontainer/devcontainer.json).
The generated configuration points to [`../.harbor/compose.yaml`](../../.harbor/compose.yaml), opens the `app` service, and uses `/app` as the workspace folder.

Open the project in VS Code with the Microsoft Dev Containers extension. When VS Code detects the [`.devcontainer/devcontainer.json`](../../.devcontainer/devcontainer.json) file, it prompts you to reopen the project in the configured container.

Harbor does not install VS Code extensions or Dev Containers tooling.

### VS Code PHP integration

For the Maho VS Code extension, configure PHP to run through Harbor from the repository root.

Add this setting to [`.vscode/settings.json`](../../.vscode/settings.json):

```json
{
  "maho.phpCommand": "./vendor/bin/harbor php"
}
```

## MCP server

Harbor can run the Maho MCP server over stdio for compatible AI clients.

Start the application stack first, then configure the client to execute Harbor from the repository root:

```json
{
  "mcpServers": {
    "maho-stdio": {
      "command": "./vendor/bin/harbor",
      "args": ["maho", "dev:mcp:start"]
    }
  }
}
```

## Customizing the stack

Customize your local stack by adding Compose fragments to [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml). Both it and [`.harbor/docker.install.yaml`](../../.harbor/docker.install.yaml) are merged over [`.harbor/compose.yaml`](../../.harbor/compose.yaml); never edit the generated file directly.

### Add a service

```yaml
# .harbor/docker.override.yaml
services:
  postgres:
    image: postgres:16
    environment:
      POSTGRES_PASSWORD: example
    ports:
      - "5432:5432"
```

To start a service only on demand instead of on every `up`, declare `profiles: [name]`; see [How profiles work](#how-profiles-work) for the details.

### Override an existing service

```yaml
# .harbor/docker.override.yaml
services:
  db:
    ports:
      - "3307:3306"
```

### Add an environment variable or a volume

```yaml
# .harbor/docker.override.yaml
services:
  app:
    environment:
      MY_FLAG: "1"
    volumes:
      - ../data:/data
```

### Enable developer mode

Set `MAGE_IS_DEVELOPER_MODE` on the `app` service to run Maho in developer mode:

```yaml
# .harbor/docker.override.yaml
services:
  app:
    environment:
      MAGE_IS_DEVELOPER_MODE: "1"
```

After editing, apply the changes with `./vendor/bin/harbor up -d`. Add `--build` when the application image must be rebuilt.

## Customization and generated files

- [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml) is for persistent project or local Compose overrides.
- [`.harbor/docker.install.yaml`](../../.harbor/docker.install.yaml) is for install-specific additions and settings.
- [`.harbor/stubs/<service>.yaml`](../../.harbor/stubs/) overrides a matching package stub when present.
- `HARBOR_PHP_EXTENSIONS` in [`.harbor/.env`](../../.harbor/.env) adds extra PHP extensions to the application image, as a space-separated list of `install-php-extensions` names:

  ```ini
  HARBOR_PHP_EXTENSIONS="gmp imagick"
  ```

  Rebuild the image after changing it with `./vendor/bin/harbor up -d --build`.

Keep secrets out of versioned override files. `init --force` can replace generated environment and Compose files.

Harbor does not update Maho's [`local.xml`](../../app/etc/local.xml) automatically.

## Troubleshooting

```bash
./vendor/bin/harbor config
./vendor/bin/harbor doctor
./vendor/bin/harbor ps
./vendor/bin/harbor logs app
./vendor/bin/harbor logs cron
```

Run the launcher from the directory containing `vendor/`, `.harbor/`, and the Maho project files. Harbor requires Docker and Docker Compose; this README does not replace their installation documentation.

## For developers

To contribute to Harbor itself (forking the repository, setting up a local `localdev` checkout, and running the pre-pull-request checks), read the contribution guide in [CONTRIBUTING.md](CONTRIBUTING.md).

## Package tests

From the Harbor package directory, the shell test suite can run without starting containers:

```bash
bash tests/harbor.sh
```

## License

Harbor is open-sourced software licensed under the [MIT license](LICENSE).

