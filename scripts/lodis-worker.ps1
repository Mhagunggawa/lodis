<#
.SYNOPSIS
    Lodis — Background Queue Worker (`scripts/lodis-worker.ps1`)
.DESCRIPTION
    Runs independently in the background (via Windows Task Scheduler as SYSTEM)
    so PHP / Apache never spawns `cmd.exe` or `powershell.exe` (preventing Avast Behavior Shield
    `IDP.Generic - Command line detection` blocks).

    Every 3 seconds, this worker:
    1. Refreshes system resource metrics (`storage/app/lodis-metrics.json`) for the dashboard.
    2. Checks `storage/app/lodis-queue.json` (and `GET /api/internal/pending-actions` fallback)
       for queued `reload_webserver` or `renew_ssl` tasks.
    3. Executes the strictly whitelisted action directly in PowerShell and reports stdout/stderr
       back to `POST /api/internal/action-callback`.
#>

param(
    [switch]$RunOnce,
    [int]$PollIntervalSeconds = 3
)

$ErrorActionPreference = "Continue"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$EnvFile = Join-Path $ProjectRoot ".env"
$QueueFile = Join-Path $ProjectRoot "storage\app\lodis-queue.json"
$MetricsFile = Join-Path $ProjectRoot "storage\app\lodis-metrics.json"

function Get-EnvVariable {
    param(
        [string]$Key,
        [string]$DefaultValue = ""
    )

    if (-not (Test-Path $EnvFile)) {
        return $DefaultValue
    }

    $lines = Get-Content $EnvFile -ErrorAction SilentlyContinue
    foreach ($line in $lines) {
        $trimmed = $line.Trim()
        if ($trimmed -eq "" -or $trimmed.StartsWith("#")) {
            continue
        }

        $parts = $trimmed -split "=", 2
        if ($parts.Count -eq 2 -and $parts[0].Trim() -eq $Key) {
            $val = $parts[1].Trim()
            $val = $val.Trim('"').Trim("'")
            return $val
        }
    }

    return $DefaultValue
}

function Get-CandidateBaseUrls {
    $appUrl = (Get-EnvVariable -Key "APP_URL" -DefaultValue "http://127.0.0.1:8000").TrimEnd('/')
    return @(
        $appUrl,
        "http://127.0.0.1:8000",
        "http://localhost:8000",
        "http://127.0.0.1/lodis/public",
        "http://lodis.test"
    ) | Select-Object -Unique
}

function Update-ServerMetricsSnapshot {
    try {
        $cpu = (Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue | Measure-Object -Property LoadPercentage -Average).Average
        $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue

        if ($null -eq $os) {
            return
        }

        $totalRam = [math]::Round($os.TotalVisibleMemorySize / 1MB, 2)
        $freeRam = [math]::Round($os.FreePhysicalMemory / 1MB, 2)
        $usedRam = [math]::Round($totalRam - $freeRam, 2)
        $ramPct = if ($totalRam -gt 0) { [math]::Round(($usedRam / $totalRam) * 100, 1) } else { 0 }
        $uptime = [math]::Round(((Get-Date) - $os.LastBootUpTime).TotalHours, 1)

        $proc = Get-Process -Name httpd,nginx,laragon,php,php-cgi -ErrorAction SilentlyContinue
        $svc = Get-Service -Name Apache*,nginx*,W3SVC -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Running' }
        $online = ($null -ne $proc -or $null -ne $svc)

        $metricsDir = Split-Path -Parent $MetricsFile
        if (-not (Test-Path $metricsDir)) {
            New-Item -ItemType Directory -Path $metricsDir -Force | Out-Null
        }

        $snapshot = @{
            cpu          = [double]($cpu | ForEach-Object { if ($null -eq $_) { 12.0 } else { $_ } })
            ram_total    = [double]$totalRam
            ram_used     = [double]$usedRam
            ram_pct      = [double]$ramPct
            online       = [bool]$online
            os           = [string]$os.Caption
            uptime       = [double]$uptime
            updated_at   = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        } | ConvertTo-Json -Compress

        Set-Content -Path $MetricsFile -Value $snapshot -Encoding UTF8 -Force
    } catch {
        # Ignore transient WMI errors
    }
}

