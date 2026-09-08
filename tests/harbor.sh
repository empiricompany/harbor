#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
assert_file() { [ -f "$1" ] || { echo "Missing file: $1" >&2; exit 1; }; }
assert_contains() { grep -Fq -- "$2" "$1" || { echo "Missing '$2' in $1" >&2; exit 1; }; }
assert_not_contains() { ! grep -Fq -- "$2" "$1" || { echo "Unexpected '$2' in $1" >&2; exit 1; }; }

PROJECT=$TMP/project
PACKAGE="$PROJECT/vendor/example/harbor"
mkdir -p "$PACKAGE" "$PROJECT/vendor/bin" "$TMP/bin" "$PROJECT/app/etc"
cp -R "$ROOT/bin" "$ROOT/resources" "$PACKAGE/"
cp "$ROOT/../../app/etc/local.xml" "$PROJECT/app/etc/local.xml"
cat >"$PROJECT/vendor/bin/harbor" <<'EOF'
#!/usr/bin/env bash
exec "$(dirname -- "$0")/../example/harbor/bin/harbor" "$@"
EOF
chmod +x "$PROJECT/vendor/bin/harbor"
cat >"$TMP/bin/docker" <<'EOF'
#!/bin/bash
if [ "${1:-}" = compose ] && [ "${2:-}" = version ]; then exit 0; fi
printf '%q ' "$@" >>"$FAKE_LOG"; printf '\n' >>"$FAKE_LOG"
exit "${FAKE_EXIT:-0}"
EOF
chmod +x "$TMP/bin/docker"

(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" init >/dev/null)
assert_file "$PROJECT/.harbor/.env"
for service in app db mailpit cron redis adminer phpmyadmin; do assert_contains "$PROJECT/.harbor/compose.yaml" "  $service:"; done
assert_contains "$PROJECT/.harbor/compose.yaml" 'profiles: [redis]'
assert_not_contains "$PROJECT/.harbor/compose.yaml" 'HARBOR_PROJECT_ROOT'
local_hash=$(sha256sum "$PROJECT/app/etc/local.xml")
compose_hash=$(sha256sum "$PROJECT/.harbor/compose.yaml")
printf '\nCUSTOM_VARIABLE=unchanged\n' >>"$PROJECT/.harbor/.env"
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" services add redis)
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" services add redis)
[ "$(grep -c '^HARBOR_PROFILES=' "$PROJECT/.harbor/.env")" -eq 1 ]
assert_contains "$PROJECT/.harbor/.env" 'CUSTOM_VARIABLE=unchanged'
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" services list >"$TMP/list")
assert_contains "$TMP/list" 'redis'
assert_contains "$TMP/list" 'Active profiles:'
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" FAKE_LOG="$TMP/up.log" "$PROJECT/vendor/bin/harbor" up -d)
assert_contains "$TMP/up.log" '--profile redis'
grep -Eq ' up -d $' "$TMP/up.log" || { echo 'up without services passed explicit services' >&2; exit 1; }
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" FAKE_LOG="$TMP/up-app.log" "$PROJECT/vendor/bin/harbor" up -d app)
grep -Eq ' up -d app $' "$TMP/up-app.log" || { echo 'up with app did not preserve explicit service' >&2; exit 1; }
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" FAKE_LOG="$TMP/config.log" "$PROJECT/vendor/bin/harbor" config)
assert_contains "$TMP/config.log" '--profile redis'
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" services remove redis)
[ "$(grep -c '^HARBOR_PROFILES=$' "$PROJECT/.harbor/.env")" -eq 1 ]
[ "$local_hash" = "$(sha256sum "$PROJECT/app/etc/local.xml")" ]
[ "$compose_hash" = "$(sha256sum "$PROJECT/.harbor/compose.yaml")" ]
[ ! -e "$PROJECT/.harbor/services.list" ]
set +e
(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" services add unknown >/dev/null 2>&1)
status=$?
set -e
[ "$status" -ne 0 ]
printf 'ok: Harbor Bash tests\n'
