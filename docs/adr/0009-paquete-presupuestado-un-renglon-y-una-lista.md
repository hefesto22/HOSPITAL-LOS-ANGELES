# ADR-0009 — El paquete presupuestado: un renglón cobrable y una lista que se va marcando

**Estado:** Aceptado
**Fecha:** 2026-08-26
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

El ADR-0008 dejó el presupuesto como un estimado que no genera cargos, y dejó abierto a propósito el §9.G9 del CLAUDE.md: *«definir qué incluye el paquete quirúrgico y calcular el excedente automáticamente»*.

Al construir la pantalla, Mauricio definió cómo cobra el hospital de verdad:

> «Todo lo que se agregue a la apendicectomía ya está en el presupuesto. Si la apendicectomía cuesta 40,000, ahí va incluido todo. En caso de que se le incluyan más cosas y rebase el presupuesto, se le comunica.»

Y cómo tiene que verse:

> «En la cuenta solo se verá apendicectomía y el costo que tiene. Que se le desglose todo lo que va presupuestado, y si agrega algo que está presupuestado no se le cobra, solo se va marcando con check de manera automática. En caso de que no sea algo que estaba presupuestado, ahí sí se le va agregando aparte.»

Eso **es** el paquete quirúrgico. La pregunta que el ADR-0008 dejó abierta queda cerrada acá.

## Decisión

**El presupuesto se agrega a la cuenta como UN renglón cobrable**, con el ítem de la cirugía y el monto del presupuesto. Es lo único que la familia ve como precio.

**Debajo se desglosa lo que ese renglón incluye, y cada línea se marca sola al consumirse.**

```
APENDICECTOMIA (PRE-HLA-2026-000001)          L 40,000.00
  [x] Uso sala de operaciones 2H                  1 de 1
  [x] Habitación por día                          3 de 3
  [~] Alimentación por día                        2 de 3
  [ ] Hemograma                                   0 de 1
─────────────────────────────────────────────────────────
FUERA DEL PRESUPUESTO
  Tomografía de abdomen                        L 3,200.00
  Habitación día 4                             L 1,200.00
```

**Lo presupuestado NO se le vuelve a cobrar.** Entra al kardex, descuenta existencia, congela su costo y queda ligado al paciente — pero con `PoliticaCargo::IncluidoEnTarifa`, así que no le llega como línea. La pieza ya existía desde el ADR-0003.

**El excedente se decide por ÍTEM Y CANTIDAD, no por monto.** El presupuesto dice 3 días de habitación: el cuarto se cobra. Un ítem que no estaba presupuestado se cobra desde la primera unidad. Por monto sería más simple y peor: un implante caro que nadie previó se comería el presupuesto de la habitación y el hospital se enteraría al final.

**Al aparecer el primer excedente se avisa a caja y a dirección.** Nunca se frena el cargo clínico: el medicamento se despacha igual y después se habla con la familia.

**Lo gravado con ISV nunca entra al paquete.** El paquete es exento como servicio médico (Art. 15 de la Ley del ISV); la cafetería del acompañante se cobra aparte siempre. Un solo renglón no puede llevar dos regímenes (§8.6.1).

## Estructura

```
presupuestos.item_cobro_id  -> con qué ítem del catálogo se cobra el paquete
cargos.presupuesto_id       -> a qué paquete pertenece este cargo
cargos.presupuesto_linea_id -> qué renglón del presupuesto consume
```

- `presupuesto_id` con `presupuesto_linea_id` NULL = **es el cargo del paquete**.
- `presupuesto_id` con `presupuesto_linea_id` = consumo previsto, `IncluidoEnTarifa`.
- Sin `presupuesto_id` = cargo normal, cobrable. Es el excedente y todo lo demás.

**Lo consumido de cada línea es DERIVADO**, no una columna: una sola consulta agrupada sobre `cargos`. Es la misma regla del kardex del ADR-0004 — un saldo que se edita es un saldo que en tres días miente.

⚠️ **`presupuestos` NO lleva llave foránea al cargo del paquete.** `cargos` está particionada y su llave primaria es `(id, fecha_operacion)`: cualquier FK hacia ella tendría que llevar las dos columnas. Se resuelve al revés, consultando por `presupuesto_id`.

## Lo que esto cambia del ADR-0008

**El medidor cambia de sentido.** Ya no es «consumido contra presupuestado» leyendo `cuentas.total`: el presupuesto ES el cargo, así que lo que se mide es cuánto de la lista se cumplió y cuánto excedente hay.

**«Emitir» deja de existir como congelamiento.** El estado pasa a llamarse **agregado**, y las líneas siguen editables mientras el paciente está internado — porque en la práctica se siguen tocando. Decisión de Mauricio: *«el papel es solo referencia»*.

**Sigue en pie del ADR-0008:** que el presupuesto se cotiza con el tarifario del pagador; que la holgura es una línea visible; que las plantillas son la replicabilidad; que un presupuesto agotado jamás detiene un cargo clínico.

## Consecuencias

**Obliga a:**

- **El motor de cargos consulta el presupuesto antes de decidir su política.** Es el punto delicado: `RegistradorDeCargo` ya toma candados en orden canónico (cuenta → costo → existencia) y esta consulta entra dentro de esa transacción, sin agregar un candado nuevo.
- **El cargo del paquete lleva precio del presupuesto, no del tarifario.** `OrigenDelPrecio` gana un caso: el número salió de una cotización, no de una lista de precios, y la factura tiene que poder explicarlo.
- **Suspender la cirugía exige revertir el cargo del paquete.** Entra el día uno y el consumo llega después; si el caso no ocurre, queda un cargo por algo que no pasó. La reversa ya existe.
- **El trigger de líneas inmutables se afloja:** escribe en `borrador` y en `agregado`, no en `cerrado`, `sustituido` ni `anulado`.

**Habilita algo que el hospital hoy no tiene:** como cada consumo incluido congela su costo real, al cerrar el caso se puede ver **cuánto costó de verdad la apendicectomía de L 40,000**. Margen por caso, no por mes.

## Cómo se verifica

- Un test que cargue un ítem presupuestado y espere `IncluidoEnTarifa`, con la cuenta sin moverse.
- Un test que cargue el 4º día de una habitación presupuestada por 3 y espere UN cargo cobrable por ese día.
- Un test que cargue un ítem que no está en el presupuesto y espere cargo cobrable desde la primera unidad.
- Un test que verifique que el medicamento incluido SÍ descontó existencia y congeló costo.
- Un test de que un ítem gravado nunca entra al paquete.

## Referencias

- ADR-0003 — `PoliticaCargo::IncluidoEnTarifa`, la pieza que hace posible esto.
- ADR-0004 — append-only: por qué lo consumido es derivado.
- ADR-0008 — el presupuesto, su cotización y sus plantillas.
- CLAUDE.md §9.G9 — el pendiente que este ADR cierra.
