# Orca: orquestador de agentes para BeeStation

Orca es la aplicación de escritorio que administra **varios agentes CLI en paralelo**,
cada uno en su propio *worktree* de git. No forma parte del runtime de BeeStation:
es el entorno de desarrollo de este equipo.

## Qué quedó instalado (2026-10-21)

| Elemento | Valor |
|---|---|
| Aplicación | `C:\Users\andre\AppData\Local\Programs\orca\Orca.exe` (v1.4.237) |
| CLI | `C:\Users\andre\AppData\Local\Programs\orca\resources\bin\orca.exe`, añadida al *PATH* de usuario (los terminales nuevos la ven) |
| Proyecto registrado | `github:jeronimoparra-ai/beestation_sena` → `C:/Users/andre/BeeStation_Sena` |
| Estado esperado | `orca status --json` → `app.running: true`, `runtime.state: "ready"` |

La identidad del proyecto es el remoto **en minúsculas**
(`github:jeronimoparra-ai/beestation_sena`) aunque el repositorio en GitHub sea
`BeeStation_Sena`: es como Orca normaliza el remoto.

## Worktrees: qué se copia a cada uno

Cada worktree nuevo es un checkout que arranca de `origin/main`. Los archivos
locales (gitignored) no viajan con git; los declara el archivo raíz
`.worktreeinclude` y se **copian** (no enlazan) al crear el worktree:

```
config/db.local.php   # credenciales reales de MariaDB
```

Reglas del mecanismo, según la documentación de Orca (v1.4.237):

- Solo rutas literales: no admite comodines ni exclusiones.
- La ruta debe existir en el checkout principal y estar gitignored para copiarse.
- Si está versionada o falta, Orca la salta en silencio (no es error).

Comprobado creando `prueba-orca` desde el CLI: el worktree recibió
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
orca skills get orca-cli    # guía completa en Markdown
```

Dentro de los terminales que Orca abre, bare `orca` ya resuelve (mete el shim en el
PATH del terminal); el shell por defecto en Windows es PowerShell.

La guía `orca-cli` completa quedó instalada globalmente en `~/.agents/skills/orca-cli`
y copiada a las casas de Codex y OpenCode con
`orca skills install --skill orca-cli --agent codex,opencode`.

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
   `DB_NAME=<base>_prueba` (con la base ya creada); y no está diseñado un sandbox
   de BD por worktree.
2. Las migraciones del sistema se ejecutan **solo** desde el checkout principal,
   una a la vez, sin agentes del mismo BD corriendo.
3. Orca lanza los agentes por defecto con sus *flags* de omitir permisos
   (`--dangerously-skip-permissions`, `--dangerously-bypass-approvals-and-sandbox`),
   porque el modo por defecto es **Yolo**. Para este repo conviene dar el clic
   manual: **Settings → Agents → Agent Permissions → Manual** (solo se cambia desde
   la app, no por CLI).

## Estado de los agentes locales (2026-10-21)

| Agente | Estado | En Orca |
|---|---|---|
| Codex 0.163.0 | Autenticado y operativo | OK (skill `orca-cli` instalada) |
| OpenCode | Operativo | OK |
| Claude Code 2.1.303 | Instalado, **sin saldo** (`Credit balance is too low`) | falla hasta recargar créditos o definir `ANTHROPIC_API_KEY` |
| Antigravity CLI | No está instalado (es un IDE de Google, no un CLI público) | Orca lo orquesta cuando exista |

Mantener al día las skills de Orca con `orca skills update`.
