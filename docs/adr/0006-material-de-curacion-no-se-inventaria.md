# ADR-0006 — El material intercambiable no se inventaría; se controla por consumo del período

**Estado:** Aceptado
**Fecha:** 2026-08-21
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

Un BENDAJE se cobra con un precio que ya incluye las vendas, el esparadrapo y la limpieza. Un procedimiento no mueve kardex, así que esas vendas salen del estante y el sistema no se entera. La pregunta era si había que declarar, procedimiento por procedimiento, qué saca de farmacia — una receta de consumo que se descontara al cobrar.

Se llegó a escribir la tabla (`consumos_de_procedimiento`, con llave foránea compuesta contra `items(id, se_almacena)` para que una receta no pudiera pedir algo que no está en ningún estante). Se descartó antes de correrla.

El caso que dio vuelta la decisión fue la sutura: **¿se registra «1 aguja y 40 cm de hilo»?** No. Una sutura es un sobre estéril de un solo uso; nadie mide el hilo y lo que sobra se bota. Y si el hilo no se mide, la pregunta deja de ser «cómo modelamos la receta» y pasa a ser **hasta dónde vale la pena dividir**.

## Decisión

**El material intercambiable no entra al inventario.** Gasa, esparadrapo, algodón, alcohol, guantes de examen y jabón se compran como gasto, su costo va dentro del precio del procedimiento, y **no tienen fila en el catálogo**.

**El material que se pide por nombre, tipo o calibre sí entra**, con existencia y lote como cualquier ampolla: tubo endotraqueal, sonda, catéter, sutura por calibre, prótesis.

**El control de lo intercambiable es por consumo del período, no por paciente.** Paquetes de gasa consumidos en el mes contra procedimientos hechos en el mes. Una desviación se ve; una gasa suelta no.

**No hay receta de consumo.** Un procedimiento se cobra con su precio y no descuenta nada.

## Razones

**1. El criterio correcto no es el precio: es si la cosa es intercambiable.**

Una gasa es una gasa — nadie la pide por nombre. Un tubo 7.5 **no** es un 7.0 y un Vicryl 3-0 no es un nylon 2-0: se piden por nombre porque elegir mal tiene consecuencia clínica. Lo que se pide por nombre hay que saber si está; lo que da lo mismo, no.

**2. Para el material crítico, el valor NO es contable — es disponibilidad.**

Si a las tres de la mañana no hay un tubo 7.5, el problema no es que el costo del procedimiento esté mal calculado: **es que no se puede intubar.** Un sistema que sabe cuántos quedan permite reponer antes de que falte. Ese es el motivo por el que ese grupo se inventaría, y no tiene nada que ver con el margen.

**3. Una receta que nadie llena es peor que no tener receta.**

Si la curación tiene quince renglones, nadie la carga y nadie la corrige. Queda de adorno y el kardex miente igual — pero ahora con la confianza de que «está el sistema», que es peor que no tenerlo. La prueba para cada renglón era: *¿si esto desaparece del estante sin que nadie lo anote, importa?* Para la gasa la respuesta es no.

**4. Contar lo barato cuesta más de lo que controla.**

El costo del control es por transacción y lo paga la persona que está atendiendo. El consumo del período controla el mismo dinero con cero fricción en el mostrador.

**5. La regla general, que también explica por qué el jarabe SÍ se lleva al mililitro:**
*¿lo que sobra le sirve al próximo paciente?* Los 55 ml de un frasco abierto sí — por eso existe `RepartidorDeEnvases`. El sobre de sutura abierto no. Donde el sobrante se bota, medirlo no compra nada.

## Consecuencias

**Obliga a:**

- **`Insumo` sale del formulario de Servicios** (`AmbitoCatalogo::tiposPermitidos()`). Un insumo es físico; si se almacena va en Productos, y si no se almacena su costo va en el precio del procedimiento — no necesita fila que alguien pueda cargar a una cuenta.
- **El costo de estos procedimientos es estimado, y hay que decirlo.** El precio de un BENDAJE no sale de un costo calculado: sale del criterio de dirección. No se puede reportar margen real por procedimiento.
- **El conteo físico de material de curación no cuadra contra el kardex** — porque no hay kardex. Se controla contra compras del período.
- El oxígeno queda fuera por otra razón: un tanque sirve a varios pacientes durante días. Se cobra como servicio por hora o por día y el tanque se controla por recargas.

**Cierra:** el descuento automático de material al cobrar un procedimiento.

**Qué la revertiría** — cualquiera de estas dos, y entonces se construye la receta solo para los procedimientos que la justifiquen:

1. Aparece faltante de material que el consumo del período no explica.
2. Dirección necesita margen real por procedimiento, no estimado.

No es un refactor: son unas horas. La tabla descartada está descrita acá arriba y la llave foránea compuesta contra `items(id, se_almacena)` es el detalle que vale la pena rescatar — hace imposible que una receta pida algo que no está en ningún estante.

## Cómo se verifica

- En code review: si aparece una migración que agrega descuento de material a un procedimiento, este ADR se supersede primero.
- `AmbitoCatalogo::tiposPermitidos()` no ofrece `Insumo` del lado de Servicios.
- El reporte de consumo del período existe y se mira: es el único control que queda sobre este material.

## Referencias

- ADR-0003 — catálogo único: por qué servicios y productos comparten `items` y se separan por `se_almacena`.
- ADR-0004 — kardex append-only: por qué lo que sí se inventaría no se corrige con `UPDATE`.
- `app/Domain/ValueObjects/EnvaseDisponible.php` — la regla del sobrante que conserva valor, aplicada al frasco abierto.
