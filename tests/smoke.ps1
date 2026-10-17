# Lolilota backend smoke test
# Usage: powershell -ExecutionPolicy Bypass -File tests\smoke.ps1 [-Base http://127.0.0.1:8787]
#
# This file intentionally contains ASCII only, so it parses correctly no matter
# which code page the local PowerShell uses. Chinese test labels live in
# tests/messages.json (read as UTF-8).

param(
    [string]$Base = 'http://127.0.0.1:8787',
    [string]$User = 'tester',
    [string]$Pass = 'Passw0rd!2024'
)

$ErrorActionPreference = 'Continue'
$script:okCount = 0
$script:failCount = 0

# ---- localized labels (UTF-8) ---------------------------------------------
$script:msg = @{}
$msgFile = Join-Path $PSScriptRoot 'messages.json'
if (Test-Path $msgFile) {
    $json = [System.IO.File]::ReadAllText($msgFile, [System.Text.Encoding]::UTF8) | ConvertFrom-Json
    $json.PSObject.Properties | ForEach-Object { $script:msg[$_.Name] = $_.Value }
}

function Msg([string]$key, [string]$fallback) {
    if ($script:msg.ContainsKey($key)) { return $script:msg[$key] }
    return $fallback
}

function Show([string]$key, [string]$fallback, [bool]$ok, [string]$detail = '') {
    $label = Msg $key $fallback
    if ($ok) {
        $script:okCount++
        Write-Host ("  [OK]   " + $label + $(if ($detail) { "  " + $detail } else { '' }))
    } else {
        $script:failCount++
        Write-Host ("  [FAIL] " + $label + $(if ($detail) { "  " + $detail } else { '' })) -ForegroundColor Red
    }
}

function Invoke-Api {
    param(
        [string]$Path,
        [string]$Method = 'GET',
        $Body = $null,
        $Session = $null,
        $Headers = $null
    )

    $params = @{
        Uri             = "$Base$Path"
        Method          = $Method
        UseBasicParsing = $true
    }
    if ($Headers) { $params.Headers = $Headers }
    if ($null -ne $Body) {
        $params.Body = ($Body | ConvertTo-Json -Depth 8 -Compress)
        $params.ContentType = 'application/json'
    }
    if ($Session) { $params.WebSession = $Session }

    try {
        $r = Invoke-WebRequest @params
        $json = $null
        try { $json = $r.Content | ConvertFrom-Json } catch { }
        return @{ status = [int]$r.StatusCode; json = $json; raw = $r.Content }
    } catch {
        $resp = $_.Exception.Response
        if ($resp) {
            $stream = $resp.GetResponseStream()
            $text = (New-Object System.IO.StreamReader($stream)).ReadToEnd()
            $json = $null
            try { $json = $text | ConvertFrom-Json } catch { }
            return @{ status = [int]$resp.StatusCode; json = $json; raw = $text }
        }
        return @{ status = 0; json = $null; raw = $_.Exception.Message }
    }
}

Write-Host ""
Write-Host ("Lolilota backend smoke test   " + $Base) -ForegroundColor Cyan
Write-Host ""

# ---- 1. health -------------------------------------------------------------
$h = Invoke-Api '/api/v1/health'
Show 'health_ok' 'health returns ok' ($h.status -eq 200 -and $h.json.ok -eq $true) ("status=" + $h.status)
Show 'health_version' 'health returns version' ($null -ne $h.json.data.version) ("v" + $h.json.data.version)

# ---- 2. config -------------------------------------------------------------
$c = Invoke-Api '/api/v1/config'
Show 'config_providers' 'config lists providers' ($c.json.data.providers.Count -ge 1) (($c.json.data.providers | ForEach-Object { $_.name }) -join ', ')

# ---- 3. guest session ------------------------------------------------------
$s = Invoke-Api '/api/v1/session'
Show 'guest_session' 'guest session authenticated=false' ($s.json.data.authenticated -eq $false)

# ---- 4. guarded endpoint ---------------------------------------------------
$guard = Invoke-Api '/api/v1/history'
Show 'history_guard' 'history without login -> 401' ($guard.status -eq 401) ("status=" + $guard.status)

# ---- 5. wrong password -----------------------------------------------------
$bad = Invoke-Api '/api/v1/session/password_sign_in' 'POST' @{ username = $User; password = 'wrong-password'; provider = 'local' }
Show 'wrong_password' 'wrong password -> 401' ($bad.status -eq 401) ("code=" + $bad.json.error.code)

# ---- 6. sign in ------------------------------------------------------------
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Invoke-Api '/api/v1/session/password_sign_in' 'POST' @{ username = $User; password = $Pass; provider = 'local'; device = 'smoke' } $session
Show 'sign_in' 'sign in succeeds' ($login.status -eq 200 -and $login.json.data.authenticated -eq $true) ("status=" + $login.status)
Show 'sign_in_token' 'sign in returns access_token' ([bool]$login.json.data.access_token)
Show 'sign_in_refresh' 'sign in returns refresh_token' ([bool]$login.json.data.refresh_token)
Show 'sign_in_user' 'sign in returns user profile' ($login.json.data.user.username -eq $User)

