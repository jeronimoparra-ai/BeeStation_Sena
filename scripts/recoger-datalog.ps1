# ============================================================
#  recoger-datalog.ps1
#  Recoge los datos Offline de una microSD (o carpeta) y los sube
#  al backend de BeeStation.
#
#  El ESP32 escribe un CSV por dia en la raiz de la tarjeta:
#      log_AAAA-MM-DD.csv
#      Fecha_hora,Epoch,Temp,Hum,Presion,Peso,Sonido,Luz
#
#  Este script los lee, los convierte al formato de la API y los sube
#  POR LOTES con la firma HMAC heredada (el backend la sigue aceptando
#  para nodos con firmware antiguo). Los ficheros ya subidos se renombran
#  a *.enviado.csv, de modo que no se repiten.
#
#  NOTA: este camino es LEGADO. El firmware actual usa una identidad ECDSA
#  cuya clave privada nunca sale del ESP32, asi que no se puede firmar
#  como el nodo desde un PC. La via recomendada es la cola automatica del
#  propio nodo.
#
#  Uso:
#    powershell -ExecutionPolicy Bypass -File .\recoger-datalog.ps1 `
#        -Carpeta "E:\" `
#        -BaseUrl "http://192.168.1.7/BeeStation_Sena" `
#        -Token   "BS-NODO-..." `
#        -Mac     "AA:BB:CC:DD:EE:FF" `
#        [-Simular]
# ============================================================
param(
    [Parameter(Mandatory = $true)][string]$Carpeta,
    [string]$BaseUrl = "http://127.0.0.1/BeeStation_Sena",
    # Credencial HMAC heredada: sin valor por defecto. Se pasa por linea de
    # comandos y NUNCA se escribe en el repositorio.
    [Parameter(Mandatory = $true)][string]$Token,
    [Parameter(Mandatory = $true)][string]$Mac,
    [int]$LineasPorLote = 30,
    [switch]$Simular
)

$ErrorActionPreference = "Stop"
$Token = $Token.ToUpper()

# ── Firma HMAC-SHA256 (esquema heredado del backend) ───────────────────
function Get-Sha256Hex([string]$texto) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    return -join ($sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($texto)) |
                  ForEach-Object { $_.ToString("x2") })
}

function New-Nonce {
    $bytes = New-Object byte[] 16
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $_.ToString("x2") })
}

# Cadena canonica del esquema HMAC heredado:
#   METODO \n RUTA \n TS \n MAC \n NONCE \n sha256_hex(cuerpo)
function Get-Firma([string]$secreto, [string]$metodo, [string]$ruta, [string]$ts,
                   [string]$mac, [string]$nonce, [string]$cuerpo) {
    $canonica = "$metodo`n$ruta`n$ts`n$mac`n$nonce`n" + (Get-Sha256Hex $cuerpo)
    $hmac = [System.Security.Cryptography.HMACSHA256]::new([System.Text.Encoding]::UTF8.GetBytes($secreto))
    return -join ($hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($canonica)) |
                  ForEach-Object { $_.ToString("x2") })
}

$prefijoApp = ([Uri]$BaseUrl).AbsolutePath.TrimEnd("/")

