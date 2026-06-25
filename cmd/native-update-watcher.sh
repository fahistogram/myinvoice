#!/usr/bin/env bash
# MyInvoice.cz — native upgrade watcher.
#
# Sleduje storage/upgrade-requested.json na lokálním filesystému a když ho UI
# vytvoří (POST /api/admin/update/trigger), spustí cmd/native-update.sh a
# výsledek zapíše do storage/upgrade-result.json. UI v Systém → Aktualizace
# to zobrazí jako „aplikováno / selhalo".
#
# Na rozdíl od docker-update-watcheru čte flag přímo ze souborového systému
# (žádný `docker compose exec`) — web app i watcher sdílí stejné storage/.
#
# Spouštět jako OS uživatel, který VLASTNÍ repo a má v PATH git/php/composer/pnpm.
# Provoz: systemd unit / cron @reboot / Scheduled Task — viz manuál § 38.6.
#
# Idempotent — flag se zpracuje jednou (rename na inflight před spuštěním).
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

INTERVAL_S="${MYINVOICE_WATCHER_INTERVAL:-30}"
# Storage cesta — respektuj MYINVOICE_DATA_DIR (stejně jako Config::resolveDataDir()
# v PHP), jinak fallback na <repo>/storage.
STORAGE_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/storage"
FLAG="${STORAGE_DIR}/upgrade-requested.json"
INFLIGHT="${STORAGE_DIR}/upgrade-inflight.json"
RESULT="${STORAGE_DIR}/upgrade-result.json"

echo "[watcher] start, polling ${FLAG} every ${INTERVAL_S}s"

while true; do
  if [[ -f "$FLAG" ]]; then
    TARGET="$(grep -oE '"target_version"[[:space:]]*:[[:space:]]*"[^"]+"' "$FLAG" 2>/dev/null \
      | head -1 \
      | sed -E 's/.*"target_version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/' \
      || true)"
    TARGET="${TARGET:-}"
    echo "[watcher] $(date -u +%FT%TZ) upgrade requested -> ${TARGET:-latest}"

    # Zámek proti double-triggeru — přejmenuj flag před spuštěním update skriptu.
    mv -f "$FLAG" "$INFLIGHT" 2>/dev/null || true

    LOG="${STORAGE_DIR}/upgrade-$(date -u +%Y%m%dT%H%M%SZ).log"
    if bash "$PROJECT_ROOT/cmd/native-update.sh" "$TARGET" >"$LOG" 2>&1; then
      STATUS="applied"
      MESSAGE="Upgrade dokončen. Log: ${LOG}"
      echo "[watcher] OK"
    else
      STATUS="failed"
      MESSAGE="Upgrade selhal. Log: ${LOG}"
      echo "[watcher] FAILED. Viz ${LOG}"
    fi

    APPLIED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    # Escape pro JSON — message obsahuje cesty (/), ne ale uvozovky.
    SAFE_MSG="$(printf '%s' "$MESSAGE" | sed 's/\\/\\\\/g; s/"/\\"/g')"
    printf '{"status":"%s","target_version":"%s","applied_at":"%s","message":"%s"}\n' \
      "$STATUS" "$TARGET" "$APPLIED_AT" "$SAFE_MSG" > "$RESULT" \
      || echo "[watcher] WARN: nelze zapsat ${RESULT}"
    rm -f "$INFLIGHT" 2>/dev/null || true
  fi
  sleep "$INTERVAL_S"
done
