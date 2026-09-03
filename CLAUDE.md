# INSTRUCCIONES DE DESARROLLO — SIHLA · Sistema Integral Hospital Los Ángeles · v1.0 (17 de agosto de 2026)

**Contrato de trabajo entre Mauricio (Inversiones Olympo) y Claude. Se lee y se aplica en cada sesión, completo, antes de escribir la primera línea de código.**

**Stack objetivo — verificado en Packagist y fuentes oficiales el 17-ago-2026:**
**PHP 8.5.9 · Laravel 13.25 · Filament v5.7 (Livewire 4.4 + Tailwind 4.3) · PostgreSQL 18.6 · Redis 8.10 · Pest 5 · Larastan 3 (PHPStan 2) nivel 7 · Pint · Rector 2**

> **Estado del repo hoy:** `hospital-los-angeles` es un clon virgen de la plantilla Grupo Olympo (PHP 8.4 · Laravel 12 · Filament v4 · PG 16 · Pest 3), sin renombrar todavía (`APP_SLUG=plantilla_olympo`). La actualización al stack objetivo es la **Etapa 0** y se ejecuta en su propia sesión, **ANTES de la primera migración del dominio**. Hasta que la Etapa 0 cierre con CI verde, este documento describe el destino, no el presente. **No se construye ningún módulo del hospital sobre el stack viejo.**

---

## 0. CÓMO USAR ESTE DOCUMENTO

Orden de fuentes de verdad cuando algo no esté claro:

1. **Este documento** — reglas operativas, decisiones cerradas y catálogo anti-errores (§9).
2. **Memoria del proyecto** (MEMORY.md + archivos de tema) — estado actual, decisiones recientes, lecciones nuevas.
3. **ADRs y `docs/` del repo** — decisiones cerradas. NO se re-discuten sin una razón técnica nueva.
4. **Normativa hondureña citada en §8** — Ley del ISV, Acuerdo 481-2017 (facturación), Código de Salud 65-91, Acuerdos ARSA.
5. **Documentación oficial** — Laravel 13, Filament 5.x (`filamentphp.com/docs/llms.txt`), Livewire 4, PostgreSQL 18.

Si una instrucción de sesión contradice este documento: señalo el conflicto, explico la razón técnica y procedo solo tras confirmación. Si detecto que estoy por violar una regla del catálogo §9, **la cito antes de continuar**.

**Al iniciar cada sesión:** leo la memoria del proyecto, pido `git status` + últimos commits, y confirmo desde dónde arrancamos. No propongo trabajo sin saber dónde quedamos ni qué está sin commitear.

---

## 1. EL PRODUCTO — QUÉ CONSTRUIMOS Y PARA QUIÉN

### 1.1 La tesis

**SIHLA es el sistema de información de Hospital Los Ángeles, construido desde el día uno como producto replicable a otras clínicas y hospitales, y multi-sede.** No es un desarrollo a la medida que después "se adapta".

De ahí sale la regla que ordena todo el diseño:

> **Adaptar el sistema a otro hospital, a otra sede o a otro convenio es trabajo de configuración, no de programación.** Si para abrir la sede 2 o para firmar con una aseguradora nueva hay que escribir una migración, el diseño falló.

Traducción técnica concreta, verificable en cada revisión de código:

- **`sede_id` en toda tabla transaccional desde la PRIMERA migración**, aunque hoy exista una sola sede. Agregarlo después a 200 tablas con expedientes, cargos e inventario histórico es un proyecto de meses y una fuente permanente de datos mal atribuidos.
- **Cero precios en el catálogo.** El precio es una función `(ítem, convenio, fecha, sede)` resuelta por vigencia (§8.5). Una columna `precio` en el catálogo obliga a duplicar el catálogo por cada aseguradora.
- **Cero reglas de negocio en constantes de código.** Política de censo, ventana de cargo tardío, tope de descuento por rol, umbral de autorización previa, redondeo, ISV: todo es dato configurable por sede.
- **Cero hard-code de "Honduras".** ISV 15 %, formato de RTN, régimen CAI y layout de factura son configuración, con punto de extensión listo para la factura electrónica del SAR (§8.6.6).

### 1.2 Decisiones de alcance ya tomadas (confirmadas 17-ago-2026)

| Decisión | Resolución | Consecuencia técnica |
|---|---|---|
| **Alcance de instalación** | **Multi-sede, un solo dueño por instalación; producto replicable a otras clínicas** | `sede_id` desde la primera migración. Inventario, caja, correlativos fiscales, almacenes y reportes son por sede. **NO es multi-tenant SaaS**: no se activa `BelongsToEmpresa` de la plantilla, no se usa Filament Tenancy. Replicar = instalación nueva con su propia base |
| **Módulos principales (los que mueven el dinero)** | **Farmacia + Inventario + Facturación + Laboratorio + Rayos X**, alimentando a facturación e inventario | El eje del sistema es: *orden clínica → ejecución del servicio → cargo a la cuenta del paciente → consumo de inventario*. Todo lo demás orbita esto |
| **Precios** | **Tarifario por convenio con vigencia** (particular es un convenio más) | `(ítem, convenio, vigencia)` con snapshot inmutable en cada cargo. Sin esto, renegociar con Ficohsa reescribe facturas de meses pasados |
| **Expediente clínico** | **Máximo control desde el día 1**: append-only, bitácora de LECTURA, cifrado de datos sensibles, MFA, retención 20 años | Nada de `UPDATE`/`DELETE` en dato clínico. Enmienda, no edición |
| **ISV** | **Determinado por línea de ítem, no por factura ni por empresa** | La mayoría del negocio es EXENTO por ley (§8.6.1). Cafetería, parqueo y estética son gravados. Conviven en la misma factura |
| **Documento fiscal** | **Régimen CAI (Acuerdo 481-2017)** con interfaz `AutorizadorFiscal` para el futuro CAEE | La factura electrónica no es obligatoria hoy en Honduras, pero llegará. Se aísla desde ahora |

### 1.3 Fuera de alcance de v1 — se construye después o se cotiza aparte

Facturación electrónica en línea con el SAR (sí se deja el punto de extensión) · portal del paciente · app móvil nativa · telemedicina · contabilidad general y estados financieros · nómina y recursos humanos · integración bancaria · PACS propio (se integra con uno, no se construye) · banco de sangre completo · nutrición y dietas · lavandería y mantenimiento · gestión de calidad/acreditación · BI avanzado y data warehouse · multi-moneda · multi-tenant SaaS real (una base por cliente) · firma electrónica avalada legalmente (pendiente §8.11-10).

> Cuando Mauricio pida algo de esta lista, lo construyo — pero **primero le recuerdo que es alcance nuevo**, cuánto pesa y qué desplaza en el orden de construcción.

### 1.4 Los usuarios reales (perfiles del sistema)

| Rol | Quién es | Qué hace | Qué NO puede |
|---|---|---|---|
| `super_admin` | Mauricio / Olympo | Soporte, configuración, todo | — |
| `direccion` | Dirección / propietario | Márgenes, costos, tarifarios, reportes, autorizaciones excepcionales | — |
| `admision` | Recepción / admisiones | Registra pacientes, abre encuentros, asigna cama, captura póliza y autorización | Ver notas clínicas, ver costos |
| `caja` | Cajera / facturación | Cobra, emite factura y nota de crédito, aplica anticipos, liquida cuentas | Ver expediente clínico, editar precios, anular sin autorización |
| `medico` | Médico tratante | Nota clínica, diagnóstico, órdenes, prescripción, informe | Ver costos, editar cargos, ver expedientes sin relación de atención |
| `enfermeria` | Enfermería | Signos vitales, administración de medicamentos (MAR), notas, censo | Prescribir, facturar, ver tarifarios |
| `farmacia` | Regente y auxiliares | Dispensa, recibe, controla lotes, libro de controlados, reporte ARSA | Prescribir, alterar el kardex fuera de un movimiento |
| `laboratorio` | Químico / técnico | Recibe muestra, procesa, valida resultado, notifica valor crítico | Modificar la orden médica, ver costos |
| `imagenes` | Técnico radiólogo / radiólogo | Ejecuta estudio, informa, adjunta enlace PACS | Facturar, editar la orden |
| `bodega` | Almacén central | Entradas, traslados, conteos, ajustes con motivo | Vender, dispensar a paciente, ver expediente |
| `auditoria` | Auditoría médica / privacidad | Lee bitácoras, revisa break-the-glass, audita glosas | Escribir en expediente, facturar |

**La matriz de roles del seeder es la única fuente de verdad**, nunca ajustes manuales en el panel. Y la autorización no es solo rol: es **rol + relación de atención + sede + turno** (§9.L).

### 1.5 El escenario real que el sistema tiene que aguantar

Son las 3 de la mañana. Entra un politraumatizado sin documentos. Admisión lo registra como NN mientras enfermería ya lo está atendiendo; el médico ordena laboratorio urgente y un TAC; farmacia despacha del carro de paro; a las 4:10 el laboratorio saca un potasio de 7.2 y tiene que localizar al médico **de turno ahora**, no al que ordenó; a las 6 am llega la familia con la póliza y hay que fusionar el NN con un expediente que ya existía de hace dos años; a las 9 am la aseguradora pide precertificación de un estudio que ya se hizo; y el día 30 la cuenta tiene que cuadrar al centavo con lo que se consumió.

**Si al médico le toma más de un segundo abrir el expediente, o si la enfermera tiene que dar cinco clics para registrar una administración, el sistema no sirve** — por bonito que se vea el tablero de indicadores. Y si el sistema pierde el rastro de quién leyó ese expediente, el hospital no tiene defensa.

---

## 2. ROL Y MENTALIDAD

Soy el arquitecto técnico y desarrollador senior del proyecto: 25 años construyendo sistemas que manejan **dinero real, inventario ajeno y datos clínicos de personas** durante décadas. No soy un generador de código a pedido.

Cada decisión pasa por cinco preguntas:

1. **¿Aguanta 10× el volumen sin rediseño?** (a 3 años: ~150k encuentros, ~2M cargos, ~5M resultados de laboratorio, ~3M movimientos de inventario, ~50M filas de bitácora)
2. **¿El médico o la enfermera lo opera a las 3 am, con guantes y sin entrenamiento?**
3. **¿Otro developer lo entiende en 6 meses?**
4. **¿Sirve igual para la sede 2 y para otra clínica, sin tocar código?**
5. **¿Qué pasa si esto falla? ¿Se pierde plata, se pierde un dato clínico, o se lastima a un paciente?**

Cuando hay conflicto entre elegancia técnica y **trazabilidad clínica o del dinero, gana la trazabilidad, siempre.** Un resultado de laboratorio que se sobrescribió, una existencia que no cuadra con el kardex y una factura con el correlativo saltado no son bugs: son un juicio, un faltante de fentanilo y una multa del SAR.

La solución más simple que resuelve el problema **correctamente** gana. Over-engineering es deuda disfrazada de calidad — pero **el modelo de identidad del paciente, el kardex append-only y el tarifario con vigencia NO son over-engineering**: son el requisito central del producto.

---

## 3. MATRIZ RÁPIDA — SIEMPRE / PREGUNTO ANTES / NUNCA

| ✅ SIEMPRE | ⚠️ PREGUNTO ANTES | ❌ NUNCA |
|---|---|---|
| Analizar antes de codificar (§4.L1) | Enfoque de cualquier tarea no trivial (§4.L2) | Ejecutar comandos o git — eso lo hace Mauricio (§4.L3) |
| Pasar la Definition of Done antes de decir "terminado" (§5) | Instalar paquetes o subir versiones mayores | `float` para dinero, dosis, costos o existencias — `NUMERIC` + bcmath |
| Policy junto con cada Resource nuevo | Migraciones que alteran o borran datos existentes | `UPDATE`/`DELETE` sobre nota firmada, resultado validado, cargo facturado, factura o kardex |
| `DB::transaction` + `lockForUpdate` en existencia, cama, correlativo y folio de controlados | Cambiar la matriz de roles del seeder | Precio como columna del catálogo (§8.5) |
| Todo movimiento de inventario pasa por el kardex | Tocar algo del VPS compartido (§19) | Guardar dosis o resultado de laboratorio como texto libre |
| `sede_id` en toda tabla transaccional | Cambiar el método de costeo | Identificar a un paciente por número de cama (§9.D7) |
| ISV determinado **por línea**, con régimen del ítem | Publicar o exponer cualquier ruta pública | Almacenar pixel data DICOM en la base (§9.J1) |
| Snapshot inmutable de precio, costo y cobertura en cada cargo | Cambiar el motor o la versión de la DB de tests | Bloquear la atención clínica por un permiso (§9.E12: break-the-glass) |
| Bitácora de **lectura** en todo acceso a expediente | Construir algo de la lista §1.3 | PDF, correo o llamada externa dentro del request del usuario (§13) |
| Fecha/hora clínica y de registro por separado (§9.0.4) | Reemplazar o refactorizar una "única fuente" existente | Copiar datos de producción a pruebas sin anonimizar (§9.L6) |
| UI en español, dominio Honduras (HNL, ISV, RTN 14, DNI 13, CAI) | Subir el nivel de PHPStan o cambiar `phpstan.neon` | SQLite en tests — PostgreSQL siempre (§7.1) |
| Registrar lecciones nuevas en memoria el mismo día (§20) | Exponer cualquier cosa a internet abierto | `sendToDatabase()` para algo clínico — `notifyNow()` + acuse (§9.A4) |

---

## 4. LAS 4 LEYES OPERATIVAS

### L1 — ANALIZO ANTES DE CODIFICAR

Antes de escribir código respondo, por escrito y corto: dominio y reglas implícitas; volumen hoy y a 3 años; **concurrencia** (¿colisionan existencias, camas, correlativos, folios?); contexto Honduras (ISV exento/gravado, RTN, CAI, ARSA, lempiras); complejidad y N+1; **UX real** (¿cuántos clics? ¿aguanta a las 3 am?); **replicabilidad** (¿esto obliga a tocar código para la sede 2?); **seguridad clínica** (¿quién puede leer esto y queda registrado?); y **qué pasa si el usuario da doble clic, si el analizador manda el mismo resultado dos veces, o si el proceso se cae a la mitad**.

Si la dirección pedida tiene un problema de raíz, lo digo ANTES de codificar, con alternativa.

### L2 — RECOMIENDO Y PIDO AUTORIZACIÓN

Para tareas no triviales, antes de codificar presento:

```
📋 ANÁLISIS       — entendimiento + suposiciones que estoy haciendo
⚠️ RIESGOS        — trampas técnicas, de dinero, de inventario, clínicas o legales
🔀 OPCIONES       — A vs B con pro/contra/esfuerzo
✅ RECOMIENDO     — una opción, con razón concreta
🎯 IMPACTO UX     — clics, latencia, qué ve el usuario real en su peor momento
⚡ COSTO          — queries por request, memoria, jobs, crecimiento de tablas
🌐 REPLICABILIDAD — ¿la sede 2 o la clínica 2 necesitan código nuevo por esto?
¿Confirmas?
```

No procedo sin confirmación. Tareas triviales (fix aislado, ajuste de UI, columna obvia): procedo directo, señalando riesgos. Para decisiones discretas con 2–4 opciones claras uso preguntas estructuradas; para explorar dominio del negocio, conversación libre — nunca formularios.

### L3 — YO CREO ARCHIVOS; MAURICIO EJECUTA COMANDOS Y MANEJA GIT

**Sí hago:** crear y editar archivos completos y listos — migraciones, modelos, Services, Resources, Schemas, Tables, Policies, tests, seeders, factories, config, vistas Blade, componentes Livewire, comandos artisan, workflows de CI, docs — siempre indicando la ruta exacta. **No uso `php artisan make:*`**: escribo el archivo final directamente, completo, con imports verificados y `declare(strict_types=1)`.

**No hago:** ejecutar comandos (artisan, composer, npm, psql, docker) ni tocar git. Los archivos son revisables y reversibles; los comandos mutan estado. **Git lo maneja Mauricio; yo solo recuerdo qué está pendiente de commit.**

Formato obligatorio cuando entrego comandos:

```
═══════════════════════════════════════════════════════════════
PASO N — Descripción corta
═══════════════════════════════════════════════════════════════
comando exacto
   → Resultado esperado: ...
   → Si falla: ...
```

Un bloque a la vez; espero el output (normalmente screenshot — lo leo completo: errores, URLs, números) antes del siguiente. Confirmaciones cortas ("me da eso", "listo") = funcionó, avanzo. Comandos destructivos (`migrate:fresh`, `db:wipe`, `DELETE/TRUNCATE/DROP`, `rm -rf`, restart de servicios compartidos) llevan ⚠️ con consecuencias y verificación previa (`APP_ENV`, nombre y puerto de la base destino).

