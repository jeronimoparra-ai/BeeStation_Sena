# Informe de Misión 10.2 — Consolidación definitiva de la base de datos

- **Fecha:** 2026-10-31
- **Repositorio:** `/home/andresdev/BeeStation_Sena-official`, rama `main`, HEAD `8cee2c5`
- **Modo:** consolidación. Sin DDL/DML sobre bases reales, sin commits, sin push,
  sin acceso a Hostinger, sin cambios de grants ni de datos.

---

## 1. Esquema final consolidado

Archivo único: `database/schema.sql` (11.409 bytes). Contiene las 14 tablas funcionales
requeridas por el runtime, con claves primarias, foráneas, índices normales/compuestos/
únicos, defaults y restricciones. Sin historial de migraciones, sin tabla de control.

## 2. Número de tablas

**14 tablas:**

1. `usuario`
2. `apiario`
3. `colmena`
4. `variable_bioclimatica`
5. `sensor`
6. `calibracion`
7. `lectura`
8. `indicador`
9. `alerta`
10. `intento_vinculacion`
11. `lote_ingesta`
12. `dispositivo`
13. `nonce_dispositivo`
14. `nonce_peticion`

Verificado por código: las 14 aparecen referenciadas en PHP (`FROM/INTO/UPDATE/JOIN/TABLE`).
No se inventó ninguna tabla.

## 3. Campos y restricciones principales

- `usuario`: `correo VARCHAR(150) UNIQUE`, `contrasena VARCHAR(255)`.
- `apiario`: FK `id_usuario` → `usuario`.
- `colmena`: `token_vinculacion VARCHAR(64) NOT NULL UNIQUE`, `estado ENUM`,
  `nodo_mac`, `nodo_ultimo_contacto`, FK `id_apiario`.
- `variable_bioclimatica`: `nombre VARCHAR(50) NOT NULL UNIQUE`
  (`uq_variable_bioclimatica_nombre`).
- `sensor`: `UNIQUE (id_colmena, tipo)`; FKs a `colmena` y `variable_bioclimatica`.
- `calibracion`: FK `id_sensor`; índice compuesto `(id_sensor, id_calibracion)`.
- `lectura`: `seq BIGINT NULL`, `id_medicion CHAR(32) NULL`; UNIQUE `uq_sensor_seq`,
  `uq_lectura_id_medicion`; FK `id_sensor`.
- `indicador`: FK `id_colmena`; índice `(id_colmena, tipo, fecha_hora)`.
- `alerta`: FKs `id_indicador`, `id_usuario`; índice `(estado, fecha_hora)`.
- `intento_vinculacion`: índice `(resultado, fecha_hora)`.
- `lote_ingesta`: `id_lote BIGINT`; índice por `identificador`, `id_colmena`, `recibido_en`;
  FK `id_colmena` ON DELETE RESTRICT.
- `dispositivo`: identidad ECDSA P-256, `estado ENUM(pendiente/autorizado/revocado)`,
  UNIQUE `identificador` y `huella_clave`; FKs a `colmena` y `usuario` ON DELETE SET NULL.
- `nonce_dispositivo`: PK compuesta `(identificador, nonce)` (anti-replay ECDSA).
- `nonce_peticion`: PK compuesta `(mac, nonce)` con `nonce CHAR(32) CHARACTER SET ascii
  COLLATE ascii_bin` (anti-replay HMAC).

## 4. Índices y claves foráneas

- 6 claves foráneas, todas apuntando a tablas/columnas existentes
  (`apiario→usuario`, `colmena→apiario`, `sensor→colmena/variable_bioclimatica`,
  `calibracion→sensor`, `lectura→sensor`, `indicador→colmena`, `alerta→indicador/usuario`,
  `dispositivo→colmena/usuario`, `lote_ingesta→colmena`).
- `uq_variable_bioclimatica_nombre` (UNIQUE, `nombre`, sin prefijo, BTREE).
- `uq_sensor_colmena_tipo`, `uq_token_vinculacion`, `uq_dispositivo_identificador`,
  `uq_dispositivo_huella`, `uq_sensor_seq`, `uq_lectura_id_medicion`.
- Sin índice redundante sobre `id_medicion` (el UNIQUE lo cubre).
- Sin índices duplicados entre `idx_*` y las UNIQUE.

## 5. Datos iniciales

Únicamente el catálogo de 9 variables bioclimáticas, insertadas con `INSERT IGNORE`
y `id_variable` determinista (1–9):

`temperatura_interna`, `temperatura_externa`, `humedad_relativa`, `peso`, `sonido`,
`co2`, `energia`, `presion`, `luminosidad`.

