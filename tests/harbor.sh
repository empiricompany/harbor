#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fail() {
	echo "FAIL: $*" >&2
	exit 1
}
assert_file() { [ -f "$1" ] || fail "missing file: $1"; }
assert_contains() { grep -Fq -- "$2" "$1" || fail "missing '$2' in $1"; }
assert_not_contains() { ! grep -Fq -- "$2" "$1" || fail "unexpected '$2' in $1"; }

PROJECT=$TMP/project
PACKAGE=$PROJECT/vendor/example/harbor
mkdir -p "$PACKAGE" "$PROJECT/vendor/bin" "$TMP/bin" "$PROJECT/app/etc"

# Package-only fixture: no application files are copied from the repository.
cp -R "$ROOT/bin" "$ROOT/resources" "$PACKAGE/"
printf '%s\n' '<fixture>unchanged</fixture>' >"$PROJECT/app/etc/local.xml"

cat >"$PROJECT/vendor/bin/harbor" <<'EOF'
#!/usr/bin/env bash
exec "$(dirname -- "$0")/../example/harbor/bin/harbor" "$@"
EOF
chmod +x "$PROJECT/vendor/bin/harbor"

cat >"$TMP/bin/docker" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = compose ] && [ "${2:-}" = version ]; then
    exit 0
fi
printf '%q ' "$@" >>"$FAKE_LOG"
printf '\n' >>"$FAKE_LOG"
exit "${FAKE_EXIT:-0}"
EOF
chmod +x "$TMP/bin/docker"

run_harbor() {
	(cd "$PROJECT" && PATH="$TMP/bin:$PATH" "$PROJECT/vendor/bin/harbor" "$@")
}
run_logged() {
	local log=$1
	shift
	(cd "$PROJECT" && PATH="$TMP/bin:$PATH" FAKE_LOG="$TMP/$log" "$PROJECT/vendor/bin/harbor" "$@")
}

run_harbor init
for file in .harbor/.env .harbor/.env.example .harbor/compose.yaml \
	.harbor/docker.override.yaml .harbor/docker.install.yaml; do
	assert_file "$PROJECT/$file"
done

compose_hash=$(sha256sum "$PROJECT/.harbor/compose.yaml")
local_hash=$(sha256sum "$PROJECT/app/etc/local.xml")
printf '\nCUSTOM_VARIABLE=unchanged\n' >>"$PROJECT/.harbor/.env"

run_harbor services add redis
run_harbor services add redis
run_harbor services add adminer
[ "$(grep -c '^HARBOR_PROFILES=redis,adminer$' "$PROJECT/.harbor/.env")" -eq 1 ] ||
	fail 'profiles are not comma-separated or add is not idempotent'
run_harbor services list >"$TMP/list"
assert_contains "$TMP/list" 'Active profiles:'
assert_contains "$TMP/list" 'redis'
assert_contains "$TMP/list" 'adminer'
assert_contains "$PROJECT/.harbor/.env" 'CUSTOM_VARIABLE=unchanged'

run_logged up.log up -d
assert_contains "$TMP/up.log" '--profile redis'
assert_contains "$TMP/up.log" '--profile adminer'
assert_contains "$TMP/up.log" '-f .harbor/compose.yaml -f'
for service in app db mailpit cron redis adminer; do
	assert_contains "$TMP/up.log" "$PACKAGE/resources/stubs/$service.yaml"
done
assert_contains "$TMP/up.log" ' up -d '

run_harbor services remove redis
[ "$(grep -c '^HARBOR_PROFILES=adminer$' "$PROJECT/.harbor/.env")" -eq 1 ] ||
	fail 'remove did not leave adminer active'
run_logged after-remove.log up -d
assert_not_contains "$TMP/after-remove.log" '--profile redis'
assert_contains "$TMP/after-remove.log" '--profile adminer'
[ "$compose_hash" = "$(sha256sum "$PROJECT/.harbor/compose.yaml")" ] ||
	fail 'services add/remove changed compose.yaml'
[ "$local_hash" = "$(sha256sum "$PROJECT/app/etc/local.xml")" ] ||
	fail 'launcher changed the fixture application file'

if run_harbor services add unknown >/dev/null 2>&1; then
	fail 'unknown service was accepted'
fi

printf 'ok: Harbor Bash smoke test\n'
