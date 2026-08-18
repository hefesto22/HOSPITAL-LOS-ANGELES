# Dominio — Inventario, costeo y motor de precios

**Estado:** refleja lo que Mauricio explicó y decidió el 17-ago-2026.
**§4.5 (política de precio) está DECIDIDO.** Queda pendiente su confirmación en §2.6 (costo por producto vs por lote).

> **Corrección del 18-ago-2026 — §4.3 y §4.4 se reescribieron contra la fuente primaria.**
> Las cifras anteriores venían de prensa y estaban mal en dos puntos que movían dinero.
> El precio de lista de todo el catálogo bajó ~20 % como consecuencia. Ver §4.4.1.

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

| Rango | Edad | Aplica a |
|---|---|---|
| Normal | < 60 | — |
| **Tercera edad** | **60+** | **Todos los descuentos en salud** (Art. 30, Decreto 199-2006) |
| Cuarta edad | 80+ | **Solo servicios básicos**, NO salud (Art. 31 reformado, Decreto 45-2025) |

**En salud hay un solo umbral: 60 años.** La cuarta edad existe en la ley, pero vive en otra sección — ver §4.4.

**El rango se calcula de la fecha de nacimiento del paciente, contra la FECHA DEL SERVICIO** — nunca contra la fecha de facturación ni contra "hoy". Un paciente que cumple 60 durante la hospitalización cambia de rango a mitad de la cuenta, y cada cargo debe llevar el rango vigente el día que se generó.

**El rango se guarda en el snapshot del cargo.** Recalcularlo al reimprimir daría otro resultado.

Los rangos, sus edades y sus porcentajes son **configuración con vigencia**, no constantes (§1.1). La ley se reformó en enero de 2026 y volverá a reformarse. **La cuarta edad se modela desde ya aunque hoy no aplique a salud**: el día que el Congreso la extienda a servicios médicos —que es exactamente lo que la prensa ya daba por hecho— es una fila de configuración, no un despliegue.

### 4.4 ⚠️ Los descuentos son OBLIGACIÓN LEGAL, no política comercial

**Fuente:** Ley Integral de Protección al Adulto Mayor y Jubilados, **Decreto Legislativo 199-2006**, Capítulo VI, Sección I, **Artículo 30**. Verificado el 18-ago-2026 contra el texto de la ley publicado por la Biblioteca Virtual en Salud de Honduras.

| Numeral | Concepto | Descuento |
|---|---|---:|
| 30.5 | Servicios de salud en **hospitales y clínicas privadas** | **25 %** |
| 30.6 | **Medicamentos y material quirúrgico** (con receta) | **25 %** |
| 30.7 | Honorarios por **consulta médica general** | **25 %** |
| 30.7 | Honorarios por **consulta médica especializada** | **30 %** |
| 30.8 | **Intervención quirúrgica** | **30 %** |
| 30.8 | **Odontología, optometría y oftalmología** | **30 %** |
| 30.8 / 30.9 | **Radiología y laboratorio** | **30 %** |
| 30.9 | **Medicina computarizada** (TAC y similares) | **30 %** |

**Requisito del numeral 6, confirmado en el Artículo 34:** el descuento en medicamentos exige **receta original firmada y sellada** por médico colegiado o en servicio social autorizado. No es opcional y el sistema tiene que poder demostrar que existía — ver §4.4.2.

**El incumplimiento se denuncia ante Protección al Consumidor (línea 115).**

#### 4.4.1 🔴 Qué cambió respecto de la versión anterior de este documento

La versión del 17-ago decía «30 % consulta de especialista · 25 % medicamentos · **20 % hospitales y clínicas privadas** · **hasta 40 % cuarta edad (80+)**». Dos errores, los dos con consecuencia en dinero:

1. **Hospitales y clínicas privadas es 25 %, no 20 %** (numeral 30.5).
2. **El Decreto 45-2025 NO toca salud.** Reforma el **Artículo 31**, que es la *Sección II — Descuento al Pago de Servicios*: energía eléctrica, agua, telecomunicaciones, cable, bienes inmuebles y salida aeroportuaria. Ahí sí existe la cuarta edad con 35 % (40 % en cable). **El Artículo 30, que es el de salud, quedó intacto.** El título oficial del decreto lo dice literalmente: *"Reformar el Artículo 31 … Sección II, Descuento al Pago de Servicios; y adicionar los artículos 31-A y 31-B"*.

**Consecuencia:** el descuento máximo que puede recibir un ítem de salud es **30 %**, no 40 %. Y para medicamentos es **25 %**.

Con el costo de L 10.00 y el margen objetivo de 120 % del §4.5:

| | Antes (dato de prensa) | **Ahora (Art. 30)** |
|---|---:|---:|
| Descuento máximo del medicamento | 40 % | **25 %** |
| Precio de lista | 22.00 ÷ 0.60 = L 36.67 | **22.00 ÷ 0.75 = L 29.33** |
| Paga el paciente < 60 | L 36.67 | **L 29.33** |
| Paga el adulto mayor | L 22.00 (a los 80) | **L 22.00 (a los 60)** |