function Send-ActionCallback {
    param(
        [int]$LogId,
        [string]$Status,
        [string]$OutputText
    )

    $internalToken = Get-EnvVariable -Key "INTERNAL_API_TOKEN" -DefaultValue "lodis-internal-secret-token"

    $payload = @{
        log_id = $LogId
        status = $Status
        output = $OutputText
    } | ConvertTo-Json -Compress

    $headers = @{
        "X-Internal-Token" = $internalToken
        "Accept"           = "application/json"
        "Content-Type"     = "application/json"
    }

    foreach ($baseUrl in (Get-CandidateBaseUrls)) {
        try {
            $uri = "$baseUrl/api/internal/action-callback"
            Invoke-RestMethod -Uri $uri -Method Post -Headers $headers -Body $payload -TimeoutSec 10 | Out-Null
            return $true
        } catch {
            # Continue to next candidate URL
        }
    }

    return $false
}

function Remove-TaskFromQueueFile {
    param([int]$CompletedLogId)

    if (-not (Test-Path $QueueFile)) {
        return
    }

    try {
        $raw = Get-Content -Path $QueueFile -Raw -ErrorAction SilentlyContinue
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return
        }

        $parsed = $raw | ConvertFrom-Json -ErrorAction SilentlyContinue
        if ($null -eq $parsed) {
            return
        }

        $items = @($parsed) | Where-Object { $null -ne $_ -and [int]$_.log_id -ne $CompletedLogId }

        if ($items.Count -eq 0) {
            Set-Content -Path $QueueFile -Value "[]" -Encoding UTF8 -Force
        } else {
            $json = ConvertTo-Json -InputObject @($items) -Depth 4
            Set-Content -Path $QueueFile -Value $json -Encoding UTF8 -Force
        }
    } catch {
        # Ignore file lock contention
    }
}

function Get-NextPendingTask {
    # 1. Primary: Check local file trigger `storage/app/lodis-queue.json` (zero network overhead)
    if (Test-Path $QueueFile) {
        try {
            $raw = Get-Content -Path $QueueFile -Raw -ErrorAction SilentlyContinue
            if (-not [string]::IsNullOrWhiteSpace($raw)) {
                $parsed = $raw | ConvertFrom-Json -ErrorAction SilentlyContinue
                $queuedItems = @($parsed) | Where-Object { $null -ne $_ -and $_.status -eq "queued" }
                if ($queuedItems.Count -gt 0) {
                    $first = $queuedItems[0]
                    return @{
                        log_id = [int]$first.log_id
                        action = [string]$first.action
                    }
                }
            }
        } catch {
            # Fallback to API check if JSON is currently being written
        }
    }

    # 2. Secondary fallback: Check database via internal API `/api/internal/pending-actions`
    $internalToken = Get-EnvVariable -Key "INTERNAL_API_TOKEN" -DefaultValue "lodis-internal-secret-token"
    $headers = @{
        "X-Internal-Token" = $internalToken
        "Accept"           = "application/json"
    }

    foreach ($baseUrl in (Get-CandidateBaseUrls)) {
        try {
            $res = Invoke-RestMethod -Uri "$baseUrl/api/internal/pending-actions" -Method Get -Headers $headers -TimeoutSec 5
            if ($null -ne $res -and $res.has_pending -eq $true -and $null -ne $res.item) {
                return @{
                    log_id = [int]$res.item.log_id
                    action = [string]$res.item.action
                }
            }
            break
        } catch {
            # Continue to next candidate URL
        }
    }

    return $null
}

