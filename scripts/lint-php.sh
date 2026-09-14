#!/usr/bin/env bash
set -euo pipefail

failures=0

while IFS= read -r -d '' file; do
  if ! php -l "$file" > /dev/null 2>&1; then
    php -l "$file" >&2
    ((failures++))
  fi
done < <(find . -type f \( -name '*.php' -o -name '*.inc' \) -not -path './vendor/*' -print0)

if [ "$failures" -gt 0 ]; then
  printf '\n%d file(s) with syntax errors.\n' "$failures" >&2
  exit 1
fi

printf 'All PHP files OK.\n'
