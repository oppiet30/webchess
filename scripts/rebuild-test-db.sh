#!/usr/bin/env bash
#
# Rebuilds the local *test* database from the canonical schema in docs/tables.
# Runs the WebChess DDL (CREATE TABLE statements are embedded in the *.txt
# docs). Never touches the real `webchess` database.
#
# Usage: scripts/rebuild-test-db.sh [dbname]   (default: webchess_test)

set -euo pipefail

DB="${1:-webchess_test}"

mariadb <<SQL
DROP DATABASE IF EXISTS \`${DB}\`;
CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL

for f in docs/tables/*.txt; do
  # Extract everything from the first `CREATE TABLE` to the terminating `;`.
  awk '/^CREATE TABLE /{on=1} on{print} on && /;$/{exit}' "$f" |
    mariadb "$DB"
done

echo "Recreated database '${DB}' from docs/tables/*.txt"