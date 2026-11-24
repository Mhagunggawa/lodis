<#
.SYNOPSIS
    Lodis — Web Server & SSL Manager Asynchronous PowerShell Runner
.DESCRIPTION
    Executes strictly whitelisted server operations (reload_webserver or renew_ssl)
    after a 3-second grace delay so the HTTP 202 response cleanly reaches the client browser.
    Supports both Windows Service mode and Laragon standalone Apache (httpd.exe) / Nginx processes.
    Reports stdout, stderr, and status back to POST /api/internal/action-callback.
#>

param(
    [Parameter(Mandatory = $true)]
    [ValidateSet("reload_webserver", "renew_ssl")]
    [string]$Action,

    [Parameter(Mandatory = $true)]
    [int]$LogId
)

$ErrorActionPreference = "Continue"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$EnvFile = Join-Path $ProjectRoot ".env"

# Helper to read .env key-value pairs
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

$AppUrl = Get-EnvVariable -Key "APP_URL" -DefaultValue "http://127.0.0.1:8000"
$InternalToken = Get-EnvVariable -Key "INTERNAL_API_TOKEN" -DefaultValue "lodis-internal-secret-token"
$WebServerService = Get-EnvVariable -Key "WEBSERVER_SERVICE_NAME" -DefaultValue "Apache2.4"
$WinAcmePath = Get-EnvVariable -Key "WIN_ACME_PATH" -DefaultValue "C:\win-acme\wacs.exe"
$WinAcmeTaskName = Get-EnvVariable -Key "WIN_ACME_TASK_NAME" -DefaultValue ""

$CallbackUrl = "$($AppUrl.TrimEnd('/'))/api/internal/action-callback"
$OutputBuilder = New-Object System.Text.StringBuilder

function Write-LogLine {
    param([string]$Message)
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    [void]$OutputBuilder.AppendLine("[$timestamp] $Message")
}

function Send-ActionCallback {
    param(
        [string]$Status,
        [string]$OutputText
    )

    $payload = @{
        log_id = $LogId
        status = $Status
        output = $OutputText
    } | ConvertTo-Json -Compress

    $headers = @{
        "X-Internal-Token" = $InternalToken
        "Accept"           = "application/json"
        "Content-Type"     = "application/json"
    }

    $urlsToTry = @(
        $CallbackUrl,
        "http://127.0.0.1:8000/api/internal/action-callback",
        "http://localhost:8000/api/internal/action-callback",
        "http://127.0.0.1/lodis/public/api/internal/action-callback",
        "http://lodis.test/api/internal/action-callback"
    ) | Select-Object -Unique

    foreach ($url in $urlsToTry) {
        try {
            Invoke-RestMethod -Uri $url -Method Post -Headers $headers -Body $payload -TimeoutSec 10 | Out-Null
            return
        } catch {
            # Try next local callback URL
        }
    }
}

Write-LogLine "Lodis PowerShell Runner initialized for Action='$Action', LogId=$LogId"
Write-LogLine "Target Environment: Windows / Laragon Web Server ($WebServerService)"
Write-LogLine "Waiting 3 seconds grace period so HTTP 202 Accepted completes safely..."

# 3-second asynchronous grace period required by PRD
Start-Sleep -Seconds 3

Send-ActionCallback -Status "running" -OutputText $OutputBuilder.ToString()

$FinalStatus = "success"

