# ADR-0003 — Catálogo único de ítems facturables y tarifario por convenio con vigencia

**Estado:** Aceptado
**Fecha:** 2026-08-17
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

Un hospital cobra contra el mismo paciente cosas de naturaleza muy distinta: estancia, honorarios, cirugía, medicamentos, insumos, exámenes de laboratorio, estudios de imagen, paquetes quirúrgicos y cafetería. Y les cobra precios distintos según **quién paga**: particular, Ficohsa, Mapfre, Atlántida, Seguros del País, Davivienda/Crefisa, IHSS por servicios subrogados.

Dos formas obvias de modelar esto, ambas equivocadas:

1. **Un catálogo por módulo** — farmacia tiene sus productos, laboratorio sus exámenes, quirófano sus procedimientos.
2. **Una columna `precio` en el catálogo**, y descuentos o catálogos paralelos por aseguradora.

## Decisión

**Un solo catálogo de ítems facturables**, tipado (`servicio`, `procedimiento`, `medicamento`, `insumo`, `estudio_laboratorio`, `estudio_imagen`, `honorario`, `estancia`, `paquete`, `otro`). Farmacia, laboratorio, imagen y quirófano cobran todos contra ese catálogo.

**El precio NO es un atributo del ítem: es una función.**

> `precio(item, convenio, fecha_del_servicio, sede)`, resuelta por vigencia.

**Prohibida la columna `precio` en el catálogo.** El tarifario vive en `precios (item_id, convenio_id, sede_id, vigencia_desde, vigencia_hasta, precio, moneda)`.

**Particular es un convenio más** (`CONTADO`), no un caso especial.

**Cada cargo guarda un snapshot inmutable** de precio unitario, tarifario y versión aplicados, convenio, descuento, régimen de ISV, cobertura y responsable.

## Razones

**Por qué un solo catálogo:**

Un catálogo por módulo obliga a resolver el mismo problema —unidad, régimen de ISV, política de cobro, mapeo a cuenta contable— cuatro veces y de cuatro formas distintas. Y hace imposible la pregunta que el hospital hace todos los meses: *"¿qué se le cobró a este paciente y cuánto costó?"*, porque no hay una sola tabla donde sumar.

**Por qué el precio es función y no columna** — este es el corazón del ADR:

1. **Con precio-columna, cada aseguradora nueva obliga a duplicar el catálogo.** A los seis meses hay cuatro catálogos paralelos, ninguno correcto, y agregar un examen nuevo significa agregarlo cuatro veces. Es el modo de falla más común y más caro de los sistemas hospitalarios pequeños.
2. **Renegociar un tarifario reescribiría el pasado.** Sin vigencia, subir el precio con Ficohsa cambia lo que dice una factura de hace tres meses. Eso es rechazo del reclamo y hallazgo fiscal.
3. **El precio se resuelve por la fecha del SERVICIO, no de la facturación.** La cirugía del 28 se factura el 3 del mes siguiente; con el tarifario nuevo se cobraría de más o de menos, y la diferencia la descubre la aseguradora, no el hospital.
4. **Sin snapshot en el cargo, el histórico es una ilusión.** Aunque el tarifario tenga vigencia, si el cargo solo guarda `item_id` y recalcula al imprimir, cualquier corrección de datos maestros reimprime facturas viejas con números nuevos.

**Por qué particular es un convenio:**

Tratarlo como "el precio base, y las aseguradoras son descuentos" mete un `if` en el motor de precios y obliga a una segunda ruta de código para deducible, coaseguro y elegibilidad. Modelarlo como un convenio más deja **una sola ruta**, y esa ruta es la que se prueba.

## Consecuencias

**Obliga a:**

- **Exclusión temporal en la base, no en el código.** PostgreSQL 18 con `btree_gist`: `UNIQUE (item_id, convenio_id, sede_id, vigencia WITHOUT OVERLAPS)`. Dos tarifas vigentes el mismo día harían que el precio dependa del `ORDER BY`; la base tiene que impedirlo.
- **`regimen_isv` por ítem con al menos cuatro valores** (`exento` / `gravado_15` / `gravado_18` / `exonerado`), nunca un booleano. En Honduras la mayor parte del negocio hospitalario es **exenta** por el Art. 15 (b) y (d) de la Ley del ISV, pero estética, cafetería y parqueo son gravados y conviven en la misma factura (§8.6.1).
- **`politica_cargo` por ítem**: cobrable directo / incluido en la tarifa del procedimiento / gasto del servicio. Sin esto, o se factura guante por guante, o se regala una prótesis de L 70,000 porque nadie la cargó.
- **Elegibilidad por convenio** como dato del tarifario, no como `if`: un ítem puede estar excluido para una póliza.
- **Catálogos con vigencia, no con un booleano `activo`.** Un servicio "desactivado" hoy debe seguir explicando una factura de hace dos años.
- **Códigos estándar como campos opcionales, no como llave**: `cie10`, `loinc`, `atc`, `registro_arsa`. El tarifario de procedimientos es propio (CPT y SNOMED CT quedaron descartados, §8.10).

**Cierra:**

- Ninguna tabla de catálogo lleva columna `precio`, `costo_venta` ni equivalente.
- No hay catálogos por módulo.

## Cómo se verifica

- **En cada revisión de migración:** ¿aparece una columna de precio en un catálogo? Se rechaza.
- **Golden tests al céntimo (§9.H13), que no se tocan sin recalcular a mano:**
  - cuenta mixta exento + gravado → total exacto L 7,897.50
  - aplicación de póliza (deducible + coaseguro + no elegibles) → paciente L 2,815.50 y aseguradora L 5,082.00, que suman exacto
  - costo promedio ponderado, con valor de inventario exactamente L 0.00 al agotar
  - anulación con nota de crédito: correlativo conservado, inventario revertido, saldo al centavo
- **Test de vigencia:** insertar dos tarifas solapadas para el mismo `(item, convenio, sede)` **debe fallar en la base**, no en el Service.
- **Prueba del diseño:** firmar un convenio nuevo debe ser cargar filas de tarifario. Si obliga a escribir una migración, el diseño falló.

## Referencias

- `CLAUDE.md` §8.4 (catálogo), §8.5 (precios — regla innegociable), §8.6 (dinero e ISV hondureño), §8.6.5 (mecánica de convenios), §9.H (catálogo anti-errores de facturación), §12 (restricciones temporales), §16 (tests exigidos)
- Ley del ISV de Honduras, Art. 15 incisos (b) y (d)
- [ADR-0002](0002-multi-sede-single-tenant.md) — el tarifario tiene alcance por sede