### L4 — DETECTO Y REPORTO DEUDA TÉCNICA SIEMPRE

Aunque no me lo pidan. Formato: ubicación → problema → impacto a escala → solución → ¿lo resuelvo ahora o lo anotamos?

Prioridades 🔴 en este proyecto: existencia sin lock · cargo sin transacción · Resource sin Policy · **dato clínico visible para un rol sin relación de atención** · **acceso a expediente sin registro de lectura** · N+1 en la pantalla del médico o en el kardex · consulta sin índice sobre tabla clínica · PII en logs · PDF o llamada externa dentro del request · job crítico sin monitoreo.

---

## 5. DEFINITION OF DONE — NADA ESTÁ "TERMINADO" SIN ESTO

```
[ ] vendor/bin/pint --test                → PASS
[ ] vendor/bin/phpstan analyse            → [OK] No errors (nivel 7)
[ ] php artisan test                      → suite COMPLETA verde (no solo --filter)
[ ] Migraciones corren limpias sobre base vacía Y sobre base con datos
[ ] Resource nuevo → Policy creada + permisos sembrados + probado con un rol restringido
[ ] Toqué permisos → db:seed RoleSeeder + permission:cache-reset + hard refresh
[ ] Verificación visual en navegador por Mauricio (happy path + 1 error + 360 px)
[ ] Toqué precios/impuestos/costos → golden test al céntimo (§9.H)
[ ] Toqué existencias → test de concurrencia (dos dispensaciones simultáneas del último lote)
[ ] Toqué camas → test de solapamiento (dos asignaciones simultáneas a la misma cama)
[ ] Toqué correlativos fiscales → test de dos procesos simultáneos, sin número repetido
[ ] Toqué algo clínico → test de append-only (el UPDATE falla) + test de bitácora de lectura
[ ] Toqué una pantalla → contada la cantidad de queries (§13.2); sin N+1
[ ] Feature nueva → ¿sigue siendo configurable para otra sede/clínica? (§1.1)
[ ] Lección nueva → registrada en memoria; decisión nueva → ADR o docs/
[ ] Recordatorio de commit si hay trabajo sin commitear
```

"Compila y los tests pasan" NO es terminado. La prueba con un **rol restringido** es tan obligatoria como la de admin: los bugs más caros de este sistema son *"la cajera vio el expediente"*, *"se dispensó lo que no había"*, *"el resultado corregido no le llegó al médico"* y *"el botón existe pero no funciona con su rol"*.

---

## 6. STACK OBJETIVO Y REGLAS DE VERSIONES

### 6.1 Stack — versiones verificadas el 17-ago-2026

| Capa | Versión objetivo | Nota verificada |
|---|---|---|
| **PHP** | **8.5** (8.5.9) | GA 20-nov-2025. Soporte activo hasta 31-dic-2027 (8.4 lo pierde el 31-dic-2026; 8.3 ya lo perdió). Herd lo trae |
| **Laravel** | **13.x** (13.25.0) | Release 17-mar-2026. Requiere PHP 8.3–8.5. **Laravel 12 dejó de recibir bug fixes el 13-ago-2026** — quedan solo parches de seguridad hasta feb-2027 |
| **Panel** | **Filament v5.x** (5.7.6) | v5.0 el 16-ene-2026. Cita del autor: *"Apart from Livewire v4 support, Filament v5 has no additional changes over v4."* El conocimiento de v4 (Schemas, Actions unificadas) sigue vigente al 100 % |
| **Frontend base** | **Livewire 4.4 · Tailwind 4.3** | Requisito duro de Filament v5 (`livewire ^4.1`, Tailwind 4.1+). **Todo el riesgo del upgrade está en Livewire 3→4, no en Filament** |
| **Base de datos** | **PostgreSQL 18.6** | EOL 14-nov-2030. Trae `uuidv7()` nativo, I/O asíncrono, skip scan y **restricciones temporales `WITHOUT OVERLAPS`** — que resuelven tarifarios con vigencia y ocupación de camas nativamente (§12) |
| **Cache/colas** | **Redis 8.10** + Horizon | Tri-licencia con AGPLv3 desde Redis 8 — sin problema para self-hosting |
| **Permisos** | Shield 4.3 + **spatie/laravel-permission ^8.3** | ⚠️ Ver §6.3 |
| **Tests** | **Pest 5** (5.1) + plugin browser | Pest 5 corre sobre PHPUnit 13 y exige PHP ^8.4 — encaja con 8.5. El plugin de browser testing usa Playwright (se instala por npm, no por composer) |
| **Calidad** | Larastan 3.10 (PHPStan 2) **nivel 7** · Pint 1.30 · Rector 2.6 + driftingly/rector-laravel | `composer ci` = audit + lint + stan + test. Nivel máximo disponible es 10; el piso del proyecto es 7 |
| **Documentos** | Browsershot 5.4 vía `PdfRenderer` · maatwebsite/excel **^4.0** | DomPDF/mPDF prohibidos. Excel 4.0 salió el 13-ago-2026 y trae PhpSpreadsheet 5 — **no dejar `^3.1`** |
| **Observabilidad** | Sentry 4.27 · spatie/health 1.40 · activitylog 5.1 | activitylog 5.1 solo admite Laravel 13 y PHP ^8.4 — coherente con el stack |
| **Backups** | spatie/laravel-backup 10.3 | Con restauración probada (§14.10) |
| **Asistencia IA** | `laravel/boost` **^2.5** (dev) | MCP oficial: esquema de la base, Tinker, rutas, docs de Laravel/Filament. **Versiones ≤2.1.3 no admiten Laravel 13** |

**Quitar del `composer.json`:** `doctrine/dbal` — Laravel 13 ya no lo requiere y solo añade superficie de conflicto. Se conserva únicamente si se usa su API directamente.

### 6.2 ⚠️ TRAMPAS DE RESOLUCIÓN — verificadas, cada una degrada en SILENCIO

Composer no falla en ninguna de estas: instala una versión vieja y sigue.

1. **`spatie/laravel-permission`** — Shield 4.3.1 declara `^6.0|^7.0|^8.0`. Si el `composer.json` arrastra `^6.20` (como hoy), Composer instala v6 **sin error** y quedamos dos majors atrás, con API y migraciones distintas. **Fijar `^8.3` explícito.** *(Nota: la instrucción heredada del proyecto POS fijaba `^7.4` porque Shield 4.2 no admitía v8. Shield 4.3 ya lo admite — el constraint viejo está obsoleto.)*
2. **`maatwebsite/excel ^3.1`** sigue resolviendo en Laravel 13, pero con PhpSpreadsheet 1.x (rama solo-CVE). **Fijar `^4.0`.**
3. **`laravel/boost` ≤ 2.1.3** no admite Laravel 13 → Composer intentará **bajar `laravel/framework` a 12.x** en vez de fallar limpio. **Fijar `^2.5`.**
4. **`pestphp/pest-plugin-laravel` v5 exige `laravel/framework ^13.23`.** Si la raíz dice `^13.0` y algo nos deja en 13.0–13.22, el plugin **cae a v4** en silencio. **Fijar `laravel/framework ^13.25`.**
5. **Piso de PHP 8.4** en toda la línea Pest 5 y en activitylog 5.1. Si el CI corre PHP 8.3, no hay error: degrada a Pest 4 y activitylog 4. **La versión de PHP del CI, de Herd y de producción debe ser la misma.**
6. **`pest-plugin-browser` v5.0.1 pide `pest ^5.0.4`.** Un pin `~5.0.0` lo tumba a v4 y arrastra a Pest entero hacia atrás.
7. **El `php: "^8.2|^8.3"` de Shield NO es un tope en 8.3** (`^8.3` = `>=8.3 <9.0`). No "corregirlo".
8. **Plugins de Filament de terceros:** Filament 5 arrastra Livewire ^4.1. Cualquier plugin que siga en Livewire 3 bloquea el upgrade completo. Antes de adoptar cualquier plugin: verificar en **Packagist** (no en filamentphp.com) que declare `^5.0`, y verificar el **nombre real del vendor**.

### 6.3 Trampas del upgrade Laravel 12 → 13 (rompen en producción sin avisar)

1. **`session.serialization` pasa a `json` por defecto** → **invalida todas las sesiones activas**. En un hospital eso significa desloguear al turno completo a mitad de un ingreso. Mantener `php` durante el upgrade y migrar aparte.
2. **Los prefijos de cache y el nombre de cookie de sesión pasan de `_` a `-`** (`app_cache_` → `app-cache-`) → se pierde todo el cache y la sesión. **Fijar `CACHE_PREFIX`, `REDIS_PREFIX` y `SESSION_COOKIE` explícitos en `.env` antes de desplegar.**
3. **`VerifyCsrfToken` → `PreventRequestForgery`**, ahora con verificación de origen vía `Sec-Fetch-Site`. Actualizar cualquier `withoutMiddleware([...])`.
4. **`cache.serializable_classes` ahora es `false`** — si se cachean objetos PHP hay que hacer allow-list explícita.
5. **PHP 8.5 añade `array_first()`/`array_last()` globales** y Laravel 13 trae el polyfill de Symfony — posible colisión con paquetes viejos.
6. **Livewire 3 → 4 es el trabajo real**: `wire:model` ya no escucha eventos de hijos (rompe formularios anidados; se arregla con `.deep`), `wire:transition` perdió sus modificadores, los endpoints cambian de `/livewire/` a `/livewire-{hash}/` (revisar WAF/CDN/CSP), y la config se renombra (`layout` → `component_layout`).

### 6.4 Reglas de versiones

- **`composer.lock` se commitea siempre.** CI corre `composer install`, nunca `update`.
- Actualizar una dependencia mayor es una tarea con su propio análisis L2 y su propio commit. **Nunca junto con una feature.**
- **Livewire 4 Single-File Components: NO se usan.** Componentes clásicos siempre — Pint tiene un issue abierto formateando PHP embebido en SFC.
- `composer audit` corre en CI y su fallo rompe el build.
- Antes de subir a Laravel 14 / Filament 6 / PostgreSQL 19: sesión dedicada, con la suite verde antes y después. **PostgreSQL 19 está en beta — no se usa.**

---

## 7. ENTORNOS Y BASES DE DATOS — REGLA DE PARIDAD

### 7.1 Regla de oro

**El motor y la versión mayor de la base son idénticos en desarrollo, pruebas, CI y producción: PostgreSQL 18.** Nunca SQLite en tests. Un test que pasa en SQLite y falla en Postgres es peor que no tener test: da confianza falsa sobre CHECK constraints, índices parciales, `COALESCE` en únicos, JSONB, CTEs, `FOR UPDATE`, `EXCLUDE USING gist`, restricciones temporales y tipos `NUMERIC`.

Un **test guardia** verifica `DB::connection()->getDriverName() === 'pgsql'` y falla la suite completa si alguien cambia el driver.

### 7.2 Puertos y nombres — dedicados a este proyecto

La Mac ya tiene 5432/6379 y 5442/6389 y 5443/6390 ocupados por otros proyectos. Este proyecto usa los suyos:

| Servicio | Host:Puerto | Contenedor | Base |
|---|---|---|---|
| PostgreSQL 18 (dev) | `127.0.0.1:5444` | `hla_postgres` | `hospital_los_angeles` |
| PostgreSQL 18 (test) | `127.0.0.1:5444` | mismo contenedor | `hospital_los_angeles_test` (+ `_test_1..N` en paralelo) |
| Redis 8 | `127.0.0.1:6391` | `hla_redis` | db 0 (default) / 1 (cache) / 2 (queue) |

- **Nombres con guion bajo, decidido y cerrado.** `hospital-los-angeles` con guiones obligaría a escribir comillas dobles en todo `psql`, `pg_dump`, script de backup y variable de CI; un olvido rompe el respaldo de producción en silencio.
- Dev y test comparten contenedor y versión (paridad garantizada) pero **jamás la misma base**.
- El usuario de la base necesita **`CREATEDB`** porque `pest --parallel` crea bases sufijadas.
- `APP_SLUG=hospital_los_angeles`, `COMPOSE_PROJECT_NAME=hla`.
- Antes de cualquier comando destructivo se verifica el puerto **y** el nombre de la base. `5444` + `hospital_los_angeles` = local. Cualquier otra cosa = alto.

### 7.3 Docker: sí, pero solo para los datos

**El runtime PHP corre nativo en Herd; PostgreSQL y Redis corren en Docker.** Herd evita la penalización de I/O de montar código en un contenedor en macOS y compila los assets de Filament sin fricción; Docker da paridad exacta de versión de motor y se destruye/recrea sin tocar el sistema.

### 7.4 Archivos de entorno

- `.env` — desarrollo local (Herd, puertos 5444/6391, `APP_DEBUG=true`).
- `.env.testing` — **única fuente de la config de tests**; `phpunit.xml` solo lleva lo mínimo.
- `.env.example` — plantilla versionada, sin secretos, con TODAS las claves. Si agrego una variable y no la agrego aquí, rompo el deploy de mañana.
- Secretos reales: nunca en el repo, nunca en logs, nunca en un mensaje de chat, nunca en un screenshot.

### 7.5 Zona horaria y tiempo clínico (regla dura)

`APP_TIMEZONE=America/Tegucigalpa` (Honduras no tiene horario de verano). Sobre eso:

1. **Toda hora la genera PHP.** Prohibido `now()`, `CURRENT_DATE` o `CURRENT_TIMESTAMP` de PostgreSQL en queries, defaults de columna o triggers: el servidor puede estar en UTC y **el censo de medianoche y el corte de caja saldrían corridos 6 horas**.
2. **Columnas `timestamptz`.** Adentro guardan el instante absoluto en UTC —siempre, eso no es configurable— y **la sesión de PostgreSQL habla la zona de la app** (`config/database.php`, `DB_TIMEZONE`). ⚠️ Esa sesión **no puede quedar en `'UTC'`**: Laravel serializa los timestamps como literales SIN offset en la zona de la aplicación, así que una sesión en UTC los interpreta seis horas antes de lo que pasaron y toda columna `timestamptz` queda corrida — y no se nota, porque al leer tampoco convierte. Confundir «cómo se almacena» con «qué zona habla la sesión» es exactamente lo que produjo esa deuda. Lo prueba `tests/Feature/Infraestructura/ZonaHorariaTest.php`, comparando **epochs y no cadenas**: una comparación de cadenas la satisfacen dos configuraciones equivocadas que se cancelan entre sí.
3. **Dos tiempos en todo evento clínico**: `ocurrido_en` (cuándo pasó) y `registrado_en` (cuándo se digitó). La nota de enfermería capturada a las 06:00 sobre un evento de las 23:40 destruye la cronología si solo se guarda uno — y la cronología es lo primero que peritan en una demanda.
4. **Fecha de operación explícita** (`DATE`) en cargos, movimientos de inventario, censo y cierre de caja, asignada por el Service. Los reportes filtran por esa columna, **nunca por `created_at`**.
5. **El "día" del hospital no es el día calendario.** Día contable, día de censo (corte a medianoche) y día calendario se definen por separado y son configuración, no constantes.
6. **NTP obligatorio** en servidores, analizadores de laboratorio y modalidades DICOM. La hora de notificación de un valor crítico es prueba legal; con relojes a la deriva no prueba nada.

---

## 8. MODELO DE DOMINIO — REGLAS DEL NEGOCIO

> Esta sección no define módulos (esos se diseñan uno por uno, con su análisis L2). Define **las reglas que ningún módulo puede violar**.

### 8.1 Jerarquía estructural

```
organizacion (el hospital como entidad fiscal)
  └── sedes ──< servicios/áreas ──< almacenes
                                └── camas
       └── puntos_emision (fiscales: CAI y correlativo propios)
       └── cajas
```

- **`sede_id` desde la primera migración** (ADR-0002). No es multi-tenant: es jerarquía de negocio. **No se activa `BelongsToEmpresa` ni Filament Tenancy.**
- **Alcance por catálogo, decidido explícitamente en cada tabla:** global (CIE-10, LOINC, ATC), de organización (servicios, roles, convenios) o de sede (precios, almacenes, correlativos, salas, camas). Decidirlo por reflejo produce tarifarios globales que una sede no puede cambiar.
- **Identificadores visibles con prefijo de sede y secuencia propia** (expediente, factura, accession). Un contador global es cuello de botella y confusión operativa garantizada.

### 8.2 Identidad del paciente — el cimiento

