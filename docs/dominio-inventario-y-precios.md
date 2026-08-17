# Dominio — Inventario, costeo y motor de precios

**Estado:** 🚧 BORRADOR — refleja lo que Mauricio explicó el 17-ago-2026 y cómo lo entendí yo.
**Falta su visto bueno.** Nada de esto se codifica hasta que confirme el §2.6 y el §4.5.

Este documento es la especificación de los bloques **3 (catálogos y convenios)**, **5 (inventario)**, **6 (farmacia)** y la parte de precios del **7 (facturación)** del Apéndice B.

---

## 1. Lo principal, en una frase

> **Lo que entra, lo que sale, a qué costo entró, y cuánto cuesta hoy lo que TENGO** — no lo que costó históricamente. Sobre eso se decide el margen, y sobre el margen cae un descuento por edad que es obligación legal.

---

## 2. El costo — promedio ponderado **móvil**

### 2.1 La fórmula

Cada **entrada** recalcula el costo promedio contra la **existencia actual**:

```
                    (existencia_actual × costo_promedio_actual) + (cantidad_que_entra × costo_de_entrada)
nuevo_promedio  =  ───────────────────────────────────────────────────────────────────────────────────────
                                       existencia_actual + cantidad_que_entra
```

### 2.2 Lo que NO es

**No** es el promedio de todas las compras históricas. La diferencia es sustantiva: si un producto se agotó y se vuelve a comprar, el promedio **arranca del costo nuevo** y no arrastra el de hace dos años. Un promedio histórico haría que el sistema reporte una utilidad que no existe.

### 2.3 Reglas que se derivan

