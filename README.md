# Harbor

Harbor is a Docker-based local development environment for Maho Commerce.
It provides a small Bash CLI around Docker Compose and convenient commands for
the Maho containers.

Harbor is for local development only. It is not a production deployment or
hardening solution.

## Requirements

- Docker with the Compose plugin;
- a Maho project checkout;
- Composer only to install or update the package.

All commands must be run from the Maho project root.

## Install and start

```bash
composer require --dev empiricompany/harbor
./vendor/bin/harbor init
./vendor/bin/harbor up -d
./vendor/bin/harbor doctor
```

Harbor is a local-development tool, so `--dev` keeps it out of production
installations.

After installing the package, regenerate Composer's autoload files so Maho can
discover the package's database commands: `db:export` and `db:import`.
Harbor service management is Bash-only and is not registered in the Maho CLI.

The two database commands read the configured connection from `app/etc/local.xml`
and invoke `mysqldump`/`mysql` against the configured Docker database service.
They do not run the database on the host; run them through the app container with
`./vendor/bin/harbor maho ...`.

`init` is a Bash-only bootstrap: the operational manifest is
`.harbor/compose.yaml`. When missing, `init` creates the following files in
`.harbor`:

- `.env.example`, copied from `resources/env.example`;
- `.env`, copied from `resources/env.example`;
- `.gitignore`, copied from `resources/harbor.gitignore`;
- `docker.override.yaml` containing `services: {}`;
- `docker.install.yaml` containing `services: {}`;
- `compose.yaml`, copied from the current `resources/stubs/compose.stub`.

It does not invoke PHP or Maho. If `.harbor/compose.yaml` already exists,
`init` without `--force` exits with an error and does not overwrite it. Use
`init --force` only after reviewing any customizations: it replaces the
manifest with the current stub. Review `.harbor/.env` before starting the
stack. Do not commit `.harbor/.env`.

The `compose.stub` already contains the base services `app`, `db`, `mailpit`,
and `cron`, plus the optional Compose-profile services `redis`, `adminer`, and
`phpmyadmin`. Service changes only update `HARBOR_PROFILES` in `.harbor/.env`;
they never modify `.harbor/compose.yaml`, start or stop containers, create
`.harbor/services.list`, or change `app/etc/local.xml`:

```bash
./vendor/bin/harbor services list
./vendor/bin/harbor services add redis
./vendor/bin/harbor services remove redis
```

`services list` shows the available profiles and active profiles. After adding
or removing a profile, run `up -d` to apply the change; `services add/remove`
does not perform that lifecycle operation. The generated manifest remains
`.harbor/compose.yaml`.

### Verified profile workflow

This is the complete verified workflow for enabling Redis:

```bash
./vendor/bin/harbor init --force
./vendor/bin/harbor services add redis
./vendor/bin/harbor services list
./vendor/bin/harbor down
./vendor/bin/harbor up -d
./vendor/bin/harbor ps
```

After `services add redis`, `up -d` is required before `ps`; Redis must then
appear among the active services. To disable it, run:

```bash
./vendor/bin/harbor services remove redis
./vendor/bin/harbor down
./vendor/bin/harbor up -d
./vendor/bin/harbor ps
```

When `up -d` is used without explicit service names, Compose selects the base
services and any services whose profiles are active through `HARBOR_PROFILES`.

The first application image build may take several minutes:

```bash
./vendor/bin/harbor up -d --build
```

## Daily commands

### Stack lifecycle

```bash
./vendor/bin/harbor up -d
./vendor/bin/harbor up -d --build
./vendor/bin/harbor ps
./vendor/bin/harbor logs
./vendor/bin/harbor logs cron
./vendor/bin/harbor logs -f app
./vendor/bin/harbor stop
./vendor/bin/harbor restart
./vendor/bin/harbor down
```

`down` removes containers and the network but keeps volumes. To remove volumes
as well, use:

```bash
./vendor/bin/harbor down -v
```

**Warning:** this permanently removes local database data. Harbor asks for
confirmation and refuses this operation in non-interactive mode.

### Commands in the app container