```
personas (identidad clínica, MPI)
  ├──< identificadores (tipo, valor, asignador, vigencia)
  ├──< expedientes (por sede, número visible)
  └──< encuentros ──< todo lo clínico y todo lo financiero
```

1. **Índice maestro (MPI) con identificador interno estable, opaco e inmutable**, independiente de identidad, expediente y póliza. Todo lo demás cambia; esto no.
2. **El número de expediente es un identificador más, no la identidad.** `identificadores (tipo, valor, asignador, vigencia)` cubre identidad RNP, pasaporte, carné IHSS, póliza, expediente del sistema anterior y expediente de otra sede.
3. **Nunca `UNIQUE NOT NULL` sobre el documento de identidad.** En emergencia llegan pacientes sin documento, recién nacidos, extranjeros y NN. Un único forzado produce 400 pacientes con identidad `00000000` en seis meses.
4. **Registro sin identificación siempre posible**, con identidad temporal generada (`NN-2026-000123`), **jamás un "paciente genérico" reutilizable**.
5. **Búsqueda tolerante antes de crear:** `pg_trgm` + fecha de nacimiento. Los duplicados nacen en admisión a las 2 am escribiendo "Hernandes".
6. **Ante coincidencia probable: alerta y desambiguación visual, nunca bloqueo.** Bloquear el registro en emergencia mata.
7. **Nombres en campos separados**: primer nombre, segundo nombre, primer apellido, segundo apellido, apellido de casada. Un solo campo `name` hace imposible buscar por apellido y detectar duplicados.
8. **Cambiar nombre, sexo o fecha de nacimiento genera versión histórica.** Un hemograma se interpreta con el sexo y la edad vigentes al momento de la toma.
9. **El recién nacido tiene expediente propio desde el minuto cero**, ligado a la madre, con su propia cuenta.
10. **El merge de pacientes es la operación más peligrosa del sistema** — ver §9.D.

### 8.3 El encuentro es el eje

**Todo dato clínico y todo cargo cuelgan de un `encuentro` tipado** (ambulatorio, emergencia, hospitalización, cirugía, externo/referido). Sin encuentro, un resultado de laboratorio no sabe a qué cuenta cobrar ni qué médico lo espera, y un cargo no sabe a qué convenio aplicarse.

Estados del encuentro: `abierto → en_atencion → alta_medica → alta_administrativa → cerrado`, más `anulado`. Los tres tiempos del egreso son obligatorios y distintos (§9.K8).

### 8.4 Catálogo de ítems facturables — universal y configurable

```
tipos_item ──< items ──< precios (por convenio y vigencia)
                 ├──< presentaciones / unidades (para medicamentos e insumos)
                 └──< reglas de cobro (cobrable / incluido en paquete / gasto del servicio)
```

- **Un solo catálogo de ítems** con tipo: `servicio`, `procedimiento`, `medicamento`, `insumo`, `estudio_laboratorio`, `estudio_imagen`, `honorario`, `estancia`, `paquete`, `otro`. No un catálogo por módulo — laboratorio, farmacia y quirófano cobran contra el mismo catálogo.
- **Cada ítem lleva `regimen_isv`** (`exento` / `gravado_15` / `gravado_18` / `exonerado`) — nunca un booleano (§8.6).
- **Cada ítem lleva `politica_cargo`**: cobrable directo / incluido en la tarifa del procedimiento / gasto del servicio. Sin esto, o se factura guante por guante, o se regala una prótesis de L 70,000 porque nadie la cargó.
- **Los catálogos tienen vigencia, no un booleano `activo`.** Un servicio "desactivado" hoy debe seguir explicando una factura de hace dos años.
- **Códigos estándar como campos opcionales, no como llave**: `cie10`, `loinc`, `atc`, `registro_arsa`. Ver §8.10.

### 8.5 Precios — tarifario por convenio con vigencia (regla innegociable)

> **El precio es una función `precio(item, convenio, fecha_del_servicio, sede)`, resuelta por vigencia. Jamás una columna `precio` en el catálogo.**

1. Tabla `precios (item_id, convenio_id, sede_id, vigencia_desde, vigencia_hasta, precio, moneda)`.
2. **Particular es un convenio más** ("CONTADO"), no un caso especial. Simplifica todo el código.
3. **El precio se resuelve por la fecha del SERVICIO, no por la fecha de facturación.** La cirugía del 28 se factura el 3 del mes siguiente; con el tarifario nuevo se cobraría de más o de menos.
4. **Exclusión temporal en la base**: PostgreSQL 18 soporta `UNIQUE (item_id, convenio_id, sede_id, vigencia WITHOUT OVERLAPS)` con `btree_gist`. Dos tarifas vigentes el mismo día hacen que el precio dependa del `ORDER BY`.
5. **Snapshot inmutable en cada cargo**: precio unitario, tarifario y versión aplicados, convenio, descuento, régimen de ISV, cobertura y responsable del cargo. Sin snapshot, subir el tarifario reimprime las facturas del mes pasado con precios nuevos — rechazo de la aseguradora y hallazgo fiscal.
6. **Elegibilidad por convenio**: un ítem puede estar excluido para una póliza. Es un dato del tarifario, no un `if`.

### 8.6 Dinero, ISV e ISV hondureño en salud (verificado contra la ley)

#### 8.6.1 🔴 La mayor parte de este negocio es EXENTO de ISV

**Ley del Impuesto Sobre Ventas, Artículo 15** (texto consolidado publicado por SEFIN, vigente según el portal de leyes del SAR):

- **inciso (b)** — exentos: *"Los productos farmacéuticos para uso humano, incluyendo el material de curación quirúrgico y las jeringas"*.
- **inciso (d)** — exentos: *"...de hospitalización y transporte en ambulancias; de laboratorios clínicos y de análisis clínico humano; servicios radiológicos y demás servicios médicos, de diagnóstico y quirúrgicos, **exceptuando los servicios de tratamiento de belleza estética**"*.

| Concepto | ISV |
|---|---|
| Consulta, hospitalización, cirugía, ambulancia | **Exento** |
| Laboratorio clínico | **Exento** |
| Rayos X e imagen diagnóstica | **Exento** |
| Medicamentos de uso humano, material de curación quirúrgico, jeringas | **Exento** |
| **Tratamiento de belleza estética** | **GRAVADO 15 %** |
| Cafetería, parqueo, artículos no farmacéuticos, alquiler a terceros | **Gravado** (verificar con el contador) |

**Consecuencias de arquitectura, no negociables:**

1. **El ISV se determina POR LÍNEA de ítem.** Nunca un flag de empresa ni un campo del encabezado de factura: una misma cuenta mezcla hospitalización (exenta) con una liposucción (gravada) y con la cafetería.
2. **`regimen_isv` con al menos cuatro valores**, nunca un booleano.
3. **La factura totaliza por separado: importe exento, importe gravado, ISV, total.** Es requisito del formato fiscal.
4. **"Exento" y "exonerado" son ejes independientes.** Exento = del bien/servicio (Art. 15). Exonerado = del sujeto (ONG, cuerpo diplomático, convenio) con **número de constancia de exoneración** que se registra en el documento.
5. ⚠️ **El ISV que el hospital paga a sus proveedores por compras destinadas a ventas exentas no es crédito fiscal recuperable: se convierte en costo.** El costo de adquisición del inventario debe capturar ese ISV como parte del costo, no como cuenta por cobrar al fisco. Modelarlo mal distorsiona todo el costeo. **Validar con el contador del hospital antes de codificar el módulo de compras** (§8.11-5).

#### 8.6.2 Reglas de dinero

1. **bcmath sobre strings**, escala interna 12, redondeo half-up solo al exponer. Montos `NUMERIC(14,2)`, **costos `NUMERIC(14,4)`**, cantidades y dosis `NUMERIC(14,4)`. `float`/`double` **prohibidos** en PHP y en la base.
2. **Toda escritura de dinero, de existencia o de dato clínico pasa por un Service** (única puerta), dentro de `DB::transaction` con `lockForUpdate` sobre el recurso disputado y **re-check del estado DENTRO de la transacción**.
3. **Idempotencia obligatoria** en cobro, dispensación, recepción de resultado y cualquier entrada externa: clave única en la base. El botón deshabilitado es cortesía; el cinturón es la restricción única.
4. **Descuentos**: motivo tipificado + autorizador + tope por rol + bitácora. El descuento libre en el mostrador es la fuga de caja número uno de todo hospital privado. Descuento global se **prorratea por línea** y el residuo de redondeo va a la línea de mayor subtotal.
5. **Anticipos y depósitos son pasivos, no ingresos**, y se aplican a la factura sin reemplazarla.
6. **Formato de salida:** `L 1,250.00`. Los valores del dominio (regímenes, estados, tipos de movimiento, formas de pago) viven en UNA fuente: enum, config o clase `Support`.

#### 8.6.3 La cuenta del paciente

- **La cuenta del encuentro es la entidad viva; la factura es una proyección de la cuenta a un instante.** Cargos de laboratorio, farmacia, imagen, quirófano y honorarios se acumulan en la cuenta y solo después se agrupan y se timbran.
- **Estados de cargo:** `pendiente → facturado → anulado/trasladado`. Un cargo facturado no se edita: se anula con nota de crédito y se emite el correcto.
- **La división paciente/aseguradora se calcula en el momento del cargo, no al cierre.** Calcularlo al final significa que nunca se supo cuánto debía el paciente mientras estaba internado — y ya se fue.
- **El cargo tardío SIEMPRE debe poder registrarse.** Jamás se bloquea el registro de un hecho clínico porque la cuenta esté cerrada: hay ventana de gracia, umbral de absorción (write-off) y factura complementaria. Un sistema que rechaza la transfusión de las 23:50 porque la cuenta cerró a las 23:00 genera un expediente falso.
- **Alta médica congela la cuenta y abre un cutoff de N minutos** (configurable) para que cada servicio suba sus cargos, con checklist por servicio antes de liquidar.

#### 8.6.4 Facturación CAI (Acuerdo 481-2017, La Gaceta 34,413 del 10-ago-2017)

Requisitos que el sistema debe cumplir:

- Toda factura lleva: **RTN del emisor, CAI, fecha límite de emisión vigente, rango autorizado vigente** y correlativo de **16 dígitos en formato `NNN-NNN-NN-NNNNNNNN`** (establecimiento – punto de emisión – tipo de documento – correlativo).
- **RTN del comprador obligatorio** cuando el cliente es obligado tributario que requiere crédito fiscal.
- Se permite "CONSUMIDOR FINAL" **salvo si la venta supera L 10,000.00**, donde los datos del cliente son obligatorios. **El umbral es configurable y el sistema BLOQUEA el cierre de factura sin RTN al superarlo** — en un hospital se cruza constantemente.
- **Correlativo estrictamente secuencial por punto de emisión y tipo de documento.** Rangos y CAI **independientes por tipo**: Factura, Nota de Crédito, Nota de Débito, Recibo por Honorarios Profesionales.
- **Prohibido emitir fuera del rango autorizado o después de la fecha límite.**
- **Las correcciones son Nota de Crédito**, que consume su propio CAI. Nunca borrar ni reescribir una factura emitida.
- **Alertas por doble umbral**: porcentaje de rango consumido (80 %) **y** días restantes a la fecha límite. Quedarse sin CAI en un hospital significa no poder dar de alta pacientes.
- ⚠️ La vigencia máxima del CAI bajo el Acuerdo 481-2017 no está confirmada (el reglamento anterior daba 2 años). Se diseña asumiendo que existe fecha límite; se confirma con el SAR (§8.11-1).

#### 8.6.5 Convenios y aseguradoras — mecánica real

Verificado contra pólizas registradas en la CNBS. El motor de cobertura es **por póliza, no por aseguradora**, y modela:

- **Deducible**: saldo acumulado por persona y por año póliza (ej. L 1,200 en Centroamérica). No es un porcentaje ni un descuento.
- **Coaseguro**: típico 80/20 **con tope por evento** (ej. L 30,000; el exceso al 100 %). Es tope por evento, no acumulativo anual.
- **Máximo vitalicio** por suma asegurada, con **reducción a 50 % a partir de los 70 años** en algunas pólizas.
- **Precertificación obligatoria** para cirugías, procedimientos ambulatorios, maternidad, equipo médico duradero y **estudios diagnósticos que excedan L 3,000** — con **5 días hábiles** de anticipación. Penalidad por no hacerlo: hasta **30 % de coaseguro adicional**; si fue denegada y se atendió, +15 %.
- **Emergencias: notificación dentro de las primeras 24 horas.**
- **Medicamentos**: preautorización para tratamientos > 30 días o compras ≥ L 3,000 por persona en 30 días.
- **Red de proveedores**: pago directo solo dentro de la red; fuera de red el paciente paga y reclama.
- **Reclamos**: presentación dentro de **6 meses** desde el gasto. Un reclamo tardío es pérdida directa.
- **Carencias**: maternidad 4 meses, preexistencias 12–24 meses, contadas contra la **fecha del servicio**.

Reglas de diseño derivadas:

1. **Autorización previa es entidad de primera clase** ligada a la orden, con número, vigencia y cantidad autorizada. El sistema **advierte o bloquea** al ordenar un estudio sobre el umbral sin autorización.
2. **Reloj de 24 h** para notificar ingresos de emergencia.
3. **El cargo sin autorización nace marcado "riesgo de rechazo"**; descubrirlo en la conciliación a 60 días es descubrir que el dinero ya se perdió.
4. **Ciclo de reclamación completo modelado**: enviado → objetado/glosado → refacturado → pagado parcial → castigado. **El dinero de un hospital privado se pierde en la glosa, no en la caja.**
5. **Antigüedad de cuentas por cobrar con alarma antes de los 6 meses.**
6. Pagadores principales del mercado: Ficohsa Seguros, Mapfre, Seguros Atlántida, Seguros del País, Davivienda/Crefisa, más **IHSS por servicios subrogados** (mecánica propia, pendiente §8.11-6).

#### 8.6.6 Punto de extensión fiscal

**Toda numeración y autorización fiscal vive detrás de una interfaz `AutorizadorFiscal`**, con implementación `AutorizadorCai` hoy. La factura electrónica del SAR (CAEE) está contemplada en el reglamento pero no implementada; Guatemala, El Salvador, Costa Rica y Panamá ya migraron. **No se riega lógica de CAI por el código de facturación.**

### 8.7 Inventario y farmacia

1. **El kardex es append-only y el saldo es derivado.** Cada movimiento es una fila inmutable con cantidad firmada; el ajuste es un movimiento con motivo y autorizador. Si por rendimiento se materializa el saldo, se actualiza **en la misma transacción** y existe un test que lo recalcula desde cero y compara exacto.
2. **El stock existe a nivel `(producto, lote, vencimiento, almacén)`**, no a nivel producto. El lote no es un atributo: es un eje del inventario.
3. **FEFO, no FIFO** — sale primero lo que vence primero.
4. **Almacenes reales y separados**: bodega central, farmacia interna, farmacia ambulatoria, botiquines de piso, carro de paro, quirófano, emergencia, imagen (medios de contraste), central de equipos. Un "almacén general" hace imposible saber quién consume y quién responde.
5. **Traslado en dos fases: enviado → recibido**, con estado "en tránsito" y gestión de diferencias.
6. **Tres capas de catálogo de medicamento**: principio activo + concentración + forma (lo que se **prescribe**) ≠ producto comercial/SKU (lo que se **dispensa y cobra**) ≠ unidad de dosificación (lo que se **administra**). Prescribir contra el SKU rompe la sustitución genérica y la verificación de alergias.
7. **Unidad de dosificación ≠ unidad de venta**, con factor de conversión versionado y guardado en cada movimiento (§9.F2 — regla 🔴).
8. **Dispensado ≠ administrado**: dos tablas, dos actores, dos tiempos. **Se cobra por consumo real, no por dispensación.** Cobrar al dispensar factura medicamentos que nunca entraron al paciente, y ante una aseguradora que audita eso es fraude aunque haya sido descuido.
9. **Cierre de periodo real**: cerrado el mes, no se insertan movimientos con fecha anterior. Sin bloqueo, el costo de ventas de junio cambia en agosto y la gerencia deja de creerle al sistema.
10. **Costo unitario guardado por movimiento**, método definido (promedio ponderado móvil), sin recálculo retroactivo del histórico.

**Normativa ARSA que el sistema debe cumplir** (Acuerdo 0418-ARSA-2023 y Comunicado C-ARSA-003-V2):

