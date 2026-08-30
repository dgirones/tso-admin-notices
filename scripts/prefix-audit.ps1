# TSO Admin Notices Manager — prefix audit
# Usage: .\scripts\prefix-audit.ps1
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Violations = @()

$Patterns = @(
    @{ Label = 'bare TSO_ constant (too short)'; Regex = "define\s*\(\s*'TSO_[^A]" },
    @{ Label = 'TSO_ADMIN_NOTICES_ constant'; Regex = 'TSO_ADMIN_NOTICES_' },
    @{ Label = 'class TSO_ (not TSOAN)'; Regex = 'class TSO_[^A]' },
    @{ Label = 'tsoAdmin JS global'; Regex = 'tsoAdmin' },
    @{ Label = 'tsoAn JS global'; Regex = '\btsoAn' },
    @{ Label = 'GLOBALS[tso_]'; Regex = "\`$GLOBALS\['tso_" },
    @{ Label = 'function tso_'; Regex = '\bfunction tso_[a-z]' },
    @{ Label = 'wp_ajax_tso_'; Regex = 'wp_ajax_tso_' },
    @{ Label = 'inline style tag'; Regex = '<style\b' },
    @{ Label = 'inline script tag'; Regex = '<script\b' },
    @{ Label = 'load_plugin_textdomain'; Regex = 'load_plugin_textdomain' }
)

Get-ChildItem $Root -Recurse -Include *.php,*.js |
    Where-Object {
        $_.FullName -notmatch '[\\/](vendor|node_modules)[\\/]'
    } |
    ForEach-Object {
        $rel = $_.FullName.Substring($Root.Length + 1)
        foreach ($p in $Patterns) {
            Select-String -Path $_.FullName -Pattern $p.Regex -CaseSensitive -AllMatches | ForEach-Object {
                $Violations += [pscustomobject]@{
                    Rule = $p.Label
                    File = $rel
                    Line = $_.LineNumber
                    Text = $_.Line.Trim()
                }
            }
        }
    }

if ($Violations.Count -eq 0) {
    Write-Host 'OK: no prefix / review-blocker violations.' -ForegroundColor Green
    exit 0
}

Write-Host "FAIL: $($Violations.Count) violation(s):" -ForegroundColor Red
$Violations | Format-Table -AutoSize
exit 1
