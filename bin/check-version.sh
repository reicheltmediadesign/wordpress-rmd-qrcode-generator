#!/usr/bin/env bash
# Verifies that plugin header, version constant and CHANGELOG.md agree. Pass a
# git tag (v1.2.3) to also compare against it.
set -euo pipefail

cd "$(dirname "$0")/.."
source bin/plugin.env

header=$(grep -E '^ \* Version:' "$MAIN_FILE" | awk '{print $3}' | tr -d '\r')
constant=$(grep -E "define\('$VERSION_CONSTANT'" "$MAIN_FILE" | sed -E "s/.*'([0-9.]+)'.*/\1/" | tr -d '\r')
changelog=$(grep -m1 -E '^## \[[0-9]' CHANGELOG.md | sed -E 's/^## \[([0-9.]+)\].*/\1/' | tr -d '\r')

echo "Plugin header:     $header"
echo "$VERSION_CONSTANT: $constant"
echo "CHANGELOG.md:      $changelog"

status=0
for value in "$constant" "$changelog"; do
	if [ "$value" != "$header" ]; then
		status=1
	fi
done

if [ "${1:-}" != "" ]; then
	tag="${1#v}"
	echo "Tag:               $tag"
	if [ "$tag" != "$header" ]; then
		status=1
	fi
fi

if [ $status -ne 0 ]; then
	echo "Version mismatch." >&2
	exit 1
fi
echo "All versions match."