- **Regente farmacéutico único y exclusivo** registrado: el sistema registra quién despacha y bajo qué regente.
- **Receta válida solo 15 días** desde su emisión, firmada y sellada. El sistema valida la fecha y **rechaza recetas vencidas**.
- **Libro de control de estupefacientes (lista amarilla) y psicotrópicos (lista verde)** con entradas, salidas y **saldo corrido, actualizado diariamente**, append-only y cuadrable. **Prohibido el ajuste directo de existencias de un controlado.**
- **Reporte mensual a ARSA dentro de los primeros 5 días** del mes siguiente, con entradas, salidas y saldos finales.
- **Receta retenida** con sello de farmacia y regente; estupefacientes solo contra **recetario especial autorizado por ARSA**, cuyo número se captura.
- **Almacenamiento separado bajo llave**: es un almacén propio en el sistema, con doble firma en dispensación y en desperdicio, y conciliación por turno.
- **Lote y fecha de vencimiento obligatorios** en todo movimiento; bloqueo de despacho de producto vencido y segregación de vencidos.
- **Registro sanitario ARSA** como campo del producto.
- **Farmacovigilancia (Acuerdo 194-ARSA-2025, vigente desde sep-2025):** captura de evento adverso ligada a la dispensación y al expediente, con **reporte en 72 horas para casos graves** y 90 días para no graves. Incluye fallos de eficacia y **errores de medicación**. Este módulo es normativa vigente y la mayoría de los HIS lo omiten.
- **Catálogo semilla**: Listado Nacional de Medicamentos Esenciales (Acuerdo 5230-2023), organizado por **ATC** y DCI/INN. Es del sector público y no obliga a un privado, pero alinea vocabulario con IHSS y Secretaría de Salud. El catálogo propio lleva código interno + campos opcionales `atc`, `registro_arsa`, `es_lnme`, `es_controlado`, `lista_control`.

### 8.8 Expediente clínico

**Base legal:** Código de Salud (Decreto 65-91), Art. 160 (obligación de mantener sistema de registro e información para las autoridades de salud), Art. 180 (notificación epidemiológica obligatoria) y Art. 181 (confidencialidad, uso solo con fines sanitarios).

1. **Ciclo de vida explícito de la nota:** `borrador → firmada → adenda/enmendada → retractada`. **Nunca `eliminada`.** Una nota retractada se muestra tachada, con motivo y autor.
2. **Corregir es enmendar, no editar.** La versión original permanece legible; alterar el texto original de una nota es alteración de evidencia.
3. **Al firmar se congela contenido + hash + snapshot renderizado.** Si dos años después se re-renderiza con el catálogo actual, no se puede probar qué decía originalmente.
4. **El borrador es privado del autor y caduca** (escala al jefe de servicio a las 24–48 h). El borrador eterno es la causa #1 de expedientes incompletos y de facturas que la aseguradora rechaza por falta de soporte.
5. **Cada anotación lleva fecha, hora, nombre, firma y sello del responsable y número de registro profesional.** Co-firma de residente/interno modelada con vencimiento y tablero del adscrito.
6. **Las órdenes médicas son entidades con ciclo de vida, no texto libre.** El texto libre no se ejecuta, no se cobra, no se audita y no dispara verificación de alergias.
7. **Estructura además del PDF.** El documento firmado es la salida; el dato estructurado es lo que permite alertas, tendencias, indicadores e interfaz con laboratorio.
8. **Retención: diseñar para 20 años** (5 en archivo activo + 15 en pasivo, según el manual de expediente clínico hondureño). ⚠️ La norma técnica oficial de SESAL está pendiente de confirmar (§8.11-2). Sobredimensionar retención es barato; perder expedientes no lo es.
9. **Reportería epidemiológica a la Secretaría de Salud desde el día uno**, no como añadido: el Art. 160 es obligación legal directa.
10. **Honduras no tiene ley de protección de datos vigente** (anteproyecto detenido desde 2018), pero el **hábeas data (Art. 182-2 constitucional) es accionable judicialmente de inmediato**. Se construye con derechos **ARCO** (Acceso, Rectificación, Cancelación, Oposición) como requisito voluntario: el día que la ley entre en vigor, se cumple sin refactor.

### 8.9 Laboratorio e imágenes — reglas de estructura

**Laboratorio:** cuatro entidades distintas — `orden → estudio/batería → muestra → resultado por analito`. Guardar el resultado a nivel de batería impide graficar, comparar y corregir un solo analito. Estados con timestamp y actor. Detalle completo en §9.I.

**Imágenes:** `orden → accession number → estudio → informe`. **El sistema no almacena pixel data**: el PACS guarda imágenes, el sistema guarda la referencia. Detalle en §9.J.

**El ID de muestra y el accession number los genera SIEMPRE el sistema**, se imprimen en código de barras y son la llave de reconciliación de todo el flujo hasta el cargo.

### 8.10 Estándares — qué se adopta y qué no

| Estándar | Uso | Licencia | Decisión |
|---|---|---|---|
| **CIE-10 en español** | Diagnósticos, reportes a SESAL, justificación ante aseguradoras | Gratis (OPS) | **SE ADOPTA** — es el lenguaje del sector |
| **LOINC** | Identificación de exámenes de laboratorio | Gratis, uso comercial permitido con aviso de atribución | **SE ADOPTA** — es lo que hablan los analizadores |
| **ATC** | Clasificación de medicamentos | Consulta gratis; distribución comercial restringida | **SE ADOPTA como referencia** (ya viene en el LNME) |
| **HL7 v2.x** | Mensajería con analizadores y RIS | Gratis con restricciones | **SE ADOPTA** — es lo que soportan los equipos reales |
| **DICOM (MWL, C-STORE, MPPS)** | Imágenes y worklist | Gratis | **SE ADOPTA** — no hay alternativa |
| **FHIR** | APIs propias, integraciones futuras | CC0, dominio público | **SE ADOPTA para APIs nuevas**, no para hablar con equipos |
| **CIE-11** | Sucesor de CIE-10 | Gratis | **No aún.** Honduras está en preparación (misión OPS nov-2024), sin fecha. **El campo de diagnóstico se diseña con catálogo versionado** para que migrar sea cambio de datos, no de esquema |
| **CPT** | Procedimientos (EE.UU.) | Licenciado: US$1,050/año + US$18.50 por usuario | **NO** |
| **SNOMED CT** | Terminología clínica | Honduras no es país miembro → licencia de Afiliado | **NO por ahora** |

**El tarifario de procedimientos y servicios es propio**, con código interno mapeable a CIE-10. Un hospital privado mediano en Honduras no necesita CPT ni CUPS: necesita un tarifario propio bien estructurado.

### 8.11 Preguntas abiertas de dominio — se responden antes de codificar el módulo correspondiente

Se documentan por escrito en `docs/dominio.md`. Las marcadas 🚧 **bloquean** el módulo indicado.

1. **Vigencia exacta del CAI** bajo el Acuerdo 481-2017 → consulta al SAR. 🚧 bloquea Facturación.
2. **Norma Técnica del Expediente Clínico de SESAL**: contenido mínimo, custodia y retención exacta → solicitud por escrito. 🚧 bloquea Expediente.
3. **Validez legal de la firma electrónica en el expediente clínico** en Honduras. **Es el mayor riesgo estratégico:** si no está reconocida, órdenes médicas, autorizaciones quirúrgicas y epicrisis deben imprimirse y firmarse en papel, y eso cambia el diseño del módulo. 🚧 bloquea Expediente y Quirófano.
4. **Trámite de inscripción como autoimpresor / Sistema de Facturación Computarizado** ante el SAR. 🚧 bloquea el arranque en producción de Facturación.
5. **Tratamiento contable del ISV pagado en compras destinadas a venta exenta** → contador del hospital. 🚧 bloquea Compras y costeo de inventario.
6. **Mecánica operativa del IHSS por servicios subrogados**: documentación, tarifario, proceso de reclamo.
7. **Formato oficial del reporte mensual de controlados a ARSA** (estructura del formulario/archivo). 🚧 bloquea el reporte de Farmacia.
8. **Marca y modelo de los analizadores de laboratorio**, y si soportan **interfaz bidireccional (host query)**. 🚧 bloquea la interfaz de Laboratorio.
9. **PACS actual del hospital (si existe), marca de los equipos de rayos X y su DICOM Conformance Statement.** 🚧 bloquea Imágenes.
10. **Aseguradoras con las que ya hay convenio firmado** y copia de sus tarifarios vigentes. 🚧 bloquea la carga inicial de tarifarios.
11. **Cuántas camas, cuántos servicios, cuántos puntos de emisión fiscal y cuántas cajas** hay hoy.
12. **Impresoras reales**: térmicas de ticket, de etiquetas de muestra (ZPL) y de brazaletes. 🚧 bloquea Admisión y Laboratorio.
13. **Sistema actual del hospital y volumen de datos a migrar** (pacientes, expedientes, saldos, inventario).
14. **Política de descuentos**: quién autoriza y hasta cuánto, por rol.
15. **Habilitación vigente del establecimiento ante SESAL/ARSA** y qué reportes exige hoy en papel.

---

## 9. CATÁLOGO ANTI-ERRORES — CADA REGLA EXISTE PORQUE YA COSTÓ CARO

Heredado de MAYAP, Praderas del Sol y del POS, más las reglas propias de un sistema hospitalario. **Cito la regla cuando aplique. Toda lección nueva se agrega aquí el mismo día** (§20).

Las reglas marcadas 🔴 son aquellas cuya violación produce **daño irreversible**: un paciente lastimado, un expediente indefendible en juicio, o dinero que nunca se cobra.

### 9.0 Fundamentos transversales — se deciden el día 1 o nunca

1. **Persona ≠ expediente ≠ encuentro: tres tablas.** Si el número de expediente es la identidad, no se pueden fusionar duplicados ni abrir la sede 2 sin renumerar el hospital entero.
2. **Todo dato clínico y financiero cuelga de un encuentro tipado.** Sin encuentro, un resultado no sabe a qué cuenta cobrar.
3. 🔴 **Prohibido `UPDATE` y `DELETE` sobre nota firmada, resultado validado, cargo facturado, factura emitida y movimiento de kardex.** Se corrige con una fila nueva que referencia la anterior. El día que un abogado pida "el expediente como estaba el 12 de marzo", una tabla mutable convierte al hospital en indefendible.
4. **Dos tiempos en todo evento: `ocurrido_en` (clínico) y `registrado_en` (sistema).** (§7.5-3)
5. **`timestamptz` siempre, UTC en la base, Tegucigalpa en la app.** El día contable, el día de censo y el día calendario se definen por separado.
6. **Nada de `float` para dinero ni para dosis.** `NUMERIC` con regla de redondeo declarada.
7. **Ningún usuario compartido** (`enfermeria1`, `caja`). Sin actor identificado no hay libro de controlados, ni bitácora, ni responsable del error.
8. **PK interna `bigint` + identificador público opaco** (PostgreSQL 18 trae `uuidv7()` nativo, ordenable). Exponer correlativos en URLs es un IDOR servido en bandeja.
9. **Los catálogos tienen vigencia, no un booleano `activo`.**
10. **NTP obligatorio** en servidores, analizadores y modalidades.

### 9.A Filament v5 / Livewire 4 (el conocimiento de v4 aplica igual — v5 es v4 sobre Livewire 4)

1. **Acciones en cabecera de páginas (Edit/View) dentro de `ActionGroup` NO reciben `$record`** → quedan invisibles y `callAction` falla. En cabecera: acciones directas; en tablas el ActionGroup sí funciona por fila. Todo `visible()`/`action()` tipa `?Modelo $record` con guard null.
2. **En CREATE el schema recibe un modelo VACÍO, no null** — los guards `$record !== null` pasan y luego el estado es null y revienta. Patrón: `$record?->getAttribute('estado')` + `instanceof EstadoX`.
3. **Imports completos en todo archivo nuevo**: una clase sin `use` resuelve al namespace del archivo. `Grid`/`Section`/`Fieldset` viven en `Filament\Schemas\Components`; las acciones unificadas en `Filament\Actions` (los `Filament\Tables\Actions\*` son v3). `Section`/`Grid` ocupan 1 columna → `columnSpanFull()` cuando aplique.
4. **Notificaciones clínicas: SIEMPRE `notifyNow()` + acuse**, nunca `sendToDatabase()`/`notify()` encolado. `DatabaseNotification` implementa `ShouldQueue`: sin worker no llegan jamás, y encoladas dentro de una transacción se enviarían aunque haya rollback. Un valor crítico "encolado" es un valor crítico no notificado.
5. **Enums casteados devuelven instancias**: comparar contra el enum, `->value` al exponer; `pluck()` devuelve enums; `Tab::getBadge()` devuelve string.
6. **RelationManager**: `protected static string $relationship` tipada; `$icon` es `string|BackedEnum|null`.
7. **Blades custom dentro del panel**: el CSS de Filament está precompilado — clases Tailwind nuevas NO existen ahí. Todo blade custom lleva su propio `<style>`.
8. **`auth()->id()` es `int|string|null`** → normalizar a `?int` antes de usar en queries.
9. **La búsqueda de tablas en PostgreSQL envuelve la columna en `lower()`** → índice funcional `lower(columna)` y `pg_trgm` en columnas `searchable()` de tablas grandes (nombres de paciente, catálogos).
10. 🔴 **NO se modelan como Resource CRUD:** prescripción, administración de medicamentos (MAR), dispensación, validación de resultados, toma de censo, caja/cobro, merge de pacientes, cierre de cuenta y consumo de quirófano. Son flujos con estado, verificación clínica y contexto; un formulario genérico permite guardar estados imposibles. **Filament brilla en catálogos, tarifarios, configuración, conciliación y tableros; no en la pantalla que usa una enfermera con guantes a las 3 am** — esas son páginas Livewire dedicadas, teclado-first, con código de barras y cero clics innecesarios.
11. **Cero lógica de negocio en el Resource.** El caso de uso vive en un Service testeable. Si la única forma de dispensar es la UI, no se puede recibir dispensación por interfaz, ni migrar, ni testear.
12. **Nunca generar cargos, movimientos de kardex ni notificaciones desde Observers o model events.** El observer no conoce el motivo, ni el autorizador, ni el contexto — y se dispara también en seeders, imports y comandos, generando cargos fantasma.
13. **`DB::transaction` envuelve el caso de uso completo** (cargo + movimiento + saldo + evento). No se confía en que el Action de Filament lo haga. La mitad de un caso de uso ejecutada es peor que ninguna.
14. **Nada de mutar estado en `mutateFormDataBeforeSave`.** Es donde la lógica clínica se vuelve invisible y no testeable.
15. **Los badges de navegación (`getNavigationBadge`) ejecutan `COUNT` en cada carga de página.** Cinco badges = cinco conteos sobre tablas grandes en cada clic de todo el hospital. Se cachean o se quitan.
16. **Nada de búsqueda global de Filament sobre pacientes, cargos o resultados sin índice trigram y sin límite.** Es la consulta que tumba la base a las 10 am.
17. **Desactivar `SoftDeletes` en tablas clínicas y financieras, y desactivar las bulk actions destructivas.** Un `bulk delete` accidental sobre cargos o resultados es un incidente del que no se vuelve.
18. **Rendimiento**: `deferLoading()` en tablas pesadas; nunca `paginated(['all'])`; `live(onBlur: true)` / `live(debounce: 500)`; `afterStateUpdatedJs()` para cálculos visuales sin round-trip.
19. **Filament v5 exige Tailwind 4.1+ y Livewire 4.** No copiar configuración de Tailwind 3 ni ejemplos de v3.
20. **MFA del panel activo** para `direccion`, `super_admin` y todo rol con export masivo, antes de producción.

### 9.B PHPStan nivel 7 (Larastan 3 / PHPStan 2)

1. **`nullsafe.neverNull`**: Larastan tipa BelongsTo como no-nulo → `$x?->prop ?? 'default'` falla. Chequear null explícito primero y luego acceder directo.
2. **Propiedades con cast `date`/`datetime` reciben Carbon**, nunca strings.
3. **`DB::transaction(fn () => $this->metodoVoid())` falla** ("result of void method is used") → closure completa `function (): void { ... }`.
4. **Nunca escribir `algo_*/otro_*` en un docblock** — la secuencia cierra el comentario y rompe el parse.
5. `find($mixed)` puede devolver Collection → castear `(int)` antes.
6. **Los errores nuevos NO se tapan engordando `phpstan.neon`.** Primero se corrige el código; si es falso positivo real, `@phpstan-ignore identificador (razón)` inline.
7. Nivel 7 es el piso, no el techo. Al cerrar la Etapa 1 se evalúa subir a 8. **No fijar `max` sin un spike previo.**

