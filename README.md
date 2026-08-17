# SIHLA — Sistema Integral Hospital Los Ángeles

Sistema de información hospitalario construido desde el día uno como **producto replicable** a otras clínicas y **multi-sede**. No es un desarrollo a la medida que después "se adapta".

> **La regla que ordena todo el diseño:** adaptar el sistema a otro hospital, a otra sede o a otro convenio es trabajo de **configuración**, no de programación. Si para abrir la sede 2 o para firmar con una aseguradora nueva hay que escribir una migración, el diseño falló.

El contrato de trabajo completo — reglas de dominio, catálogo anti-errores, normativa hondureña verificada y orden de construcción — está en **[`CLAUDE.md`](CLAUDE.md)**. Este README solo explica cómo levantar el proyecto.

---

## Stack

| Capa | Versión |
|---|---|
| PHP | 8.5 |
| Laravel | 13.x |
| Panel | Filament v5 (Livewire 4 · Tailwind 4) |
| Base de datos | PostgreSQL 18 |
| Cache / colas | Redis 8 + Horizon |
| Tests | Pest 5 sobre PHPUnit 13 |
| Calidad | Larastan 3 (PHPStan 2) nivel 7 · Pint · Rector 2 |

**El runtime PHP corre nativo en Herd. Solo los datos van en Docker** — Herd evita la penalización de I/O de montar código en un contenedor en macOS, y Docker da paridad exacta de versión de motor.

---

## Puertos y bases

Esta máquina ya tiene 5432/6379, 5442/6389 y 5443/6390 ocupados por otros proyectos. SIHLA usa los suyos:

| Servicio | Host:Puerto | Contenedor | Base |
|---|---|---|---|
| PostgreSQL 18 | `127.0.0.1:5444` | `hla_postgres` | `hospital_los_angeles` |
| PostgreSQL 18 (tests) | `127.0.0.1:5444` | mismo contenedor | `hospital_los_angeles_test` (+ `_test_1..N` en paralelo) |
| Redis 8 | `127.0.0.1:6391` | `hla_redis` | db 0 / 1 (cache) / 2 (queue) |

Nombres **con guion bajo**: con guiones habría que escribir comillas dobles en todo `psql`, `pg_dump`, script de respaldo y variable de CI, y un olvido rompe el backup de producción en silencio.

> ⚠️ **Antes de cualquier comando destructivo se verifica puerto Y nombre de base.**
> `5444` + `hospital_los_angeles` = local. Cualquier otra cosa = alto.

---

## Arranque desde cero

```bash
cp .env.example .env
cp env.testing.example .env.testing
docker compose up -d
docker compose ps
docker compose exec postgres psql -U postgres -c "CREATE DATABASE hospital_los_angeles_test;"

composer install
npm install
php artisan key:generate
php artisan storage:link
php artisan migrate --seed
npm run build

herd link hospital-los-angeles
herd secure hospital-los-angeles
```

`herd secure` no es opcional: sin HTTPS no hay acceso a cámara (lectura de códigos de barras) ni acceso remoto seguro.

Las extensiones `pg_trgm` y `btree_gist` **no se crean a mano**: van en la migración `0000_01_01_000000_create_postgres_extensions.php`, para que existan también en las bases que crea `pest --parallel` y en cada sede o clínica nueva.

---

## El día a día

```bash
composer dev          # servidor + Horizon + Pail + Vite
composer test         # Pest en paralelo
composer lint         # Pint (corrige)
composer lint:check   # Pint (solo verifica — es lo que corre CI)
composer stan         # PHPStan nivel 7
composer rector       # Rector dry-run
composer ci           # audit + lint + stan + test
```

---

## Tres decisiones de infraestructura que no se cambian sin dump/restore

Están comentadas en `docker-compose.yml`. Se resumen acá porque las tres fallan **sin dar error**:

1. **El volumen de Postgres se monta en `/var/lib/postgresql`**, no en `/var/lib/postgresql/data`. PostgreSQL 18 movió `PGDATA` a `/var/lib/postgresql/18/docker`; con el mount viejo la base escribe en un volumen anónimo y un `down -v` o un recreate del contenedor la deja en cero, sin log.
   Verificación: `SHOW data_directory;` debe decir `/var/lib/postgresql/18/docker`.

2. **Collation ICU `es-HN`, fijada en `initdb`.** Con el proveedor glibc, una actualización de la imagen base cambia la versión de collation y corrompe los índices de texto en silencio. ICU está versionado y PostgreSQL avisa. Además ordena "Ángel" antes que "Bravo", que es lo que espera quien busca un paciente.
   Verificación: `\l` debe mostrar Locale Provider `icu` y Locale `es-HN`.

3. **Redis con `maxmemory-policy noeviction`.** Redis carga cache, colas (Horizon) y sesiones. Con `allkeys-lru`, bajo presión de memoria descarta jobs encolados sin avisar — se pierde la notificación de un valor crítico de laboratorio y nadie se entera. Con `noeviction` la escritura falla y el error se ve.
   Verificación: `redis-cli CONFIG GET maxmemory-policy` debe decir `noeviction`.

---

## Tests

**Nunca SQLite.** Un test verde en SQLite que falla en Postgres es peor que no tener test: da confianza falsa sobre CHECK constraints, índices parciales, únicos con `COALESCE`, JSONB, CTE, `FOR UPDATE` y `EXCLUDE USING gist`. `tests/Feature/EntornoTest.php` revienta la suite entera si alguien cambia el driver, la versión mayor, las extensiones o la zona horaria.

La configuración de tests vive **entera** en `.env.testing` (generado desde `env.testing.example`). `phpunit.xml` solo lleva `APP_ENV`, que es lo que dispara su carga. El runner de CI publica Postgres en 5444 y Redis en 6391 justo para que ese mismo archivo sirva en la Mac y en CI sin un `sed` en el medio.

---

© Inversiones Olympo — software propietario.