$token = $login.json.data.access_token
$refresh = $login.json.data.refresh_token
$authHeader = @{ Authorization = "Bearer $token" }

# ---- 7. cookie session -----------------------------------------------------
$s2 = Invoke-Api '/api/v1/session' 'GET' $null $session
Show 'cookie_session' 'cookie session is active' ($s2.json.data.authenticated -eq $true)

# ---- 8. bearer token -------------------------------------------------------
$b = Invoke-Api '/api/v1/session' 'GET' $null $null $authHeader
Show 'bearer_session' 'bearer token is active' ($b.json.data.authenticated -eq $true -and $b.json.data.via -eq 'bearer')

# ---- 9. push history -------------------------------------------------------
$now = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$items = @(
    @{ text = 'lolilota smoke test'; time = $now },
    @{ text = 'less variables'; time = $now - 1000 },
    @{ text = 'php backend'; time = $now - 2000 }
)
$push = Invoke-Api '/api/v1/history' 'POST' @{ items = $items; device = 'smoke' } $session
Show 'push_history' 'push history succeeds' ($push.status -eq 200 -and $push.json.data.accepted -eq 3) ("accepted=" + $push.json.data.accepted)

# ---- 10. dedupe ------------------------------------------------------------
$push2 = Invoke-Api '/api/v1/history' 'POST' @{ items = $items; device = 'smoke' } $session
$list = Invoke-Api '/api/v1/history?limit=50' 'GET' $null $session
Show 'dedupe' 'duplicate keywords are merged' ($list.json.data.total -eq 3) ("total=" + $list.json.data.total)

# ---- 11. pull with bearer --------------------------------------------------
$pull = Invoke-Api '/api/v1/history?limit=10' 'GET' $null $null $authHeader
Show 'pull_history' 'pull history with bearer' ($pull.json.data.items.Count -ge 3) ("count=" + $pull.json.data.items.Count)

# ---- 12. delete one --------------------------------------------------------
$firstId = $list.json.data.items[0].id
$del = Invoke-Api ("/api/v1/history/" + $firstId) 'DELETE' $null $session
Show 'delete_one' 'delete single record' ($del.status -eq 200 -and $del.json.data.removed -eq $true)

# ---- 13. refresh token -----------------------------------------------------
$ref = Invoke-Api '/api/v1/session/refresh_token' 'POST' @{ refresh_token = $refresh } $session
Show 'refresh_token' 'refresh token succeeds' ($ref.status -eq 200 -and [bool]$ref.json.data.access_token)
Show 'refresh_rotate' 'token is rotated on refresh' ($ref.json.data.access_token -ne $token)

# ---- 14. old refresh token invalid ----------------------------------------
# Deliberately no WebSession here: with a fresh valid cookie the request would
# legitimately succeed via the cookie channel and mask the replay check.
$ref2 = Invoke-Api '/api/v1/session/refresh_token' 'POST' @{ refresh_token = $refresh }
Show 'refresh_replay' 'old refresh_token rejected' ($ref2.status -eq 401) ("status=" + $ref2.status)

# ---- 15. clear -------------------------------------------------------------
$clear = Invoke-Api '/api/v1/history' 'DELETE' $null $session
Show 'clear_history' 'clear history succeeds' ($clear.status -eq 200)
$after = Invoke-Api '/api/v1/history' 'GET' $null $session
Show 'clear_empty' 'history total is 0 after clear' ($after.json.data.total -eq 0)

# ---- 16. sign out ----------------------------------------------------------
$out = Invoke-Api '/api/v1/session/sign_out' 'POST' @{} $session
Show 'sign_out' 'sign out succeeds' ($out.status -eq 200)
$s3 = Invoke-Api '/api/v1/session' 'GET' $null $session
Show 'signed_out' 'session invalid after sign out' ($s3.json.data.authenticated -eq $false)

# ---- 17. routing -----------------------------------------------------------
$nf = Invoke-Api '/api/v1/does-not-exist'
Show 'not_found' 'unknown endpoint -> 404' ($nf.status -eq 404) ("code=" + $nf.json.error.code)
$mna = Invoke-Api '/api/v1/health' 'POST' @{}
Show 'method_not_allowed' 'unsupported method -> 405' ($mna.status -eq 405) ("code=" + $mna.json.error.code)

Write-Host ""
if ($script:failCount -eq 0) {
    Write-Host ("  PASS: " + $script:okCount + " checks") -ForegroundColor Green
    exit 0
}
Write-Host ("  FAILED: " + $script:failCount + " of " + ($script:okCount + $script:failCount) + " checks") -ForegroundColor Red
exit 1