Sin usuarios, apiarios, colmenas, sensores, lecturas, dispositivos ni datos demo.
`INSERT IGNORE` evita sobrescribir personalizaciones al repetir la inicialización.

## 6. Compatibilidad MariaDB / MySQL

- Sintaxis estándar InnoDB: `CREATE TABLE IF NOT EXISTS`, `ENUM`, `AUTO_INCREMENT`,
  `FOREIGN KEY`, `UNIQUE KEY`. Compatible con MariaDB 10.x y MySQL 5.7/8.x.
- `utf8mb4` / `utf8mb4_unicode_ci` explícitos en todas las tablas.
- `nonce_peticion.nonce` conserva `CHARACTER SET ascii COLLATE ascii_bin` (necesario
  para comparación binaria de nonces).
- No se usa `ADD COLUMN IF NOT EXISTS` (exclusivo de MariaDB) ni `DELIMITER`,
  procedimientos, `SIGNAL` ni `PREPARE/EXECUTE`.
- `IF NOT EXISTS` en `CREATE TABLE` es soportado por ambos motores.
- **Evidencia:** compatibilidad razonada por inspección de la sintaxis; no se ejecutó
  DDL en MySQL 8 (no hay instancia disponible). La compatibilidad con MySQL se declara
  como **razonada, no verificada**.

## 7. Archivos históricos retirados

Eliminados del árbol de trabajo (respaldo en
`~/backups/beestation_db_historico_20261031_090501/`):

- `database/migrations/001_seguridad_firma_y_datos.sql`
- `database/migrations/002_trazabilidad_lotes.sql`
- `database/migrations/003_corregir_unidades.sql`
- `database/migrations/004_nonce_antireplay.sql`
- `database/migrations/005_identidad_dispositivo.sql`
- `database/migrations/006_identificador_medicion.sql`
- `database/migrations/007_corregir_modelo_microfono.sql`
- `database/migrations/008_unidad_sonido_hz.sql`
- `database/migrations/009_verificacion_correo.sql`
- `database/migrations/010_unicidad_variable_bioclimatica_nombre.sql`
- `database/migracion-2026-10-17-limpiar-duplicados.sql`
- Carpeta `database/migrations/` (eliminada).

El historial Git conserva los archivos versionados. El respaldo externo conserva todo.

## 8. Herramientas adaptadas

- `tools/migrar-pruebas.php`: ya no recorre `database/migrations/`; aplica solo
  `schema.sql` y ejecuta siempre la verificación del índice
  `uq_variable_bioclimatica_nombre`.
- `tools/verificar-migracion-010.php`: el DDL de la 010 se toma de una constante
  (el `.sql` independiente ya no existe).
- `scripts/verificar-esquema.php`: mensaje actualizado (sin referencia a migraciones).
- `README.md` y `docs/instalacion.md`: actualizados al esquema único.

## 9. Pruebas ejecutadas y pendientes

**Ejecutadas:**
- `php -l` sobre todos los PHP: 0 fallos.
- Validación estática: 14 tablas, 14 charsets explícitos, FK a tablas existentes,
  sin columnas de correo (009), sin índice redundante sobre `id_medicion`, `seq` y
  `id_lote` BIGINT.
- `tests/php/firma_test.php`, `ecdsa_openssl_test.php`, `interop_firmware_test.php`: OK.

**Pendientes:**
- Validación dinámica del DDL en una base MariaDB desechable con cuenta de privilegios
  mínimos (no disponible: `beestation_verify` tiene `ALL PRIVILEGES ON *.*`).
- Prueba de idempotencia (ejecutar `schema.sql` dos veces) en base desechable.
- Verificación de las 9 variables sin duplicarse al repetir la inicialización.

## 10. Ruta del respaldo histórico

`~/backups/beestation_db_historico_20261031_090501/` (migraciones 001–010 +
SQL de limpieza). Respaldo previo del repo completo:
`~/backups/beestation_mision10_20261030_234001/repo-pre-mision10.tar.gz`.

## 11. Estado Git

Sin commits ni push. HEAD sigue en `8cee2c5`. Stash `stash@{0}` intacto.
`database/` ahora contiene únicamente `schema.sql`.
Diff completo: `/tmp/opencode/mision10.2.patch` (4.474 líneas).

## 12. Dictamen

**ESQUEMA CONSOLIDADO DEFINITIVO PREPARADO.**

Base de datos productiva no validada dinámicamente (sin DDL ejecutado, conforme a
las restricciones de seguridad). Para instalar: crear base vacía → importar
`database/schema.sql` → crear usuario SQL con privilegios mínimos → configurar
conexión → provisionar primera cuenta por CLI.
