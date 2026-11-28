# Creates the Android release signing key ONCE, outside the repository.
#
#   powershell -ExecutionPolicy Bypass -File apps\mobile\scripts\new-release-key.ps1 -OutDir "$HOME\CampusTAB-keys"
#
# keytool runs inside Docker (Temurin JDK), so nothing is installed on this
# computer. It asks for the passwords itself; they are never stored here.
# Afterwards: put the printed base64 and the passwords into GitHub secrets,
# then back up the .jks file and the passwords offline (see README.md).
param(
    [Parameter(Mandatory = $true)][string]$OutDir,
    [string]$Alias = "campustab"
)

$ErrorActionPreference = "Stop"
$repo = (Resolve-Path "$PSScriptRoot\..\..\..").Path
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$out = (Resolve-Path $OutDir).Path

if ($out.StartsWith($repo, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Choose a folder OUTSIDE the repository ($repo). The key must never be committed."
}
$jks = Join-Path $out "campus-tab-release.jks"
if (Test-Path $jks) {
    throw "$jks already exists. The release key is made once; losing or replacing it means a new app listing."
}

docker run --rm -it -v "${out}:/keys" eclipse-temurin:21-jdk `
    keytool -genkeypair -v -keystore /keys/campus-tab-release.jks -alias $Alias `
    -keyalg RSA -keysize 4096 -validity 10000 -storetype PKCS12 `
    -dname "CN=Campus TAB, O=Sujal Chandel, C=IN"
if ($LASTEXITCODE -ne 0) { throw "keytool failed." }

$b64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes($jks))
$b64Path = Join-Path $out "campus-tab-release.jks.base64.txt"
Set-Content -Path $b64Path -Value $b64 -Encoding ascii -NoNewline

Write-Host ""
Write-Host "Key:    $jks"
Write-Host "Base64: $b64Path  (paste into the GitHub secret ANDROID_KEYSTORE_BASE64, then delete this .txt)"
Write-Host "Alias:  $Alias     (GitHub secret ANDROID_KEY_ALIAS)"
Write-Host "Also set ANDROID_KEYSTORE_PASSWORD and ANDROID_KEY_PASSWORD (with PKCS12 both are the store password)."
