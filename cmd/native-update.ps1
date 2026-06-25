# MyInvoice.cz — native (non-Docker) update. Windows / PowerShell varianta.
#
# Aktualizuje source/git nativni instalaci na cilovy release tag:
#   1. git fetch --tags  +  checkout v<TARGET>
#   2. composer install --no-dev   (api/vendor)
#   3. pnpm install + build         (web/dist)
#   4. regenerace manualu           (manual/generated + manual.pdf)
#   5. php api/bin/migrate.php       (pending migrace)
#
# Spoustet jako uzivatel, ktery VLASTNI repo a ma v PATH git/php/composer/pnpm.
# Cilovou verzi predej -TargetVersion (napr. 4.40.1). Bez nej se vezme nejvyssi tag.
#
# Idempotent — opakovane spusteni je bezpecne. Config ani data nemaze.
[CmdletBinding()]
param([string]$TargetVersion = '')

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

# --- preflight: nastroje + git checkout ----------------------------------
foreach ($cmd in 'git', 'php', 'composer', 'pnpm') {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        Write-Error "'$cmd' neni v PATH. Pri behu pod Scheduled Task over, ze ucet ma nastroj v PATH."
    }
}
if (-not (Test-Path .git)) {
    Write-Error "$ProjectRoot neni git checkout — nativni auto-update vyzaduje git instalaci. Pro tarball pouzij production bundle (manual § 38.6)."
}

Write-Host "==> git fetch --tags"
& git fetch --tags --force
if ($LASTEXITCODE -ne 0) { Write-Error "git fetch selhal" }

# Cilovy tag — z parametru, jinak nejvyssi vX.Y.Z.
$target = $TargetVersion.TrimStart('v')
if (-not $target) {
    $tags = & git tag -l 'v*'
    $target = $tags | ForEach-Object { $_.TrimStart('v') } |
        Where-Object { $_ -match '^\d+\.\d+\.\d+' } |
        Sort-Object { [version]($_ -replace '[^0-9.].*$', '') } |
        Select-Object -Last 1
    if (-not $target) { Write-Error "nenalezen zadny verzovy tag (vX.Y.Z)." }
}
$tag = "v$target"

& git rev-parse -q --verify "refs/tags/$tag" *> $null
if ($LASTEXITCODE -ne 0) { Write-Error "tag $tag po fetchi neexistuje." }

$dirty = & git status --porcelain --untracked-files=no
if ($dirty) {
    Write-Warning "Necommitnute zmeny ve verzovanych souborech budou checkoutem zahozeny:"
    Write-Host $dirty
}

Write-Host "==> git checkout $tag"
& git checkout -f $tag
if ($LASTEXITCODE -ne 0) { Write-Error "git checkout selhal" }

Write-Host "==> composer install --no-dev (api)"
Push-Location api
& composer install --no-dev --no-interaction --prefer-dist
$rc = $LASTEXITCODE
Pop-Location
if ($rc -ne 0) { Write-Error "composer install selhal" }

Write-Host "==> pnpm install + build (web)"
Push-Location web
& pnpm install
if ($LASTEXITCODE -ne 0) { Pop-Location; Write-Error "pnpm install selhal" }
& pnpm build
$rc = $LASTEXITCODE
Pop-Location
if ($rc -ne 0) { Write-Error "pnpm build selhal" }

Write-Host "==> regenerace manualu (html + pdf) — nefatalni"
# Manual je jen kosmeticky (gitignored vystup). Selhani (typicky mpdf/fonty)
# nesmi zablokovat migraci niz — jinak by zustal novy kod + stara DB schema.
& php tools/generateManualHtml.php
if ($LASTEXITCODE -ne 0) { Write-Warning "generateManualHtml selhal — pokracuji (HTML manualu muze byt neaktualni)" }
& php tools/exportManualToPdf.php
if ($LASTEXITCODE -ne 0) { Write-Warning "exportManualToPdf selhal — pokracuji (PDF manualu muze byt neaktualni)" }

Write-Host "==> php api/bin/migrate.php"
& php api/bin/migrate.php
if ($LASTEXITCODE -ne 0) { Write-Error "migrate selhal" }

Write-Host ""
Write-Host "============================================================"
Write-Host " Nativni update na $tag dokoncen."
Write-Host "============================================================"
