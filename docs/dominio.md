# Preguntas abiertas de dominio — SIHLA

**Última actualización:** 31 de agosto de 2026
**Responsable de conseguir las respuestas:** Mauricio Cruz

Este documento existe porque el `CLAUDE.md` §8.11 exige responder por escrito, **antes de codificar el módulo correspondiente**, las preguntas que hoy no tienen respuesta verificada.

Las marcadas 🚧 **bloquean** su módulo. No es una formalidad: construir sobre una suposición equivocada en cualquiera de ellas significa refactor con datos reales de pacientes adentro.

**Cómo se usa:** cuando llegue una respuesta, se escribe acá con su fuente y su fecha, se cambia el estado a ✅, y **si contradice algo del `CLAUDE.md` se corrige el `CLAUDE.md` en el mismo commit**.

---

## Prioridad 1 — tienen tiempo de respuesta de terceros, se piden YA

Estas cuatro dependen de instituciones o de personas fuera del equipo. Pedirlas tarde es lo que empuja el cronograma.

### 1. 🚧 Vigencia exacta del CAI bajo el Acuerdo 481-2017

**A quién:** SAR.
**Qué preguntar exactamente:** cuál es el plazo máximo de vigencia de un CAI emitido hoy, y si la fecha límite de emisión se calcula desde la autorización o desde otro hito.
**Por qué importa:** el sistema debe alertar por doble umbral — porcentaje de rango consumido **y** días restantes a la fecha límite. **Quedarse sin CAI en un hospital significa no poder dar de alta pacientes.** Si el plazo real difiere de lo asumido, la alerta avisa tarde.
**Estado asumido hoy:** ⚠️ se diseña asumiendo que existe fecha límite (el reglamento anterior daba 2 años), sin confirmar.
**Bloquea:** Facturación (bloque 7).
**Respuesta:** _pendiente_

### 2. 🚧 Trámite de autoimpresor / Sistema de Facturación Computarizado

**A quién:** SAR.
**Qué preguntar:** requisitos, tiempos y documentación para inscribir el sistema; si exige certificación previa del software.
**Por qué importa:** sin esto no se puede emitir una factura legal el día 1, por más que el módulo funcione.
**Bloquea:** el **arranque en producción** de Facturación.
**Respuesta:** _pendiente_

### 3. 🚧 Norma Técnica del Expediente Clínico de SESAL, y firma electrónica

**A quién:** Secretaría de Salud, por escrito.
**Qué preguntar:** (a) contenido mínimo obligatorio, custodia y plazo exacto de retención del expediente; (b) **si la firma electrónica está reconocida legalmente en el expediente clínico**.
**Por qué importa:** el punto (b) es **el mayor riesgo estratégico del proyecto**. Si la firma electrónica no está reconocida, órdenes médicas, autorizaciones quirúrgicas y epicrisis **deben imprimirse y firmarse en papel** — y eso cambia el diseño del módulo, no un detalle de él.
**Estado asumido hoy:** ⚠️ retención de 20 años (5 activo + 15 pasivo), según el manual de expediente clínico hondureño. Sin confirmar contra norma técnica oficial.
**Bloquea:** Expediente clínico (bloque 10) y Quirófano (bloque 11).
**Respuesta:** _pendiente_

### 4. 🚧 ISV pagado en compras destinadas a venta exenta

**A quién:** contador del hospital.
**Qué preguntar:** confirmar que el ISV pagado a proveedores por compras que alimentan ventas exentas **no es crédito fiscal recuperable** y se incorpora al costo del inventario; y cómo se registra.
**Por qué importa:** la mayor parte del negocio es exenta (Art. 15 b y d de la Ley del ISV). Si ese ISV se modela como cuenta por cobrar al fisco en vez de como costo, **todo el costeo de inventario queda distorsionado** y el margen que ve la dirección es falso.
**Bloquea:** Compras y costeo de inventario (bloque 5).
**Respuesta:** _pendiente_

---

## Prioridad 2 — dependen de equipos y proveedores, se piden antes de su módulo

### 5. 🚧 Analizadores de laboratorio

**A quién:** proveedor de los equipos.
**Qué conseguir:** marca, modelo, y **si soportan interfaz bidireccional (host query)**; protocolo (HL7 v2 ORU/ORM o ASTM E1381/E1394 por serial) y ficha de interfaz.
**Por qué importa:** con interfaz **unidireccional** hay que digitar el ID de muestra en el equipo, y ahí nacen los errores de asignación de resultado — que en un grupo sanguíneo matan.
**Bloquea:** la interfaz de Laboratorio (bloque 8).
**Respuesta:** _pendiente_

