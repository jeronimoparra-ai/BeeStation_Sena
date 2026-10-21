# Plan de trabajo: auditoría y modernización de BeeStation

## Estado inicial y límites del entorno

La carpeta contiene el código fuente, pero no contiene metadatos Git (`.git`) ni hay un repositorio Git en sus directorios superiores. No se pueden confirmar remoto, rama, historial, cambios previos ni crear una rama de forma segura. No se ejecutarán scripts SQL, ni se manipularán `config/db.local.php`, servicios o datos de la base local.

Hallazgos iniciales verificados:

- `includes/firma.php` autentica comparando HMAC con `colmena.token_vinculacion`, usa MAC como identidad indicativa y permite `timestamp=0` sin ventana temporal.
- `api/ingest.php` acepta `seq` opcional, devuelve aceptación parcial sin identificadores por lectura, y los valores inválidos se omiten mientras la respuesta puede ser HTTP 200.
- `beestation_node/beestation_node.ino` persiste el token en NVS, imprime sus fragmentos en serie y construye `seq` desde epoch; la cola SD avanza según respuesta parcial global.
- `config/db.php` cae a `root`/contraseña vacía cuando no hay configuración explícita. Existe `config/db.local.php`; sus contenidos se consideran sensibles y no se inspeccionaron.
- Hay tres migraciones SQL y dos esquemas; no existe historial Git disponible para establecer sus bases anteriores.

## Decisiones y restricciones

- Priorizar seguridad y conservación de datos; mantener el HMAC existente solo como compatibilidad temporal hasta que el enrolamiento por dispositivo esté completo.
- Ninguna clave privada de ESP32 se transmite. El camino preferido es clave asimétrica por nodo, pero requiere verificar capacidades del core y la interoperabilidad real antes de implementarlo.
- No ejecutar SQL contra la instancia de XAMPP; cualquier migración será revisada estáticamente y documentada para una base aislada.
- No crear historia Git artificial ni reemplazar el directorio por un clon mientras el estado local no pueda compararse.
- Trabajar en cortes compatibles, cada uno con validación reproducible, y dejar explícito lo que depende de hardware o herramientas ausentes.

## Fases

### Fase 1: Auditoría y base segura
- [ ] Mapear autenticación, sesiones, CSRF, ownership, configuración, contratos de sensores y colas SD.
- [ ] Comparar esquemas y migraciones de forma estática, sin ejecutarlos.
- [ ] Establecer comprobaciones locales disponibles (PHP, MySQL/MariaDB, Arduino CLI, bibliotecas).
- [ ] Crear reporte de auditoría y changelog con hallazgos demostrables.

### Fase 2: Contrato y seguridad de telemetría
- [ ] Diseñar y documentar identidad/enrolamiento/revocación de cada dispositivo, con compatibilidad transitoria.
- [ ] Implementar autenticación fresca con nonce atómico y rechazar reloj no sincronizado en subida online.
- [ ] Hacer ingestión idempotente por identidad de dispositivo y medición, con acuse por registro.
- [ ] Actualizar reenvío SD para conservar filas sin hora y avanzar solo sobre filas confirmadas.
- [ ] Agregar pruebas automatizadas de validación, idempotencia, replay y acuse parcial.

### Fase 3: Esquema, instalación y coherencia de sensores
- [ ] Definir migraciones no destructivas verificables para dispositivos, nonces, lotes y lecturas.
- [ ] Alinear esquema limpio con migraciones y requerimientos leídos por PHP.
- [ ] Alinear sensores físicos, rangos/unidades, variables derivadas y estados de mediciones inválidas/no disponibles.
- [ ] Documentar instalación nueva, migración, recuperación y compatibilidad de firmware.

### Fase 4: Acceso web y refinamiento visual
- [ ] Corregir sesión, CSRF, control de propiedad, escape y errores públicos.
- [ ] Revisar y corregir errores de estructura detectados en las vistas implicadas.
- [ ] Aplicar ajustes visuales pequeños, accesibles, responsive y compatibles con reduced motion.

### Checkpoints
- [ ] Cada fase deja contratos documentados y verificaciones ejecutables.
- [ ] No afirmar pruebas de integración/hardware/DB que no puedan ejecutarse en este entorno.
- [ ] Revisión final del diff no está disponible hasta recuperar metadatos Git; inventariar los archivos tocados.

## Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| El checkout carece de Git | No se pueden preservar/cotejar cambios previos ni revisar diff | No crear commits ni historia; mantener cambios localizados y listar rutas |
| Versión real de MariaDB desconocida | Sintaxis de migración podría no aplicar | Diseñar scripts compatibles y documentar preflight de versión/base aislada |
| Core ESP32 y librerías no verificadas | Firma asimétrica puede no compilar/interoperar | Detectar herramientas disponibles antes de elegir implementación |
| Firmware viejo en campo | Corte de compatibilidad impediría ingesta | Mantener ruta heredada temporal con revocación y plan de migración explícito |
| Sensor/calibración no verificados | Métricas podrían ser engañosas | Marcar como no disponible/experimental; no inventar ceros ni precisión |
