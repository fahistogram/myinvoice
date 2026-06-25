# MyInvoice.cz — native upgrade watcher. Windows / PowerShell varianta.
#
# Sleduje storage\upgrade-requested.json na lokalnim filesystemu a kdyz ho UI
# vytvori (POST /api/admin/update/trigger), spusti cmd\native-update.ps1 a
# vysledek zapise do storage\upgrade-result.json. UI to zobrazi jako
# „aplikovano / selhalo".
#
# Spoustet jako ucet, ktery VLASTNI repo a ma v PATH git/php/composer/pnpm.
# Provoz: Scheduled Task (onstart) — viz manual § 38.6.
#
# Idempotent — flag se zpracuje jednou (rename na inflight pred spustenim).
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

$interval = if ($env:MYINVOICE_WATCHER_INTERVAL) { [int]$env:MYINVOICE_WATCHER_INTERVAL } else { 30 }
# Storage cesta — respektuj MYINVOICE_DATA_DIR, jinak <repo>\storage.
$storageDir = if ($env:MYINVOICE_DATA_DIR) { Join-Path $env:MYINVOICE_DATA_DIR 'storage' } else { Join-Path $ProjectRoot 'storage' }
$flag = Join-Path $storageDir 'upgrade-requested.json'
$inflight = Join-Path $storageDir 'upgrade-inflight.json'
$result = Join-Path $storageDir 'upgrade-result.json'

Write-Host "[watcher] start, polling $flag every ${interval}s"

while ($true) {
    if (Test-Path $flag) {
        $json = Get-Content $flag -Raw -ErrorAction SilentlyContinue
        $target = ''
        if ($json -match '"target_version"\s*:\s*"([^"]+)"') { $target = $Matches[1] }
        $shown = if ($target) { $target } else { 'latest' }
        Write-Host "[watcher] $([DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')) upgrade requested -> $shown"

        # Zamek proti double-triggeru — prejmenuj flag pred spustenim.
        Move-Item -Force $flag $inflight -ErrorAction SilentlyContinue

        $stamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')
        $log = Join-Path $storageDir "upgrade-$stamp.log"
        & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ProjectRoot 'cmd\native-update.ps1') -TargetVersion $target *> $log
        if ($LASTEXITCODE -eq 0) {
            $status = 'applied'
            $message = "Upgrade done. Log: $log"
            Write-Host "[watcher] OK"
        } else {
            $status = 'failed'
            $message = "Upgrade failed. Log: $log"
            Write-Host "[watcher] FAILED. See $log"
        }

        $appliedAt = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
        # Escape pro JSON — message obsahuje cesty (\), ne ale uvozovky.
        $safeMsg = $message -replace '\\', '\\' -replace '"', '\"'
        $resultJson = '{"status":"' + $status + '","target_version":"' + $target + '","applied_at":"' + $appliedAt + '","message":"' + $safeMsg + '"}'
        # WriteAllText = UTF-8 bez BOM (PHP json_decode by na BOM spadl).
        [System.IO.File]::WriteAllText($result, $resultJson)
        Remove-Item -Force $inflight -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds $interval
}