function Invoke-Lote([string]$cuerpo, [string]$lote) {
    $endpoint = "/api/ingest.php"
    $ruta  = $prefijoApp + $endpoint
    $ts    = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
    $nonce = New-Nonce
    $firma = Get-Firma $Token "POST" $ruta $ts $Mac $nonce $cuerpo
    $cab = @{
        "Content-Type"            = "application/json"
        "X-BeeStation-Mac"        = $Mac
        "X-BeeStation-Timestamp"  = $ts
        "X-BeeStation-Nonce"      = $nonce
        "X-BeeStation-Signature"  = $firma
    }
    try {
        $r = Invoke-WebRequest -Uri ($BaseUrl + $endpoint) -Method Post -Headers $cab `
             -Body $cuerpo -ContentType "application/json" -TimeoutSec 60 -UseBasicParsing
        return @{ codigo = [int]$r.StatusCode; cuerpo = $r.Content }
    } catch {
        $codigo = 0
        if ($_.Exception.Response) { $codigo = [int]$_.Exception.Response.StatusCode }
        return @{ codigo = $codigo; cuerpo = $_.Exception.Message }
    }
}

# ── 1. Localizar los CSV del datalogger ───────────────────────────────
if (-not (Test-Path $Carpeta)) {
    Write-Host "[ERROR] No existe la carpeta o unidad: $Carpeta" -ForegroundColor Red
    exit 1
}

$ficheros = Get-ChildItem $Carpeta -Filter "log_*.csv" -File -ErrorAction SilentlyContinue |
             Where-Object { $_.Name -notlike "*.enviado.csv" } | Sort-Object Name

if (-not $ficheros) {
    Write-Host "[i] No hay ficheros de datalogger en $Carpeta"
    Write-Host "    Formato esperado: log_AAAA-MM-DD.csv con cabecera"
    Write-Host "    Fecha_hora,Epoch,Id_medicion,Temp,Hum,Presion,Peso,Sonido,Luz"
    exit 0
}

Write-Host "=============================================="
Write-Host " Recogida del datalogger BeeStation"
Write-Host " Carpeta : $Carpeta"
Write-Host " Servidor: $BaseUrl"
Write-Host " Ficheros: $($ficheros.Count)"
Write-Host "=============================================="

$totalLineas = 0
$totalLecturas = 0
$errores = 0

foreach ($f in $ficheros) {
    Write-Host "`n> $($f.Name)"
    $lineas = Get-Content $f.FullName -Encoding UTF8
    if (-not $lineas) { Write-Host "    (vacio)"; continue }

    $cabecera = $lineas[0]
    if ($cabecera -notmatch "Epoch") {
        Write-Host "    [AVISO] Formato antiguo (sin columna Epoch): se omite." -ForegroundColor Yellow
        Write-Host "            Actualiza el firmware para registrar la fecha de medicion."
        continue
    }
    # Formato nuevo (9 columnas): Fecha,Epoch,Id_medicion,Temp..Luz.
    # Formato legado (8 columnas): Fecha,Epoch,Temp..Luz. Se aceptan ambos.
    $tieneId = $cabecera -match "Id_medicion"
    $base = if ($tieneId) { 3 } else { 2 }

    $datos = @()
    foreach ($l in $lineas) {
        if ($l.Trim() -eq "" -or $l.StartsWith("Fecha_hora")) { continue }
        $c = $l.Split(",")
        if ($c.Count -lt 8) { continue }
        $epoch = [long]$c[1]
        if ($epoch -le 0) {
            Write-Host "    [AVISO] Linea sin epoch (nodo sin reloj): se omite." -ForegroundColor Yellow
            continue
        }
        $datos += ,@{
            epoch = $epoch
            id    = if ($tieneId -and $c[2].Length -eq 32) { $c[2] } else { "" }
            temp  = $c[$base]; hum = $c[$base+1]; pres = $c[$base+2]
            peso  = $c[$base+3]; son = $c[$base+4]; luz = $c[$base+5]
        }
    }
    $totalLineas += $datos.Count
    if (-not $datos) { Write-Host "    (sin filas con fecha)"; continue }

    # Subir por lotes
    $subidas = 0
    for ($i = 0; $i -lt $datos.Count; $i += $LineasPorLote) {
        $fin = [Math]::Min($i + $LineasPorLote, $datos.Count)
        $lecturas = @()
        $sufijos = @("00", "01", "02", "03", "04", "05")
        for ($j = $i; $j -lt $fin; $j++) {
            $d = $datos[$j]
            $ts = $d.epoch; $sq = $d.epoch
            $values = @([double]$d.temp, [double]$d.hum, [double]$d.pres,
                        [double]$d.peso, [double]$d.son, [double]$d.luz)
            $tipos  = @("temperatura_interna", "humedad_relativa", "presion",
                        "peso", "sonido", "luminosidad")
            for ($k = 0; $k -lt 6; $k++) {
                $lec = @{ tipo = $tipos[$k]; valor = $values[$k]; ts = $ts; seq = $sq }
                if ($d.id) {
                    # Mismo id de medicion para los 6 sensores, con el ultimo
                    # byte reservado al indice del sensor.
                    $lec.id_medicion = $d.id.Substring(0, 30) + $sufijos[$k]
                }
                $lecturas += $lec
            }
        }
        $cuerpo = @{ lote = $f.BaseName; lecturas = $lecturas } | ConvertTo-Json -Depth 6 -Compress

        if ($Simular) {
            Write-Host "    [SIMULACION] lote de $($lecturas.Count) lecturas desde $($datos[$i].epoch)"
            $subidas += $lecturas.Count
            $totalLecturas += $lecturas.Count
            continue
        }

        $r = Invoke-Lote $cuerpo $f.BaseName
        if ($r.codigo -eq 200) {
            $j = $r.cuerpo | ConvertFrom-Json
            $estado = if ($j.ok_total) { "completo" } else { "PARCIAL ($($j.errores.Count) rechazos)" }
            Write-Host ("    lote {0}: HTTP {1} - {2} lecturas, {3}" -f ($subidas + 1), $r.codigo, $lecturas.Count, $estado)
            $subidas += $lecturas.Count
            $totalLecturas += $lecturas.Count
        } else {
            Write-Host ("    lote {0}: HTTP {1} - {2}" -f ($subidas + 1), $r.codigo, $r.cuerpo) -ForegroundColor Red
            $errores++
            break
        }
    }

    if (-not $Simular -and $errores -eq 0) {
        $destino = Join-Path $f.DirectoryName ($f.BaseName + ".enviado.csv")
        Move-Item $f.FullName $destino -Force
        Write-Host "    -> renombrado a $(Split-Path $destino -Leaf)"
    }
}

Write-Host "`n=============================================="
Write-Host " Filas leidas   : $totalLineas"
Write-Host " Lecturas subidas: $totalLecturas"
if ($errores -gt 0) { Write-Host " Errores        : $errores" -ForegroundColor Red }
Write-Host "=============================================="
if ($errores -gt 0) { exit 1 }
