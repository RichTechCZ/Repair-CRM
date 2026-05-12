$ErrorActionPreference = 'Stop'

$blockedPrefixes = @(
    'backup_db/',
    'uploads/',
    'memory/',
    'temp/',
    'facktura/'
)

$stagedFiles = @(git diff --cached --name-only --diff-filter=ACMR)
if ($LASTEXITCODE -ne 0) {
    Write-Error 'Unable to read staged files from git.'
    exit 1
}

if ($stagedFiles.Count -eq 0) {
    exit 0
}

$issues = New-Object System.Collections.Generic.List[string]

$patterns = @(
    @{ Name = 'Telegram bot token'; Regex = '\b\d{8,10}:AA[A-Za-z0-9_-]{20,}\b' },
    @{ Name = 'OpenAI-style key'; Regex = '\bsk-[A-Za-z0-9_-]{20,}\b' },
    @{ Name = 'GitHub token'; Regex = '\bghp_[A-Za-z0-9]{20,}\b|\bgithub_pat_[A-Za-z0-9_]{20,}\b' },
    @{ Name = 'Google API key'; Regex = '\bAIza[0-9A-Za-z\-_]{20,}\b' },
    @{ Name = 'Slack token'; Regex = '\bxox[baprs]-[0-9A-Za-z-]{10,}\b' },
    @{ Name = 'Private key material'; Regex = 'BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY' },
    @{ Name = 'Non-empty secret assignment'; Regex = '(?im)^\s*(DB_PASS|AI_API_KEY|TG_BOT_TOKEN|SYNC_TOKEN|OPENAI_API_KEY|OPENROUTER_API_KEY)\s*=\s*(?!\s*$)(?!your_)(?!change_me)(?!replace_me)(?!default_secure_token_replace_me)(.+)$' }
)

foreach ($file in $stagedFiles) {
    if ([string]::IsNullOrWhiteSpace($file)) {
        continue
    }

    $normalized = $file.Replace('\', '/')

    if ($normalized -eq '.env') {
        $issues.Add('Blocked staged file: .env')
        continue
    }

    $isBlockedPath = $false
    foreach ($prefix in $blockedPrefixes) {
        if ($normalized.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
            $issues.Add("Blocked staged path: $normalized")
            $isBlockedPath = $true
            break
        }
    }
    if ($isBlockedPath) {
        continue
    }

    $content = git show ":$file" 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrEmpty($content)) {
        continue
    }

    foreach ($pattern in $patterns) {
        if ([regex]::IsMatch($content, $pattern.Regex)) {
            $issues.Add("${normalized}: matched $($pattern.Name)")
            break
        }
    }
}

if ($issues.Count -gt 0) {
    Write-Host ''
    Write-Host 'Secret scan failed. Commit blocked.' -ForegroundColor Red
    Write-Host ''
    foreach ($issue in $issues) {
        Write-Host " - $issue" -ForegroundColor Yellow
    }
    Write-Host ''
    Write-Host 'Remove the secret or sensitive file from the index before committing.' -ForegroundColor Red
    exit 1
}

exit 0
