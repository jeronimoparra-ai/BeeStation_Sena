# Informe de Misión 10.3 — Validación final de schema.sql

- **Fecha:** 2026-10-31
- **Repositorio:** `/home/andresdev/BeeStation_Sena-official`, rama `main`, HEAD `8cee2c5`
- **Modo:** validación. Sin DDL/DML, sin commits, sin push, sin acceso a Hostinger.

---

## 1. Resultado de validación estática

| Comprobación | Resultado |
|---|---|
| Número de tablas | **14** (usuario, apiario, colmena, variable_bioclimatica, sensor, calibracion, lectura, indicador, alerta, intento_vinculacion, lote_ingesta, dispositivo, nonce_dispositivo, nonce_peticion) |
| Claves primarias | Presentes en las 14 tablas |
| Claves foráneas | 9 FKs, todas apuntando a tablas/columnas existentes |
| Índices normales y UNIQUE | UNIQUE: `uq_variable_bioclimatica_nombre`, `uq_token_vinculacion`, `uq_sensor_colmena_tipo`, `uq_dispositivo_identificador`, `uq_dispositivo_huella`, `uq_sensor_seq`, `uq_lectura_id_medicion`, `correo` en `usuario`; normales: `idx_sensor_fecha`, `idx_fecha`, `idx_colmena_tipo_fecha`, `idx_estado_fecha`, `idx_intento_resultado_fecha`, `idx_lote_*`, `idx_dispositivo_*`, `idx_nonce_*` |
| Registros bioclimáticos | 9 variables con `INSERT IGNORE` e `id_variable` determinista (1–9) |
| Motor | InnoDB en las 14 tablas |
| Charset/collation | `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` en las 14 tablas; `nonce_peticion.nonce` conserva `CHARACTER SET ascii COLLATE ascii_bin` |
| `lectura.seq` | `BIGINT NULL` |
| `lote_ingesta.id_lote` | `BIGINT AUTO_INCREMENT` |
| Columnas de 009 | **0** (`correo_verificado`, `token_verificacion_hash`, `token_verificacion_expira` ausentes) |
| Índice redundante sobre `id_medicion` | **0** (el UNIQUE `uq_lectura_id_medicion` lo cubre) |

## 2. Compatibilidad del runtime

Se extrajeron todos los `INSERT INTO ... (...)` y `UPDATE ... SET` del código PHP y se
verificó que cada columna exista en `schema.sql`:

- `usuario`: `nombre`, `correo`, `contrasena` ✓
- `apiario`: `nombre`, `ubicacion`, `municipio`, `id_usuario` ✓
- `colmena`: `nombre`, `especie`, `fecha_instalacion`, `estado`, `token_vinculacion`,
  `id_apiario`, `intentos_fallidos_token` ✓
- `sensor`: `tipo`, `modelo`, `rango_min`, `rango_max`, `precision_valor`, `estado`,
  `fecha_instalacion`, `id_colmena`, `id_variable` ✓
- `indicador`: `tipo`, `valor`, `fecha_hora`, `descripcion`, `estado_colonia`,
  `id_colmena` ✓
- `alerta`: `tipo`, `nivel`, `mensaje`, `id_indicador` ✓
- `intento_vinculacion`: `token_recibido`, `ip_origen`, `resultado` ✓
- `lectura`: `valor_bruto`, `valor_calibrado`, `es_valida`, `id_sensor`, `fecha_hora`,
  `seq`, `id_medicion` ✓
- `lote_ingesta`: `identificador`, `lote`, `id_colmena`, `filas_recibidas` ✓
- `nonce_dispositivo`: `identificador`, `nonce`, `creado_en` ✓
- `nonce_peticion`: `mac`, `nonce`, `creado_en` ✓
- `dispositivo`: `identificador`, `mac`, `nombre`, `clave_publica_pem`, `huella_clave`,
  `estado`, `codigo_hash`, `codigo_expira`, `codigo_intentos`, `codigo_usado`,
  `creado_en`, `mac`, `clave_publica_pem`, `huella_clave`, `id_colmena`, `id_usuario`,
  `aprobado_en`, `revocado_en`, `motivo_revocacion`, `ultimo_contacto` ✓