### 9.C Tests (Pest 5)

1. **Services SIEMPRE con `app(Servicio::class)`, nunca `new Servicio(...)`** — los constructores crecen y rompen todos los tests.
2. **Fechas relativas (`now()`, `subDays()`), nunca hardcodeadas en el pasado.** Para lo que dependa del calendario, `travelTo()`.
3. **PostgreSQL siempre; SQLite nunca**, con test guardia (§7.1).
4. **CHECK constraints se testean con `DB::table()->insert()` crudo** — el cast enum lanza `ValueError` antes de llegar al CHECK.
5. `assertSee` de dinero formateado es frágil → asertar el valor bcmath directo (`"2999.50"`).
6. **Los defaults de PostgreSQL NO llegan al modelo en memoria tras `create()`** → declarar explícitos en las factories los campos con default en la base.
7. `pest --parallel` crea bases sufijadas: el usuario de la base necesita `CREATEDB`.
8. Memoización: `WeakMap` por instancia u `once()` no-static — el estado static queda stale entre tests.
9. **Tests de permisos con roles reales** (`medico`, `enfermeria`, `caja`, `farmacia`, `laboratorio`), no solo super_admin: `Gate::before` para super_admin no genera permisos por sí solo con `RefreshDatabase`.
10. **Datos de prueba SIEMPRE sintéticos.** Nunca una copia de producción (§9.L6).

### 9.D Identidad del paciente

1. Ver §8.2 para el modelo. Aquí van las reglas de operación.
2. 🔴 **Dos identificadores en todo acto de riesgo**: brazalete, etiqueta de muestra, bolsa de sangre, administración de medicamento, entrada a quirófano y estudio de imagen exigen **nombre completo + fecha de nacimiento** (o expediente), **nunca el número de cama**. Identificar por cama es el mecanismo clásico de la transfusión al paciente equivocado tras un traslado nocturno.
3. **Homónimos: alerta bidireccional en ambos expedientes**, visible en toda pantalla clínica. Dos "José Antonio Martínez" de 54 años en el mismo piso terminan con la quimioterapia cruzada.
4. 🔴 **El merge de pacientes nunca borra ni mueve filas, y siempre es reversible.** El subsumido queda con `merged_into` + un registro de fusión con todas las filas afectadas; toda consulta resuelve identidad por función. Es la operación más peligrosa del sistema: fusiona dos personas reales en una sola historia — alergias, tipo de sangre, resultados y deuda — y sin `unmerge` no hay vuelta atrás.
5. **Merge = doble aprobación humana + evidencia. Nunca automático por score.** Un job nocturno que fusiona por similitud es una máquina de mezclar pacientes distintos a escala.
6. **Bloquear el merge si hay factura fiscal emitida, cuenta abierta o muestra en proceso en ambos lados**, o resolverlos explícitamente en el flujo. Fusionar cambia el dueño de un documento fiscal ya timbrado.
7. **Al fusionar, se unen alergias y problemas — no se elige.** Los conflictos (tipo de sangre distinto, alergia contradictoria) quedan marcados "requiere reconciliación clínica" y visibles hasta que un médico decida.
8. **El duplicado detectado pero no fusionado también se documenta** (`posible_duplicado`, con enlace). El peor estado es el que solo conoce la señora de archivo.

### 9.E Expediente clínico

1. Ver §8.8 para el ciclo de vida. Aquí van los errores clásicos.
2. **`Sin alergias conocidas` es una afirmación con autor y fecha, distinta de `no preguntado`.** El campo vacío se lee como "no es alérgico" y alguien inyecta penicilina.
3. **Alergias estructuradas** (sustancia codificada, reacción, severidad, fuente, fecha), nunca un `text` llamado `alergias`. Un texto libre jamás dispara una alerta.
4. **Marcar el copy-forward.** Si se permite copiar la nota anterior, se marca y se muestra el diff; sin eso, "dolor torácico activo" sigue en la nota ocho días después del alta y contamina las decisiones.
5. **El acceso se basa en relación de atención (médico ↔ encuentro activo), no solo en rol.** "Todos los médicos ven todo" es indefendible ante el primer reclamo de privacidad.
6. **Etiquetas de sensibilidad en el dato, no en el módulo**: VIH, salud mental, violencia sexual, adicciones. La restricción por pantalla se evade por el reporte, el export o la búsqueda global.
7. 🔴 **Break-the-glass: NUNCA se bloquea la atención por permisos.** Se permite el acceso siempre, pero con motivo tipificado + texto obligatorio, banner rojo permanente, **ventana de vigencia limitada** (horas, atada al episodio) y **revisión obligatoria del oficial de privacidad en menos de 72 h**. Un sistema que niega el expediente a las 3 am mata pacientes; uno que lo abre sin dejar rastro destruye la confianza y expone al hospital. **El acceso de emergencia expira; no otorga permiso permanente.**
8. **Impresión y exportación del expediente se registran** (quién, qué, para quién, motivo). El 90 % de las filtraciones salen por la impresora y por Excel.
9. **Consentimientos versionados por procedimiento**, guardando la versión exacta del texto firmado. "El consentimiento estándar" cambió tres veces desde la cirugía en disputa.
10. **La firma electrónica guarda evidencia**: usuario, momento, hash del contenido y método de autenticación — no un `firmado_por` suelto.

### 9.F Medicamentos y farmacia

1. Ver §8.7 para el modelo y la normativa ARSA.
2. 🔴 **Unidad de dosificación ≠ unidad de venta, con factor de conversión versionado y guardado en cada movimiento.** "Ampolla 500 mg/2 mL" administrada como 250 mg = 1 mL = 0.5 ampolla: el kardex descuenta 1 ampolla, el expediente anota 250 mg, y la merma son 250 mg. Confundir mg con mL con ampollas produce errores de 10× — y en heparina, insulina o electrolitos, un 10× es una muerte.
3. **Nunca guardar la dosis como texto ("1 tab c/8h").** Estructura: dosis, unidad, vía, frecuencia, duración, condición PRN, velocidad de infusión, diluyente.
4. 🔴 **Prescripción mg/kg exige peso vigente con fecha; se bloquea si el peso está vencido o ausente.** Un lactante dosificado con el peso de hace ocho meses recibe tres veces la dosis.
5. **El sistema calcula, muestra el cálculo y el volumen a administrar, pero no redondea solo**, y aplica dosis techo por kg, por toma y por día. El redondeo silencioso convierte 0.45 mL en 5 mL.
6. 🔴 **Verificar alergias contra principio activo y clase cruzada en los TRES puntos: al prescribir, al dispensar y al administrar.** Verificar solo al prescribir falla cuando la orden verbal de emergencia se digita después; verificar contra nombre comercial falla porque el paciente es alérgico a "penicilina" y le dan "Ampicilina".
7. **Detectar duplicidad de principio activo entre servicios.** Acetaminofén de medicina + del combo analgésico de cirugía = 6 g/día = hepatotoxicidad.
8. **Bloqueo automático de lotes vencidos, en cuarentena o en recall**, sin posibilidad de forzar sin autorización registrada.
9. 🔴 **Trazabilidad lote → paciente obligatoria.** Ante un retiro de mercado, el sistema responde en segundos "a qué pacientes se les administró el lote X". Si la respuesta es "revisemos las hojas de enfermería", el hospital no puede notificar y asume el daño.
10. **La devolución al stock depende de la presentación, no del deseo del bodeguero.** `retornable` por presentación y estado: un vial reconstituido, una jeringa preparada o una bolsa de infusión mezclada **jamás** regresan al inventario.
11. **Controlados: libro con folio, saldo por movimiento, doble firma en dispensación y en desperdicio, conciliación por turno, y prohibición absoluta de ajuste directo de existencias.** El ajuste libre de un estupefaciente es el mecanismo por el cual desaparece el fentanilo y el hospital pierde la licencia.
12. **El botiquín de piso y el carro de paro son almacenes con responsable**, con reposición por consumo y conciliación por turno. No un limbo donde el stock desaparece.
13. **Tipificar la merma** (derrame, rotura, vencimiento, dosis parcial/waste, error de preparación) con motivo, testigo y aprobación. La merma sin categoría es el disfraz contable del robo.
14. **El reenvasado/fraccionamiento es una transformación de inventario** (consume A, produce B con lote propio y vencimiento recalculado), no un ajuste de cantidad.
15. **Cadena de frío por lote y almacén, con registro de excursión térmica.** Una excursión invalida el lote completo de vacunas o insulina; sin registro se administra producto inactivo y nadie se entera hasta el brote.
16. **Muestras médicas, donaciones y medicamento propio del paciente entran al sistema con costo 0**, no fuera del sistema. Lo que no está en el kardex se administra sin verificación y sin trazabilidad.

### 9.G Inventario

1. 🔴 **El kardex es append-only y el saldo es derivado, nunca una columna que se edita.** Un `UPDATE productos SET existencia = 12` borra para siempre la evidencia del faltante.
2. 🔴 **Descuento de existencias bajo lock pesimista sobre la fila `(producto, lote, almacén)`** — `SELECT ... FOR UPDATE` dentro de la misma transacción que crea el movimiento. Sin lock, dos farmacias dispensan simultáneamente el último frasco de inmunoglobulina y uno de los dos pacientes se queda sin tratamiento con el sistema diciendo que había existencia.
3. **Existencia negativa prohibida por CHECK**, con excepción explícita, autorizada y alertada para emergencia. El negativo silencioso convierte el inventario en ficción en menos de un mes.
4. **El conteo físico es un documento** (corte congelado, encabezado, detalle, recuento a ciegas, segunda cuenta de diferencias) que genera movimientos de ajuste. **Nunca "editar la existencia para que cuadre".**
5. **Conteos cíclicos por clasificación ABC**, no un inventario general anual: controlados e implantes caros se cuentan por turno o semanalmente.
6. **El consumo de quirófano se registra contra el caso** (paciente + procedimiento + cirujano + tiempo), con lista de preferencia y devolución de lo no abierto. La bolsa de insumos que "se descarga a fin de mes contra el servicio" es la mayor fuga de dinero de un hospital privado.
7. **Implantes y dispositivos: número de serie/lote ligado al paciente (UDI), obligatorio para cerrar el caso.** Sin eso no hay recall, no hay garantía y no hay defensa cuando el implante falla.
8. **Consignación separada del inventario propio.** El implante en consignación no es del hospital hasta que se usa; mezclarlo infla el activo.
9. **Definir qué incluye el paquete quirúrgico y calcular el excedente automáticamente.** El "todo incluido" sin límite modelado es donde se pierde el margen del caso.
10. **Reporte de vencimientos a 30/60/90 días y bloqueo el día del vencimiento**, con costeo de lo vencido.
11. **Unidad de compra ≠ unidad de consumo, y el factor no se cambia con existencias vivas** sin un movimiento de conversión. Cambiar "caja de 100" a "tableta" en caliente multiplica o divide todo el inventario por 100.

### 9.H Facturación, ISV y convenios

1. Ver §8.5 y §8.6 para las reglas de precio, ISV y CAI.
2. 🔴 **El precio es una función con vigencia, jamás una columna del catálogo** (§8.5). Con precio-columna, renegociar con una aseguradora obliga a duplicar el catálogo — y en seis meses hay cuatro catálogos paralelos, ninguno correcto.
3. 🔴 **La línea de cargo guarda snapshot inmutable** de precio, tarifario, versión, convenio, descuento, régimen de ISV y cobertura.
4. 🔴 **La factura fiscal es inmutable y el correlativo es sagrado.** Nunca se borra: anulación por nota de crédito o anulación fiscal registrada, conservando el número. Número reutilizado, saltado sin documentar o borrado = multa, cierre y una auditoría que el hospital pierde.
5. **El correlativo se asigna lo más tarde posible en la transacción, con secuencia dedicada por punto de emisión y advisory lock — nunca `MAX(numero)+1`.** Y se documenta cada hueco por rollback: la secuencia de PostgreSQL no revierte, así que la política de huecos debe existir **antes** del primer día.
6. **Un lock de correlativo dentro de una transacción larga** (que genera PDF, envía correo o llama al PACS) **serializa toda la caja del hospital.** El lock se toma tarde y se libera rápido.
7. **Correlativo y CAI por sede y punto de emisión**, no un contador global.
8. **La cobertura se modela como reglas**, no como un porcentaje suelto: qué cubre, % de cobertura, tope por evento/año, copago fijo, deducible acumulado, exclusiones, requiere preautorización.
9. **El deducible es un saldo acumulado por póliza y año, no un porcentaje.** Modelarlo como descuento produce cobros dobles o coberturas regaladas.
10. **El impuesto se calcula y se guarda por línea con regla de redondeo explícita.** Recalcular al imprimir produce centavos que no cuadran y facturas que el SAR objeta.
11. **Mapear cada tipo de cargo a cuenta contable y centro de costo desde el día uno.** Hacerlo dos años después es un proyecto de meses sobre millones de filas.
12. **La fuga del paciente sin pagar es un evento registrado**, con responsable y saldo, no un dato faltante que ensucia las cuentas por cobrar.
13. **GOLDEN TESTS OBLIGATORIOS** — verificados al céntimo, no se tocan sin recalcular a mano:

    **13.1 · Cuenta mixta exento + gravado**
    > Estancia habitación privada 2 días × L 2,400.00 = **L 4,800.00** — *exento*
    > Laboratorio: hemograma L 380.00 + química sanguínea L 640.00 = **L 1,020.00** — *exento*
    > Medicamentos dispensados y administrados: **L 1,732.50** — *exento*
    > Cafetería (acompañante): **L 345.00** con ISV incluido — *gravado 15 %*
    > **Importe exento = L 7,552.50** · **Importe gravado (base) = 345.00 ÷ 1.15 = L 300.00** · **ISV = L 45.00**
    > **Total factura = 7,552.50 + 300.00 + 45.00 = L 7,897.50**
    > Verificación cruzada: exento + base + ISV = total, exacto ✔

    **13.2 · Aplicación de póliza sobre la cuenta anterior**
    > Gastos elegibles = L 7,552.50 (la cafetería no es elegible)
    > Deducible pendiente del año = L 1,200.00 → se consume completo
    > Base de coaseguro = 7,552.50 − 1,200.00 = **L 6,352.50**
    > Coaseguro 20 % del paciente = **L 1,270.50** (por debajo del tope de L 30,000 por evento)
    > **Porción aseguradora = L 5,082.00** · **Porción paciente = 1,200.00 + 1,270.50 + 345.00 = L 2,815.50**
    > Verificación cruzada: 5,082.00 + 2,815.50 = **L 7,897.50** exacto ✔

    **13.3 · Costo promedio ponderado y residuo de inventario**
    > Entrada 1: **120** viales a **L 47.5000** → valor L 5,700.00, existencia 120
    > Entrada 2: **80** viales a **L 52.2500** → valor L 9,880.00, existencia 200
    > Costo promedio = 9,880.00 ÷ 200 = **L 49.4000**
    > Dispensación de **37** viales → costo de salida = **L 1,827.80**; existencia 163, valor **L 8,052.20**
    > **Regla dura:** al agotar la existencia, el valor del inventario queda **exactamente en L 0.00** — el residuo de redondeo lo absorbe la **última salida**.

    **13.4 · Nota de crédito por anulación**
    > La factura de 13.1 se anula completa con nota de crédito. Resultado exigido: la factura conserva su número, la nota de crédito consume su propio correlativo y CAI, los cargos vuelven a `pendiente`, los movimientos de inventario se revierten con movimientos opuestos (no se borran), y el saldo de la cuenta vuelve al estado previo **al centavo**.

### 9.I Laboratorio

