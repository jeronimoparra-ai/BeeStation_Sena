# AGENTS.md — BeeStation_Sena

Reglas permanentes del proyecto. **Léelas antes de tocar código.**
El estado evolutivo de la auditoría vive en `docs/ESTADO-ACTUAL-AUDITORIA.md`:
**consúltalo antes de continuar cualquier trabajo de auditoría o migración.**

---

## 1. Identidad del proyecto

- Repositorio: `https://github.com/jeronimoparra-ai/BeeStation_Sena`
- Directorio canónico: la raíz de este repositorio (no fijar rutas personales).
- Producto: plataforma IoT de monitoreo apícola (BeeStation / ApiTechnology, SENA).
- Stack: PHP 8+, MariaDB/MySQL, PDO, HTML5, CSS3, JavaScript, Chart.js, ESP32 (Arduino IDE).

## 2. Arquitectura funcional (no negociable)

- Registro público de usuarios + autenticación por sesión.
- **No existe sistema de roles y no debe introducirse.** Todos los usuarios
  autenticados tienen el mismo nivel funcional.
- Cada usuario accede **únicamente** a sus propios apiarios y colmenas.
- Los ESP32 se autentican contra la API y envían lecturas que se guardan en la BD.
- `database/schema.sql` es el esquema canónico de instalación limpia; ya incluye
  el resultado de las migraciones `001`–`008`. No se aplican migraciones sobre
  una instalación nueva.
- No hay tabla de control de migraciones: `tools/migrar-pruebas.php` reaplica
  todas y tolera "already exists". No asumas que una migración se aplicó.

## 3. Reglas de producto e integridad de datos (duras)

- No inventar datos de sensores ni insertar registros ficticios en producción.
- No modificar la propiedad de registros de usuarios.
- No alterar lecturas históricas para hacer pasar pruebas.
- No eliminar, fusionar ni sobrescribir registros para resolver duplicados:
  se reportan y se resuelven manualmente.
- No usar `ALTER TABLE IGNORE`.
- No activar funciones no implementadas (CO2, energía, verificación de correo/SMTP).
- Las pruebas locales **no** equivalen a validación de producción.

## 4. Seguridad

- Sin secretos en el repositorio: ni contraseñas, ni tokens, ni claves, ni
  `config/db.local.php`. Credenciales solo por entorno.
- Hash centralizado en `includes/password.php`: `PASSWORD_BCRYPT`, costo 12,
  mínimo 12 caracteres Unicode, máximo 72 bytes. **No reimplementar.**
- No imprimir credenciales en logs, informes ni salidas de comandos.
- `.htaccess` bloquea `.sql`, `config/db.php` y archivos de documentación.

## 5. Git: control estricto

Prohibido sin autorización explícita y específica del usuario:

```
git push            git push --force        git reset --hard
git clean           git checkout -- .       git restore .
git stash drop      git stash clear         git rebase / filter-branch
```

Reglas:

- No crear ramas nuevas.
- No reescribir ni alterar commits publicados ni el commit local `8cee2c5`.
- No descartar cambios de otros agentes (Hermes, Kilo CLI, etc.).
- No eliminar stash ni respaldos.
- Antes de un commit: mostrar diff + archivos + pruebas + informe y **esperar
  autorización**. Antes de publicar: autorización aparte.
- Nunca commitear depuradores, credenciales ni configuración local.

## 6. Bases de datos

- Producción/Hostinger: **prohibido** acceder o modificar en esta fase.
- Pruebas: bases desechables con nombre que termine en `_prueba` o `_test`,
  instancia local aislada, con marcador de propiedad.
- Toda migración debe ser: idempotente, no destructiva, verificable y con
  prechequeo que aborte antes del DDL si hay riesgo.
- El ejecutor del proyecto divide SQL por `;\s*\n` y usa `PDO::exec()`:
  **no usar** `DELIMITER`, procedimientos, `SIGNAL`, `PREPARE/EXECUTE` en
  archivos `.sql` del runner.
- La detección de duplicados se hace **en SQL** (`GROUP BY`), nunca agrupando
  cadenas en PHP: la collation del servidor manda.

## 7. Forma de trabajo

Fases pequeñas y verificables, una por respuesta:

1. Diagnóstico → 2. Plan → 3. Implementación acotada → 4. Pruebas →
5. Revisión independiente → 6. Informe → 7. Autorización → 8. Integración.

- No afirmar que un subagente revisó algo sin haber recibido su resultado.
- No declarar pruebas aprobadas sin salida real de ejecución.
- No ocultar fallos. Distinguir siempre: **compatibilidad demostrada**,
  **razonada** y **no verificada**.

## 8. Agentes

- Auditores en modo solo lectura (`.opencode/opencode.jsonc`): `auditor-php`,
  `auditor-seguridad`, `auditor-datos`, `auditor-firmware`. Reportan defectos;
  no implementan.
- Nunca dos agentes modificando los mismos archivos a la vez.
- El agente principal integra, prueba y emite el informe consolidado.

## 9. Entorno verificado

- OpenCode v2.2.5 · PHP 8.5.12 · MariaDB **11.8.8** en `127.0.0.1:3310`.
- MySQL 5.7 / 8.x: **no verificado** (ver `docs/ESTADO-ACTUAL-AUDITORIA.md`).
- Instancia de pruebas: puerto distinto de 3306; la batería lo exige salvo
  `MIG010_PERMITIR_INSTANCIA=1`.
