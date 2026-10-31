# Revisión independiente — Migración 010 (datos)

- **Agente:** `auditor-datos` (subagente OpenCode, solo lectura)
- **Fecha:** 2026-10-30
- **Alcance:** migración 010: SQL, migrador PHP y batería de pruebas
- **Archivos revisados:** `database/migrations/010_*.sql`,
  `tools/migrar-010-verificacion-bioclimatica.php`,
  `tools/verificar-migracion-010.php`, `database/schema.sql`
- **Pruebas ejecutadas por el auditor:** ninguna (shell denegado por permisos de
  solo lectura). Revisión estática.
- **Limitación:** MySQL 5.7/8.x no disponible en el entorno.

## Veredicto

**APROBADO CON OBSERVACIONES.**

## Hallazgos

### Alto

1. **H1** El runner toleraba `Duplicate key name` como idempotencia sin verificar
   que el índice fuera UNIQUE. *Corregido:* verificación posterior en
   `tools/migrar-pruebas.php` + rc 1.
2. **H2** El `.sql` no incluye prechequeo de duplicados. *Corregido
   documentalmente:* el `.sql` declara que la ruta válida para instalaciones
   existentes es el migrador; el runner verifica el invariante.
3. **H3** `GROUP BY nombre` incluía el grupo `NULL`. *Corregido:* `WHERE nombre
   IS NOT NULL` y post-chequeo equivalente.
4. **H4** Nombres de índice se comparaban con distinción de mayúsculas.
   *Corregido:* agrupación con `strtolower`.
5. **H5** La batería no ejecutaba el `.sql`. *Corregido:* caso K.
6. **H6** Faltaban rutas: nullable, marcador ajeno, base real. *Corregido:*
   casos L y M; `--aceptar-nullable` cubierto por código.

### Medio

7. **H7–H10** Aserciones débiles (integridad fuera de `$ok`, IDs por subcadena,
   E2 con orden no peligroso, aserción muerta de mayúsculas). *Corregido.*
8. **H11** `--info --aplicar` aplicaba DDL. *Corregido:* error de opciones.
9. **H12** Consultas fuera de `try/catch` → rc 255. *Corregido:* manejador de
   excepciones.
10. **H13** El mensaje "sin modificar registros" no estaba respaldado.
    *Corregido:* conteo + huella antes/después.
11. **H15** El comentario de reversibilidad contenía un `DROP INDEX;` ejecutable.
    *Corregido:* sin terminador.
12. **H16** UNIQUE equivalente con otro nombre generaba índice redundante.
    *Corregido:* se detecta y no se duplica.
13. **H17** Collation `_bin` permitiría variantes. *Documentado* con aviso.
14. **H18** La batería no exigía instancia aislada. *Corregido:* puerto 3306
    requiere `MIG010_PERMITIR_INSTANCIA=1`.
15. **H19** Documento de estado desactualizado. *Corregido.*
16. **H20** Entorno heredado completo al subproceso. *Corregido:* entorno mínimo.
17. **H21–H22** Lock/TOCTOU. *Documentado:* `lock_wait_timeout = 30`, `ALTER`
    atómico, sin pérdida de datos.

### Bajo

18. **H14** `IGNORED`/`IS_VISIBLE`. *Investigado:* en MariaDB 11.8.8 un UNIQUE
    `IGNORED` sigue aplicando unicidad (probado). MySQL 8 invisible: razonado,
    no verificado.

## Conclusión

Diseño seguro en lo esencial: no toca datos, no elimina índices, detecta
duplicados en SQL con la collation real y aborta antes del DDL. Los bloqueos
(H1, H3, H5) fueron corregidos y verificados.
