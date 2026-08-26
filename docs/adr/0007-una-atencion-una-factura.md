# ADR-0007 — Una atención asegurada, una sola factura

**Estado:** Aceptado
**Fecha:** 2026-08-21
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

Se escribe **antes** de que exista el módulo de facturación, porque es una regla que no se puede agregar después: condiciona cómo se cierra una cuenta, qué se hace con un cargo tardío y si una atención puede repartirse en dos cuentas.

La regla la puso el negocio, no el diseño:

> «Le extienden una sola factura a la aseguradora por el paciente, ya que si le dan dos facturas la aseguradora solo cubre la primera o la segunda, y a veces no en su totalidad.»

No es una preferencia administrativa. Es cómo funcionan las aseguradoras en Honduras: el reclamo se presenta por evento, y un evento con dos documentos se procesa como si fueran dos reclamos — o uno se rechaza, o los dos se cubren parcialmente. **La diferencia la termina pagando el hospital, y aparece semanas después, cuando ya nadie recuerda por qué se partió la cuenta.**

Y aplica en los dos casos, no solo cuando el hospital cobra:

- **Con convenio**, el hospital le factura a la aseguradora. Dos facturas, un reclamo mal pagado.
- **Con seguro externo** (ADR sin número; ver `TipoConvenio::Reembolso`), el paciente paga en caja y reclama él. Dos facturas y el problema es idéntico — solo que ahora lo sufre el paciente, con la factura del hospital en la mano.

## Decisión

**Una atención con un seguro detrás produce UN documento fiscal.** No se factura parcialmente, no se emite una segunda factura «por lo que faltó», y no se reparte una atención en dos cuentas.

**Un cargo que aparece después de facturar NO abre una factura nueva.** Se anula la factura con nota de crédito y se reemite completa, o el cargo se absorbe. La decisión es de dirección, caso por caso — pero «emitir otra» no está entre las opciones.

**Antes de cerrar hay una revisión explícita** de que no falta nada por cargar. Es el único momento en que corregir sale gratis.

## Razones

**1. El costo del error es asimétrico y llega tarde.** Facturar de más se corrige con una nota de crédito el mismo día. Partir un reclamo se descubre cuando la aseguradora paga de menos, semanas después, y para entonces no hay a quién reclamarle.

**2. El sistema ya puede partir una cuenta.** `cuentas.cuenta_anterior_id` existe para cuando cambia el pagador a mitad de atención, y está bien que exista. Pero eso mismo hace posible el error, así que la regla tiene que estar escrita: **cambiar de pagador es la única razón válida para abrir una segunda cuenta sobre un mismo encuentro.**

**3. El cargo tardío ya está reconocido.** `cargos.es_tardio` existe. Lo que faltaba era decir qué se hace con él después de facturar, que es justamente donde la respuesta intuitiva —«emito otra»— es la cara.

## Consecuencias

**Obliga a:**

- **La facturación valida antes de emitir:** que la cuenta esté cerrada, que no queden cargos pendientes, y que el encuentro no tenga otra cuenta viva.
- **Un encuentro con pagador tercero no admite emisión parcial.** Ni siquiera «lo que ya está listo».
- **Corregir después de facturar es nota de crédito + reemisión completa**, nunca una factura complementaria (§ correlativos fiscales: la nota de crédito consume su propio CAI — ver ADR-0004).
- **La pantalla de cierre tiene que mostrar lo que podría faltar** —órdenes de laboratorio sin resultado, medicamentos despachados sin cargar— porque cerrar es el último momento barato.

**Cierra:** la facturación parcial y la factura complementaria.

**Lo que NO decide este ADR:** si un cargo tardío se absorbe o se reemite. Eso es caso por caso y lo decide dirección; lo único que queda fijo es que no se emite un segundo documento.

## Cómo se verifica

- En code review: cualquier camino que emita un documento fiscal debe negarse si el encuentro ya tiene uno vivo.
- Un test que intente facturar dos veces el mismo encuentro con pagador tercero y espere el rechazo.
- Un test que intente abrir una segunda cuenta sobre un encuentro con cuenta viva, sin cambio de pagador, y espere el rechazo.

## Referencias

- ADR-0004 — append-only: por qué corregir es enmendar y no editar, y por qué la nota de crédito consume su propio correlativo.
- `TipoConvenio::Reembolso` — el seguro externo no es pagador, pero la regla de la factura única lo alcanza igual.
- `cuentas.cuenta_anterior_id`, `cargos.es_tardio` — las dos piezas que ya existían y que esta regla acota.