1. **Cuatro entidades: orden → estudio/batería → muestra → resultado por analito.** Guardar el resultado a nivel de batería impide graficar, comparar y corregir un solo analito.
2. **Estados con timestamp y actor:** ordenado, muestra tomada, recibida, en proceso, preliminar, validado, entregado, corregido, cancelado, rechazado. Un `estado` sin quién y cuándo no sirve.
3. 🔴 **La muestra tiene identidad propia**: código de barras impreso **en el punto de toma**, con hora, flebotomista, condiciones y contenedor. Etiquetar tubos después, en la estación de enfermería, es el mecanismo estándar del resultado cruzado — y una transfusión por grupo sanguíneo equivocado mata.
4. **El rechazo de muestra es un estado con motivo** (hemólisis, coágulo, volumen, mal rotulada), no un borrado, y **notifica al que ordenó** para repetir. La muestra rechazada en silencio es un resultado que el médico espera para siempre.
5. **Solo un rol autorizado valida.** Antes de la validación, todo resultado visible va marcado **"PRELIMINAR — no apto para decisión clínica"**.
6. 🔴 **El resultado nunca se sobrescribe: corregir crea una versión nueva** con estado "corregido", motivo y autor, con la anterior visible. **Y si la versión anterior ya fue vista o entregada, la corrección notifica activamente a quien la vio**: cambiar un potasio de 7.1 a 4.1 sin avisar deja al médico tratando una hiperkalemia inexistente.
7. **Los valores de referencia son función de (analito, método/equipo, sexo, edad, embarazo) y se guardan como snapshot en el resultado.** Cambiar de analizador sin snapshot re-marca años de resultados históricos como anormales.
8. **Separar valor numérico, operador y unidad.** "<5" no es un número; almacenarlo como texto rompe toda gráfica de tendencia y toda alerta automática.
9. 🔴 **Valor crítico: notificación con acuse de recibo obligatorio, lectura de vuelta (read-back) y escalamiento automático hasta un humano.** Se registra a quién se notificó, a qué hora, quién recibió y qué se leyó de vuelta; si nadie acusa en N minutos, escala a jefe de turno y luego a jefatura médica. **Un valor crítico "enviado" y no acusado es el caso judicial más frecuente y más perdido de todo laboratorio hospitalario.**
10. **El destinatario del valor crítico es el médico responsable AHORA, no el que ordenó.** El sistema debe conocer guardias y turnos; notificar al médico que se fue a las 6 pm es no notificar.
11. **Interfaz con analizadores por middleware** (HL7 v2 ORU/ORM, o ASTM E1381/E1394 en equipos con serial), con **cola persistente, reintentos e idempotencia por control ID**, y **log crudo de tramas**. Un resultado perdido por un error de parseo es un resultado que nadie sabrá que existió.
12. **Exigir interfaz bidireccional (host query)**: el analizador consulta al sistema con el ID de muestra y el sistema responde qué pruebas correr. La unidireccional obliga a digitar el ID en el equipo y ahí nacen los errores de asignación.
13. **Mapa de códigos por equipo** entre el código propietario del analizador y el catálogo interno (y a LOINC). Cada equipo usa códigos distintos para "glucosa".
14. **Microbiología es otro modelo, no una columna más**: cultivo con lecturas parciales a 24/48/72 h, aislamientos múltiples, antibiograma S/I/R por antibiótico, y alimentación del mapa de resistencia del comité de infecciones.
15. **Patología: caso ≠ muestra ≠ bloque ≠ lámina**, con informe estructurado y adenda, y trazabilidad de la pieza.
16. **El cargo se genera al recibir/procesar la muestra, no al ordenar.** Cobrar al ordenar factura estudios cancelados y muestras rechazadas.
17. **Entrega de resultados con canal seguro y registro de entrega.** Nunca la foto por WhatsApp desde el celular del técnico: es la filtración más común y la más difícil de investigar.

### 9.J Rayos X e imágenes

1. 🔴 **El sistema JAMÁS almacena pixel data.** El PACS guarda imágenes; el sistema guarda orden, `accession number`, `StudyInstanceUID`, estado y informe. Un `bytea` con DICOM revienta backups, replicación, vacuum y presupuesto — un solo TAC son cientos de MB.
2. **El `accession number` lo genera el sistema, es único e inmutable**, y es la llave de reconciliación orden ↔ estudio ↔ informe ↔ cargo.
3. 🔴 **Modality Worklist (DICOM MWL / C-FIND) obligatoria: el técnico selecciona al paciente de la lista, nunca lo teclea en la consola.** El nombre tecleado a mano no hace match, el estudio se pierde o se adjunta al paciente equivocado, y el radiólogo informa una fractura sobre imágenes de otra persona — que se opera.
4. **El sistema publica un SCP de worklist con su propio AE Title, IP y puerto** (104 o 11112), y registra el AE Title, IP y puerto de cada modalidad. La relación es mutua: ambos lados deben conocerse. Se usa **raíz UID propia del hospital** para los `StudyInstanceUID` — no se inventan UIDs.
5. **Flujo de reconciliación para estudios huérfanos y procedimiento formal de corrección en PACS.** Sin él, la solución del técnico será "borrar y repetir el estudio" — más radiación al paciente.
6. **Informe preliminar ≠ definitivo ≠ adenda**, con marcado visible, reemplazo formal y registro de discrepancia como indicador de calidad.
7. **Hallazgo crítico en imagen usa el mismo motor de acuse y escalamiento que el valor crítico de laboratorio** (neumotórax a tensión, hemorragia intracraneal, embarazo ectópico roto). Ponerlo solo en el texto del informe es no comunicarlo.
8. **Registrar dosis por estudio** (CTDIvol, DLP, vía MPPS/RDSR si el equipo lo emite) y acumular por paciente, con alerta en pediatría y en TAC repetidos. La dosis acumulada no se puede reconstruir después.
9. **Verificación de embarazo obligatoria y registrada** antes de estudio ionizante en mujeres en edad fértil. "Se preguntó verbalmente" no existe si no está en la base.
10. **El medio de contraste es un medicamento**: verificar alergia y función renal vigente, y descontarlo del inventario con lote.
11. **La orden lleva indicación clínica obligatoria**, no solo el nombre del estudio. Sin motivo, el informe es genérico y clínicamente inútil.
12. **La agenda se hace por sala/equipo, no por radiólogo**, con tiempos de preparación, limpieza y contraste, y modela cancelación y no-show con causa.
13. **El acceso al visor va por enlace con token de sesión y expiración, con bitácora** — nunca una URL abierta al PACS ni un puerto DICOM expuesto a internet.
14. **Definir retención, respaldo y prueba de restauración del PACS antes del primer estudio**, y registrar la entrega de CD/enlace al paciente.
15. **Exigir el DICOM Conformance Statement de cada equipo ANTES de comprarlo**, y probar MWL en la aceptación. Los equipos no negocian: si la worklist no responde exactamente como esperan, el técnico vuelve a digitar a mano.

### 9.K Hospitalización

1. **La cama es un recurso con máquina de estados**: disponible, asignada (pendiente de llegada), ocupada, sucia, en limpieza, bloqueada, fuera de servicio. Sin estado "sucia", admisiones asigna una cama que aún tiene las sábanas del paciente anterior y el censo miente.
2. 🔴 **La ocupación es un intervalo temporal (`asignacion_cama` con inicio y fin), nunca un `cama_id` en la tabla del paciente.** Con columna, el traslado destruye la historia y no se puede responder "¿en qué cama estaba a las 3 am?" — pregunta que se hace exactamente cuando algo salió mal.
3. **Impedir solapamientos en la base, no en el código**: `EXCLUDE USING gist (cama_id WITH =, periodo WITH &&)` o `UNIQUE (cama_id, periodo WITHOUT OVERLAPS)` de PostgreSQL 18, con `btree_gist`.
4. **El traslado es atómico**: cierre de un intervalo + apertura del otro en una transacción, con cambio de servicio, de médico tratante y **del destinatario de valores críticos**.
5. **La regla del paciente que ocupa dos camas en un día es configuración, no código** (cama a la hora de corte, la de mayor tarifa, o prorrateo). Cambia por sede y por convenio sin tocar código.
6. **El censo es un snapshot inmutable calculado a la hora de corte y archivado**, con ingresos, egresos y traslados del día. Recalcular el censo del mes pasado con datos actuales da un número distinto y la dirección deja de creerle al sistema para siempre.
7. **Definir y documentar qué es cama censable**: observación en emergencia, recuperación, labor de parto y ambulatorio prolongado ocupan espacio pero no necesariamente censan ni facturan igual.
8. 🔴 **Tres timestamps distintos y obligatorios: alta médica (decisión clínica), alta administrativa (cuenta liquidada) y salida física (cama liberada).** Colapsarlos en uno hace imposible medir la demora del egreso — el mayor devorador de capacidad de un hospital — y produce el caso del paciente que "ya salió" según el sistema pero sigue en la cama sin pagar.
9. **Tipificar el egreso**: domicilio, traslado, alta voluntaria (con firma), fuga, defunción. La defunción tiene flujo propio (certificado, cuerpo, pertenencias) y bloquea cargos nuevos salvo autorizados.
10. **Aislamiento como restricción de asignación, no como una nota.** Un paciente con germen multirresistente asignado a habitación compartida por un sistema que no lo sabe genera un brote.
11. **Restricciones de habitación compartida por sexo y edad, configurables**, verificadas al asignar.
12. **Responsable económico y contacto clínico son personas distintas** y ambas se registran.
13. **Reconciliación de medicamentos obligatoria al ingreso, al traslado y al egreso.** Sin ella, el paciente se va con la mitad de sus medicamentos crónicos suspendidos sin que nadie lo decidiera.
14. **Encuentros ligados para detectar reingreso < 30 días.** Es indicador de calidad y de negociación con aseguradoras; reconstruirlo después es imposible si cada ingreso es una isla.

### 9.L Permisos, seguridad y privacidad clínica

1. **Todo Resource nuevo nace con su Policy.** Sin Policy queda visible a cualquier autenticado.
2. **Receta obligatoria para cualquier permiso personalizado**: (1) constante en `App\Support\Permisos` con formato `{Accion}:{Modelo}`; (2) agregarlo a su grupo en `PERSONALIZADOS_POR_MODULO`; (3) `findOrCreate` + asignación explícita por rol en el seeder, incluyendo super_admin al final; (4) chequear siempre por la constante, nunca string suelto; (5) test en `RolesOperativosTest`.
3. **Permisos custom JAMÁS por patrón** (`LIKE '%:Modelo'`) — así se fugó `Anular:Compra` a recepción en MAYAP. Explícitos siempre.
4. **La UI de Shield sincroniza solo lo visible**: un permiso custom fuera del registro se pierde en silencio al guardar un rol.
5. **Autorización = rol + relación con el paciente + sede + turno.** Un modelo solo de roles degenera en 80 roles que nadie entiende y termina con todos como "supervisor". **El scoping se aplica en `getEloquentQuery()` Y en los badges/contadores**, con la misma fuente. Se prueba explícitamente que un usuario de la sede A no puede abrir por ID un registro de la sede B: en Filament, la ruta de edición directa es el agujero típico.
6. 🔴 **Se registra la LECTURA, no solo la escritura.** Cada visualización de expediente, resultado, imagen o cuenta queda con usuario, paciente, recurso, momento, IP, terminal y motivo si aplica. **Sin log de lectura, cuando se filtre el expediente de una figura pública el hospital no puede identificar al responsable y responde él.**
7. **La bitácora es append-only, particionada, con retención larga, y no editable ni por el DBA**, idealmente replicada a un destino de solo escritura. Una bitácora que el administrador puede borrar no es evidencia.
8. **Detección proactiva de accesos anómalos**: mismo apellido usuario↔paciente, paciente marcado VIP, acceso sin relación de atención, volumen inusual, acceso fuera de turno. **El caso real dominante no es el hacker: es el empleado que abre el expediente de su ex pareja, de su vecina o de un familiar.**
9. **Marca VIP con alerta al acceder, no con ocultamiento.** Esconder el expediente del famoso impide su atención; alertar y auditar lo protege de verdad.
10. **Cero PII/PHI en logs de aplicación, mensajes de excepción, URLs, Sentry/APM y trazas.** Las URLs quedan en los logs del proxy y del navegador; el nombre del paciente en una URL es una filtración lenta y permanente. Mantener `FilterSensitiveData` actualizado y `SENTRY_SEND_DEFAULT_PII=false`.
11. 🔴 **Jamás copiar producción a QA o desarrollo sin anonimizar**, incluidas las fechas (desplazadas de forma consistente) y el texto libre, que está lleno de nombres. La base de pruebas con datos reales termina en la laptop de un contratista, y esa filtración no se deshace.
12. **Todo export se registra** (usuario, filtro, filas, formato), está limitado por rol y marca el archivo con el usuario. **El Excel es el vector de fuga masiva.**
13. **El costo y el margen son un permiso, no una columna.** `Ver:Costo` se chequea en el Resource, en la tabla, en el export a Excel y en el reporte PDF. Los cuatro.
14. **Sesiones cortas en estaciones compartidas + cambio rápido de usuario.** Si cerrar sesión cuesta 20 segundos, enfermería compartirá una sesión abierta todo el turno y la trazabilidad se evapora.
15. **MFA para acceso remoto y para todo rol con capacidad de export masivo.** Rate limiting en login con bloqueo temporal, y en exports y generación de documentos.
16. **La baja de un empleado revoca accesos el mismo día.** El médico que renunció y sigue entrando es un hallazgo de auditoría garantizado.

### 9.M Reglas nuevas del proyecto — se agregan conforme aparezcan

1. *(17-ago-2026)* Shield 4.3.1 **sí admite** `spatie/laravel-permission ^8.0`; el constraint `^7.4` heredado del proyecto POS está obsoleto y degradaría la instalación dos majors en silencio (§6.2-1).
2. *(17-ago-2026)* `maatwebsite/excel ^3.1` resuelve en Laravel 13 pero arrastra PhpSpreadsheet 1.x (rama solo-CVE). Fijar `^4.0` (§6.2-2).
3. *(17-ago-2026)* Livewire 4 SFC no se usan: Pint no formatea el PHP embebido.
4. *(17-ago-2026)* Los servicios médicos y los medicamentos son **exentos de ISV** en Honduras (Art. 15 b y d de la Ley del ISV). Diseñar el impuesto como flag de empresa habría sido un error estructural (§8.6.1).

---

## 10. PATRÓN FILAMENT APROBADO

Patrón validado en MAYAP y Praderas ("me encanta, guarda ese tipo de diseño"). Todo Resource lo sigue:

1. **Layout con `Tabs::make()->persistTabInQueryString()`**: Tab 1 identificación, Tab 2 contenido principal, Tab 3 "Estado".
2. **Tab Estado enriquecido**: toggle activo + Section "Información del registro" (solo edit) con conteo de relaciones, fecha de creación y últimos cambios del activitylog. Nunca un tab con solo un toggle.
3. **Códigos generados por sistema**: `{PREFIJO}-{SEDE}-{AÑO}-{#####}` en evento `creating` con `lockForUpdate` dentro de transacción. Oculto en CREATE, readonly "Código del sistema" en EDIT. Los campos que componen el código quedan `disabledOn('edit')` con `helperText` que explica por qué.
4. **Auto-uppercase con triple defensa** vía macro `->mayusculas()` (CSS + dehydrate `mb_strtoupper` UTF-8) + mutator en el modelo. **NO** aplica a nombres de personas, correos, contraseñas, códigos de barras, códigos LOINC/ATC/CIE-10 ni unidades con casing significativo (mg, mL, mcg — **`mg` y `Mg` no son lo mismo y en dosis eso mata**).
5. **Navegación pulida**: `getNavigationLabel()` y `getBreadcrumb()` explícitos (`Str::headline` produce "Formas De Pago").
6. **Tablas**: columnas explícitas + eager loading en `getEloquentQuery()` con columnas nombradas, filtros con la misma fuente de scoping, `defaultSort`, paginación 25/50/100, `deferLoading()` en las pesadas.
7. **Pantallas dedicadas (no Resource)**, todas Livewire, teclado-first, con lector de código de barras y foco persistente:
   - **Admisión / registro de paciente** (con búsqueda tolerante y desambiguación antes de crear)
   - **Caja y facturación**
   - **Dispensación de farmacia**
   - **Administración de medicamentos (MAR)** — la pantalla que se usa con guantes
   - **Recepción y validación de resultados de laboratorio**
   - **Censo y mapa de camas**
   - **Consumo de quirófano**
   - **Merge de pacientes** (con doble aprobación)
8. **Toda pantalla clínica muestra siempre la cabecera del paciente**: nombre completo, edad, sexo, expediente, alergias en rojo, aislamiento y convenio. Es la barra que evita el error de identidad.

---

## 11. ARQUITECTURA Y CONVENCIONES DE CÓDIGO

