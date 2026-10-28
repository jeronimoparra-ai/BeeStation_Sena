#!/bin/bash
# ─────────────────────────────────────────────────────────────
#  BeeStation Pro — Arranque de la plataforma (XAMPP)
#  Levanta XAMPP (Apache + MariaDB + PHP) y abre la interfaz web.
#  XAMPP se controla con el servicio systemd 'xampp'.
# ─────────────────────────────────────────────────────────────
set -u

# ▼▼▼ CONFIGURA AQUÍ LA IP DE TU ESP32 (déjalo en "" para omitir la búsqueda)
ESP32_IP="172.30.2.23"
# ▲▲▲

URL_WEB="http://localhost/BeeStation_Sena/"

echo "── BeeStation: comprobando XAMPP ──"
if systemctl is-active --quiet xampp; then
    echo "  [OK] XAMPP ya está activo"
else
    echo "  [..] Arrancando XAMPP ..."
    if sudo -n systemctl start xampp 2>/dev/null || sudo systemctl start xampp; then
        echo "  [OK] XAMPP arrancado"
    else
        echo "  [!!] No se pudo arrancar XAMPP (¿está instalado en /opt/lampp?)"
    fi
fi

# IP local del equipo (la que debe usar el ESP32 y otros dispositivos)
IP_LOCAL=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')
[ -z "$IP_LOCAL" ] && IP_LOCAL=$(hostname -I 2>/dev/null | awk '{print $1}')

echo
echo "  IP local del servidor     : ${IP_LOCAL:-desconocida}"
echo "  Dashboard (este equipo)   : $URL_WEB"
echo "  Dashboard (otros equipos) : http://${IP_LOCAL}/BeeStation_Sena/"
echo "  Ingesta para el ESP32     : http://${IP_LOCAL}/BeeStation_Sena/api/ingest.php"
echo "  phpMyAdmin                : http://localhost/phpmyadmin/"

# Conexión con el ESP32 (opcional)
if [ -n "$ESP32_IP" ]; then
    echo
    if timeout 6 ping -c1 -W2 "$ESP32_IP" >/dev/null 2>&1; then
        echo "  [OK] El ESP32 responde en $ESP32_IP"
        xdg-open "http://$ESP32_IP/monitoreo" >/dev/null 2>&1 &
    else
        echo "  [!] El ESP32 no responde en $ESP32_IP (¿está encendido?)"
    fi
fi

xdg-open "$URL_WEB" >/dev/null 2>&1 &
echo
echo "  Abriendo $URL_WEB ..."
