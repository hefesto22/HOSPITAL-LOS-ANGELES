# ADR-0002 — Multi-sede single-tenant: `sede_id` desde la primera migración

**Estado:** Aceptado
**Fecha:** 2026-08-17
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

SIHLA se construye para Hospital Los Ángeles, pero **desde el día uno como producto replicable a otras clínicas y hospitales**, y con más de una sede por instalación. No es un desarrollo a la medida que después "se adapta".

Eso abre tres modelos posibles:

1. **Instalación única por cliente, sin noción de sede.** Lo más simple hoy; la sede 2 obliga a una migración sobre todas las tablas.
2. **Multi-tenant SaaS real**: una sola base, todos los clientes adentro, aislados por `empresa_id` y por Filament Tenancy. Es lo que la plantilla Grupo Olympo trae preparado con el trait `BelongsToEmpresa`.
3. **Multi-sede single-tenant**: una instalación por cliente, con su propia base, y jerarquía de sedes adentro.

## Decisión

**Multi-sede single-tenant.** Una instalación y una base por cliente; dentro de esa instalación, `sede_id` en **toda tabla transaccional desde la PRIMERA migración**, aunque hoy exista una sola sede.

**NO se activa el multi-tenant de la plantilla:** el trait `BelongsToEmpresa` se eliminó del repo y no se usa Filament Tenancy. Replicar a otra clínica es una instalación nueva, no un tenant nuevo.

`sede` no es una unidad de aislamiento de seguridad entre clientes: es **jerarquía de negocio** dentro de un mismo dueño.

## Razones

**Por qué `sede_id` desde la primera migración y no cuando haga falta:**

Agregarlo después a ~200 tablas con expedientes, cargos, resultados de laboratorio e inventario histórico no es una migración: es un proyecto de meses. Y peor que el costo es el resultado — todas las filas viejas quedan con una sede asumida, y esa asunción es una fuente permanente de datos mal atribuidos que nadie puede auditar después. En un hospital eso significa un censo que no cuadra, un inventario que no se puede responsabilizar y una factura que no se sabe de qué establecimiento salió.

El costo de llevarlo desde hoy es una columna y un índice por tabla. La asimetría es enorme.

**Por qué NO multi-tenant SaaS:**

1. **Datos clínicos de hospitales distintos no comparten base.** Un bug de scoping en un sistema administrativo mezcla pedidos; acá mezclaría expedientes. El aislamiento físico es la única defensa que no depende de que ningún developer se equivoque nunca.
2. **La normativa es por establecimiento.** Habilitación ante SESAL/ARSA, regente farmacéutico, CAI y rangos fiscales del SAR, libro de controlados: todo se emite y se audita por establecimiento. Una base por cliente hace que el respaldo, la retención de 20 años y una eventual entrega a la autoridad sean operaciones acotadas.
3. **Restaurar un backup de un cliente no puede tocar a otro.** Con base compartida, restaurar es una cirugía; con base propia, es un `pg_restore`.
4. **Filament Tenancy agrega una capa de scoping implícito** que compite con el scoping por sede y por relación de atención (§9.L5). Dos mecanismos de aislamiento superpuestos es la receta para que uno de los dos falle sin que nadie lo note.

**Por qué la sede sí va adentro:**

Un hospital con dos sedes es un solo dueño, un solo catálogo de ítems, un solo índice maestro de pacientes y una sola contabilidad — pero **inventario, caja, correlativos fiscales, almacenes, camas y reportes son por sede**. Esa combinación no se modela con instalaciones separadas sin duplicar el MPI, que es exactamente lo que ADR-0004 y el §8.2 prohíben.

## Consecuencias

**Obliga a:**

- `sede_id` con FK e índice en **toda tabla transaccional**, en la misma migración que la crea.
- Decidir **explícitamente el alcance de cada catálogo**: global (CIE-10, LOINC, ATC), de organización (servicios, roles, convenios) o de sede (precios, almacenes, correlativos, salas, camas). Decidirlo por reflejo produce tarifarios globales que una sede no puede cambiar.
- Identificadores visibles **con prefijo de sede y secuencia propia** (expediente, factura, accession number). Un contador global es cuello de botella y confusión operativa garantizada.
- Scoping aplicado en `getEloquentQuery()` **y en los badges y contadores**, desde la misma fuente (§9.L5).
- Locks de correlativo fiscal **por sede y punto de emisión**, nunca globales (§9.H7).

**Cierra:**

- No se activa `BelongsToEmpresa` ni Filament Tenancy. El trait fue borrado del repo el 17-ago-2026.
- No hay una tabla `empresas` con varios clientes adentro.

**Costo aceptado:**

- Un cliente nuevo implica aprovisionar una instalación (base, Redis, deploy), no crear una fila. Se asume a cambio del aislamiento.
- Reportes consolidados entre clientes distintos no son posibles — y no se necesitan: cada instalación tiene un solo dueño.

## Cómo se verifica

- **En cada revisión de migración:** ¿la tabla es transaccional y no tiene `sede_id`? Se rechaza.
- **En cada Resource nuevo:** ¿el scoping por sede está en `getEloquentQuery()` y en el badge de navegación, con la misma fuente?
- **Test obligatorio (§16):** un usuario de la sede A **no puede abrir por ID** un registro de la sede B. En Filament la ruta de edición directa es el agujero típico, así que el test golpea la URL, no solo el listado.
- **Prueba del diseño (§1.1):** ante cualquier feature nueva, la pregunta es *"¿abrir la sede 2 o firmar un convenio nuevo obliga a escribir código?"*. Si la respuesta es sí, el diseño falló.

## Referencias

- `CLAUDE.md` §1.1 (la tesis del producto replicable), §1.2, §8.1 (jerarquía estructural), §9.L5 (autorización = rol + relación + sede + turno), §12 (índices), §16 (tests exigidos)
- [ADR-0003](0003-catalogo-unico-y-tarifario-con-vigencia.md) — el tarifario también tiene alcance por sede
- [ADR-0005](0005-infraestructura-de-produccion.md) — el aislamiento físico entre clientes depende de cómo se despliegue