- **ADR-0001 (cerrado): Laravel tradicional** — Services + Models + Filament Resources + páginas Livewire. NO Clean Architecture. `app/Domain/` conserva Value Objects (`Monto`, `RTN`, `CAI`, `Dosis`, `CodigoBarras`, `NumeroExpediente`) y excepciones raíz.
- **ADR-0002 (cerrado, 17-ago-2026): multi-sede single-tenant** — `sede_id` desde la primera migración; sin Filament Tenancy; replicar = instalación nueva.
- **ADR-0003 (cerrado, 17-ago-2026): catálogo único de ítems facturables + tarifario por convenio con vigencia** — prohibida la columna `precio` en el catálogo (§8.5).
- **ADR-0004 (cerrado, 17-ago-2026): expediente y kardex append-only**, con bitácora de lectura y break-the-glass.
- **ADR-0005 (abierto): infraestructura de producción** — se decide al cerrar la Etapa 1 (§19).
- Capas: **Models** = relaciones, casts, scopes, accessors. **Services** = todo el dominio, única puerta de escritura. **Resources / Pages / Componentes Livewire** = orquestación delgada. **Form Requests** = validación HTTP. **Enums PHP tipados** para estados (+ CHECK en la base).
- SOLID práctico: SRP, OCP (comportamientos nuevos = clases nuevas, no flags booleanos), DIP (dependencias por constructor, nunca `new` dentro de un Service). Composición sobre herencia. Excepciones de dominio tipadas (`SihlaException` → por módulo). Fail fast.
- **Naming**: dominio en español (`Encuentro`, `RegistrarCargoService`, `descontarExistencia()`), técnico en inglés (Service, Builder, Repository). camelCase descriptivo, booleanos `is/has/can`, constantes SCREAMING_SNAKE. `declare(strict_types=1)` en todo archivo.
- PHPDoc en públicos de Services: documenta el **porqué** (regla del negocio o artículo de la norma), no el qué.
- Duplicación: regla del tres para código; el conocimiento del dominio se centraliza desde la primera vez.
- **Reglas de negocio como datos, no como código**: política de censo, ventana de cargo tardío, topes de descuento, redondeo, dosis techo, umbrales de autorización. Si son código, cada clínica nueva es un fork y al tercer cliente ya no se puede mantener nada.

---

## 12. POSTGRESQL Y ELOQUENT — REGLAS DURAS

- **Toda FK con índice en la misma migración.** Índices obligatorios de este sistema:
  - `(paciente_id, fecha DESC)` en **toda** tabla clínica
  - `(encuentro_id)` en cargos, órdenes y resultados
  - `UNIQUE (producto_id, lote_id, almacen_id)` en existencias
  - `UNIQUE (accession_number)` y `UNIQUE (id_muestra)`
  - `GIN`/`pg_trgm` sobre nombres de paciente y sobre catálogos con búsqueda
  - `BRIN` por fecha en bitácora y en kardex
  - **Índices parciales para bandejas de trabajo** (`WHERE estado IN ('pendiente','en_proceso')`): consultan el 0.1 % de la tabla; un índice completo las hace lentas y enormes
- **Restricciones temporales de PostgreSQL 18** con `btree_gist`: `UNIQUE (item_id, convenio_id, sede_id, vigencia WITHOUT OVERLAPS)` para tarifarios y `EXCLUDE USING gist (cama_id WITH =, periodo WITH &&)` para camas. **La integridad temporal se resuelve en la base, no en el código.**
- **CHECK constraints como defensa profunda**: estados válidos, `cantidad > 0`, `existencia >= 0`, `precio >= 0`, `descuento <= subtotal`, `SUM(pagos) <= total`, dosis dentro de rango. Otra aplicación, un import o un script escribirán en esa base algún día.
- **Índices únicos con columnas nullable requieren `COALESCE`** o índice parcial (NULL≠NULL).
- `NUMERIC` para dinero (2 dec), costos (4 dec), cantidades y dosis (4 dec); `JSONB` (+ GIN si se filtra) solo para metadata realmente dinámica — **los atributos clínicos NO son JSONB, son tablas**; `timestamps()` siempre; `softDeletes()` **solo en catálogos y personas**, nunca en expediente, cargos, facturas ni kardex.
- **Particionar por rango de fecha desde el diseño**: `cargos`, `resultados`, `movimientos_inventario`, `bitacora`, `signos_vitales`, `asignaciones_cama`, `accesos_expediente`. A los dos años son decenas de millones de filas y la partición no se agrega en caliente sin ventana de mantenimiento — y en un hospital **no hay ventana de mantenimiento a la que apelar**.
- **Migraciones expand/contract, sin locks largos**: `CREATE INDEX CONCURRENTLY`, `ADD CONSTRAINT ... NOT VALID` + `VALIDATE CONSTRAINT`, columnas nuevas nullable primero, backfill por lotes.
- Prohibido: `get()`/`all()` sin límite (paginate/cursor/`lazyById`), `SELECT *` en tablas anchas, N+1 (eager load con columnas explícitas), interpolación en SQL (bindings siempre), acceso a relación sin null-safe, `COUNT(*)` sobre tablas grandes en el request.
- `upsert()` para importaciones de catálogo; `withCount()` para contadores; **`Model::preventLazyLoading()` activo en desarrollo y en tests**.
- **Nunca `ALTER TABLE` manual**: todo en migraciones. Las migraciones ya aplicadas en producción son inmutables — se corrige con una migración nueva.
- Antes de cualquier `migrate --force` en producción: **`pg_dump` previo**, automatizado en el pipeline (§18).
- **Archivar moviendo a particiones frías, nunca borrando.** El histórico debe seguir siendo consultable 20 años después (§8.8-8).

---

## 13. RENDIMIENTO Y CONSUMO DE RECURSOS — REGLA DE ORO DEL PROYECTO

> **El sistema tiene que responder al momento, todo el tiempo, sin saturarse.** Un hospital no puede esperar. Esta sección no es "optimización prematura": es un presupuesto que se verifica en cada entrega.

### 13.1 Presupuesto de latencia por pantalla (percibido por el usuario)

| Pantalla | Objetivo | Máximo tolerable |
|---|---|---|
| Buscar paciente / abrir expediente | < 300 ms | 800 ms |
| Agregar un ítem escaneado (farmacia, caja) | < 200 ms | 400 ms |
| Registrar administración de medicamento (MAR) | < 300 ms | 600 ms |
| Mapa de camas / censo en vivo | < 500 ms | 1 s |
| Guardar nota clínica | < 500 ms | 1 s |
| Emitir factura (sin PDF) | < 700 ms | 1.5 s |
| Tablero de indicadores | < 1 s (con datos pre-calculados) | 2 s |

Si una pantalla no cabe en su presupuesto, **no está terminada**. Se optimiza o se rediseña, no se justifica.

### 13.2 Presupuesto de queries por request

- **Pantalla de lectura: ≤ 15 queries.** Pantalla de escritura: ≤ 25.
- **Cero N+1, verificado**, no asumido: `Model::preventLazyLoading()` en dev y tests, y conteo de queries en los tests de las pantallas críticas (`assertQueryCountLessThan`).
- **N+1 clásicos de este dominio, todos ya conocidos:** cargos → paciente/servicio/convenio/tarifa · resultados → analito/rango/validador · kardex → producto/lote/almacén · censo → paciente/cama/servicio/médico tratante · dispensación → lote/vencimiento.
- **Los accessors que consultan la base (`$paciente->saldo`, `$producto->existencia`) dentro de columnas de tabla son N+1 invisibles**: 50 filas = 50 consultas que ningún `with()` arregla. Se convierten en subconsultas (`addSelect`) o en columnas materializadas.
- Toda tabla del panel: **paginación obligatoria**, columnas explícitas, `deferLoading()` si pesa.

### 13.3 Qué NUNCA corre dentro del request del usuario

**Prohibido, sin excepciones:**

- Generar un PDF
- Enviar correo, SMS o WhatsApp
- Llamar al PACS, a un analizador, a la aseguradora o a cualquier API externa
- Reportes de rango amplio, exports, recosteo o recálculo masivo de saldos
- Refrescar vistas materializadas
- Cualquier cosa que dependa de un tercero para responder

Todo eso va a **cola con Horizon**, con timeout, reintentos acotados y notificación al terminar.

**La única excepción, y es obligatoria:** la **alerta de alergia** y el **valor crítico de laboratorio** exigen confirmación **síncrona en pantalla**, con la cola solo como respaldo y un watchdog independiente que escale. Una alerta que "se encoló" es una alerta que no ocurrió (§9.I9, §9.A4).

### 13.4 Política de documentos — no se genera un PDF que nadie pidió

**Regla dura: un PDF se genera solo cuando un humano lo solicita explícitamente, o cuando la ley obliga a conservarlo.** Chromium headless cuesta ~150–300 MB de RAM y cientos de milisegundos por documento; un hospital que genera PDFs "por si acaso" se cae solo.

1. **Nunca generar el PDF "al guardar".** El documento se genera al pedirlo.
2. **Cache por hash del contenido**: si el documento no cambió, se sirve el archivo ya generado. Un resultado de laboratorio validado no cambia — su PDF se genera una vez en la vida.
3. **Un solo `PdfRenderer`** (wrapper único de Browsershot) con instancia reutilizada, `--no-sandbox`, `--disable-crashpad`, HOME escribible y **timeout duro**. Nunca Browsershot directo, nunca DomPDF/mPDF.
4. **Cola dedicada para PDFs, con concurrencia limitada** (2–4 workers máximo). Sin límite, 30 solicitudes simultáneas levantan 30 Chromium y tumban el servidor.
5. **Documentos de alto volumen NO son PDF**: ticket de caja, etiqueta de muestra, brazalete y etiqueta de medicamento se imprimen **directo con ESC/POS o ZPL**, no como PDF que el usuario abre y manda. A las 3 am el flujo de "descargar e imprimir" falla y alguien escribe la etiqueta a mano.
6. **Retención y limpieza**: los PDFs generados tienen política de expiración y un job de limpieza. El almacenamiento no crece sin límite.
7. **Excel: `FromQuery` + `WithMapping` en streaming**, nunca cargar la colección completa en memoria. Más de 5,000 filas → `ShouldQueue` obligatorio. **Todo export respeta `Ver:Costo` y queda registrado** (§9.L12).
8. **Impresión masiva es un job con progreso**, no un botón que bloquea el navegador.

### 13.5 Base de datos y conexiones

- **PgBouncer con pools separados** para web, colas y reportes. Un reporte pesado no puede consumir las conexiones de la caja.
- **READ COMMITTED + locks explícitos + retry ante deadlock.** No `SERIALIZABLE` global: en un HIS produce abortos aleatorios en el peor momento.
- **Locks pesimistas exactamente en cinco lugares**, y en ningún otro: existencia `(producto, lote, almacén)` · asignación de cama · correlativo fiscal por punto de emisión · folio del libro de controlados · asignación de número de expediente/accession.
- **Se materializa transaccionalmente lo que decide**: saldo de existencias y saldo de la cuenta del paciente se actualizan en la misma transacción que el movimiento. **Las vistas materializadas refrescadas cada N minutos sirven para tableros, nunca para descontar stock ni para liquidar.**
- **El censo del día y los indicadores se calculan por job y se archivan**, no se recalculan en cada carga de pantalla.
- Toda consulta de reporte que pase de ~500 ms → vista materializada refrescada por job, o tabla de agregados.

### 13.6 Cache

- **Redis con prefijo propio del proyecto** (`hospital_los_angeles_`). Nunca compartir keyspace con otro proyecto del VPS.
- **Cache con invalidación por evento, no por TTL ciego**, en catálogos, tarifarios y permisos. Un tarifario cacheado con TTL produce cobros con precios viejos.
- **Jamás cachear datos clínicos de un paciente entre usuarios.** El cache de un expediente es una fuga esperando su turno.
- `config:cache`, `route:cache`, `view:cache`, `event:cache` y `filament:cache-components` en producción, siempre.

### 13.7 Integraciones y colas

- **Idempotencia obligatoria en toda entrada externa**: mensajes HL7, resultados de analizador, webhooks, reintentos de POS. Sin clave de idempotencia, el reintento duplica resultados y cargos, y el paciente paga dos veces.
- **Patrón outbox para eventos salientes y tabla de mensajes crudos entrantes.** Disparar integraciones desde el modelo garantiza que un rollback deje un mensaje enviado que nunca debió salir.
- **Timeout + circuit breaker** hacia PACS, analizador y aseguradora. El registro clínico debe funcionar en modo degradado: **un sistema que se cae porque el PACS no responde detiene el hospital entero.**
- **Colas separadas por prioridad**: `critica` (valores críticos, alertas), `operativa` (cargos, integraciones), `reportes` (PDF, Excel, tableros). Horizon con **alerta humana ante cola fallida**: un job fallido en silencio en la cola crítica es un valor de pánico no notificado. **El monitoreo de la cola es un dispositivo de seguridad del paciente, no de infraestructura.**

### 13.8 Plan de contingencia (downtime)

**Desde el día uno**, no al final: formularios en papel definidos, vista de solo lectura del expediente con caché local, y proceso de captura retroactiva marcada como tal. **El sistema se va a caer; lo que se diseña es cómo se cae.**

---

## 14. SEGURIDAD — MENTALIDAD DE QUIEN CUSTODIA DATOS CLÍNICOS Y DINERO AJENO

1. **Menor privilegio real, probado con el usuario real de cada rol** — no asumido (§1.4, §9.L).
2. **Todo lo que toca dinero, inventario o expediente deja bitácora**: quién, cuándo, desde qué IP y terminal, valor anterior y nuevo. **Y todo lo que se LEE del expediente también** (§9.L6).
3. **Registros clínicos y financieros append-only.** Anulación = registro nuevo con motivo obligatorio.
4. **El costo y el margen son un permiso**, protegido en Resource, tabla, export y PDF (§9.L13).
5. **PII/PHI fuera de logs y de Sentry** (§9.L10).
6. **MFA obligatorio** para `direccion`, `super_admin`, acceso remoto y roles con export masivo.
7. **Cifrado**: TLS obligatorio, cifrado en reposo de discos y respaldos, y cifrado a nivel de columna en los campos más sensibles según se defina en el diseño del expediente.
8. **Rate limiting** en login (con bloqueo temporal), exports, generación de documentos y cualquier ruta pública.
9. **Sesión corta en estaciones compartidas + cambio rápido de usuario** (§9.L14). El cierre de sesión debe cerrar también la caja si quedó abierta, o alertar.
10. **Backups probados**: un backup que nunca se restauró no es un backup. **Restauración documentada antes de producción y repetida cada trimestre**, con tiempo medido.
11. Dependencias: `composer audit` en CI; Dependabot para composer, npm y actions.
12. **Nada de secretos en el repo, en capturas de pantalla ni en mensajes.** Credenciales demo rotadas antes de dar acceso real.
13. **Baja de empleado revoca accesos el mismo día** (§9.L16).

---

## 15. UX — EL MÉDICO A LAS 3 AM Y LA ENFERMERA CON GUANTES

- **Las pantallas críticas se operan sin mouse**: escanear brazalete → escanear medicamento → confirmar → listo. Todo lo demás se subordina a ese flujo.
- **La cabecera del paciente siempre visible** con alergias en rojo, aislamiento y convenio (§10.8).
- **Defaults inteligentes**: fecha y hora de ahora, sede y servicio del usuario, convenio del paciente, cantidad 1.
- Toda acción > 300 ms da feedback; exports y documentos pesados → job + notificación al terminar.
- **Errores en español y accionables, con ejemplo del formato**: "El RTN debe tener 14 dígitos. Ejemplo: 08011985012345". Nunca "Error de validación".
- **Confirmación con motivo obligatorio** en acciones destructivas, anulaciones y cualquier cosa que afecte dinero, existencia o expediente.
- Estados vacíos que guían al primer paso.
- **Responsive a 360 px verificado** — el censo, la consulta de resultados y el inventario se hacen desde el celular, caminando por el piso.
- **La cámara para escanear exige HTTPS.** `getUserMedia` no funciona en `http://` salvo en `localhost`. En desarrollo se usa el dominio `.test` de Herd con TLS activado (`herd secure`); probar desde el celular contra la IP de la Mac **no va a abrir la cámara** y no es un bug.
- **Impresión probada con el hardware real** (térmica de ticket, ZPL de etiquetas, brazaletes), no con una hoja A4 idealizada.

---

## 16. TESTING — QUÉ SE PRUEBA SÍ O SÍ

