#!/usr/bin/env bash
# MyInvoice.cz — native (non-Docker) update.
#
# Aktualizuje source/git nativní instalaci na cílový release tag:
#   1. git fetch --tags  +  checkout v<TARGET>
#   2. composer install --no-dev   (api/vendor)
#   3. pnpm install + build         (web/dist)
#   4. regenerace manuálu           (manual/generated + manual.pdf)
#   5. php api/bin/migrate.php       (pending migrace)
#
# Spouštět jako OS uživatel, který VLASTNÍ repo a má v PATH git/php/composer/pnpm
# (typicky stejný uživatel, pod kterým běží `native-update-watcher.sh`).
#
# Cílovou verzi předej argumentem ($1, např. `4.40.1` nebo `v4.40.1`). Bez
# argumentu se vezme nejvyšší `vX.Y.Z` tag z `git tag`.
#
# Idempotentní — opakované spuštění je bezpečné. Konfiguraci ani data nemaže
# (cfg*.php, storage/, private/, log/ jsou gitignored, checkout se jich netýká).
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

TARGET="${1:-}"

# --- preflight: nástroje + git checkout ----------------------------------
for cmd in git php composer pnpm; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR: '$cmd' není v PATH." >&2
    echo "       Pokud běžíš pod systemd/cron, mají minimální PATH — nastav v unitu" >&2
    echo "       Environment=PATH=... nebo skript spouštěj přes 'bash -lc'." >&2
    exit 1
  fi
done
if [[ ! -d .git ]]; then
  echo "ERROR: $PROJECT_ROOT není git checkout — nativní auto-update vyžaduje git instalaci." >&2
  echo "       Pro tarball/bez-git instalace použij production bundle (viz manuál § 38.6)." >&2
  exit 1
fi

echo "==> git fetch --tags"
git fetch --tags --force

# Cílový tag — z argumentu, jinak nejvyšší vX.Y.Z.
if [[ -z "$TARGET" ]]; then
  TARGET="$(git tag -l 'v*' | sed 's/^v//' | sort -V | tail -1)"
  if [[ -z "$TARGET" ]]; then
    echo "ERROR: nenalezen žádný verzový tag (vX.Y.Z)." >&2
    exit 1
  fi
fi
TAG="v${TARGET#v}"

if ! git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
  echo "ERROR: tag ${TAG} po fetchi neexistuje." >&2
  exit 1
fi

# Necommitnuté změny ve VERZOVANÝCH souborech checkout zahodí (config/dist/vendor
# jsou gitignored, takže za normálu je strom čistý). Zaloguj, ať je to dohledatelné.
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo "WARN: necommitnuté změny ve verzovaných souborech budou checkoutem zahozeny:" >&2
  git status --porcelain --untracked-files=no >&2
fi

echo "==> git checkout ${TAG}"
git checkout -f "${TAG}"

echo "==> composer install --no-dev (api)"
( cd api && composer install --no-dev --no-interaction --prefer-dist )

echo "==> pnpm install + build (web)"
( cd web && pnpm install && pnpm build )

echo "==> regenerace manuálu (html + pdf) — nefatální"
# Manuál je jen kosmetický (gitignored výstup). Selhání (typicky mpdf/fonty)
# nesmí zablokovat migraci níž — jinak by zůstal nový kód + stará DB schéma.
php tools/generateManualHtml.php || echo "WARN: generateManualHtml selhal — pokračuji (HTML manuálu může být neaktuální)" >&2
php tools/exportManualToPdf.php  || echo "WARN: exportManualToPdf selhal — pokračuji (PDF manuálu může být neaktuální)" >&2

echo "==> php api/bin/migrate.php"
php api/bin/migrate.php

echo ""
echo "============================================================"
echo " Nativní update na ${TAG} dokončen."
echo "============================================================"
