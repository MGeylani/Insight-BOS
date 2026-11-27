#!/usr/bin/env bash
# Adds the untouched Laravel skeleton files (config, storage dirs, public/index.php, artisan...)
# that this repository does not track. Existing files are never overwritten.
set -euo pipefail
REF=f32d7019cbd0ab0c378b3154c551d0c60c553ebc
TMP=$(mktemp -d)
git clone --quiet https://github.com/laravel/laravel.git "$TMP"
git -C "$TMP" checkout --quiet "$REF"
rm -rf "$TMP/.git" "$TMP/.github" "$TMP/resources/js" "$TMP/resources/views/welcome.blade.php" \
       "$TMP/tests/Feature/ExampleTest.php" "$TMP/tests/Unit/ExampleTest.php" \
       "$TMP/AGENTS.md" "$TMP/CLAUDE.md" "$TMP/CHANGELOG.md" "$TMP/.styleci.yml" "$TMP/README.md" \
       "$TMP/package.json" "$TMP/vite.config.js" "$TMP/composer.json" "$TMP/public/favicon.ico"
cp -rn "$TMP/." .
rm -rf "$TMP"
echo "Skeleton files restored."
