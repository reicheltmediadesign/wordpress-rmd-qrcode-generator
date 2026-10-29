#!/usr/bin/env bash
# Builds dist/<slug>.zip with a single top-level folder named after the plugin
# slug (required for in-place updates). Used locally and in CI.
set -euo pipefail

cd "$(dirname "$0")/.."
source bin/plugin.env

stage="dist/$SLUG"
composer_bin="$(command -v composer || command -v composer.bat)"   # Git Bash on Windows only finds composer.bat.

rm -rf dist
mkdir -p "$stage"

# Copy everything except what .distignore lists (tar is available on Git Bash and Linux alike).
tar --exclude-from=.distignore -cf - . | tar -xf - -C "$stage"

# Production vendor folder with plugin-update-checker (composer files are removed again afterwards).
cp composer.json composer.lock "$stage/"
(
	cd "$stage"
	"$composer_bin" install --no-dev --no-interaction --no-progress --classmap-authoritative --quiet
	rm -f composer.json composer.lock
)

(
	cd dist
	rm -f "$SLUG.zip"
	zip -qr "$SLUG.zip" "$SLUG"
)

rm -rf "$stage"
echo "Created dist/$SLUG.zip"
unzip -l "dist/$SLUG.zip" | head -n 12
