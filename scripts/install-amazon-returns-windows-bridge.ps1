param(
    [string]$WorkerSource = "$PSScriptRoot\amazon-returns\seller-central-bridge-worker.mjs",
    [string]$ReadWorkerSource = "$PSScriptRoot\amazon-returns\seller-central-safe-t-read-worker.mjs",
    [string]$AuthSource = "$PSScriptRoot\amazon-returns\seller-central-auth.mjs",
    [string]$TrackingEvidenceSource = "$PSScriptRoot\amazon-returns\TrackingEvidence.mjs",
    [string]$StatusParserSource = "$PSScriptRoot\amazon-returns\safe-t-status-parser.mjs",
    [string]$InstallDir = 'C:\ShopVivaliz\amazon-returns-bridge',
    [string]$TaskName = 'ShopVivaliz Amazon Returns Browser Dispatcher',
    [string]$BridgeEndpoint = 'https://returns.shopvivaliz.com.br/api/amazon-returns/bridge.php',
    [string]$StatusBridgeEndpoint = 'https://returns.shopvivaliz.com.br/api/amazon-returns/status-bridge.php',
    [string]$UsernameFile = '',
    [string]$PasswordFile = '',
    [string]$TotpHost = '',
    [string]$TotpKeyFile = '',
    [string]$TotpKnownHostsFile = '',
    [int]$PollMinutes = 1440,
    [string]$OperaPath = '',
    [string]$ProfilePath = ''
)

$ErrorActionPreference = 'Stop'
if ($PollMinutes -ne 1440) { throw 'PollMinutes must be 1440 to preserve the approved daily browser cadence.' }
$node = (Get-Command node.exe -ErrorAction Stop).Source
$ssh = (Get-Command ssh.exe -ErrorAction Stop).Source
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
$authHelper = Join-Path $InstallDir 'seller-central-auth.mjs'
$trackingEvidence = Join-Path $InstallDir 'TrackingEvidence.mjs'
$statusParser = Join-Path $InstallDir 'safe-t-status-parser.mjs'
$logDir = Join-Path $InstallDir 'logs'
$evidenceDir = Join-Path $InstallDir 'evidence'
if ([string]::IsNullOrWhiteSpace($UsernameFile)) { $UsernameFile = Join-Path $InstallDir 'amazon.username' }
if ([string]::IsNullOrWhiteSpace($PasswordFile)) { $PasswordFile = Join-Path $InstallDir 'amazon.password' }
if ([string]::IsNullOrWhiteSpace($TotpKeyFile)) { $TotpKeyFile = Join-Path $InstallDir 'totp_ed25519' }
if ([string]::IsNullOrWhiteSpace($TotpKnownHostsFile)) { $TotpKnownHostsFile = Join-Path $InstallDir 'totp_known_hosts' }
if ([string]::IsNullOrWhiteSpace($TotpHost)) { throw 'TotpHost is required.' }

foreach ($required in @($WorkerSource, $ReadWorkerSource, $AuthSource, $TrackingEvidenceSource, $StatusParserSource, $opera, $profile, $token, $UsernameFile, $PasswordFile, $TotpKeyFile, $TotpKnownHostsFile)) {
    if (-not (Test-Path $required)) { throw "Required bridge dependency missing: $required" }
}
New-Item -ItemType Directory -Force $InstallDir, $logDir, $evidenceDir | Out-Null
Copy-Item -Force $WorkerSource $worker
Copy-Item -Force $ReadWorkerSource $readWorker
Copy-Item -Force $AuthSource $authHelper
Copy-Item -Force $TrackingEvidenceSource $trackingEvidence
Copy-Item -Force $StatusParserSource $statusParser
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
function Protect-SecretReadFile([string]$Path) {
    $acl = Get-Acl $Path
    $acl.SetAccessRuleProtection($true, $false)
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentUser, 'Read', 'Allow')
    $acl.SetAccessRule($rule)
    Set-Acl -Path $Path -AclObject $acl
}
Protect-SecretReadFile $token
Protect-SecretReadFile $UsernameFile
Protect-SecretReadFile $PasswordFile
Protect-SecretReadFile $TotpKeyFile
Protect-SecretReadFile $TotpKnownHostsFile

