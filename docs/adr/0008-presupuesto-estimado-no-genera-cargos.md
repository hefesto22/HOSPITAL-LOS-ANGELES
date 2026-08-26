# ADR-0008 — El presupuesto es un estimado que no genera cargos

**Estado:** Aceptado
**Fecha:** 2026-08-25
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

El hospital no vende cirugías a precio fijo. Vende un **presupuesto**:

> «Una cirugía de apéndice se le dice 40,000 y de ahí se le va restando lo de medicamentos, honorarios, comidas, camilla, todo. A veces es más, a veces menos. No hay precio fijo porque unos usan unos medicamentos y otros otros, honorarios más altos y esas cosas.»

Eso obliga a separar tres cosas que en el hospital se llaman igual y que el sistema tiene que tratar distinto:

| | Qué es | ¿Es plata? | Dónde vive |
|---|---|---|---|
| **Estimado** | «le sale como en 40,000» | No, es una proyección | **Este ADR** |
| **Anticipo** | La familia deposita 20,000 en caja | Sí, y es pasivo, no ingreso | Bloque 7 — caja |
| **Paquete cerrado** | Precio único que ya incluye X cosas | Sí, es precio | `TipoItem::Paquete` + `PoliticaCargo::IncluidoEnTarifa` (ya existe) |

El paquete cerrado ya está modelado y **no es lo que se decide acá**: `HOS-011 USO SALA DE OPERACIONES PAQUETE CESAREA 2H` es uso de sala por dos horas, no «todo incluido».

La tentación obvia —cargar los 40,000 a la cuenta y después restarle cargos negativos— rompe el append-only del ADR-0004, infla la base de ISV, descuadra los CHECK de `cuentas` y deja una cuenta que ya no dice qué se consumió.

## Decisión

**El presupuesto es un documento aparte que NO genera cargos.** La cuenta sigue siendo la única verdad: cargos reales, con su snapshot, append-only.

**El presupuesto solo aporta el denominador de un medidor.** El numerador ya existe y es gratis: `cuentas.total`, materializado en la misma transacción de cada cargo y verificado por CHECK.

```
Presupuestado   L 40,000.00   <- presupuestos.total, congelado al emitir
Consumido       L 28,350.00   <- cuentas.total, ya existe
Disponible      L 11,650.00   <- derivado en la consulta, NUNCA una columna
```

**Se presupuesta el TOTAL de la atención, no la porción del paciente.** Decisión del negocio: «se le da el total al paciente; ya los seguros arreglan qué pagan ellos y qué paga el cliente». La división pagador/paciente la sigue haciendo `CalculadoraDeCobertura` línea por línea, y no se refleja en el presupuesto. Es coherente con el ADR-0007: una atención, una cuenta, un total.

**Se cotiza con el mismo motor de precios que cobra.** `ResolutorDePrecio(item, convenio, fecha, sede)`. Si el caso es PALIG, el presupuesto sale con el tarifario de PALIG. Un presupuesto no inventa precios: los congela.

**El presupuesto puede nacer antes del encuentro.** Alguien llega solo a preguntar cuánto le sale. `encuentro_id` es nullable y se amarra al ingresar.

**Al pasarse, avisa y registra. Nunca bloquea.** Alerta al 80 % y al 100 %, marca la cuenta y deja constancia de quién siguió cargando. Un presupuesto agotado jamás detiene un cargo clínico — es la misma regla que impide rechazar la transfusión de las 23:50.

**La holgura va como línea visible.** Si el estimado real es 36,500 y se cotiza 40,000, el colchón de 3,500 es una línea que se ve. Mismo criterio con el que se descartó el precio de lista inflado para el adulto mayor: el margen no se esconde adentro de otros números.

## Estructura

```
plantillas_presupuesto --< plantilla_lineas     (APENDICECTOMIA trae sus ~22 lineas tipicas)
presupuestos           --< presupuesto_lineas   (cotizacion congelada, con vigencia)
presupuestos.encuentro_id -> encuentros         (nullable: se cotiza antes de ingresar)
presupuestos.presupuesto_anterior_id            (revision: el viejo queda sustituido)
```

