# Contributing to Harbor

Harbor contributions should remain scoped to the Harbor package.
Do not commit generated runtime files, local environment files, database dumps, or
dependencies.

To develop Harbor locally, first fork the Harbor repository into your own
account. Then create a `localdev` directory in the Maho project root and check
out your fork there:

```bash
mkdir -p localdev
git clone <your-harbor-fork-url> localdev/harbor
```

Add this repository entry to the project root's
[`composer.json`](../../composer.json:8) if it is not already present:

```json
"repositories": [
  {
    "type": "path",
    "url": "./localdev/*",
    "canonical": false
  }
],
```

The `canonical: false` setting allows the local path repository to override the
same package from another repository. Running Composer from the project root
keeps the standard
`vendor/empiricompany/harbor` symlink pointed at the local checkout:

```bash
cd project-root
composer update empiricompany/harbor
./vendor/bin/harbor --help
```

Commit changes to your fork and open a pull request against the upstream Harbor
repository when the work is ready.

Before opening a pull request, run:

```bash
cd localdev/harbor
composer validate
bash -n bin/harbor
composer test
composer lint
composer format
```

`composer test` checks initialization and the launcher without requiring Bats or
PHP. Install ShellCheck and shfmt through the operating system package manager
before running the complete checklist. YAML validation is performed by the CI
pipeline. Harbor's runtime is Bash plus Docker Compose; PHP is used only inside
the application container.

Describe behavior changes and note any checks that could not be run.