- **Las salidas NO alteran el promedio.** Salen al promedio vigente al momento del movimiento. Solo entradas, devoluciones a proveedor y ajustes de costo lo mueven.
- **El costo de entrada incluye todo lo que costó poner el producto en bodega**, no solo la factura: flete, importación, y **el ISV pagado en compras destinadas a venta exenta**, que no es crédito fiscal recuperable y va al costo (⚠️ pendiente #4 de `dominio.md`, confirmar con el contador).
- **La conversión de unidad se aplica ANTES de promediar.** Si se compra por caja y se vende por unidad, el costo se lleva a la unidad mínima. Promediar caja contra unidad da un costo mil veces mayor y nadie lo nota hasta el cierre.
- **Con existencia 0 o negativa, el promedio se reinicia al costo de la entrada.** Sin esta regla la fórmula divide por cero o produce un promedio absurdo.
- **El costo promedio es por `(item, sede, almacén)`**, según ADR-0002. Dos sedes que compran al mismo proveedor a precios distintos no comparten costo.
- **Todo movimiento es append-only** (ADR-0004). El promedio resultante se **guarda en la fila del movimiento**, no solo en el producto: así se puede reconstruir el costo de cualquier fecha sin recalcular la historia.

### 2.4 Ejemplo trabajado — el que se convierte en test

| # | Movimiento | Cant. | Costo unit. | Existencia | Promedio | Valor |
|---|---|---:|---:|---:|---:|---:|
| 1 | Entrada | 100 | 10.00 | 100 | **10.0000** | 1,000.00 |
| 2 | Salida | 40 | *10.00* | 60 | 10.0000 | 600.00 |
| 3 | Entrada | 100 | 13.00 | 160 | **11.8750** | 1,900.00 |
| 4 | Salida | 160 | *11.875* | 0 | 11.8750 | **0.00** |
| 5 | Entrada | 50 | 9.00 | 50 | **9.0000** | 450.00 |

Fila 3: `(60 × 10 + 100 × 13) / 160 = 11.875`.
Fila 4: al agotar, el valor de inventario debe quedar **exactamente L 0.00**. Es el test de §9.G3 — si queda un centavo, el redondeo está mal.
Fila 5: la existencia estaba en cero, así que el promedio **no arrastra** el 11.875.

### 2.5 Precisión

El costo promedio se guarda con **más decimales que el dinero** (4, no 2). Redondear el promedio a 2 en cada entrada acumula error y a los seis meses el valor de inventario no cuadra con la contabilidad.

### 2.6 ⚠️ Pendiente de confirmar

- ¿El costo promedio se lleva también por **lote**, o solo por producto? El lote es obligatorio para farmacia por trazabilidad y vencimiento (ARSA), pero eso es distinto de costear por lote. **Recomendación: promedio por producto, lote solo para trazabilidad y FEFO.** Costear por lote obliga a decidir de qué lote salió cada unidad y complica la dispensación sin beneficio contable.

---

## 3. Unidades — cómo se compra y en qué se vende

Un producto tiene **al menos dos unidades** y un factor entre ellas.

| Concepto | Ejemplo |
|---|---|
| `unidad_compra` | caja |
| `factor_conversion` | 100 |
| `unidad_dispensacion` | ampolla |

> **Caja de Nantium: se compra la caja de 100 ampollas, se dispensa la ampolla.**

**El kardex se lleva SIEMPRE en la unidad mínima de dispensación.** Guardarlo en unidad de compra obliga a fracciones en cada salida y hace imposible cuadrar.

### 3.1 Fraccionables (ml / cc)

**Decisión de Mauricio: depende del medicamento Y del caso — las dos formas coexisten.**

Eso obliga a dos niveles:

1. **En el catálogo:** bandera `fraccionable` (sí/no) y unidad de fracción (ml, cc, mg). Una ampolla no es fraccionable; un frasco de nebulización sí.
2. **En la dispensación:** para un ítem fraccionable, quien dispensa elige **cobrar la dosis aplicada** o **cobrar el envase completo**, y el sistema registra cuál se usó y por qué.

Reglas que esto arrastra:

- **Envase abierto = existencia parcial con su propia vida útil.** Muchos multidosis vencen a las 24–48 h de abiertos, sin importar la fecha del frasco. Hay que guardar `abierto_en` y una caducidad post-apertura por producto.
- **El sobrante que se descarta es MERMA, no venta.** Debe salir del kardex con motivo, no desaparecer. Si no, el inventario "cuadra" porque nadie registró lo que se botó.
- **El precio del ml no es el precio del frasco entre los ml.** Cuando se cobra por dosis casi siempre se pierde producto; el margen tiene que absorberlo o el hospital pierde en cada nebulización.

### 3.2 Otros atributos del producto

Código de barras propio e **impresión de etiquetas** para producto (ZPL — ver pendiente #7 de `dominio.md`), tipo de medicamento, presentación, principio activo (ATC), registro sanitario ARSA, si es controlado, y lote + vencimiento obligatorios.

---

## 4. El precio — la cadena completa

### 4.1 Las dos rutas

**Ruta A — productos que se compran y se venden (farmacia, insumos):** el precio se **deriva del costo**.

```
costo_promedio_ponderado  ──×(1 + margen)──►  precio_de_lista  ──−descuento_legal──►  precio_al_paciente
```

**Ruta B — servicios (habitación, camilla, uso de equipo, laboratorio, radiografía, honorarios):** el precio **no se deriva de nada**, se fija en el tarifario.

Las dos rutas terminan en lo mismo: una fila de tarifario con vigencia (ADR-0003). **La Ruta A no reemplaza al tarifario: lo alimenta.** El margen es la regla que *genera* el precio; el precio vigente es el que manda.

### 4.2 ⚠️ Por qué esa distinción no es burocracia

Mauricio pidió que el ponderado sea **automático en cada entrada**. Perfecto — **pero el precio de venta se congela en el cargo.**

Sin ese congelado, cada compra nueva le cambiaría el precio a las facturas ya emitidas: se reimprime una factura de hace tres meses y sale con otro monto. Eso es hallazgo fiscal y rechazo de reclamo del seguro.

**Con snapshot en el cargo (ADR-0003), recalcular el precio automáticamente es seguro.** Las dos cosas conviven.

**Salvaguarda que hay que agregar:** alertar cuando una entrada mueva el costo promedio más de un umbral configurable (p. ej. 25 %). Un cero de más digitado en el costo de una factura de compra se propaga al mostrador en segundos y nadie lo revisa.

### 4.3 Rangos de edad — detección automática

| Rango | Edad | Fuente |
|---|---|---|
| Normal | < 60 | — |
| **Tercera edad** | **60+** | Ley Integral de Protección al Adulto Mayor y Jubilados |
| **Cuarta edad** | **80+** | Decreto 45-2025, reforma del Art. 31, vigente 19-ene-2026 |

**El rango se calcula de la fecha de nacimiento del paciente, contra la FECHA DEL SERVICIO** — nunca contra la fecha de facturación ni contra "hoy". Un paciente que cumple 60 durante la hospitalización cambia de rango a mitad de la cuenta, y cada cargo debe llevar el rango vigente el día que se generó.

**El rango se guarda en el snapshot del cargo.** Recalcularlo al reimprimir daría otro resultado.

Los rangos y sus edades son **configuración con vigencia**, no constantes (§1.1). La ley cambió en enero de 2026 y volverá a cambiar.

### 4.4 ⚠️ Los descuentos son OBLIGACIÓN LEGAL, no política comercial

Verificado en prensa hondureña y en fuentes legales secundarias, 17-ago-2026:

| Descuento | Aplica a |
|---|---|
| 30 % | consulta de especialista · cirugías · odontología · oftalmología |
| 25 % | consulta de médico general · **medicamentos** · **material quirúrgico** |
| 20 % | **hospitales y clínicas privadas** |
| hasta 40 % | **cuarta edad** (80+), Decreto 45-2025 |

**El incumplimiento se denuncia ante Protección al Consumidor (línea 115) y la sanción va de 1 a 10,000 salarios mínimos.**

Consecuencias de diseño:

1. **El porcentaje depende del TIPO de ítem, no del paciente.** Un paciente de 65 años lleva, en la misma cuenta, 25 % en el medicamento, 30 % en el honorario del especialista y 20 % en la habitación. **Se resuelve por línea, exactamente igual que el ISV.**
2. Va como atributo del catálogo, `descuento_adulto_mayor`, **por tipo de ítem y por rango de edad, con vigencia**.
3. **Prohibido dejarlo como constante en código.** Es el §1.1 aplicado: el día que la ley cambie, se edita configuración, no se despliega.
4. El paciente acredita la condición con **tarjeta de identidad**; los jubilados, con carné del IPM o INJUPEMP. El expediente ya exige documento de identidad (§8.2), así que la detección es automática — pero el documento debe estar verificado, no digitado a ojo.

### 4.5 ⚠️ El ejemplo del acetaminofén — necesito que confirmes esto

Así entendí lo que explicaste:

| Rango | Margen configurado | Precio de lista | Descuento legal | **Paciente paga** | Margen real |
|---|---:|---:|---:|---:|---:|
| Normal | 120 % | L 22.00 | — | **L 22.00** | 120 % |
| Tercera edad | 150 % | L 25.00 | 30 % | **L 17.50** | 75 % |
| Cuarta edad | 160 % | L 26.00 | 40 % | **L 15.60** | 56 % |

Sobre un costo promedio de L 10.00. **Si la lectura es correcta, el margen se configura POR RANGO DE EDAD**, precisamente para que el descuento obligatorio no se coma toda la utilidad.

**Dos cosas que hay que resolver antes de codificar esto:**

**(a) Los porcentajes no coinciden con lo que encontré.** Vos dijiste 30 % tercera edad y 40 % cuarta. Las fuentes dan **25 % para medicamentos** en tercera edad y **hasta 40 %** en cuarta. La diferencia entre 25 y 30 en cada caja de medicina, multiplicada por un año, es dinero real — y equivocarse hacia abajo es la multa. **Hay que confirmar contra el texto de La Gaceta antes de cargar un solo porcentaje.**

**(b) Subir el precio de lista solo para el adulto mayor es un riesgo legal que no me corresponde evaluar a mí.** Funcionalmente, cobrarle L 25 de lista a un paciente de 60 lo que a otro se le cobra L 22, para que el descuento obligatorio quede neutralizado, puede leerse como evasión del beneficio. No estoy diciendo que lo sea — **estoy diciendo que esa decisión la tenés que tomar con tu abogado, no conmigo, y que el sistema va a dejar rastro de ella.**

El sistema puede soportar cualquiera de los dos modelos sin cambiar de diseño:

- **Margen único** con el descuento saliendo del margen del hospital.
- **Margen por rango de edad**, como lo describiste.

En los dos casos el sistema debe **mostrarte el margen después del descuento legal**, por rango, antes de que fijes el precio. Esa pantalla es la que hace que la decisión sea informada.

---

## 5. Convenios y seguros

**Confirmado por Mauricio:** los seguros y el Hospital Militar tienen **precios propios e independientes** para habitación, camilla, servicios del hospital, laboratorio y radiografía. No son un porcentaje sobre el precio normal.

Eso es exactamente el tarifario por convenio del **ADR-0003**, sin excepciones: `precio(item, convenio, fecha_servicio, sede)`.

- **Particular es un convenio más** (`CONTADO`). Su tarifario es el que alimenta la Ruta A.
- **Hospital Militar e IHSS son convenios**, con su propia mecánica de reclamo.
- **Copago, deducible y coaseguro** viven en el convenio, no en el ítem.

### 5.1 Pregunta abierta que quedó sin responder

**¿El descuento legal de tercera y cuarta edad aplica cuando paga un seguro, y sobre qué base?** Es la pregunta más cara que queda abierta: cambia el orden de operaciones del motor de facturación completo.

Hay un indicio, no una respuesta: en el descuento de energía eléctrica la ley dice que **no se combina con otras rebajas especiales**. Si ese principio se extiende a salud, el descuento aplicaría solo a la porción del paciente. **Hay que confirmarlo** — va como pregunta #16 en `dominio.md`.

Mientras no haya respuesta, la estructura se diseña para soportar las tres variantes (sobre el total, sobre la porción del paciente, o configurable por convenio) **sin migración**.

---

## 6. Estructura operativa mencionada

Anotado para el bloque 1 (Cimientos), pendiente de detallar:

- **Almacenes distintos con reglas distintas:** farmacia de venta, **farmacia de consumo interno**, dispensario, laboratorio, rayos X. El consumo interno no factura al paciente igual — sale como gasto del servicio (`politica_cargo` del ADR-0003).
- **Turnos A / B / C, extensibles.** Afectan cierre de caja, responsabilidad sobre el inventario y autorización (§9.L5: rol + relación + sede + turno).
- **Caja con varios usuarios** y cierre por turno.
- **Roles mencionados:** administrador, director general, cajera, laboratorio, rayos X, farmacia, médico.
- **Planilla** (fijos, horas extra) — ⚠️ **fuera del alcance de v1** según §1.3. Si entra, se cotiza aparte.
- **Catálogo de honorarios médicos** y su reparto con el médico.
- **Expediente de emergencias** y **registro de cirugía**.

---

## 7. Qué falta para poder codificar

| # | Falta | Bloquea |
|---|---|---|
| 1 | Confirmar los porcentajes de descuento contra **La Gaceta** (§4.4) | Motor de precios |
| 2 | Decidir §4.5(a) y §4.5(b) — margen por rango de edad, con tu abogado | Motor de precios |
| 3 | ¿El descuento legal aplica con seguro? (§5.1) | Facturación |
| 4 | ISV en compras exentas → costo (pendiente #4) | Costeo |
| 5 | Tarifarios reales de las aseguradoras (pendiente #9) | Carga inicial |
| 6 | Confirmar §2.6: costo por producto, no por lote | Kardex |
| 7 | Reparto del honorario médico: ¿qué porcentaje y sobre qué base? | Honorarios |

**Nada de esto bloquea el bloque 1 (Cimientos) ni el 2 (Identidad del paciente).** Se puede construir mientras llegan las respuestas.
