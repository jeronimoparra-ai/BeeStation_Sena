# Plan de trabajo — BeeStation

> Plan completo: `docs/PLAN-REESTRUCTURACION-2026-10-21.md`
> Auditoría: `docs/AUDITORIA-CAMPO-2026-10-21.md`
> Última actualización: 2026-10-21

## Estado

- Fase 0 (verdad y limpieza): implementada en código, pendiente de prueba en
  hardware y de commit.
- Fase 1 (hardware y datos): bloqueada por placa ESP32, RTC y sensores de energía.
- Fases 2–5: planificadas; no empezar hasta cerrar la Fase 0.

## Fase 0 — cerrar ahora

- [x] Recursos locales (Chart.js, Lucide), sin CDN.
- [x] Menú y UI sin módulos vacíos ni controles falsos.
- [x] Dashboard de estado + 4 métricas reales.
- [x] Frescura de datos y “dato antiguo” en dashboard, peso y acústica.
- [x] Acústica experimental sin alerta de enjambrazón.
- [x] Sensores con unidades y estado real.
- [x] Informe sin IBB/EV/ΔT/H_miel.
- [x] Firmware: alta 4-4, `test` sin telemetría falsa, `/status` sin código.
- [x] `php -l`, compilación ESP32 y render autenticado con Playwright.
- [ ] Prueba de alta, `test` y `/status` en hardware real.
- [x] Revisar worktrees Orca e integrar selectivamente seguridad y documentos.
- [ ] Commit y sync al docroot.

## Fase 1 — contrato de hardware y datos

- [ ] Perfil de hardware por nodo y sensores creados solo si están instalados.
- [ ] Contrato único de unidades/rangos/frecuencia.
- [ ] RTC o endpoint de hora local; firma sin internet.
- [ ] Arranque sin router y reconexión con backoff.
- [ ] Calibración HX711 persistida en NVS.
- [ ] `seq` monótono, no epoch.
- [ ] Cola SD robusta y acuse por lectura.

## Fase 2 — métricas y alertas

- [ ] Métricas núcleo y avanzadas condicionadas.
- [ ] Retirar H_miel y recomendación de cosecha.
- [ ] Flujo de peso con lectura nocturna y bitácora.
- [ ] Alertas con histéresis, persistencia, auto-resolución y cooldown.
- [ ] Calidad del dato visible.

## Fase 3 — UX de campo

- [ ] Resumen / Colmenas / Histórico / Informe.
- [ ] Estado primero; 3 acciones máximo.
- [ ] Tipografía 16 px, botones 44 px, contraste AA, foco visible.
- [ ] Móvil/tablet y `prefers-reduced-motion`.
- [ ] Integrar onboarding de nodo en el alta.

## Fase 4 — operación y seguridad

- [ ] Docroot sin `tools/`, `tests/`, `database/`, `docs/`, firmware ni `.sql`.
- [ ] Apache: denegar rutas sensibles, HTTPS, cabeceras.
- [ ] `conectar_dispositivo.php` con `auth.php`, CSRF e IDOR.
- [ ] Servidor del nodo apagado o autenticado.
- [ ] Backups, retención y monitoreo del servidor.
- [ ] Checklist `docs/SEGURIDAD-DESPLIEGUE.md`.

## Fase 5 — piloto 30 días

- [ ] 2 colmenas, 2 nodos, bitácora y revisión semanal.
- [ ] ≥ 99 % de filas recuperadas.
- [ ] 0 alertas de enjambrazón no verificadas.
- [ ] Deriva de peso < ±50 g/día.
- [ ] ≥ 7 días sin sol y ≥ 20 % de reserva.
- [ ] Nodo vivo tras 2 cortes y 1 semana sin internet.
- [ ] Resultado en `docs/PILOTO-2026.md`.

## Bloqueos

- ESP32 físico, NVS limpia y portal: prueba pendiente.
- RTC/energía: hardware no disponible.
- `ecdsa_flujo_test.php`: requiere MySQL aislado en el puerto 3399, no disponible.
- `ecdsa_openssl_test.php`: OpenSSL local sin configuración válida.
- Antigravity/Claude Code: cuota/saldo agotados; se usa Orca/Codex.