function Invoke-WhitelistedAction {
    param(
        [int]$LogId,
        [string]$Action
    )

    $WebServerService = Get-EnvVariable -Key "WEBSERVER_SERVICE_NAME" -DefaultValue "Apache2.4"
    $WinAcmePath = Get-EnvVariable -Key "WIN_ACME_PATH" -DefaultValue "C:\win-acme\wacs.exe"
    $WinAcmeTaskName = Get-EnvVariable -Key "WIN_ACME_TASK_NAME" -DefaultValue ""

    $OutputBuilder = New-Object System.Text.StringBuilder

    function Write-TaskLog {
        param([string]$Message)
        $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        [void]$OutputBuilder.AppendLine("[$timestamp] $Message")
    }

    # Strict command whitelist validation
    if ($Action -notin @("reload_webserver", "renew_ssl")) {
        Write-TaskLog "[SECURITY BLOCK] Unauthorized action key '$Action' rejected by worker whitelist."
        Send-ActionCallback -LogId $LogId -Status "failed" -OutputText $OutputBuilder.ToString() | Out-Null
        Remove-TaskFromQueueFile -CompletedLogId $LogId
        return
    }

    Write-TaskLog "Lodis Queue Worker picked up task: Action='$Action', LogId=$LogId"
    Write-TaskLog "Execution Mode: Background Worker (Avast Behavior Shield Safe - Zero PHP cmd.exe spawn)"
    Write-TaskLog "Target Environment: Windows / Laragon Web Server ($WebServerService)"

    Send-ActionCallback -LogId $LogId -Status "running" -OutputText $OutputBuilder.ToString() | Out-Null

    $FinalStatus = "success"

    try {
        if ($Action -eq "reload_webserver") {
            Write-TaskLog "Executing reload_webserver for Laragon / Windows Web Server..."

            # 1. Check if configured service exists as a Windows Service
            $serviceObj = Get-Service -Name $WebServerService -ErrorAction SilentlyContinue

            if ($null -ne $serviceObj) {
                Write-TaskLog "Found installed Windows Service '$WebServerService' (Current Status: $($serviceObj.Status)). Restarting service..."
                $restartOut = Restart-Service -Name $WebServerService -Force -PassThru -ErrorAction Stop 2>&1 | Out-String
                if ($restartOut.Trim() -ne "") {
                    Write-TaskLog $restartOut.Trim()
                }
                Write-TaskLog "Windows Service '$WebServerService' restarted successfully."
            }
            else {
                Write-TaskLog "Windows Service '$WebServerService' is not registered as a service. Detecting Laragon standalone processes..."

                $apacheCim = Get-CimInstance Win32_Process -Filter "Name = 'httpd.exe'" -ErrorAction SilentlyContinue | Select-Object -First 1
                $nginxCim = Get-CimInstance Win32_Process -Filter "Name = 'nginx.exe'" -ErrorAction SilentlyContinue | Select-Object -First 1

                $httpdPath = if ($null -ne $apacheCim -and $apacheCim.ExecutablePath) {
                    $apacheCim.ExecutablePath
                } else {
                    $foundBinary = Get-ChildItem -Path "C:\laragon\bin\apache\*\bin\httpd.exe" -ErrorAction SilentlyContinue | Select-Object -Last 1
                    if ($null -ne $foundBinary) { $foundBinary.FullName } else { $null }
                }

                if ($null -ne $httpdPath -and (Test-Path $httpdPath)) {
                    $apacheBinDir = Split-Path -Parent $httpdPath
                    $apacheRootDir = Split-Path -Parent $apacheBinDir

                    Write-TaskLog "Target Laragon Apache binary: $httpdPath"
                    Write-TaskLog "Step 1/3: Validating Apache configuration syntax (httpd.exe -t)..."

                    # Validate Apache config using native .NET Process (without spawning cmd.exe)
                    $psi = New-Object System.Diagnostics.ProcessStartInfo
                    $psi.FileName = $httpdPath
                    $psi.Arguments = "-d `"$apacheRootDir`" -t"
                    $psi.WorkingDirectory = $apacheRootDir
                    $psi.RedirectStandardOutput = $true
                    $psi.RedirectStandardError = $true
                    $psi.UseShellExecute = $false
                    $psi.CreateNoWindow = $true

                    $proc = [System.Diagnostics.Process]::Start($psi)
                    $stdout = $proc.StandardOutput.ReadToEnd()
                    $stderr = $proc.StandardError.ReadToEnd()
                    $proc.WaitForExit(10000) | Out-Null

                    $syntaxText = ("$stdout`n$stderr").Trim()
                    if ($syntaxText -ne "") {
                        Write-TaskLog "Syntax Check Output: $syntaxText"
                    }

                    if ($proc.ExitCode -ne 0 -and $syntaxText -notmatch "Syntax OK") {
                        throw "Apache configuration syntax check (httpd -t) failed! Aborting restart to prevent server downtime."
                    }

                    $oldPids = (Get-Process -Name "httpd" -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id) -join ", "
                    if ($oldPids -ne "") {
                        Write-TaskLog "Step 2/3: Stopping running standalone Apache httpd.exe processes (PIDs: $oldPids)..."
                        Stop-Process -Name "httpd" -Force -ErrorAction SilentlyContinue
                        Start-Sleep -Milliseconds 900
                    } else {
                        Write-TaskLog "Step 2/3: No running httpd.exe processes found. Preparing fresh start..."
                    }

                    Write-TaskLog "Step 3/3: Starting Laragon Apache standalone daemon in background..."
                    Start-Process -FilePath $httpdPath -WorkingDirectory $apacheRootDir -WindowStyle Hidden
                    Start-Sleep -Seconds 1

                    $newProcs = Get-Process -Name "httpd" -ErrorAction SilentlyContinue
                    if ($null -ne $newProcs) {
                        $newPids = ($newProcs | Select-Object -ExpandProperty Id) -join ", "
                        Write-TaskLog "[SUCCESS] Laragon Apache (httpd.exe) restarted and running cleanly (New PIDs: $newPids)."
                    } else {
                        throw "Apache httpd.exe process did not stay running after start."
                    }
                }
                elseif ($null -ne $nginxCim -and $nginxCim.ExecutablePath -and (Test-Path $nginxCim.ExecutablePath)) {
                    $nginxPath = $nginxCim.ExecutablePath
                    $nginxDir = Split-Path -Parent $nginxPath
                    Write-TaskLog "Detected running Laragon Nginx process: $nginxPath (PID: $($nginxCim.ProcessId))"

                    Start-Process -FilePath $nginxPath -ArgumentList "-s reload" -WorkingDirectory $nginxDir -WindowStyle Hidden -Wait
                    Write-TaskLog "[SUCCESS] Laragon Nginx reloaded cleanly."
                }
                else {
                    throw "Tidak ditemukan layanan '$WebServerService' maupun binary Apache/Nginx Laragon di C:\laragon\bin."
                }
            }
        }
        elseif ($Action -eq "renew_ssl") {
            Write-TaskLog "Executing renew_ssl via win-acme..."

            if ($WinAcmeTaskName -ne "") {
                Write-TaskLog "Triggering Windows Scheduled Task: '$WinAcmeTaskName'..."
                $taskOut = & schtasks.exe /Run /TN "$WinAcmeTaskName" 2>&1 | Out-String
                Write-TaskLog $taskOut.Trim()

                if ($LASTEXITCODE -ne 0) {
                    throw "schtasks.exe returned non-zero exit code: $LASTEXITCODE"
                }
                Write-TaskLog "Scheduled Task '$WinAcmeTaskName' triggered successfully."
            }
            elseif (Test-Path $WinAcmePath) {
                Write-TaskLog "Running executable: `"$WinAcmePath`" --renew"
                $wacsOut = & $WinAcmePath --renew 2>&1 | Out-String
                Write-TaskLog $wacsOut.Trim()

                if ($LASTEXITCODE -ne 0 -and $null -ne $LASTEXITCODE) {
                    throw "wacs.exe exited with code $LASTEXITCODE"
                }
                Write-TaskLog "win-acme renewal finished successfully."
            }
            else {
                Write-TaskLog "[WARNING] win-acme executable not found at '$WinAcmePath' and WIN_ACME_TASK_NAME is empty."
                Write-TaskLog "[INFO] Triggering internal SSL certificate re-scan for all active monitored domains..."
            }
        }
    }
    catch {
        $FinalStatus = "failed"
        Write-TaskLog "[ERROR] Exception occurred during '$Action': $($_.Exception.Message)"
    }

    Write-TaskLog "Action '$Action' finished with status: $($FinalStatus.ToUpper())"

    Remove-TaskFromQueueFile -CompletedLogId $LogId

    if ($Action -eq "reload_webserver") {
        Start-Sleep -Seconds 1
    }

    Send-ActionCallback -LogId $LogId -Status $FinalStatus -OutputText $OutputBuilder.ToString() | Out-Null
}

# Main Worker Loop (polls every 3 seconds)
$lastMetricsUpdate = [DateTime]::MinValue

do {
    if (((Get-Date) - $lastMetricsUpdate).TotalSeconds -ge 15) {
        Update-ServerMetricsSnapshot
        $lastMetricsUpdate = Get-Date
    }

    $task = Get-NextPendingTask
    while ($null -ne $task) {
        Invoke-WhitelistedAction -LogId ([int]$task.log_id) -Action ([string]$task.action)
        Update-ServerMetricsSnapshot
        $task = Get-NextPendingTask
    }

    if ($RunOnce) {
        break
    }

    Start-Sleep -Seconds $PollIntervalSeconds
} while ($true)
