# Preguntas abiertas de dominio — SIHLA

**Última actualización:** 17 de agosto de 2026
**Responsable de conseguir las respuestas:** Mauricio Cruz

Este documento existe porque el `CLAUDE.md` §8.11 exige responder por escrito, **antes de codificar el módulo correspondiente**, quince preguntas que hoy no tienen respuesta verificada.

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

---

## Resumen de bloqueos por módulo

| Bloque | Módulo | Bloqueado por |
|---|---|---|
| 3 | Catálogos y convenios | #9 |
| 5 | Inventario y compras | #4 |
| 6 | Farmacia | #8 |
| 7 | Facturación y caja | #1, #2 |
| 8 | Laboratorio | #5, #7 |
| 9 | Imágenes | #6 |
| 10 | Expediente clínico | #3 |
| 11 | Hospitalización y quirófano | #3 |

**Los bloques 1 (Cimientos) y 2 (Identidad del paciente) no están bloqueados por ninguna pregunta abierta** — se pueden construir mientras llegan las respuestas.
