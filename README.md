# Harbor local development

> **Beta:** Harbor is for local development only. It is actively developed and must not be used for production deployments. Commands, generated files, and defaults may change without notice.

Harbor is the Docker Compose launcher and local development stack for Maho projects. Run all commands from the Maho project root.

## Install Harbor and set up a first Maho project

Harbor and Maho are separate concerns:

1. A Maho project must exist first. Installing Harbor does not create a Maho project and Harbor has no project-creation command.
2. Add Harbor to an existing project as a Composer development dependency:

   ```bash
   composer require --dev empiricompany/harbor
   ```

3. Install the project dependencies. For an already configured project, use the Harbor wrapper so Composer runs in the application container:

   ```bash
   ./vendor/bin/harbor composer install
   ```

4. Generate Harbor's project files and review [`.harbor/.env`](../../.harbor/.env):

   ```bash
   ./vendor/bin/harbor init
   ```

5. Configure Maho itself using the installer documented by the Maho project. Harbor does not create [`app/etc/local.xml`](../../app/etc/local.xml) or replace the Maho installer. Database defaults exposed to the containers are `db:3306`, database `maho`, user `maho`, and password `maho`; make sure the Maho installation uses the values appropriate for the project.
6. Start the stack and check it:

   ```bash
   ./vendor/bin/harbor up -d
   ./vendor/bin/harbor ps
   ```

Use `./vendor/bin/harbor up -d --build` when the application image must be built. `init` creates `.harbor/` files only when they are absent; `init --force` replaces generated environment and Compose files and can overwrite local settings.

## Quick start and base stack

The default stack contains:

- `app`: Maho, PHP, the web server, and application tools;
- `db`: MySQL;
- `mailpit`: local SMTP capture and web inbox;
- `cron`: Ofelia, the scheduled-task service.

The application URL is controlled by `HARBOR_APP_URL` and defaults to `https://localhost:8443/`. The base Mailpit UI defaults to `http://localhost:8025`; applications send SMTP to `mailpit:1025` inside the Compose network.

Project files are generated in [`.harbor/`](../../.harbor/). Do not edit [`.harbor/compose.yaml`](../../.harbor/compose.yaml) for normal customization; use [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml) or [`.harbor/docker.install.yaml`](../../.harbor/docker.install.yaml).

## Main commands

These examples match [`localdev/harbor/bin/harbor`](bin/harbor).

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

## Xdebug

Xdebug is included in the application image: the package Dockerfile installs it together with the core PHP extensions.  
Verify it from the running `app` container:

```bash
./vendor/bin/harbor php -m | grep -i '^xdebug$'
./vendor/bin/harbor php -r 'var_dump(extension_loaded("xdebug"));'
```

Use the Harbor debug wrapper for a PHP command or script:

```bash
./vendor/bin/harbor debug script.php
./vendor/bin/harbor debug -r 'var_dump(extension_loaded("xdebug"));'
```

When Xdebug is installed, `debug` adds `-d xdebug.start_with_request=yes`. If it is unavailable, Harbor prints a warning and runs PHP without Xdebug. The package does not declare Xdebug-specific variables in [`.harbor/.env.example`](../../.harbor/.env.example), and [`resources/php/php.ini`](resources/php/php.ini) contains no Xdebug settings. Do not assume IDE path mappings, client-host settings, or a host debugging port are configured automatically.

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
## Optional services

Base services and optional profile services are separate.  
The available profiles are `redis`, `adminer`, and `phpmyadmin`.  
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

- Internal hostname and port: `redis:6379`.
- The service uses a  temporary `/data` storage with `volatile-lfu` eviction.
- The environment example defines `HARBOR_REDIS_HOST=redis`, `HARBOR_REDIS_PORT=6379`, `HARBOR_REDIS_SESSION_DB=1`, and `HARBOR_REDIS_CACHE_DB=2`.
- No host port is published by the Redis stub. Use `redis:6379` from containers or `./vendor/bin/harbor redis ...`.

### Mailpit (base service)

- Web UI: `http://localhost:${HARBOR_MAILPIT_PORT:-8025}`; default `http://localhost:8025`.
- SMTP from the host: `localhost:${HARBOR_MAILPIT_SMTP_PORT:-1025}`; default `localhost:1025`.
- SMTP from containers: `mailpit:1025`.
- `HARBOR_MAILPIT_PORT` and `HARBOR_MAILPIT_SMTP_PORT` can change the published host ports. Mailpit is always part of the base stack.

### Adminer (optional profile: `adminer`)

- Web endpoint: `http://localhost:${HARBOR_ADMINER_PORT:-8082}`; default `http://localhost:8082`.
- Its default database server is `db` (`ADMINER_DEFAULT_SERVER=db`).
- Use the credentials from [`.harbor/.env`](../../.harbor/.env).

### phpMyAdmin (optional profile: `phpmyadmin`)

- Web endpoint: `http://localhost:${HARBOR_PHPMYADMIN_PORT:-8081}`; default `http://localhost:8081`.
- Its configured database host and port are `db:3306` (`PMA_HOST=db`, `PMA_PORT=3306`).
- `HARBOR_PHPMYADMIN_PORT` changes the published host port.

## Dev Containers

Generate the Dev Container file with:

```bash
./vendor/bin/harbor devcontainer --force
```

This writes [`.devcontainer/devcontainer.json`](../../.devcontainer/devcontainer.json).  
The generated configuration points to [`../.harbor/compose.yaml`](../../.harbor/compose.yaml), opens the `app` service, and uses `/app` as the workspace folder.

Open the project in VS Code with the Microsoft Dev Containers extension, then choose **Dev Containers: Reopen in Container**. 

Harbor does not install VS Code extensions or Dev Containers tooling.

### VS Code PHP integration

For the Maho VS Code extension, configure PHP to run through Harbor from the
repository root. 

Add this setting to `.vscode/settings.json`:

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

## Customization and generated files

- [`.harbor/docker.override.yaml`](../../.harbor/docker.override.yaml) is for persistent project or local Compose overrides.
- [`.harbor/docker.install.yaml`](../../.harbor/docker.install.yaml) is for install-specific additions and settings.
- [`.harbor/stubs/<service>.yaml`](../../.harbor/stubs/) overrides a matching package stub when present.
- `HARBOR_PHP_EXTENSIONS` in [`.harbor/.env`](../../.harbor/.env) adds PHP extensions; rebuild with `./vendor/bin/harbor up -d --build`.

Keep secrets out of versioned override files. `init --force` can replace generated environment and Compose files.

Harbor does not update Maho's [local.xml](../../app/etc/local.xml) automatically.

## Troubleshooting

```bash
./vendor/bin/harbor config
./vendor/bin/harbor doctor
./vendor/bin/harbor ps
./vendor/bin/harbor logs app
./vendor/bin/harbor logs cron
```

Run the launcher from the directory containing `vendor/`, `.harbor/`, and the Maho project files. Harbor requires Docker and Docker Compose; this README does not replace their installation documentation.

## Package tests

From the Harbor package directory, the shell test suite can run without starting containers:

```bash
cd localdev/harbor
bash tests/harbor.sh
```