```bash
./vendor/bin/harbor php --version
./vendor/bin/harbor composer install
./vendor/bin/harbor maho cache:flush
./vendor/bin/harbor bin phpunit
./vendor/bin/harbor mysql --execute='SELECT 1'
./vendor/bin/harbor redis --raw ping
```

### Shell access

```bash
./vendor/bin/harbor shell
./vendor/bin/harbor shell --service db
./vendor/bin/harbor root-shell
```

`shell` opens Bash in `app` by default. Use `--service` or `-s` to select a
different running service. `root-shell` opens Bash as root in `app`.

### Generic container commands

Use `exec` when no dedicated wrapper exists:

```bash
./vendor/bin/harbor exec --service app php -v
./vendor/bin/harbor exec --service app --user root bash
./vendor/bin/harbor exec --service app --no-tty php script.php
```

Options before the command:

- `--service`/`-s` selects the Compose service;
- `--user`/`-u` selects the container user;
- `--no-tty` disables TTY allocation.

## Compose customization

Harbor keeps the generated base configuration in `.harbor/compose.yaml` and
loads these optional project-owned layers in order:

```text
.harbor/compose.yaml
.harbor/docker.override.yaml
.harbor/docker.install.yaml
```

Do not edit the generated base file. Put local or persistent customizations in
`.harbor/docker.override.yaml`; use `.harbor/docker.install.yaml` for services
or settings needed during installation. Both files are optional and can be
created manually.

Example: enable Xdebug and add a Node service:

```yaml
services:
  app:
    environment:
      XDEBUG_MODE: develop,debug
    extra_hosts:
      - host.docker.internal:host-gateway

  node:
    image: node:22
    working_dir: /app
    volumes:
       - .:/app
    networks:
      - harbor
```

Start the service and use it with `exec`:

```bash
./vendor/bin/harbor up -d
./vendor/bin/harbor exec --service node node --version
```

## Xdebug

Xdebug is included in the default app image. Configure your IDE to listen for
PHP debug connections on port `9003`, then enable the debug environment in
`.harbor/docker.override.yaml`:

```yaml
services:
  app:
    environment:
      XDEBUG_MODE: develop,debug
      XDEBUG_CONFIG: client_host=host.docker.internal client_port=9003
    extra_hosts:
      - host.docker.internal:host-gateway
```

The `XDEBUG_CONFIG` value makes the app container connect back to the host IDE.
Rebuild the app image after adding or changing this configuration:

```bash
./vendor/bin/harbor up -d --build
./vendor/bin/harbor debug -r 'echo "debug\n";'
```

Use `harbor debug` for requests that should start an Xdebug session. It enables
`xdebug.start_with_request=yes` for that PHP invocation; regular `php` and
`maho` commands do not force a debug session.

Inspect the Compose layers with:

```bash
./vendor/bin/harbor config
```

**Keep secrets in `.harbor/.env` or environment variables, not in committed
override files.**

### Maho developer mode

The project override enables Maho developer mode for the `app` service:

```yaml
services:
  app:
    environment:
      MAGE_IS_DEVELOPER_MODE: '1'
```

After changing the override, recreate the application service so the
environment is applied:

```bash
./vendor/bin/harbor up -d --force-recreate app
```

To disable developer mode while preserving the generated base configuration,
restore the empty override and recreate `app`:

```bash
printf 'services: {}\n' > .harbor/docker.override.yaml
./vendor/bin/harbor up -d --force-recreate app
```

In this repository `.harbor/.gitignore` explicitly keeps
`.harbor/docker.override.yaml` under version control, so this developer-mode
override is project-owned rather than a personal uncommitted override. If a
local-only variant is needed in another project, keep that file ignored and do
not commit it.

Verify the merged configuration and the container environment with:

```bash
./vendor/bin/harbor config
./vendor/bin/harbor exec --service app printenv MAGE_IS_DEVELOPER_MODE
```

## Scheduled Maho jobs

The stack runs the built-in Maho cron groups through Ofelia:

- `always`: every minute;
- `default`: every five minutes.

Add custom jobs to the `app` service in `.harbor/docker.override.yaml`:

```yaml
services:
  app:
    labels:
      ofelia.job-exec.catalog-reindex.schedule: "@every 10m"
      ofelia.job-exec.catalog-reindex.command: "sh -c 'cd /app && ./maho indexer:reindex catalog_product_flat'"
      ofelia.job-exec.catalog-reindex.user: maho
      ofelia.job-exec.catalog-reindex.no-overlap: "true"
```

Use a unique job name, recreate the application container, and inspect the
cron logs:

```bash
./vendor/bin/harbor up -d
./vendor/bin/harbor logs cron
```

## Maho installation

Harbor does not create or modify `app/etc/local.xml`. The Maho installer owns
that file.

For the web installer, open:

```text
https://localhost:8443/
```

Use these internal database values:

| Setting | Value |
| --- | --- |
| Database host | `db` |
| Database port | `3306` |
| Database name | value from `.harbor/.env` |
| Database user | value from `.harbor/.env` |
| Database password | value from `.harbor/.env` |
| Database engine | `mysql` |
| HTTPS URL | `https://localhost:8443/` |

For the CLI installer, run the Maho command in the app container:

```bash
./vendor/bin/harbor maho install \
  --license_agreement_accepted yes \
  --db_host db --db_name maho --db_user maho \
  --db_pass '<database password>' --db_engine mysql \
  --url https://localhost:8443/ --use_secure 1 \
  --secure_base_url https://localhost:8443/ \
  --admin_firstname Admin --admin_lastname User \
  --admin_email admin@example.test --admin_username admin \
  --admin_password '<admin password>'
```

## Database backup and restore

Export with the Maho database command:

```bash
./vendor/bin/harbor maho db:export var/backups/backup.sql.gz --compression=gzip
```

Restore a backup only when you intend to replace the local database:

```bash
./vendor/bin/harbor down -v
./vendor/bin/harbor up -d
./vendor/bin/harbor maho db:import \
  /app/var/backups/backup.sql.gz \
  --compression=gzip --drop-tables
```

**Warning:** the `down -v` step permanently deletes the current database volume.

## Optional services and URLs

Mailpit is included by default:

```text
Web UI: http://localhost:8025
SMTP:   mailpit:1025 from containers
```

Optional services are controlled through Compose profiles already defined in the
stub. List available services and active profiles with:

```bash
./vendor/bin/harbor services list
```

Enable or disable profiles with Bash; these commands preserve every other
`.harbor/.env` variable:

```dotenv
HARBOR_PROFILES=redis,adminer
```

For example, `./vendor/bin/harbor services add redis` and
`./vendor/bin/harbor services remove redis` update only that line. No change is
made to `app/etc/local.xml`.

Adminer and phpMyAdmin use the default local URLs when enabled:

```text
Adminer:    http://localhost:8082
phpMyAdmin: http://localhost:8081
```

## MCP server

Harbor can run the Maho MCP server over stdio for compatible AI clients. Start the
application stack first, then configure the client to execute Harbor from the
repository root:

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

## Diagnostics and convenience commands

```bash
./vendor/bin/harbor doctor
./vendor/bin/harbor config
./vendor/bin/harbor open
./vendor/bin/harbor open admin
./vendor/bin/harbor open mailpit
```

`doctor` checks Docker, Compose, configuration, and running services. `config`
renders the merged Compose configuration. `open` accepts `app`, `admin`, or
`mailpit` and defaults to `app`.

## Dev Containers

### VS Code PHP integration

For the Maho VS Code extension, configure PHP to run through Harbor from the
repository root. Add this setting to `.vscode/settings.json`:

```json
{
  "maho.phpCommand": "./vendor/bin/harbor php"
}
```

Make sure the Harbor application stack is running before using PHP features in
VS Code:

```bash
./vendor/bin/harbor up -d
```

With VS Code and the Dev Containers extension installed:

```bash
./vendor/bin/harbor up -d
./vendor/bin/harbor devcontainer
```

Reopen the project in the generated container. Use `--force` to replace an
existing configuration:

```bash
./vendor/bin/harbor devcontainer --force
```

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the development workflow and
quality checks.

## License

Harbor is released under the MIT License. See [`LICENSE`](LICENSE).
