# Builds tools/preview/co-notes-preview.html: index.html with the stylesheet, the
# mock API, and app.js inlined, so the frontend can be opened straight from disk
# with no PHP, MySQL, or OAuth.
#
#   powershell -ExecutionPolicy Bypass -File tools\preview\build.ps1
#
# Then double-click tools\preview\co-notes-preview.html.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)

function ReadUtf8($path) { [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8) }

$html = ReadUtf8 "$root\public_html\index.html"
$css  = ReadUtf8 "$root\public_html\assets\app.css"
$mock = ReadUtf8 "$root\tools\preview\mock-api.js"
$app  = ReadUtf8 "$root\public_html\assets\app.js"

# The asset tags carry a `?v=` cache-busting version (see sw.js), so match any
# version - and fail when nothing matches. A silent no-op here produced a 12KB
# harness with no app in it that still opened and just sat there looking fine.
$cssTag = '<link rel="stylesheet" href="/assets/app\.css(\?v=\d+)?">'
$appTag = '<script src="/assets/app\.js(\?v=\d+)?"></script>'

if ($html -notmatch $cssTag) { throw "build.ps1: no stylesheet tag matched - the asset tags in index.html have changed shape." }
if ($html -notmatch $appTag) { throw "build.ps1: no app.js tag matched - the asset tags in index.html have changed shape." }

$html = $html -replace $cssTag, "<style>`n$css`n</style>"
$html = $html -replace $appTag, "<script>`n$mock`n</script>`n<script>`n$app`n</script>"

$out = "$root\tools\preview\co-notes-preview.html"
[System.IO.File]::WriteAllText($out, $html, (New-Object System.Text.UTF8Encoding($false)))

Write-Output ("built {0} ({1:N0} bytes)" -f $out, (Get-Item $out).Length)