function Escape-PowerShellSingleQuoted([string]$Value) { return $Value.Replace("'", "''") }
$safeBridgeEndpoint=Escape-PowerShellSingleQuoted $BridgeEndpoint
$safeStatusBridgeEndpoint=Escape-PowerShellSingleQuoted $StatusBridgeEndpoint
$safeToken=Escape-PowerShellSingleQuoted $token
$safeProfile=Escape-PowerShellSingleQuoted $profile
$safeOpera=Escape-PowerShellSingleQuoted $opera
$safeEvidenceDir=Escape-PowerShellSingleQuoted $evidenceDir
$safeUsernameFile=Escape-PowerShellSingleQuoted $UsernameFile
$safePasswordFile=Escape-PowerShellSingleQuoted $PasswordFile
$safeTotpHost=Escape-PowerShellSingleQuoted $TotpHost
$safeTotpKeyFile=Escape-PowerShellSingleQuoted $TotpKeyFile
$safeTotpKnownHostsFile=Escape-PowerShellSingleQuoted $TotpKnownHostsFile
$safeSsh=Escape-PowerShellSingleQuoted $ssh
$safeInstallDir=Escape-PowerShellSingleQuoted $InstallDir
$safeNode=Escape-PowerShellSingleQuoted $node
$safeReadWorker=Escape-PowerShellSingleQuoted $readWorker
$safeWorker=Escape-PowerShellSingleQuoted $worker
$safeLogDir=Escape-PowerShellSingleQuoted $logDir

$runner = Join-Path $InstallDir 'run-browser-dispatcher.ps1'
$runnerBody = @"
`$ErrorActionPreference = 'Stop'
`$env:SELLER_CENTRAL_BRIDGE_ENDPOINT = '$safeBridgeEndpoint'
`$env:SELLER_CENTRAL_STATUS_BRIDGE_ENDPOINT = '$safeStatusBridgeEndpoint'
`$env:SELLER_CENTRAL_BRIDGE_TOKEN_FILE = '$safeToken'
`$env:SELLER_CENTRAL_PROFILE = '$safeProfile'
`$env:SELLER_CENTRAL_OPERA = '$safeOpera'
`$env:SELLER_CENTRAL_CDP_URL = 'http://127.0.0.1:9225'
`$env:SELLER_CENTRAL_STATUS_LOCK_PORT = '19225'
`$env:SELLER_CENTRAL_EVIDENCE_DIR = '$safeEvidenceDir'
`$env:SELLER_CENTRAL_USERNAME_FILE = '$safeUsernameFile'
`$env:SELLER_CENTRAL_PASSWORD_FILE = '$safePasswordFile'
`$env:SELLER_CENTRAL_TOTP_HOST = '$safeTotpHost'
`$env:SELLER_CENTRAL_TOTP_KEY_FILE = '$safeTotpKeyFile'
`$env:SELLER_CENTRAL_TOTP_KNOWN_HOSTS_FILE = '$safeTotpKnownHostsFile'
`$env:SELLER_CENTRAL_TOTP_SSH_BINARY = '$safeSsh'

function Stop-SellerCentralBrowser {
    `$roots = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        `$_.Name -eq 'opera.exe' -and
        `$_.CommandLine -like '*--remote-debugging-port=9225*' -and
        `$_.CommandLine -like '*--user-data-dir=$safeProfile*'
    })
    foreach (`$process in `$roots) {
        & "`$env:SystemRoot\System32\taskkill.exe" /PID `$process.ProcessId /T /F *> `$null
    }
    `$runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
    Remove-ItemProperty -Path `$runKey -Name 'Opera Developer' -ErrorAction SilentlyContinue
}

try {
    Set-Location '$safeInstallDir'
    & '$safeNode' '$safeReadWorker' --heartbeat *>> '$safeLogDir\safe-t-read-bridge.log'
    if (`$LASTEXITCODE -ne 0) { Write-Warning "SAFE-T read heartbeat failed with exit code `$LASTEXITCODE; continuing dispatcher" }
    & '$safeNode' '$safeReadWorker' --auth-check *>> '$safeLogDir\safe-t-read-bridge.log'
    if (`$LASTEXITCODE -ne 0) { Write-Warning "Seller Central auth check failed with exit code `$LASTEXITCODE; continuing drain" }
    & '$safeNode' '$safeReadWorker' --drain *>> '$safeLogDir\safe-t-read-bridge.log'
    if (`$LASTEXITCODE -ne 0) { throw "SAFE-T read worker failed with exit code `$LASTEXITCODE" }
    & '$safeNode' '$safeWorker' --drain *>> '$safeLogDir\bridge.log'
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
Remove-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'Opera Developer' -ErrorAction SilentlyContinue

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
Write-Output 'EXECUTION_MODE=ON_DEMAND_DRAIN'
Write-Output "TASK_NAME=$TaskName"
Write-Output "POLL_MINUTES=$PollMinutes"
Write-Output "BRIDGE_HOST=$env:COMPUTERNAME"
Write-Output "OPERA_PATH=$opera"