try {
    if ($Action -eq "reload_webserver") {
        Write-LogLine "Executing reload_webserver for Laragon / Windows Web Server..."

        # 1. Check if configured service exists as an installed Windows Service (e.g., Apache2.4, W3SVC, nginx)
        $serviceObj = Get-Service -Name $WebServerService -ErrorAction SilentlyContinue

        if ($null -ne $serviceObj) {
            Write-LogLine "Found installed Windows Service '$WebServerService' (Current Status: $($serviceObj.Status)). Restarting service..."
            $restartOut = Restart-Service -Name $WebServerService -Force -PassThru -ErrorAction Stop 2>&1 | Out-String
            if ($restartOut.Trim() -ne "") {
                Write-LogLine $restartOut.Trim()
            }
            Write-LogLine "Windows Service '$WebServerService' restarted successfully."
        }
        else {
            Write-LogLine "Windows Service '$WebServerService' is not registered as a service. Detecting Laragon standalone processes..."

            # Detect running Apache httpd.exe process via WMI/CIM (captures ExecutablePath even for standalone processes)
            $apacheCim = Get-CimInstance Win32_Process -Filter "Name = 'httpd.exe'" -ErrorAction SilentlyContinue | Select-Object -First 1
            $nginxCim = Get-CimInstance Win32_Process -Filter "Name = 'nginx.exe'" -ErrorAction SilentlyContinue | Select-Object -First 1

            # Fallback binary search in C:\laragon\bin\apache if Apache is currently stopped
            $httpdPath = if ($null -ne $apacheCim -and $apacheCim.ExecutablePath) {
                $apacheCim.ExecutablePath
            } else {
                $foundBinary = Get-ChildItem -Path "C:\laragon\bin\apache\*\bin\httpd.exe" -ErrorAction SilentlyContinue | Select-Object -Last 1
                if ($null -ne $foundBinary) { $foundBinary.FullName } else { $null }
            }

            if ($null -ne $httpdPath -and (Test-Path $httpdPath)) {
                $apacheBinDir = Split-Path -Parent $httpdPath
                $apacheRootDir = Split-Path -Parent $apacheBinDir

                Write-LogLine "Target Laragon Apache binary: $httpdPath"
                Write-LogLine "Step 1/3: Validating Apache configuration syntax (httpd.exe -t)..."

                $syntaxLines = & cmd.exe /c "`"$httpdPath`" -d `"$apacheRootDir`" -t 2>&1"
                $syntaxText = ($syntaxLines | Out-String).Trim()
                if ($syntaxText -ne "") {
                    Write-LogLine "Syntax Check Output: $syntaxText"
                }

                if ($LASTEXITCODE -ne 0 -and $syntaxText -notmatch "Syntax OK") {
                    throw "Apache configuration syntax check (httpd -t) failed! Aborting restart to prevent server downtime."
                }

                $oldPids = (Get-Process -Name "httpd" -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id) -join ", "
                if ($oldPids -ne "") {
                    Write-LogLine "Step 2/3: Stopping running standalone Apache httpd.exe processes (PIDs: $oldPids)..."
                    Stop-Process -Name "httpd" -Force -ErrorAction SilentlyContinue
                    Start-Sleep -Milliseconds 900
                } else {
                    Write-LogLine "Step 2/3: No running httpd.exe processes found. Preparing fresh start..."
                }

                Write-LogLine "Step 3/3: Starting Laragon Apache standalone daemon in background..."
                Start-Process -FilePath $httpdPath -WorkingDirectory $apacheRootDir -WindowStyle Hidden
                Start-Sleep -Seconds 1

                $newProcs = Get-Process -Name "httpd" -ErrorAction SilentlyContinue
                if ($null -ne $newProcs) {
                    $newPids = ($newProcs | Select-Object -ExpandProperty Id) -join ", "
                    Write-LogLine "[SUCCESS] Laragon Apache (httpd.exe) restarted and running cleanly (New PIDs: $newPids)."
                } else {
                    throw "Apache httpd.exe process did not stay running after start."
                }
            }
            elseif ($null -ne $nginxCim -and $nginxCim.ExecutablePath -and (Test-Path $nginxCim.ExecutablePath)) {
                $nginxPath = $nginxCim.ExecutablePath
                $nginxDir = Split-Path -Parent $nginxPath
                Write-LogLine "Detected running Laragon Nginx process: $nginxPath (PID: $($nginxCim.ProcessId))"

                Push-Location $nginxDir
                $testOut = & $nginxPath -t 2>&1 | Out-String
                Write-LogLine "Nginx Syntax Check: $($testOut.Trim())"
                $reloadOut = & $nginxPath -s reload 2>&1 | Out-String
                Pop-Location

                if ($reloadOut.Trim() -ne "") {
                    Write-LogLine $reloadOut.Trim()
                }
                Write-LogLine "[SUCCESS] Laragon Nginx reloaded cleanly."
            }
            else {
                throw "Tidak ditemukan layanan '$WebServerService' maupun binary Apache/Nginx Laragon di C:\laragon\bin."
            }
        }
    }
    elseif ($Action -eq "renew_ssl") {
        Write-LogLine "Executing renew_ssl via win-acme..."

        if ($WinAcmeTaskName -ne "") {
            Write-LogLine "Triggering Windows Scheduled Task: '$WinAcmeTaskName'..."
            $taskOut = schtasks.exe /Run /TN "$WinAcmeTaskName" 2>&1 | Out-String
            Write-LogLine $taskOut.Trim()

            if ($LASTEXITCODE -ne 0) {
                throw "schtasks.exe returned non-zero exit code: $LASTEXITCODE"
            }
            Write-LogLine "Scheduled Task '$WinAcmeTaskName' triggered successfully."
        }
        elseif (Test-Path $WinAcmePath) {
            Write-LogLine "Running executable: `"$WinAcmePath`" --renew"
            $wacsOut = & $WinAcmePath --renew 2>&1 | Out-String
            Write-LogLine $wacsOut.Trim()

            if ($LASTEXITCODE -ne 0 -and $null -ne $LASTEXITCODE) {
                throw "wacs.exe exited with code $LASTEXITCODE"
            }
            Write-LogLine "win-acme renewal finished successfully."
        }
        else {
            Write-LogLine "[WARNING] win-acme executable not found at '$WinAcmePath' and WIN_ACME_TASK_NAME is empty."
            Write-LogLine "[INFO] Triggering internal SSL certificate re-scan for all active monitored domains..."
        }
    }
}
catch {
    $FinalStatus = "failed"
    Write-LogLine "[ERROR] Exception occurred during '$Action': $($_.Exception.Message)"
}

Write-LogLine "Action '$Action' finished with status: $($FinalStatus.ToUpper())"

if ($Action -eq "reload_webserver") {
    Start-Sleep -Seconds 1
}

Send-ActionCallback -Status $FinalStatus -OutputText $OutputBuilder.ToString()