Búsquedas de `correo_verificado`, `token_verificacion_hash`, `token_verificacion_expira`
en código PHP: **0 referencias**. Ningún flujo activo depende de los campos excluidos.

## 3. Procedimiento de primera cuenta administrativa

Archivo: `tools/crear-usuario-inicial.php`.

| Comprobación | Resultado |
|---|---|
| Solo CLI | Sí (`PHP_SAPI !== 'cli'` → HTTP 404) |
| No invocable por HTTP | Sí |
| Validación de parámetros | Sí (nombre 2–100, email válido ≤150, contraseña validada con `validarPasswordSegura`) |
| Hash con mecanismo vigente | Sí (`hashearPasswordSegura`, Bcrypt costo 12 centralizado) |
| Sin credenciales predeterminadas | Sí (todo viene de `BEESTATION_BOOTSTRAP_*`) |
| Sin exposición de contraseña en logs | Sí (`error_log` solo mensaje genérico; `unset($clave)` en `finally`) |
| No modifica cuentas existentes | Sí (sale con código 3 si el correo ya existe) |

**Procedimiento adecuado. No se creó ninguna cuenta ni se imprimió contraseña.**

## 4. Prueba dinámica segura

**BLOQUEADA.** No existe una base desechable aislada ni una cuenta con privilegios
mínimos exclusivos a ella:

- La instancia disponible (`127.0.0.1:3310`) está **caída** (conexión rehusada).
- La única cuenta conocida (`beestation_verify`) tiene `ALL PRIVILEGES ON *.*`:
  usarla para DDL está prohibido por las reglas del proyecto.
- No hay otra cuenta con privilegios mínimos.

**Infraestructura necesaria para desbloquear:**
1. Instancia MariaDB aislada (puerto distinto de 3306) con una base cuyo nombre
   termine en `_prueba` o `_test`.
2. Cuenta SQL con `CREATE, ALTER, DROP, SELECT, INSERT, UPDATE, DELETE` **solo**
   sobre esa base (no `*.*`).
3. `mariadb`/`mysql` client disponible (ya lo está: `/usr/bin/mariadb`).

Con esa infraestructura se podrá verificar: importación en base vacía, 14 tablas,
9 variables exactas, ausencia de datos ficticios, UNIQUE de `nombre`, integridad
FK, segunda ejecución sin duplicados ni alteraciones de configuración.

## 5. Instalación y documentación

`docs/instalacion.md` corresponde al esquema único: crea base vacía, importa
`database/schema.sql`, configura conexión privada, crea usuario de aplicación con
mínimos privilegios, provisiona la primera cuenta por CLI, verifica con
`scripts/verificar-esquema.php`. Deja claro que `schema.sql` no actualiza bases
existentes y que no existe carpeta `database/migrations/`.

## 6. Git

- `database/` contiene **únicamente `schema.sql`**. ✓
- No se restauraron migraciones. ✓
- Stash `stash@{0}` intacto. ✓
- Sin commits ni push. ✓
- HEAD sigue en `8cee2c5`. ✓

## 7. Dictamen

**ESQUEMA APTO PARA INSTALACIÓN EN BASE NUEVA (validación estática + compatibilidad
de runtime).**

La validación dinámica queda bloqueada por falta de infraestructura segura (instancia
caída + sin cuenta de privilegios mínimos). No se declaró probado nada que no se
ejecutó. La decisión de instalar en producción corresponde al usuario.

---

Informe detallado: `docs/produccion/INFORME-MISION-10.3.md`.
Informe consolidado de Misión 10: `docs/produccion/INFORME-LIMPIEZA-MISION-10.md`.
Informe de consolidación de BD: `docs/produccion/INFORME-MISION-10.2.md`.
