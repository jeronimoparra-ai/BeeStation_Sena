#!/bin/bash
# Arranca XAMPP y abre BeeStation en el navegador.
sudo -n /opt/lampp/lampp start >/dev/null 2>&1
sleep 2
IP_NODE=$(timeout 6 ping -c1 -W2 172.30.2.23 >/dev/null 2>&1 && echo 172.30.2.23 || echo "")
if [ -n "$IP_NODE" ]; then
  xdg-open "http://$IP_NODE/monitoreo" >/dev/null 2>&1
else
  echo "ESP32 no responde en 172.30.2.23 (¿encendido?)"
fi
xdg-open "http://172.30.0.170/BeeStation_Sena/" >/dev/null 2>&1