### 6. 🚧 PACS y equipos de imagen

**A quién:** proveedor / TI del hospital.
**Qué conseguir:** si ya existe un PACS y cuál; marca de los equipos de rayos X; y el **DICOM Conformance Statement de cada equipo**.
**Qué verificar:** que soporten **Modality Worklist (C-FIND)**. Sin worklist, el técnico teclea el nombre a mano, el estudio se pierde o se adjunta al paciente equivocado, **y el radiólogo informa una fractura sobre imágenes de otra persona — que se opera.**
**Regla:** exigir el Conformance Statement **ANTES de comprar** cualquier equipo nuevo, y probar MWL en la aceptación.
**Bloquea:** Imágenes (bloque 9).
**Respuesta:** _pendiente_

### 7. 🚧 Impresoras y lectores reales

**A quién:** administración del hospital.
**Qué conseguir:** modelos de impresoras térmicas de ticket, de **etiquetas de muestra (ZPL)** y de brazaletes; y de los lectores de código de barras.
**Por qué importa:** ticket, etiqueta de muestra, brazalete y etiqueta de medicamento **no se generan como PDF** — se imprimen directo con ESC/POS o ZPL (§13.4.5). A las 3 am el flujo de "descargar e imprimir" falla y alguien escribe la etiqueta a mano.
**Bloquea:** Admisión y Laboratorio.
**Respuesta:** _pendiente_

### 8. 🚧 Formato del reporte mensual de controlados a ARSA

**A quién:** ARSA.
**Qué conseguir:** estructura exacta del formulario o archivo de entradas, salidas y saldos finales.
**Por qué importa:** vence dentro de los **primeros 5 días** del mes siguiente. Sin el formato, el libro se construye y después hay que rehacer la extracción.
**Bloquea:** el reporte de Farmacia (bloque 6).
**Respuesta:** _pendiente_

### 9. 🚧 Tarifarios de las aseguradoras con convenio firmado

**A quién:** administración del hospital / cada aseguradora.
**Qué conseguir:** con cuáles hay convenio **firmado** hoy, y copia de sus tarifarios vigentes.
**Por qué importa:** es la carga inicial del tarifario (ADR-0003). Sin esto no se puede probar el motor de precios con datos reales, que es donde aparecen los casos raros.
**Bloquea:** la carga inicial de tarifarios (bloque 3).
**Respuesta:** _pendiente_

---

## Prioridad 3 — internas, se responden en reunión

### 10. Mecánica del IHSS por servicios subrogados

Documentación exigida, tarifario y proceso de reclamo. Tiene mecánica propia, distinta de una aseguradora privada.
**Respuesta:** _pendiente_

### 11. Dimensionamiento real

Cuántas camas, cuántos servicios/áreas, cuántos **puntos de emisión fiscal** y cuántas cajas hay hoy. Define correlativos, almacenes y el mapa de camas.
**Respuesta:** _pendiente_

### 12. Política de descuentos

**Quién autoriza y hasta cuánto, por rol.** Es configuración, no código (§1.1). El descuento libre en el mostrador es la fuga de caja número uno de todo hospital privado.
**Respuesta:** _pendiente_

### 13. Sistema actual y migración

Qué sistema usa hoy el hospital, qué volumen hay que migrar (pacientes, expedientes, saldos, inventario), calidad de esos datos y cuándo sería el corte.
**Respuesta:** _pendiente_

### 14. Habilitación vigente ante SESAL/ARSA

Estado de la habilitación del establecimiento y **qué reportes exige hoy en papel** — esos son los que el sistema tiene que poder generar desde el día 1 (Art. 160 del Código de Salud).
**Respuesta:** _pendiente_

### 15. Política de retención y almacenamiento

Expediente a 20 años, imágenes en el PACS, PDFs generados con expiración. Definir dónde vive cada cosa y por cuánto tiempo, y quién paga ese almacenamiento.
**Respuesta:** _pendiente_

### 16. 🚧 ¿El descuento legal de tercera edad aplica cuando paga un seguro?

