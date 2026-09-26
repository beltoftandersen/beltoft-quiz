#!/usr/bin/env bash
# Runs every tests/test-*.php inside the wp_app container. Exit 1 if any fails.
set -u
PLUGIN=/var/www/html/wp-content/plugins/beltoft-quiz
STATUS=0
for f in "$(dirname "$0")"/test-*.php; do
  name=$(basename "$f")
  echo "== $name"
  docker exec wp_app wp --allow-root --path=/var/www/html eval-file "$PLUGIN/tests/$name" || STATUS=1
done
exit $STATUS
