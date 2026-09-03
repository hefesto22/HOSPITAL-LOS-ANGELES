# Puesta en marcha de SIHLA — qué falta para subirlo y empezar a usarlo

*Levantado el 3 de septiembre de 2026. Se tacha a medida que se cierra.*

El software está. Lo que falta se divide en tres, y **el orden importa**: hay
cosas que cuestan diez veces más si se hacen después del primer dato real.

---

## 🔴 1. Antes del PRIMER dato real — después cuesta diez veces más

### 1.1 La zona horaria — ✅ ARREGLADA (3-sep-2026)

Toda columna `timestamptz` guardaba hora de Tegucigalpa etiquetada como UTC.
Corrida seis horas, y no se notaba porque el sistema era consistente consigo
mismo: escribía 01:45 y leía 01:45.

- [x] `config/database.php`: la sesión de PostgreSQL pasó de `'UTC'` a la zona
      de la app. Laravel manda literales SIN offset en la zona de la
      aplicación; ahora PostgreSQL los interpreta como locales y guarda el
      instante correcto. Adentro `timestamptz` sigue siendo UTC — siempre lo
      fue: la zona de la sesión no decide cómo se almacena, decide cómo se lee
      lo que entra.
- [x] `tests/Feature/Infraestructura/ZonaHorariaTest.php` — el turno de caja de
      las 18:30, comparado por **epoch**. Y en `EntornoTest` se reemplazó el
      test que sostenía el bug: afirmaba `current_setting('TimeZone') = 'UTC'`
      y estaba verde midiendo la sesión en vez del instante.
- [ ] **`migrate:fresh --seed`** antes del primer dato real.

**Por qué `fresh` y no una migración que desplace seis horas:** diez triggers de
inmutabilidad (`cargos`, `facturas`, `abonos`, `movimientos_kardex`, `ajustes`,
`persona_versiones`, `conteos`, `presupuesto_lineas`…) **rechazan el UPDATE**
sobre justo las tablas que llevan la plata y la trazabilidad. Desplazarlas
obligaría a `DISABLE TRIGGER`, o sea a levantar la garantía que hace auditable
al sistema, para tocar las filas por debajo. Y `accesos_expediente` está
particionada **por `ocurrido_en`**, que es una de las columnas a mover: filas
cerca de un borde tendrían que cambiar de partición.

Todo lo que hay hoy en la base es de prueba —CAI de prueba, precios en el
centinela de L 10, la apendicectomía de Fausto—, y de todos modos hay que
rehacerla para cargar los datos reales. **A partir del primer dato real, esta
decisión se vuelve irreversible.**

### 1.2 La base de producción

**ADR-0005 sigue abierto: no hay infraestructura de producción definida.**

- [ ] Servidor con PostgreSQL 18 y Redis, con las **tres trampas** de la
      Etapa 0 resueltas — las tres fallan sin dar error:
      - `PGDATA` en `/var/lib/postgresql/18/docker`. Verificar con
        `SHOW data_directory;`. Con el mount viejo, Postgres escribe en un
        volumen anónimo y un recreate deja la base en cero, sin log.
      - **Collation ICU `es-HN` fijada en `initdb`.** ⚠️ **Cambiarla después es
        dump/restore del hospital entero.** Verificar con `\l`.
      - Redis con `maxmemory-policy noeviction`. Con LRU se descartan jobs
        encolados en silencio.
- [ ] `COMPOSE_POSTGRES_AUTH=scram-sha-256` con password. En local está en
      `trust`.
- [ ] **Backups automáticos Y una restauración probada.** Un backup que nunca
      se restauró no es un backup.
- [ ] Horizon corriendo como servicio, TLS y dominio.

### 1.3 Los datos que no se inventan

- [ ] **Rango de CAI real del SAR** (hoy hay uno de prueba).
- [ ] **Los 225 precios de lista** que están en el centinela de L 10. Mientras
      estén así **no se le puede facturar a un paciente de contado**:
      `motivo LIKE 'PENDIENTE PRECIO DE LISTA%'` los lista.
- [ ] **Inventario inicial**, por su pantalla propia — la que se decidió que NO
      va por Recibir mercadería.
- [ ] **R.T.N. del Hospital Militar** y **cobertura real** de los dos convenios
      (hoy ambos en 0 %).
- [ ] **Revisar qué ítems están marcados `fraccionable`.** Toda la regla de
      cobro por frasco cuelga de esa bandera: un jarabe mal marcado se cobra mal
      en la dirección contraria y nadie lo nota.

---

## 🟡 2. Antes de que lo use alguien que no seas vos

- [x] **La administradora existe y no es super-admin.** Angela Cardona, rol
      `direccion`: todos los permisos de la matriz del §1.4, pero **pasa por las
      policies**. `super_admin` queda como llave de soporte de Olympo — ese rol
      se salta las policies escritas a mano (borrado, break-the-glass,
      inmutabilidad) y no debe ser el de nadie que opere el hospital.
      `UsuarioDeDireccionSeeder`, con sus variables en el `.env`.
- [ ] **Una cuenta por persona.** Ya no todo es «Administrador», pero mientras
      caja y farmacia entren con la de ella, la bitácora de lectura del
      expediente y el registro de actividad **no valen nada** — y esa bitácora
      es lo que responde «¿quién vio este expediente?». Se dan de alta desde
      «Usuarios», cada una con su rol.
- [ ] **Cambiar las dos contraseñas del `.env`** antes de producción. El seeder
      de soporte ahora **falla** si `ADMIN_PASSWORD` sigue siendo la de la
      plantilla: `12345678` está publicada en el repo.
- [ ] **Pushear.** Hay 60 commits sin subir y cambios sin commitear: si esa Mac
      se moja, se pierde todo.
- [ ] **`npm audit`: 2 critical y 6 high**, y el CI corre `composer audit` pero
      **no** `npm audit`. Ese flanco está descubierto.
- [ ] Capacitar caja y farmacia, con el sistema cargado y en el flujo real.
- [ ] Definir el **día de corte**: desde cuándo se cobra por SIHLA y qué pasa
      con lo que quedó a medias en el sistema viejo.
- [ ] Plan de vuelta atrás: qué se hace si el primer día no funciona.

---

## 🟢 3. Se puede hacer con el sistema ya andando

- [ ] **Nota de crédito.** Diferida unos meses por decisión de dirección.
      Mientras tanto: un error solo se corrige anulando, y solo si la factura no
      salió del hospital y el mes no se declaró.
- [ ] Merma de lo que sobra a las 24 h · redondeo automático de receta a
      envases · mostrar qué lote eligió FEFO.
- [ ] Línea en el papel del presupuesto que anticipe el descuento del adulto
      mayor (hoy la cuenta le sale menor que el presupuesto, nunca mayor).
- [ ] Aviso a dirección al primer excedente de un paquete.
- [ ] Pruebas del módulo de presupuesto — no existe ninguna.
- [ ] Escribir los **ADR 0002 a 0005**: el `CLAUDE.md` los da por cerrados pero
      `docs/adr/` solo tiene el 0001.
- [ ] `docs/dominio.md` con las 15 preguntas del §8.11.
- [ ] Labels de navegación con `Str::headline` («Registros **De** Actividad»).

---

## Lo que NO falta

Verificado con `composer ci` verde — 870 pruebas, PHPStan nivel 7 y Pint:
catálogo y tarifario por convenio · cuentas y cargos · paquete quirúrgico con
desglose en la factura · descuentos de ley editables y con vigencia · caja y
turnos · recepción de mercadería con escaneo · kardex con costo promedio móvil ·
expediente con bitácora de lectura.
