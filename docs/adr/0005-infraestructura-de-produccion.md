# ADR-0005 — Infraestructura de producción sobre el VPS compartido

**Estado:** 🚧 **ABIERTO** — se cierra al terminar la Etapa 1 (Cimientos)
**Fecha de apertura:** 2026-08-17
**Decididores:** Mauricio Cruz (Inversiones Olympo)

> Este ADR está abierto a propósito. Se documenta ahora para que la decisión no se tome por inercia el día del deploy, con el hospital esperando.

## Contexto

El VPS disponible **comparte máquina con sistemas productivos de terceros**. El stack objetivo de SIHLA (PHP 8.5 + PostgreSQL 18 + Redis 8) **diverge** de lo que corren esos otros proyectos.

Y hay una diferencia cualitativa con todo lo anterior de la línea Olympo: **un sistema administrativo que se cae molesta; un sistema hospitalario que se cae detiene la atención.** La tolerancia a caídas es mucho menor, y eso cambia el peso de las opciones.

## Restricción absoluta, ya fijada

**No se toca nada de los sistemas de terceros del VPS** — ni sus bases, ni sus Redis, ni sus cron, ni sus vhosts, ni el `php.ini` global. Esto no está en discusión y no depende de qué opción se elija.

## Opciones en evaluación

### Opción A — Contenedores propios, con el Nginx del host como reverse proxy

- ✅ Aísla PHP 8.5, PostgreSQL 18 y Redis 8 del resto de la máquina; una actualización de SIHLA no puede romper a un tercero, ni al revés.
- ✅ El deploy y el rollback son de imagen, reproducibles.
- ✅ Es coherente con cómo ya corre el entorno local (§7.3).
- ❌ Más RAM y más disco.

### Opción B — `php8.5-fpm` en paralelo + PostgreSQL 18 en puerto aparte, sobre el host

- ✅ Consume menos memoria.
- ❌ Comparte kernel, límites y superficie con los productivos ajenos; un `apt upgrade` mal medido los alcanza.
- ❌ Convivencia de varias versiones de PHP y de PostgreSQL en el mismo host, mantenida a mano.

**Inclinación actual: Opción A**, por el argumento de tolerancia a caídas. No está decidida.

## Qué falta para cerrarlo

1. **Auditoría del VPS documentada en `docs/vps-state.md`** — RAM, CPU, disco, qué corre hoy, y crecimiento proyectado a 3 años con los volúmenes del §2 (~150k encuentros, ~2M cargos, ~5M resultados, ~3M movimientos de inventario, ~50M filas de bitácora).
2. **Decidir si la base necesita alta disponibilidad** desde el arranque o si se acepta un RTO documentado.
3. **Decidir dónde vive el PACS** y cuánto disco pide, que es lo que más crece (§9.J1: el sistema nunca guarda pixel data, pero el PACS sí).
4. **Escribir y ensayar el plan de contingencia con el personal** (§13.8): formularios en papel, vista de solo lectura del expediente, captura retroactiva marcada. **El sistema se va a caer; lo que se diseña es cómo se cae.**

## Ya decidido, independientemente de la opción

- **PgBouncer con pools separados** para web, colas y reportes. Un reporte pesado no puede consumir las conexiones de la caja (§13.5).
- **Deploy a producción con aprobación manual**, nunca automático. GitHub Environment protegido; **`pg_dump` obligatorio ANTES de `migrate --force`**, y si la migración falla se detiene y reporta (§18).
- **Nunca se despliega en horario de alta ocupación**, y jamás sin plan de rollback probado.
- **Backups diarios con retención de 30 días y restauración probada antes del día 1**, repetida cada trimestre, con el tiempo medido. Un backup que nunca se restauró no es un backup (§14.10).
- **TLS obligatorio**, cifrado en reposo de discos y respaldos, MFA para `direccion`, `super_admin` y todo rol con export masivo.
- **Monitoreo con alerta humana ante cola fallida.** En este sistema **el monitoreo de la cola es un dispositivo de seguridad del paciente**, no de infraestructura: un job fallido en silencio en la cola crítica es un valor de pánico no notificado (§13.7).
- **Credenciales demo rotadas** antes de dar cualquier acceso real.

## Cómo se cierra

Al terminar la Etapa 1, con `docs/vps-state.md` escrito, se toma la decisión, se cambia el estado de este ADR a *Aceptado* y se agrega la sección **Consecuencias**. Si se elige B, hay que dejar registrado explícitamente qué mitigación protege a los productivos ajenos.

## Referencias

- `CLAUDE.md` §19 (producción y VPS), §13.5 (base de datos y conexiones), §13.7 (colas), §13.8 (contingencia), §14 (seguridad), §18 (CI/CD)
- `docs/vps-state.template.md` — formato de la auditoría pendiente