**El adulto mayor paga lo mismo; el paciente común paga 20 % menos.** El precio de lista anterior le cobraba de más a todo el que no tiene descuento, para protegerse de un descuento que no existe en salud. La preocupación de competitividad frente a las farmacias de barrio que quedó anotada en la versión anterior se resuelve sola con el dato correcto.

Y hay un cambio de fondo: antes el piso de 120 % se tocaba solo con pacientes de 80+, que son pocos. **Ahora se toca con todos los de 60+**, que en un hospital son muchos. El piso dejó de ser un caso raro y pasó a ser el precio real de una parte grande de la venta — razón de más para que `margen_objetivo` sea afinable por categoría.

#### 4.4.2 Consecuencias de diseño

1. **El porcentaje depende del TIPO de ítem, no del paciente.** Un paciente de 65 años lleva, en la misma cuenta, 25 % en el medicamento, 30 % en el honorario del especialista y 25 % en la habitación. **Se resuelve por línea, exactamente igual que el ISV.**
2. **El eje del descuento no es `TipoItem`, es una categoría legal propia.** Consulta general (25 %) y consulta especializada (30 %) son el mismo tipo de ítem —honorario— con porcentajes distintos. Por eso cada ítem lleva una **`categoria_legal_de_descuento`** nombrada por el numeral del Artículo 30 que la sustenta. Así el sistema puede responder *"este ítem lleva 30 % porque cae en el numeral 8"*, que es lo que hay que contestar cuando llega una denuncia a la línea 115.
3. **Los porcentajes van como configuración con vigencia**, por categoría legal y rango de edad. **Prohibido dejarlos como constante en código** (§1.1): el día que la ley cambie, se edita configuración, no se despliega.
4. **El descuento en medicamentos exige receta (Art. 34).** El cargo guarda si hubo receta y cuál. En dispensación a paciente internado sale de la orden médica y es automático; **en venta de mostrador al público hay que capturarla**, y si no la hay, el descuento no procede y el sistema debe decir por qué en vez de aplicarlo en silencio.
5. El paciente acredita la condición con **tarjeta de identidad**; los jubilados, con carné del IPM o INJUPEMP. El MPI ya exige documento de identidad (§8.2) y ya distingue el carné de jubilado como tipo propio, así que la detección es automática — pero el documento debe estar **verificado**, no digitado a ojo.

### 4.5 Política de precio — DECIDIDA

> **Decisión de Mauricio, 17-ago-2026:**
> **el margen nunca baja del 120 % en medicamentos, sin importar la edad del paciente ni el descuento legal que le corresponda.**

Se implementa con **un solo precio de lista, igual para todos**, calculado desde el **peor caso** de descuento que ese ítem puede recibir:

```
                   costo_promedio × (1 + margen_objetivo)
precio_lista  =  ─────────────────────────────────────────
                    1 − descuento_maximo_del_item
```

### Resultado con costo L 10.00, margen objetivo 120 %, descuento máximo 25 %

`precio_lista = 22.00 / 0.75 = ` **L 29.33 para todos**

| Rango | Precio de lista | Descuento legal | **Paciente paga** | Margen |
|---|---:|---:|---:|---:|
| Normal (< 60) | 29.33 | — | **29.33** | 193 % |
| Tercera edad (60+) | 29.33 | 25 % | **22.00** | **120 % ← el piso** |

**El piso se cumple siempre**, y se toca exactamente en el caso de mayor descuento. Nadie recibe un precio de lista distinto por su edad: el descuento cae sobre el mismo precio que ve cualquiera, así que el adulto mayor **sí paga menos** que el paciente que va detrás en la fila.

### Por qué se calcula desde el peor caso y no desde el rango normal

Si la lista se fijara en L 22.00 (el margen objetivo sobre el rango sin descuento), el adulto mayor pagaría L 16.50 y el margen caería a 65 %. Fijarla desde el descuento máximo es lo que convierte el 120 % en **piso garantizado** en lugar de en objetivo que se incumple con cada paciente mayor.

### Reglas de implementación

- **`margen_objetivo` es configuración por categoría de producto, con vigencia.** No es una constante ni un valor único global (§1.1).
- **`descuento_maximo_del_item`** sale de la tabla de descuentos legales `(categoría legal × rango de edad)`, también con vigencia. Un medicamento (25 %) y una radiografía (30 %) tienen máximos distintos.
- **Antes de confirmar un precio, la pantalla muestra el margen resultante en CADA rango de edad**, ya con el descuento aplicado. La decisión se toma con los números a la vista, no a ojo.
- **Alerta si algún rango cae bajo el piso configurado**, y alerta también cuando una entrada mueva el costo promedio más de un umbral (§4.2).
- **Redondeo:** se redondea una sola vez, sobre el precio de lista. El descuento se aplica sobre la lista ya redondeada. El snapshot del cargo guarda **los tres valores** — lista, descuento aplicado y neto — porque recalcular cualquiera de ellos después da diferencias de centavos que en una auditoría hay que explicar.