**Las plantillas son la replicabilidad.** Otra clínica carga sus plantillas y cotiza sus cirugías sin que nadie escriba una migración.

**Una línea puede llevar precio escrito a mano** —el honorario del cirujano varía por médico— marcada como override. El presupuesto no es documento fiscal; puede.

**La revisión es un presupuesto nuevo**, no una edición: se complicó la cirugía, se emite el de 60,000 y el de 40,000 queda `sustituido` apuntado por `presupuesto_anterior_id`. Mismo patrón que `cuentas.cuenta_anterior_id`.

## Razones

**1. Un presupuesto que genera cargos deja de ser un presupuesto.** Vuelve la cuenta un neteo entre una promesa y unos consumos, y a los tres días nadie puede decir qué se le dio al paciente.

**2. El medidor ya estaba construido.** `cuentas.total` se actualiza bajo la misma transacción del cargo por el §13.5. Colgarle un denominador cuesta un `belongsTo` y una barra; recalcularlo con `SUM()` sobre una tabla particionada de millones de filas costaría la pantalla de cuentas abiertas.

**3. El estimado se corrige solo si se puede medir.** Presupuestado contra real por línea es el reporte que hace que la siguiente apendicectomía se cotice con datos y no con memoria.

## Consecuencias

**Obliga a:**

- **`disponible` es derivado.** Ninguna columna `consumido` ni `disponible` se almacena. Es la misma regla del kardex del ADR-0004.
- **Precios congelados al emitir.** Si el tarifario sube mañana, lo que la familia firmó no cambia.
- **Vigencia explícita.** Un presupuesto vale N días; vencido se recotiza, no se «reusa».
- **Avisar cuando el convenio no coincide.** Si se cotizó bajo CONTADO y la cuenta abrió con PALIG —el caso del NN de las 3 am del §1.5—, los precios del presupuesto son de otro pagador. Se marca y se ofrece recotizar; no se recalcula solo.
- **Un ítem cargado que no estaba en el presupuesto se avisa, no se impide.**

**Cierra:** el cargo de 40,000 con reversas, el precio de paquete en el catálogo y la columna `consumido`.

**Lo que NO decide este ADR:**

- **Anticipos y depósitos.** Son pasivos contables y viven en el bloque 7 con la factura y el cierre de caja. El presupuesto no sabe de plata recibida.
- **El paquete quirúrgico cerrado con excedente automático** (§9.G9). Cuando el hospital venda paquetes de verdad, se resuelve con `PoliticaCargo::IncluidoEnTarifa`, que ya existe.

**Verificado en código (25-ago-2026):** cuando cambia el pagador y un encuentro queda con dos cuentas, el consumo es la suma de `total` de las cuentas con estado distinto de `anulada`, **sin doble conteo**. `EstadoCargo::cuentaEnElSaldo()` devuelve `false` para `Trasladado` y `Cuenta::recalcular()` lo excluye: el cargo deja de sumar en la cuenta vieja en el mismo momento en que suma en la nueva.

## Cómo se verifica

- Un test que emita un presupuesto, cargue de más y espere que el cargo **pase** con la cuenta marcada en rojo.
- Un test que verifique que emitir un presupuesto no crea ninguna fila en `cargos`.
- Un test que suba el tarifario después de emitir y espere que el total del presupuesto no se mueva.
- En code review: cualquier `SELECT` que devuelva `disponible` lo calcula; si aparece como columna, se rechaza.

## Referencias

- ADR-0003 — el precio es una función con vigencia, jamás una columna del catálogo.
- ADR-0004 — append-only: por qué el saldo es derivado y corregir es enmendar.
- ADR-0007 — una atención, una factura: por qué el presupuesto es uno solo sobre el total.
- CLAUDE.md §9.G9 — el paquete quirúrgico y el excedente, que este ADR deja abierto a propósito.