| Área | Nivel mínimo exigido |
|---|---|
| Value Objects (`Monto`, `RTN`, `CAI`, `Dosis`, `NumeroExpediente`) | Unitario exhaustivo, incluidos inválidos |
| **Cálculo de cuenta con ISV mixto** | **Golden test al céntimo** (§9.H13.1) |
| **Aplicación de póliza** (deducible, coaseguro, tope, no elegibles) | **Golden test al céntimo** (§9.H13.2) |
| **Costeo promedio ponderado** | **Golden test al céntimo** (§9.H13.3) + agotar existencia deja valor en 0.00 exacto |
| **Anulación con nota de crédito** | **Golden test** (§9.H13.4): correlativo conservado, inventario revertido, saldo exacto |
| **Descuento de existencia** | Concurrencia real: dos dispensaciones simultáneas del último lote — una gana, la otra falla con error de dominio |
| **Asignación de cama** | Dos asignaciones simultáneas a la misma cama: la base rechaza el solapamiento |
| **Correlativo fiscal** | Dos procesos simultáneos no producen el mismo número; los huecos por rollback quedan documentados |
| **Append-only** | El `UPDATE` sobre nota firmada, resultado validado, cargo facturado y kardex **falla** |
| **Merge de pacientes** | Merge + unmerge devuelven el estado exacto; bloqueo con factura emitida; alergias unidas, no elegidas |
| **Conversión de unidades de dosis** | mg ↔ mL ↔ ampolla con factor versionado; el kardex y el expediente cuadran |
| **Verificación de alergias** | Dispara al prescribir, al dispensar y al administrar; por principio activo y por clase |
| **Valor crítico** | Notificación síncrona, acuse registrado, escalamiento al no acusar, destinatario = médico de turno actual |
| **Corrección de resultado ya entregado** | Genera versión nueva y **notifica a quien lo vio** |
| **Bitácora de lectura** | Abrir un expediente deja registro; break-the-glass exige motivo y expira |
| **Permisos** | Cada Resource probado con `medico`, `enfermeria`, `caja`, `farmacia`, `laboratorio`, `bodega` |
| **Aislamiento por sede** | Un usuario de la sede A no abre por ID un registro de la sede B |
| **Cargo tardío** | Se registra después del cierre de cuenta, con la política configurada |
| CHECK constraints | Con `DB::table()->insert()` crudo |
| Rendimiento | Conteo de queries en pantallas críticas (§13.2) |

---

## 17. USO DE SUBAGENTES

Mauricio autorizó el uso de agentes. Los uso así, y lo digo cuando lo hago:

- **Investigación paralela** (versiones, compatibilidad de paquetes, normativa) → agentes independientes, un tema cada uno, con exigencia de citar fuente y marcar lo no verificado.
- **Auditoría de código en paralelo** por dimensión: seguridad clínica, rendimiento/N+1, cumplimiento del catálogo §9, y cobertura de tests.
- **Exploración del repo** cuando hay que barrer muchos archivos para responder una pregunta.

Reglas: **el agente investiga o revisa; las decisiones de arquitectura las tomo yo y las confirma Mauricio.** Ningún agente escribe código de dominio sin que yo revise el resultado contra este documento. Y todo hallazgo de un agente que contradiga este documento se verifica contra la fuente primaria antes de aplicarlo.

---

## 18. CI/CD — GITHUB ACTIONS

| Disparador | Qué corre |
|---|---|
| Push/PR a `develop` y `main` | **Job `calidad`**: `composer audit` → Pint `--test` → Rector `--dry-run` → PHPStan nivel 7 → migraciones sobre PostgreSQL 18 real → Pest completo (PostgreSQL 18 + Redis 8 como *service containers*) |
| Push a `develop` (tras calidad verde) | **Job `deploy-pruebas`**: despliegue automático al entorno de pruebas |
| Push a `main` (tras calidad verde) | **Job `deploy-produccion`**: GitHub Environment protegido con aprobación de Mauricio; **`pg_dump` obligatorio ANTES de `migrate --force`**; si la migración falla, se detiene y reporta |

No negociables del workflow: `permissions: contents: read`; `concurrency` con `cancel-in-progress`; caché de Composer y npm; `shivammathur/setup-php@v2` con `php-version: 8.5` y extensiones `bcmath, intl, pdo_pgsql, redis, gd, zip, pcntl, posix, sockets`; `fail-fast: true`.

**Por qué así:** deploy automático a producción de un sistema hospitalario es apostar a que ningún `migrate` salga mal un sábado con el hospital lleno. El entorno de pruebas automático da la velocidad; la aprobación manual cuesta 10 segundos y evita el desastre. **Además: nunca se despliega en horario de alta ocupación, y jamás sin plan de rollback probado.**

---

## 19. PRODUCCIÓN Y VPS — DECISIÓN ABIERTA (ADR-0005)

**Estado: pendiente.** Se decide al cerrar la Etapa 1. Lo que ya está fijado:

- El VPS actual **comparte máquina con sistemas productivos de terceros**. Regla absoluta: **no se toca nada de ellos** — ni sus bases, ni sus Redis, ni sus cron, ni sus vhosts, ni el `php.ini` global.
- El stack objetivo (PHP 8.5 + PostgreSQL 18) diverge de lo que corren los otros proyectos. Salidas viables: (A) contenedores propios con Nginx del host como reverse proxy, o (B) `php8.5-fpm` en paralelo + PostgreSQL 18 en puerto aparte. **(A) protege mejor a los productivos; (B) consume menos RAM.**
- **Consideración propia de este proyecto**: un sistema hospitalario que cae detiene la atención. La tolerancia a caídas es **mucho menor** que en un sistema administrativo — pesa fuerte a favor de aislarlo (opción A), de tener alta disponibilidad al menos en la base, y de un plan de contingencia escrito y ensayado con el personal.
- Antes de decidir: auditoría del VPS documentada en `docs/vps-state.md`, con RAM, CPU, disco y crecimiento proyectado a 3 años.
- Checklist de deploy: `composer install --no-dev --optimize-autoloader` → **`pg_dump`** → `migrate --force` (⚠️ si falla, DETENER) → cachés (`config`, `route`, `view`, `event`, `filament:cache-components`) → `storage:link` → `horizon:terminate` → verificación en navegador **incluyendo un ingreso de prueba, un cargo y su factura**.
- Antes del día 1 de producción: backups diarios con retención 30 días y **restauración probada**, SSL (obligatorio también para la cámara y para cualquier acceso remoto), monitoreo con alerta humana, y credenciales demo rotadas.

---

## 20. PROTOCOLO DE SESIÓN Y MEMORIA

**Apertura:** leer la memoria del proyecto → pedir estado (`git status`, screenshot) → confirmar el objetivo de la sesión → arrancar desde suite verde.

**Durante:** entregas en unidades verificables (archivos + pasos numerados + qué probar en navegador). Cada unidad pasa su mini-DoD antes de la siguiente.

**Cierre de sesión:**

1. DoD completa del trabajo del día (§5).
2. Recordar explícitamente qué falta commitear (git es de Mauricio).
3. Actualizar la memoria del proyecto: estado, decisiones tomadas y **toda lección nueva** (error no catalogado + su fix) — **el mismo día**.
4. Si una lección se repite 2 veces → se agrega a §9 con su porqué.

**Comunicación:** los screenshots se leen completos (errores, valores, URLs). Confirmaciones cortas = avanzar. No repetir explicaciones de fundamentos — Mauricio tiene 20+ años de experiencia. Recomendaciones con trade-offs, no listas de opciones abiertas.

---

## 21. LO QUE NUNCA HAGO — CIERRE

- ❌ Codificar sin analizar ni pedir autorización en tareas no triviales.
- ❌ Ejecutar comandos o git — los entrego en formato de pasos y **Mauricio los ejecuta**.
- ❌ Declarar terminado sin la DoD completa (§5), incluida la prueba con un rol restringido.
- ❌ Repetir un error del catálogo §9 — si estoy por hacerlo, cito la regla y me corrijo.
- ❌ `UPDATE` o `DELETE` sobre nota firmada, resultado validado, cargo facturado, factura o kardex.
- ❌ Poner el precio como columna del catálogo, o cobrar sin snapshot inmutable.
- ❌ Tratar el ISV como flag de empresa o de factura en vez de por línea (§8.6.1).
- ❌ `float` para dinero, dosis, costos o existencias; matemática financiera fuera de bcmath.
- ❌ Descontar existencia sin transacción + `lockForUpdate` + re-check.
- ❌ Guardar dosis, resultado o alergia como texto libre.
- ❌ Identificar a un paciente por número de cama.
- ❌ Fusionar pacientes de forma automática, destructiva o irreversible.
- ❌ Bloquear la atención clínica por un permiso (break-the-glass, §9.E7).
- ❌ Abrir un expediente sin dejar registro de lectura.
- ❌ Almacenar pixel data DICOM en la base.
- ❌ Notificar un valor crítico por cola sin acuse ni escalamiento.
- ❌ Generar un PDF, enviar correo o llamar a un tercero dentro del request del usuario.
- ❌ Generar documentos que nadie pidió, o sin cache y sin límite de concurrencia.
- ❌ Resource sin Policy; permiso custom fuera de la receta; permisos por patrón.
- ❌ SQLite en tests, o cualquier divergencia de motor entre test y producción.
- ❌ Copiar datos de producción a pruebas sin anonimizar.
- ❌ Derivar la fecha de negocio de `created_at` o usar `now()` de PostgreSQL.
- ❌ Tapar errores de PHPStan engordando el neon; tests con `new Servicio()`; fechas de test hardcodeadas.
- ❌ Poner una pantalla clínica de alta frecuencia dentro de un Resource CRUD (§9.A10).
- ❌ Construir algo de la lista §1.3 sin recordar que es alcance nuevo.
- ❌ Escribir código que obligue a tocar código para abrir la sede 2 o firmar un convenio nuevo.
- ❌ Tocar servicios compartidos del VPS, `php.ini` global o cron ajenos.
- ❌ Desplegar a producción sin `pg_dump`, sin aprobación y en horario de alta ocupación.
- ❌ Olvidar registrar lecciones y estado en la memoria al cerrar la sesión.

---

## APÉNDICE A — COMANDOS PARA COPIAR Y PEGAR

> Los ejecuta **Mauricio**. Un bloque a la vez; pegar el output antes del siguiente.

### A.1 Infraestructura de datos (Docker, puertos dedicados)

```bash
docker compose up -d
docker compose ps                      # ambos deben decir "healthy"

docker compose exec postgres psql -U postgres -c "CREATE DATABASE hospital_los_angeles;"
docker compose exec postgres psql -U postgres -c "CREATE DATABASE hospital_los_angeles_test;"
docker compose exec postgres psql -U postgres -c "SELECT version();"   # debe decir 18.x
docker compose exec postgres psql -U postgres -d hospital_los_angeles -c "CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS btree_gist;"

psql -h 127.0.0.1 -p 5444 -U postgres -d hospital_los_angeles
```

### A.2 Arranque del proyecto

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan storage:link
php artisan migrate --seed
npm run build
herd link hospital-los-angeles       # http://hospital-los-angeles.test
herd secure hospital-los-angeles     # ⚠️ HTTPS obligatorio para cámara y acceso remoto
```

### A.3 El día a día

```bash
composer dev            # servidor + Horizon + Pail + Vite
composer test           # Pest en paralelo
composer lint           # Pint (corrige)
composer lint:check     # Pint (solo verifica — es lo que corre CI)
composer stan           # PHPStan nivel 7
composer rector         # Rector dry-run
composer ci             # audit + lint + stan + test
```

### A.4 Migraciones y datos

```bash
php artisan migrate
php artisan migrate --pretend
php artisan migrate:status

# ⚠️ DESTRUCTIVO — borra TODOS los datos de la base configurada.
# Verificar que .env apunta a hospital_los_angeles en el puerto 5444, NUNCA a producción.
php artisan migrate:fresh --seed

php artisan db:seed --class=RoleSeeder
```

### A.5 Después de tocar permisos o roles

```bash
php artisan db:seed --class=RoleSeeder
php artisan permission:cache-reset
php artisan optimize:clear
# + hard refresh en el navegador (Cmd+Shift+R)
```

### A.6 Cuando "algo raro" pasa en el panel

```bash
php artisan optimize:clear
php artisan filament:optimize-clear
composer dump-autoload
```

### A.7 Tests

```bash
composer test
vendor/bin/pest --filter=CuentaConIsv
vendor/bin/pest --filter=CostoPromedio
vendor/bin/pest --coverage --min=80
php artisan migrate:fresh --env=testing
```

### A.8 Diagnóstico rápido

```bash
php -v                                  # debe decir 8.5.x
php artisan about
php artisan queue:failed
php artisan pail
php artisan horizon:status
docker compose logs -f postgres
```

---

## APÉNDICE B — ORDEN DE CONSTRUCCIÓN

Los módulos se diseñan uno por uno, con su análisis L2. Este es el **orden**, no el detalle. Cada bloque cierra con su DoD antes de pasar al siguiente. **Nada de avanzar con dos módulos a medias.**

| # | Bloque | Por qué va aquí |
|---|---|---|
| **0** | **Actualización de stack** | PHP 8.5 · Laravel 13 · Filament v5 · PostgreSQL 18 · Pest 5 · CI verde · renombrado del proyecto. **Sin esto no se toca el dominio** |
| 1 | **Cimientos** | `config/sihla.php`, enums, Value Objects, `Permisos`, `Roles`, seeder de roles, organización, **sedes**, servicios, almacenes, correlativos, bitácora y bitácora de lectura |
| 2 | **Identidad del paciente (MPI)** | Personas, identificadores, expedientes, búsqueda tolerante, detección de duplicados. **Todo lo demás cuelga de aquí** |
| 3 | **Catálogos y convenios** | Catálogo único de ítems, unidades y presentaciones, CIE-10, LOINC, ATC, convenios y **tarifarios con vigencia** |
| 4 | **Encuentros y cuenta del paciente** | Encuentro tipado, cuenta, motor de cargos con snapshot, división paciente/aseguradora |
| 5 | **Inventario** | Almacenes, lotes, kardex append-only, entradas/compras, costo promedio, traslados, ajustes, conteos |
| 6 | **Farmacia** | Dispensación, FEFO, controlados con libro y reporte ARSA, devoluciones, mermas, farmacovigilancia |
| 7 | **Facturación y caja** | CAI, puntos de emisión, factura, nota de crédito, pagos mixtos, anticipos, cierre de caja |
| 8 | **Laboratorio** | Orden, muestra con código de barras, resultados por analito, validación, **valores críticos con acuse**, interfaz con analizadores |
| 9 | **Imágenes** | Orden, accession, worklist DICOM, informe, enlace a PACS, dosis |
| 10 | **Expediente clínico** | Notas con ciclo de vida, órdenes médicas, alergias, prescripción, MAR, break-the-glass |
| 11 | **Hospitalización y quirófano** | Camas, censo, traslados, egresos, consumo de quirófano, implantes |
| 12 | **Reportes y cobranza** | Glosas, antigüedad de cuentas por cobrar, indicadores, reportería a SESAL, exports con permiso de costo |
| 13 | **Cierre** | Prueba con los roles reales, backup restaurado, prueba con hardware físico, contingencia ensayada, capacitación |

> El orden es negociable según lo que el hospital necesite primero, **con una excepción: los bloques 0, 1, 2 y 3 van antes que todo.** Construir farmacia o facturación antes de tener identidad de paciente y tarifario con vigencia es garantizar un refactor con datos reales adentro.

---

## APÉNDICE C — PENDIENTES QUE ARRASTRA ESTE DOCUMENTO

1. Responder por escrito las 15 preguntas de dominio (§8.11) → `docs/dominio.md`. Las marcadas 🚧 **bloquean** su módulo.
2. **Confirmar con el SAR** la vigencia del CAI y el trámite de autoimpresor/SFC.
3. **Solicitar a SESAL** la Norma Técnica del Expediente Clínico y la posición sobre firma electrónica en el expediente.
4. **Confirmar con el contador** el tratamiento del ISV pagado en compras destinadas a venta exenta.
5. Conseguir el **DICOM Conformance Statement** de los equipos de imagen y la ficha de interfaz de los analizadores de laboratorio.
6. Obtener los **tarifarios vigentes** de las aseguradoras con convenio firmado.
7. Auditoría del VPS → `docs/vps-state.md` → cerrar ADR-0005 (§19).
8. Definir política de retención y almacenamiento (expediente 20 años, imágenes en PACS, PDFs generados).
9. Inventario del hardware real: impresoras térmicas, de etiquetas, de brazaletes, lectores de código de barras.
10. Definir el plan de migración desde el sistema actual del hospital (volumen, calidad de datos, corte).

---

**FIN DEL DOCUMENTO — v1.0 · 17 de agosto de 2026**
*Stack y normativa verificados contra fuentes oficiales en esa fecha. Toda cifra de versión, artículo de ley y acuerdo citado aquí fue confirmado en su fuente primaria; lo no confirmado está marcado con ⚠️ y aparece en el Apéndice C.*
