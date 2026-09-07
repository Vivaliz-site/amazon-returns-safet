param(
    [string]$WorkerSource = "$PSScriptRoot\amazon-returns\seller-central-bridge-worker.mjs",
    [string]$ReadWorkerSource = "$PSScriptRoot\amazon-returns\seller-central-safe-t-read-worker.mjs",
    [string]$TrackingEvidenceSource = "$PSScriptRoot\amazon-returns\TrackingEvidence.mjs",
    [string]$StatusParserSource = "$PSScriptRoot\amazon-returns\safe-t-status-parser.mjs",
    [string]$InstallDir = 'C:\ShopVivaliz\amazon-returns-bridge',
    [string]$TaskName = 'ShopVivaliz Amazon Returns Browser Dispatcher',
    [string]$BridgeEndpoint = 'https://returns.shopvivaliz.com.br/api/amazon-returns/bridge.php',
    [string]$StatusBridgeEndpoint = 'https://returns.shopvivaliz.com.br/api/amazon-returns/status-bridge.php',
    [int]$PollMinutes = 1,
    [string]$OperaPath = '',
    [string]$ProfilePath = ''
)

$ErrorActionPreference = 'Stop'
if ($PollMinutes -lt 1 -or $PollMinutes -gt 60) { throw 'PollMinutes must be between 1 and 60.' }
$node = (Get-Command node.exe -ErrorAction Stop).Source
if ([string]::IsNullOrWhiteSpace($OperaPath)) {
    $OperaPath = Join-Path $env:LOCALAPPDATA 'Programs\Opera developer\opera.exe'
    if (-not (Test-Path $OperaPath)) {
        $OperaPath = Join-Path $env:LOCALAPPDATA 'Programs\Opera\opera.exe'
    }
}
if ([string]::IsNullOrWhiteSpace($ProfilePath)) {
    $ProfilePath = Join-Path $InstallDir 'profile'
}
$opera = $OperaPath
$profile = $ProfilePath
$token = Join-Path $InstallDir 'bridge.token'
$worker = Join-Path $InstallDir 'seller-central-bridge-worker.mjs'
$readWorker = Join-Path $InstallDir 'seller-central-safe-t-read-worker.mjs'
$trackingEvidence = Join-Path $InstallDir 'TrackingEvidence.mjs'
$statusParser = Join-Path $InstallDir 'safe-t-status-parser.mjs'
$logDir = Join-Path $InstallDir 'logs'
$evidenceDir = Join-Path $InstallDir 'evidence'

foreach ($required in @($WorkerSource, $ReadWorkerSource, $TrackingEvidenceSource, $StatusParserSource, $opera, $profile, $token)) {
    if (-not (Test-Path $required)) { throw "Required bridge dependency missing: $required" }
}
New-Item -ItemType Directory -Force $InstallDir, $logDir, $evidenceDir | Out-Null
Copy-Item -Force $WorkerSource $worker
Copy-Item -Force $ReadWorkerSource $readWorker
Copy-Item -Force $TrackingEvidenceSource $trackingEvidence
Copy-Item -Force $StatusParserSource $statusParser
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$tokenAcl = Get-Acl $token
$tokenAcl.SetAccessRuleProtection($true, $false)
$tokenRule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentUser, 'Read', 'Allow')
$tokenAcl.SetAccessRule($tokenRule)
Set-Acl -Path $token -AclObject $tokenAcl

$runner = Join-Path $InstallDir 'run-browser-dispatcher.ps1'
$runnerBody = @"
`$ErrorActionPreference = 'Stop'
`$env:SELLER_CENTRAL_BRIDGE_ENDPOINT = '$BridgeEndpoint'
`$env:SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT = '$StatusBridgeEndpoint'
`$env:SELLER_CENTRAL_BRIDGE_TOKEN_FILE = '$token'
`$env:SELLER_CENTRAL_PROFILE = '$profile'
`$env:SELLER_CENTRAL_OPERA = '$opera'
`$env:SELLER_CENTRAL_CDP_URL = 'http://127.0.0.1:9225'
`$env:SELLER_CENTRAL_STATUS_LOCK_PORT = '19225'
`$env:SELLER_CENTRAL_EVIDENCE_DIR = '$evidenceDir'

function Stop-SellerCentralBrowser {
    `$roots = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        `$_.Name -eq 'opera.exe' -and
        `$_.CommandLine -like '*--remote-debugging-port=9225*' -and
        `$_.CommandLine -like '*--user-data-dir=$profile*'
    })
    foreach (`$process in `$roots) {
        & "`$env:SystemRoot\System32\taskkill.exe" /PID `$process.ProcessId /T /F *> `$null
    }
}

try {
    Set-Location '$InstallDir'
    & '$node' '$readWorker' --drain *>> '$logDir\safe-t-read-bridge.log'
    if (`$LASTEXITCODE -ne 0) { throw "SAFE-T read worker failed with exit code `$LASTEXITCODE" }
    & '$node' '$worker' --drain *>> '$logDir\bridge.log'
    if (`$LASTEXITCODE -ne 0) { throw "Seller Central write worker failed with exit code `$LASTEXITCODE" }
}
finally {
    Stop-SellerCentralBrowser
}
"@
[IO.File]::WriteAllText($runner, $runnerBody, [Text.UTF8Encoding]::new($false))

function Stop-LegacyBridgeProcesses {
    $legacyWorkers = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'node.exe' -and ($_.CommandLine -like "*$worker*" -or $_.CommandLine -like "*$readWorker*")
    })
    foreach ($process in $legacyWorkers) {
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }

    $operaRoots = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'opera.exe' -and
        $_.CommandLine -like '*--remote-debugging-port=9225*' -and
        $_.CommandLine -like "*--user-data-dir=$profile*"
    })
    foreach ($process in $operaRoots) {
        & "$env:SystemRoot\System32\taskkill.exe" /PID $process.ProcessId /T /F *> $null
    }
}

$legacyTasks = @(
    'ShopVivaliz Amazon Returns SAFE-T Read Bridge',
    'ShopVivaliz Amazon Returns Seller Central Bridge'
)
foreach ($legacyTask in $legacyTasks) {
    $existing = Get-ScheduledTask -TaskName $legacyTask -ErrorAction SilentlyContinue
    if ($existing) {
        Stop-ScheduledTask -TaskName $legacyTask -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $legacyTask -Confirm:$false
    }
}
$existingDispatcher = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existingDispatcher) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
}
Stop-LegacyBridgeProcesses

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$runner`""
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $PollMinutes) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType S4U -RunLevel Highest
$task = New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Principal $principal
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Output 'AMAZON_RETURNS_WINDOWS_BRIDGE_INSTALLED=true'
Write-Output 'EXECUTION_MODE=ON_DEMAND_ONCE'
Write-Output "TASK_NAME=$TaskName"
Write-Output "POLL_MINUTES=$PollMinutes"
Write-Output "BRIDGE_HOST=$env:COMPUTERNAME"
Write-Output "OPERA_PATH=$opera"
