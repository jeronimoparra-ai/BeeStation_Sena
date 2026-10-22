# Orca: orquestador de agentes para BeeStation

Orca es la aplicaciÃ³n de escritorio que administra **varios agentes CLI en paralelo**,
cada uno en su propio *worktree* de git. No forma parte del runtime de BeeStation:
es el entorno de desarrollo de este equipo.

## QuÃ© quedÃ³ instalado (2026-10-21)

| Elemento | Valor |
|---|---|
| AplicaciÃ³n | `C:\Users\andre\AppData\Local\Programs\orca\Orca.exe` (v1.4.237) |
| CLI | `C:\Users\andre\AppData\Local\Programs\orca\resources\bin\orca.exe`, aÃ±adida al *PATH* de usuario (los terminales nuevos la ven) |
| Proyecto registrado | `github:jeronimoparra-ai/beestation_sena` â†’ `C:/Users/andre/BeeStation_Sena` |
| Estado esperado | `orca status --json` â†’ `app.running: true`, `runtime.state: "ready"` |

La identidad del proyecto es el remoto **en minÃºsculas**
(`github:jeronimoparra-ai/beestation_sena`) aunque el repositorio en GitHub sea
`BeeStation_Sena`: es como Orca normaliza el remoto.

## Worktrees: quÃ© se copia a cada uno

Cada worktree nuevo es un checkout que arranca de `origin/main`. Los archivos
locales (gitignored) no viajan con git; los declara el archivo raÃ­z
`.worktreeinclude` y se **copian** (no enlazan) al crear el worktree:

```
config/db.local.php   # credenciales reales de MariaDB
```

Reglas del mecanismo, segÃºn la documentaciÃ³n de Orca (v1.4.237):

- Solo rutas literales: no admite comodines ni exclusiones.
- La ruta debe existir en el checkout principal y estar gitignored para copiarse.
- Si estÃ¡ versionada o falta, Orca la salta en silencio (no es error).

Comprobado creando `prueba-orca` desde el CLI: el worktree recibiÃ³
`config/db.local.php` sin pasos manuales.

## Comandos base

```text
orca status --json          # estado de app/runtime
orca project list --json    # proyectos registrados
orca worktree list --repo id:5702af19-dda3-46f0-9cbf-3e76982e7cad --json
orca worktree create --project github:jeronimoparra-ai/beestation_sena --name <nombre> --agent codex --prompt "<tarea>" --json
orca worktree rm --worktree id:<repoId>::<ruta-completa-del-worktree> --force --json
orca terminal list --worktree <id> --json
orca terminal read --terminal <handle> --json
orca skills get orca-cli    # guÃ­a completa en Markdown
```

Shell por defecto en Windows de los terminales de Orca: PowerShell.

La guÃ­a `orca-cli` completa quedÃ³ instalada globalmente en `~/.agents/skills/orca-cli`
y copiada a las casas de Codex, OpenCode y Antigravity con
`orca skills install --skill orca-cli --agent codex,opencode,antigravity`.

## Flujo probado con Orca + Codex (2026-10-21)

1. `orca worktree create --project ... --name fix-badge-acustico --agent codex --prompt "<tarea de una lÃ­nea>" --surface terminal --json`
   Ojo: los prompts se envÃ­an de una sola lÃ­nea; los saltos rompen el parseo del CLI.
2. Codex editÃ³ `js/actualizar-dashboard.js` en su propio worktree (sin tocar main),
   con las reglas dadas en el prompt (solo un archivo, sin BD, sin commit).
3. RevisiÃ³n en nuestra consola: `git -C <worktree> diff` y `node --check`.
4. AprobaciÃ³n: commit en el worktree (`eff24c6`) y `git cherry-pick <sha>` hacia
   `main` (push `0781978`).
5. `scripts/sincronizar-htdocs.ps1` desplegÃ³ el cambio en el docroot local.
6. Marca de estado del workspace: `orca worktree set --workspace-status in-review --comment ...`.

Worktrees dejados para referencia:
- `prueba-orca` (solo prueba de checkout; se puede borrar con `orca worktree rm`).
- `fix-badge-acustico` (el cambio ya estÃ¡ integrado en `main`).

## Probar cambios de un worktree en el Apache local

El despliegue local sigue siendo `scripts/sincronizar-htdocs.ps1`, que acepta `-Repo`:

```powershell
powershell -ExecutionPolicy Bypass -File C:\Users\andre\BeeStation_Sena\scripts\sincronizar-htdocs.ps1 `
  -Repo C:\Users\andre\orca\workspaces\BeeStation_Sena\<worktree>
```

Tras ejecutarlo, Apache (127.0.0.1 y 192.168.1.7) sirve el estado del worktree:
se sobrescribe el docroot, no se crea un vhost.

## Riesgos con agentes en paralelo

1. **Todos los worktrees comparten la BD real** (`beestation_sena`): mientras dos
   agentes escriban datos o ejecuten migraciones se estorban. Para pruebas, el repo
   trae el modo aislado en `config/db.php`: define `BEE_DB_AISLADA=1` y
   `DB_NAME=<base>_prueba` (con la base ya creada); y no estÃ¡ diseÃ±ado un sandbox
   de BD por worktree.
2. Las migraciones del sistema se ejecutan **solo** desde el checkout principal,
   una a la vez, sin agentes del mismo BD corriendo.
3. Orca lanza los agentes por defecto con sus *flags* de omitir permisos
   (`--dangerously-skip-permissions`, `--dangerously-bypass-approvals-and-sandbox`),
   porque el modo por defecto es **Yolo**. Para este repo conviene dar el clic
   manual: **Settings â†’ Agents â†’ Agent Permissions â†’ Manual** (solo se cambia desde
   la app, no por CLI).

## Estado de los agentes locales (2026-10-21)

| Agente | Estado | En Orca |
|---|---|---|
| Codex 0.163.0 | Autenticado y operativo | OK; primer trabajo real en worktree: `fix-badge-acustico` |
| OpenCode | Operativo | OK |
| Antigravity CLI (agy 1.4.4) | Instalada en `%LOCALAPPDATA%\agy\bin`; falta su **inicio de sesiÃ³n Ãºnico** de Google (ejecutar `agy` en un terminal o entrar desde Orca) | registrado en Orca; skill `orca-cli` instalada; entra a la misma tabla que Codex |
| Claude Code 2.1.303 | Instalado, **sin saldo** (`Credit balance is too low`) | falla hasta recargar crÃ©ditos o definir `ANTHROPIC_API_KEY` |

Mantener al dÃ­a las skills de Orca con `orca skills update`.
