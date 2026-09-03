#!/usr/bin/env bash
set -Eeuo pipefail

# Minimal POSIX-tooling-free Bash integration checks; no Bats dependency.
ROOT=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
CLI=$ROOT/bin/harbor
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

assert_file() { [ -f "$1" ] || {
	echo "Missing file: $1" >&2
	exit 1
}; }
assert_contains() { grep -Fq -- "$2" "$1" || {
	echo "Missing '$2' in $1" >&2
	exit 1
}; }

FAKE_BIN=$TMP/bin
mkdir -p "$FAKE_BIN"
cat >"$FAKE_BIN/docker" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = compose ] && [ "${2:-}" = version ]; then
	exit 0
fi
printf '%q ' "$@" >>"$HARBOR_DOCKER_LOG"
printf '\n' >>"$HARBOR_DOCKER_LOG"
EOF
chmod +x "$FAKE_BIN/docker"

HARBOR_PROJECT_ROOT=$TMP "$CLI" init >/dev/null
assert_file "$TMP/.harbor/.env"
assert_file "$TMP/.harbor/.env.example"
assert_file "$TMP/.harbor/compose.yaml"
assert_file "$TMP/.harbor/docker.override.yaml"
assert_file "$TMP/.harbor/docker.install.yaml"
assert_contains "$TMP/.harbor/compose.yaml" 'HARBOR_PHP_EXTENSIONS'
assert_contains "$ROOT/docker/Dockerfile" 'xdebug'
grep -Fq 'ftp gd intl zip soap pcntl pdo_mysql pdo_pgsql pgsql pdo_sqlite redis xdebug' "$TMP/.harbor/compose.yaml" && exit 1
grep -qE 'compose\.(override|install)\.yaml' "$TMP/.harbor"/* && exit 1
bash -n "$CLI"
assert_contains "$TMP/.harbor/compose.yaml" '/resources/mysql/server.cnf'
grep -Fq '__HARBOR_PACKAGE_ROOT__' "$TMP/.harbor/compose.yaml" && exit 1
before=$(sha256sum "$TMP/.harbor/compose.yaml")
HARBOR_PROJECT_ROOT=$TMP "$CLI" init >/dev/null
[ "$before" = "$(sha256sum "$TMP/.harbor/compose.yaml")" ] || exit 1

# root-shell accepts one shell command string and runs it through bash -c.
: >"$TMP/docker.log"
PATH=$FAKE_BIN:$PATH HARBOR_DOCKER_LOG=$TMP/docker.log \
	HARBOR_PROJECT_ROOT=$TMP "$CLI" root-shell "printf root-shell-ok" >/dev/null
assert_contains "$TMP/docker.log" "bash -c printf\ root-shell-ok"
PATH=$FAKE_BIN:$PATH HARBOR_DOCKER_LOG=$TMP/docker.log \
	HARBOR_PROJECT_ROOT=$TMP "$CLI" root-shell id -u >/dev/null
assert_contains "$TMP/docker.log" "bash -c id\ -u"
printf 'ok: Harbor Bash tests\n'