### ⚠️ Dependencia directa con la normativa

**El precio de lista de TODO el catálogo depende del descuento máximo legal**, como acaba de quedar demostrado: corregir un dato movió el precio de cada medicamento un 20 %.

Por eso los porcentajes van como **configuración con vigencia y jamás hardcoded**: el día que se confirmen contra el ejemplar físico de La Gaceta —o que la ley vuelva a reformarse, como se reformó en enero de 2026— se recalcula el catálogo desde configuración, sin desplegar código.

**Lo que todavía conviene hacer:** un abogado debería leer el ejemplar de La Gaceta No. 31,361 del 21-jul-2007 y confirmar que el Artículo 30 no fue reformado por ningún decreto posterior al 45-2025. La verificación de este documento se hizo contra el texto de la ley publicado por la Biblioteca Virtual en Salud y contra el título oficial del Decreto 45-2025 en el portal del SAR; es sólido, pero no reemplaza a un abogado revisando la consolidación completa.

---

## 5. Convenios y seguros

**Confirmado por Mauricio:** los seguros y el Hospital Militar tienen **precios propios e independientes** para habitación, camilla, servicios del hospital, laboratorio y radiografía. No son un porcentaje sobre el precio normal.

Eso es exactamente el tarifario por convenio del **ADR-0003**, sin excepciones: `precio(item, convenio, fecha_servicio, sede)`.

- **Particular es un convenio más** (`CONTADO`). Su tarifario es el que alimenta la Ruta A.
- **Hospital Militar e IHSS son convenios**, con su propia mecánica de reclamo.
- **Copago, deducible y coaseguro** viven en el convenio, no en el ítem.

### 5.1 Pregunta abierta que quedó sin responder

**¿El descuento legal de tercera edad aplica cuando paga un seguro, y sobre qué base?** Sigue siendo la pregunta más cara que queda abierta: cambia el orden de operaciones del motor de facturación completo.

Lo que se pudo verificar el 18-ago-2026:

- **El Artículo 30 no menciona seguros, aseguradoras ni pólizas.** El silencio no es permiso para no aplicarlo: la obligación está redactada sobre el prestador del servicio, no sobre quién paga.
- **La regla de "no acumulable" que sí existe está en la Sección II** (servicios básicos), que es otra sección y otro decreto. Extenderla a salud por analogía es una interpretación, no una lectura.

Por eso el diseño soporta las tres variantes **sin migración**, con un campo por convenio (`base_del_descuento_legal`: sobre el total · sobre la porción del paciente · no aplica) y sin valor por defecto silencioso: mientras no haya respuesta jurídica, el convenio se configura explícitamente y el cargo guarda cuál se usó.

Va como pregunta **#16** en `dominio.md`.

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

| # | Falta | Bloquea | Estado |
|---|---|---|---|
| 1 | Confirmar los porcentajes de descuento (§4.4). Definen el precio de lista de todo el catálogo (§4.5) | Motor de precios | ✅ **Verificado 18-ago-2026** contra el Art. 30 del Decreto 199-2006. Queda la revisión de un abogado sobre la consolidación completa |
| 2 | Definir el **margen objetivo por categoría** de producto — 120 % es el de medicamentos | Carga inicial | Abierto |
| 3 | ¿El descuento legal aplica con seguro? (§5.1) | Facturación | Abierto — la ley calla; requiere criterio jurídico |
| 4 | ISV en compras exentas → costo (pendiente #4 de `dominio.md`) | Costeo | Abierto — contador |
| 5 | Tarifarios reales de las aseguradoras (pendiente #10 de `dominio.md`) | Carga inicial | Abierto |
| 6 | Confirmar §2.6: costo por producto, no por lote | Kardex | Abierto |
| 7 | Reparto del honorario médico: ¿qué porcentaje y sobre qué base? | Honorarios | Abierto |

**Nada de esto bloquea la ESTRUCTURA del bloque 3.** Los porcentajes, los márgenes y la base del descuento son filas de configuración con vigencia; el esquema se construye ahora y los valores se corrigen sin desplegar.

---

## 8. Fuentes de la verificación legal (18-ago-2026)

- **Ley Integral de Protección al Adulto Mayor y Jubilados, Decreto 199-2006** — texto de la ley publicado por la Biblioteca Virtual en Salud de Honduras: `https://www.bvs.hn/Honduras/salud/ley.integral.de.proteccion.al.adulto.mayor.y.jubilados.pdf`
- **Decreto 45-2025**, La Gaceta No. 37,047 del 19-ene-2026 — ficha oficial en el portal del SAR, cuyo título delimita el alcance de la reforma al Artículo 31 y la Sección II: `https://www.sar.gob.hn/`
- Cobertura de prensa del 23-ene-2026 usada solo para contrastar, **no como fuente**: El País HN, Televicentro, Contexto HN, El Heraldo.
