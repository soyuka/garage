#!/usr/bin/env bash
# Imports prototype_1's paid upstream responses into this app's PostgreSQL cache
# pools (cache.siv + cache.partsapi) and seeds the per-method call counters with
# what the prototype already spent (its counter was global; breakdown from
# prototype_1/PARTSAPI.md).
#
# Thin wrapper around `bin/console app:cache:import-legacy`: it stages prototype_1's
# cache (which has its own layout) into a temporary var/-layout dir, then hands that
# dir to the command, which writes the pool keys SivClient/PartsApiClient look up.
set -euo pipefail

API_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PROTO_DIR="${1:-$API_DIR/../../../prototype_1}"

if [ ! -d "$PROTO_DIR/cache" ]; then
    echo "prototype cache not found at $PROTO_DIR/cache" >&2
    exit 1
fi

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
mkdir -p "$STAGE_DIR/siv" "$STAGE_DIR/partsapi"

find "$PROTO_DIR/cache" -maxdepth 1 -name '*.json' -exec cp -n {} "$STAGE_DIR/siv/" \;
find "$PROTO_DIR/cache/parts" -maxdepth 1 -name '[a-zA-Z]*.json' -exec cp -n {} "$STAGE_DIR/partsapi/" \;

seed() {
    echo "{\"spent\": $2}" > "$STAGE_DIR/partsapi/_calls_$1.json"
}
seed getSearchTree 3
seed getArticles 1

php "$API_DIR/bin/console" app:cache:import-legacy "$STAGE_DIR"
