# TAREA-KILO-FASE-1.1

- **Identificador:** KILO-010-2026-10-30
- **Agente:** Kilo CLI
- **Modo:** SOLO LECTURA (no commits, no push, no ramas, no borrados)
- **Fecha de emisión:** 2026-10-30
- **Emisor:** OpenCode (orquestador principal)

## 1. Objetivo

Auditoría independiente de seguridad y calidad de la migración 010, sus
herramientas y la configuración de OpenCode. No confirmes resultados de OpenCode:
busca lo que falta.

## 2. Contexto técnico

- `HEAD`: `8cee2c50a11b53aaee22434b7472736ea2921802` — **intacto**.
- Archivos de la migración 010 **sin rastrear**; `tools/migrar-pruebas.php`
  modificado sin commit.
- Entorno local: PHP 8.5.12, MariaDB 11.8.8 en `127.0.0.1:3310`.
- El usuario de pruebas `beestation_verify` tiene `ALL PRIVILEGES ON *.*`.
- Producción/Hostinger: **prohibido**.
- Credenciales: las da el usuario por entorno. No las escribas en archivos ni
  en el informe.

## 3. Archivos que puedes inspeccionar

```
tools/migrar-010-verificacion-bioclimatica.php
tools/verificar-migracion-010.php
tools/migrar-pruebas.php
database/migrations/010_unicidad_variable_bioclimatica_nombre.sql
database/schema.sql
config/db.php
.htaccess
.opencode/opencode.jsonc
AGENTS.md
docs/ESTADO-ACTUAL-AUDITORIA.md
docs/auditorias/
```

## 4. Prohibiciones

- No modificar archivos ni configuración.
- No ejecutar `git add`, `commit`, `push`, `reset`, `clean`, `stash drop`.
- No alterar usuarios ni grants de MySQL.
- No aplicar la migración a bases reales.
- No publicar ni mover archivos fuera del repositorio.
- Si detectas un defecto, **no lo corrijas**: descríbelo con evidencia.

## 5. Qué debes auditar

1. **Guardas de producción:** ¿puede el migrador ejecutar DDL sobre una base real
   por error? ¿Qué combinación de flags lo permite y cuál lo impide?
2. **Validación de argumentos:** flags desconocidos, contradicciones
   (`--info --aplicar`), host/puerto/nombre de base, collation interpolada.
3. **Manejo de errores:** ¿algún fallo puede devolver éxito? ¿alguna excepción
   deja bases huérfanas o procesos colgados?
4. **Credenciales:** ¿aparecen en argv, logs, informes o archivos? ¿Se hereda
   todo el entorno al subproceso?
5. **Permisos de pruebas:** propone el mínimo privilegio real para
   `beestation_verify`. ¿Podría tocar `beestation_sena` hoy?
6. **Configuración OpenCode:** ¿la denegación de `git push` y comandos
   destructivos es efectiva con el formato v2 (`permissions` + `policies`)?
   ¿Qué vías de evasión existen (p. ej. `bash -c`, alias, rutas alternativas)?
7. **Riesgos de publicación:** ¿qué se filtraría al commitear los 11 archivos
   nuevos (7 entradas) y el cambio en `migrar-pruebas.php`? ¿Rutas personales,
   secretos, identificadores internos?
8. **Casos negativos no cubiertos:** propone los que falten.

## 6. Pruebas que puedes ejecutar

```bash
MIG010_HOST=127.0.0.1 MIG010_PORT=3310 \
MIG010_USER=<usuario> MIG010_PASS=<clave> \
php tools/verificar-migracion-010.php
```

Debe dar 15/15 y no dejar bases nuevas. Si quieres probar guardas, usa solo
nombres que terminen en `_prueba`/`_test` y elimina lo que crees.

Pruebas de permisos (seguras, sin efectos):
- Intentar `git push -h` y comprobar que OpenCode lo bloquea.
- Intentar un comando que coincida con `rm -rf *` sobre una ruta inexistente.

## 7. Formato del informe

```markdown
# Informe Kilo — KILO-010-2026-10-30

## Veredicto
APROBADO | APROBADO CON OBSERVACIONES | RECHAZADO

## Evidencia ejecutada
(comandos y salidas reales; sin credenciales)

## Hallazgos
| ID | Severidad | Archivo:línea | Evidencia | Corrección esperada |

## Vectores de evasión de permisos
(con reproducibilidad)

## Riesgos de publicación
(qué no debería commitearse y por qué)

## Casos negativos propuestos
```

## 8. Riesgos que debes buscar

- Ejecución accidental sobre producción.
- Inyección por identificadores (base, host, collation).
- Credenciales expuestas o heredadas.
- Fallos que pasan como éxito.
- Borrado de bases ajenas.
- Permisos de OpenCode nominales pero inefectivos.
- Secretos o datos internos en el commit.