**A quién:** abogado del hospital, o consulta escrita a la Dirección General de Protección al Consumidor.
**Qué preguntar exactamente:** cuando un paciente de 60+ es atendido bajo póliza o convenio, (a) ¿el prestador debe aplicar el descuento del Artículo 30?, y (b) ¿sobre el total de la cuenta o solo sobre la porción que paga el paciente (deducible, coaseguro y copago)?
**Por qué importa:** **cambia el orden de operaciones del motor de facturación completo.** Aplicarlo sobre el total cuando correspondía solo a la porción del paciente le regala dinero a la aseguradora; al revés, incumple una obligación legal sancionable.
**Lo que se verificó el 18-ago-2026:** el Artículo 30 del Decreto 199-2006 **no menciona seguros, aseguradoras ni pólizas**. La regla de "no acumulable con otras rebajas" que sí existe está en la Sección II (servicios básicos), que es otro artículo y otro decreto — extenderla a salud es interpretación, no lectura.
**Estado asumido hoy:** ninguno. El convenio lleva un campo explícito `base_del_descuento_legal` (total · porción del paciente · no aplica) y el cargo guarda cuál se usó, así que la respuesta se aplica sin migración.
**Bloquea:** el motor de cobertura de Facturación (bloque 7). **No bloquea la estructura del bloque 3.**
**Respuesta:** _pendiente_

### 17. ¿Se puede facturarle a un seguro sin póliza y sin autorización?

**A quién:** dirección del hospital y el contacto de cada aseguradora con convenio.
**Qué preguntar exactamente:** (a) ¿una factura a nombre de la aseguradora sin número de póliza se paga, o rebota?; (b) ¿qué pasa si sale sin número de autorización cuando el convenio la exige?; (c) ¿hay casos legítimos en que el hospital tiene que facturar sin uno de los dos —una emergencia de madrugada, un asegurado sin su carné— o siempre se consiguen antes?
**Por qué importa:** hoy **el sistema no exige ninguno de los dos**. La póliza se pide al abrir la cuenta y es opcional; el interruptor `requiere_autorizacion` del convenio se guarda y **nadie lo lee**. Una factura sale a nombre de PALIG sin póliza y sin autorización, y si la aseguradora la rechaza el hospital se entera semanas después, con la cuenta cerrada y el paciente en su casa.
**Lo que hay que decidir además:** si se exige, **¿bloquea la emisión o solo avisa?** Trabar la caja por un dato que a veces no está a mano puede costar más que la factura rebotada — eso lo sabe el hospital, no el código.
**Estado asumido hoy:** ninguno. Los dos campos existen y se guardan; lo único que falta es la regla que los exija, así que la respuesta se aplica sin migración.
**Bloquea:** nada. El hospital puede arrancar y facturar; esto es cuánto se protege contra el rechazo.
**Detectado:** 31-ago-2026, revisando el modal de emisión.
**Respuesta:** _pendiente_

---

## Respuestas ya conseguidas

### ✅ Porcentajes del descuento de tercera edad en salud — verificado 18-ago-2026

Los porcentajes que definen el precio de lista de todo el catálogo (§4.5 de `dominio-inventario-y-precios.md`) están verificados contra el **Artículo 30 del Decreto Legislativo 199-2006**: 25 % en hospitales y clínicas privadas, 25 % en medicamentos y material quirúrgico (con receta, Art. 34), 25 % en consulta general, 30 % en consulta especializada, 30 % en cirugía, odontología, optometría, oftalmología, radiología, laboratorio y medicina computarizada.

**Hallazgo que corrigió el diseño:** el Decreto 45-2025 (La Gaceta 37,047, 19-ene-2026) reforma el **Artículo 31 — Sección II, Descuento al Pago de Servicios**, no el Artículo 30. La "cuarta edad" con 35 %/40 % existe, pero **solo para energía, agua, telecomunicaciones, cable, bienes inmuebles y salida aeroportuaria**. En salud el único umbral es 60 años y el descuento máximo es 30 %.

Detalle, cifras y consecuencia sobre el precio de lista en `docs/dominio-inventario-y-precios.md` §4.4.

---

## Resumen de bloqueos por módulo

| Bloque | Módulo | Bloqueado por |
|---|---|---|
| 3 | Catálogos y convenios | #9 (solo la **carga inicial** de tarifarios; la estructura no está bloqueada) |
| 5 | Inventario y compras | #4 |
| 6 | Farmacia | #8 |
| 7 | Facturación y caja | #1, #2, **#16** |
| 8 | Laboratorio | #5, #7 |
| 9 | Imágenes | #6 |
| 10 | Expediente clínico | #3 |
| 11 | Hospitalización y quirófano | #3 |

**Los bloques 1 (Cimientos) y 2 (Identidad del paciente) no están bloqueados por ninguna pregunta abierta** — se pueden construir mientras llegan las respuestas.
