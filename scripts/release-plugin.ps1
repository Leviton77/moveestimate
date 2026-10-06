<#
.SYNOPSIS
  Publish the WordPress plugin to the sites' self-updater (TME_Updater).

.DESCRIPTION
  Default: build dist\tom-moving-estimate-<version>.zip from
  wordpress-plugin\tom-moving-estimate, publish it as GitHub pre-release
  plugin-v<version>, and point update.json's "testing" channel at it.
  Staging picks it up on its next update check.

  -Promote: point update.json's "stable" channel at the current "testing"
  build and mark that release as a full release. Production picks it up.

  -BuildOnly: just build and check the zip; publish nothing.

  Either way, update.json then has to be committed and pushed to main --
  that's what the sites read.

.EXAMPLE
  ./scripts/release-plugin.ps1
  ./scripts/release-plugin.ps1 -Promote
#>
param([switch]$Promote, [switch]$BuildOnly)

$ErrorActionPreference = 'Stop'
$repo     = Split-Path -Parent $PSScriptRoot
$plugin   = Join-Path $repo 'wordpress-plugin\tom-moving-estimate'
$manifest = Join-Path $repo 'wordpress-plugin\update.json'
$base     = 'https://github.com/Leviton77/moveestimate/releases/download'

$data = Get-Content $manifest -Raw -Encoding UTF8 | ConvertFrom-Json

# No BOM: Set-Content -Encoding utf8 on Windows PowerShell writes one, and
# PHP's json_decode() then rejects the whole manifest.
function Save-Manifest($value) {
    $json = ($value | ConvertTo-Json -Depth 5) + "`n"
    [IO.File]::WriteAllText($manifest, $json, (New-Object Text.UTF8Encoding $false))
}

if ($Promote) {
    $data.stable = $data.testing
    $tag = 'plugin-v' + $data.stable.version
    gh release edit $tag --repo Leviton77/moveestimate --prerelease=false --latest
    if (-not $?) { throw "gh release edit $tag failed" }
    Save-Manifest $data
    Write-Host "stable -> $($data.stable.version). Commit and push wordpress-plugin/update.json to main."
    return
}

$main = Get-Content (Join-Path $plugin 'tom-moving-estimate.php') -Raw -Encoding UTF8
$version = [regex]::Match($main, "define\('TME_VERSION', '([^']+)'\)").Groups[1].Value
$header  = [regex]::Match($main, '\* Version: (\S+)').Groups[1].Value
$readme  = Get-Content (Join-Path $plugin 'readme.txt') -Raw -Encoding UTF8
$stable  = [regex]::Match($readme, 'Stable tag: (\S+)').Groups[1].Value
if (-not $version -or $version -ne $header -or $version -ne $stable) {
    throw "Version mismatch: TME_VERSION '$version', header '$header', readme Stable tag '$stable'."
}
$notes = [regex]::Match($readme, "(?s)= $([regex]::Escape($version)) =\s*(.*?)(\r?\n= |\s*$)").Groups[1].Value.Trim()
if (-not $notes) { throw "readme.txt has no '= $version =' changelog entry." }

$php = $env:TME_PHP
if ($php -and (Test-Path $php)) {
    Get-ChildItem $plugin -Recurse -Filter *.php | ForEach-Object {
        & $php -l $_.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "php -l failed: $($_.FullName)" }
    }
} else {
    Write-Warning 'TME_PHP not set -- skipping php -l.'
}

# Forward-slash entry names: Compress-Archive writes backslashes, which
# WordPress's unzip turns into flat files named "includes\class-....php".
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$dist = Join-Path $repo 'dist'
New-Item -ItemType Directory -Force $dist | Out-Null
$name = "tom-moving-estimate-$version.zip"
$zipPath = Join-Path $dist $name
if (Test-Path $zipPath) { Remove-Item -LiteralPath $zipPath -Confirm:$false }
$root = Split-Path -Parent $plugin
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    Get-ChildItem $plugin -Recurse -File | ForEach-Object {
        $entry = $_.FullName.Substring($root.Length + 1).Replace([char]92, [char]47)
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry)
    }
} finally {
    $zip.Dispose()
}

if ($BuildOnly) {
    Write-Host "Built $zipPath`n`nRelease notes:`n$notes"
    return
}

$tag = "plugin-v$version"
$notesFile = Join-Path $dist "$tag-notes.md"
Set-Content $notesFile $notes -Encoding utf8
gh release create $tag $zipPath --repo Leviton77/moveestimate --prerelease --target main --title "Tom Moving Estimate $version" --notes-file $notesFile
if (-not $?) { throw "gh release create $tag failed" }

$data.testing = [pscustomobject]@{
    version = $version
    package = "$base/$tag/$name"
    notes   = $notes
}
Save-Manifest $data
Write-Host "testing -> $version. Commit and push wordpress-plugin/update.json to main."
