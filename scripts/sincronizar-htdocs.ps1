<#
    scripts\sincronizar-htdocs.ps1
    ----------------------------------------------------------------
    Despliega el repositorio en el docroot de Apache (htdocs).

    Antes el despliegue era una copia manual de archivos sueltos: el
    docroot acababa con .git, con volcados de datos y con versiones
    distintas del código, y nada avisaba de la divergencia.

    Este script:
      1. copia al docroot solo lo que debe servirse por HTTP;
      2. elimina del docroot lo que no debe estar (.git, datos, docs);
      3. comprueba al final que repo y docroot coinciden.

    Uso:  powershell -ExecutionPolicy Bypass -File scripts\sincronizar-htdocs.ps1
#>
param(
    [string]$Repo     = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string]$Docroot  = 'C:\xampp\htdocs\BeeStation_Sena',
    [switch]$Simular
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $Docroot)) {
    if ($Simular) {
        Write-Host "El docroot no existe: $Docroot"
        Write-Host "Simulacion sin crear directorios ni copiar archivos."
        exit 0
    }
    New-Item -ItemType Directory -Force -Path $Docroot | Out-Null
}

# Extensiones que forman parte de la aplicación servida
# (las imagenes tambien: el fondo del login y el heroe viven en assets/)
$extensiones = @('.php', '.js', '.css', '.htm', '.html',
                 '.jpg', '.jpeg', '.png', '.webp', '.svg', '.ico')

Write-Host "Repositorio: $Repo"
Write-Host "Docroot    : $Docroot"
Write-Host ""

# ── 1. Copiar ────────────────────────────────────────────────────────
$copiados = 0
Get-ChildItem $Repo -Recurse -File -Force | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and
    $_.FullName -notmatch '\\(?:tools|tests|database|docs|beestation_node|scripts)\\' -and
    ($extensiones -contains $_.Extension.ToLower() -or $_.Name -eq '.htaccess')
} | ForEach-Object {
    $rel = $_.FullName.Substring($Repo.Length + 1)
    if ($rel -eq 'config\db.local.php' -or $rel -like 'scripts\*') { return }
    $dest = Join-Path $Docroot $rel
    $destDir = Split-Path $dest -Parent
    if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Force -Path $destDir | Out-Null }
    if (-not (Test-Path $dest) -or (Get-FileHash $_.FullName).Hash -ne (Get-FileHash $dest).Hash) {
        if ($Simular) {
            Write-Host "  ~ $rel (simulado)"
        } else {
            Copy-Item $_.FullName $dest -Force
            Write-Host "  + $rel"
        }
        $copiados++
    }
}
# La configuración local sí debe estar en el docroot (son las credenciales reales)
$local = Join-Path $Repo 'config\db.local.php'
if (Test-Path $local) {
    $dest = Join-Path $Docroot 'config\db.local.php'
    if (-not (Test-Path $dest) -or (Get-FileHash $local).Hash -ne (Get-FileHash $dest).Hash) {
        if ($Simular) { Write-Host "  ~ config\db.local.php (simulado)" }
        else { Copy-Item $local $dest -Force; Write-Host "  + config\db.local.php" }
    }
}
Write-Host "  -> $copiados archivo(s) actualizado(s)"

# ── 2. Limpiar el docroot ────────────────────────────────────────────
$prohibidos = @('.git', '.svn', '.hg', '.rnd', 'repomix-output.xml', 'test_connection.php',
                'guia-configuracion-beestation.docx', 'INFORME-FUNCIONAMIENTO.docx',
                'INFORME-FUNCIONAMIENTO.md', 'README.md', 'README.txt', 'CHANGELOG.md',
                'LICENSE', 'tasks')
foreach ($p in $prohibidos) {
    $dest = Join-Path $Docroot $p
    if (Test-Path $dest) {
        if ($Simular) { Write-Host "  - eliminaria del docroot: $p" }
        else { Remove-Item $dest -Recurse -Force; Write-Host "  - eliminado del docroot: $p" }
    }
}
# Directorios que no deben permanecer en el docroot, aunque una sincronizacion
# anterior los haya copiado.
foreach ($d in @('tools', 'tests', 'database', 'docs', 'beestation_node', 'scripts', 'tasks')) {
    $dest = Join-Path $Docroot $d
    if (Test-Path $dest) {
        if ($Simular) { Write-Host "  - eliminaria del docroot: $d\" }
        else { Remove-Item $dest -Recurse -Force; Write-Host "  - eliminado del docroot: $d\" }
    }
}

Get-ChildItem $Docroot -Recurse -File -Force | Where-Object {
    $_.Extension.ToLower() -in @('.sql', '.ino', '.h', '.md')
} | ForEach-Object {
    $rel = $_.FullName.Substring($Docroot.Length + 1)
    if ($Simular) { Write-Host "  - eliminaria del docroot: $rel" }
    else { Remove-Item -LiteralPath $_.FullName -Force; Write-Host "  - eliminado del docroot: $rel" }
}

# ── 3. Verificar ─────────────────────────────────────────────────────
Write-Host ""
$dif = 0
Get-ChildItem $Repo -Recurse -File -Force | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and $_.FullName -notmatch '\\docs\\' -and
    $_.FullName -notmatch '\\tests\\' -and
    $_.FullName -notmatch '\\(?:tools|database|beestation_node|scripts)\\' -and
    ($extensiones -contains $_.Extension.ToLower() -or $_.Name -eq '.htaccess')
} | ForEach-Object {
    $rel = $_.FullName.Substring($Repo.Length + 1)
    if ($rel -eq 'config\db.local.php' -or $rel -like 'scripts\*') { return }
    $t = Join-Path $Docroot $rel
    if (-not (Test-Path $t)) { Write-Host "  FALTA: $rel"; $dif++ }
    elseif ((Get-FileHash $_.FullName).Hash -ne (Get-FileHash $t).Hash) { Write-Host "  DIVERGE: $rel"; $dif++ }
}
if ($dif -eq 0) {
    Write-Host "  Verificacion: repo y docroot coinciden."
} else {
    Write-Host "  Verificacion: $dif archivo(s) sin sincronizar."
}
