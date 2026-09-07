param(
    [string]$WorkerSource = "$PSScriptRoot\amazon-returns\seller-central-bridge-worker.mjs",
    [string]$TrackingEvidenceSource = "$PSScriptRoot\amazon-returns\TrackingEvidence.mjs",
    [string]$InstallDir = 'C:\ShopVivaliz\amazon-returns-bridge',
    [string]$TaskName = 'ShopVivaliz Amazon Returns Seller Central Bridge',
    [string]$BridgeEndpoint = 'https://returns.shopvivaliz.com.br/api/amazon-returns/bridge.php',
    [string]$OperaPath = '',
    [string]$ProfilePath = ''
)

$ErrorActionPreference = 'Stop'
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
$trackingEvidence = Join-Path $InstallDir 'TrackingEvidence.mjs'
$logDir = Join-Path $InstallDir 'logs'
$evidenceDir = Join-Path $InstallDir 'evidence'

foreach ($required in @($WorkerSource, $TrackingEvidenceSource, $opera, $profile, $token)) {
    if (-not (Test-Path $required)) { throw "Required bridge dependency missing: $required" }
}
New-Item -ItemType Directory -Force $InstallDir, $logDir, $evidenceDir | Out-Null
Copy-Item -Force $WorkerSource $worker
Copy-Item -Force $TrackingEvidenceSource $trackingEvidence
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$tokenAcl = Get-Acl $token
$tokenAcl.SetAccessRuleProtection($true, $false)
$tokenRule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentUser, 'Read', 'Allow')
$tokenAcl.SetAccessRule($tokenRule)
Set-Acl -Path $token -AclObject $tokenAcl

$runner = Join-Path $InstallDir 'run-bridge.ps1'
$runnerBody = @"
`$ErrorActionPreference = 'Stop'
`$env:SELLER_CENTRAL_BRIDGE_ENDPOINT = '$BridgeEndpoint'
`$env:SELLER_CENTRAL_BRIDGE_TOKEN_FILE = '$token'
`$env:SELLER_CENTRAL_PROFILE = '$profile'
`$env:SELLER_CENTRAL_OPERA = '$opera'
`$env:SELLER_CENTRAL_EVIDENCE_DIR = '$evidenceDir'
Set-Location '$InstallDir'
& '$node' '$worker' *>> '$logDir\bridge.log'
exit `$LASTEXITCODE
"@
[IO.File]::WriteAllText($runner, $runnerBody, [Text.UTF8Encoding]::new($false))
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$runner`""
$triggers = @(
    (New-ScheduledTaskTrigger -AtStartup),
    (New-ScheduledTaskTrigger -AtLogOn -User $currentUser)
)
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType S4U -RunLevel Highest
$task = New-ScheduledTask -Action $action -Trigger $triggers -Settings $settings -Principal $principal
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Start-Sleep -Seconds 4
$state = (Get-ScheduledTask -TaskName $TaskName).State
$writerProcesses = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'node.exe' -and $_.CommandLine -like "*$worker*"
})
$writerProcessCount = $writerProcesses.Count
if ($state -ne 'Running') { throw "Bridge task failed to stay running: $state" }
if ($writerProcessCount -ne 1) { throw "Bridge writer process count invalid: $writerProcessCount" }
Write-Output "AMAZON_RETURNS_WINDOWS_BRIDGE_INSTALLED=true"
Write-Output "TASK_STATE=$state"
Write-Output "WRITER_PROCESS_COUNT=$writerProcessCount"
Write-Output "BRIDGE_HOST=$env:COMPUTERNAME"
Write-Output "OPERA_PATH=$opera"