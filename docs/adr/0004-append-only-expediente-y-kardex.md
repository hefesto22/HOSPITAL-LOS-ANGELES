# ADR-0004 — Expediente y kardex append-only, con bitácora de lectura y break-the-glass

**Estado:** Aceptado
**Fecha:** 2026-08-17
**Decididores:** Mauricio Cruz (Inversiones Olympo)

## Contexto

El sistema custodia tres cosas que no son datos comunes: **historia clínica de personas**, **inventario ajeno** (incluidos estupefacientes y psicotrópicos) y **documentos fiscales**. Las tres tienen en común que, cuando algo sale mal, lo que se pide no es el estado actual sino **el estado que tenían en un momento del pasado y quién lo tocó**.

Un CRUD normal —con `UPDATE`, `DELETE` y `SoftDeletes`— no puede responder eso. Y en un hospital las preguntas llegan en forma de demanda, de auditoría de ARSA, de reclamo de privacidad o de inspección del SAR.

## Decisión

**Prohibido `UPDATE` y `DELETE` sobre nota clínica firmada, resultado de laboratorio validado, cargo facturado, factura emitida y movimiento de kardex.** Se corrige con una fila nueva que referencia a la anterior.

**Corregir es enmendar, no editar.** La versión original permanece legible. Ciclo de vida de la nota: `borrador → firmada → adenda/enmendada → retractada`. **Nunca `eliminada`.**

**Se registra la LECTURA, no solo la escritura.** Cada visualización de expediente, resultado, imagen o cuenta deja usuario, paciente, recurso, momento, IP, terminal y motivo si aplica.

**Break-the-glass: nunca se bloquea la atención por permisos.** Se permite el acceso siempre, con motivo tipificado + texto obligatorio, banner rojo permanente, **ventana de vigencia limitada atada al episodio** y **revisión del oficial de privacidad en menos de 72 horas**. El acceso de emergencia **expira**; no otorga permiso permanente.

## Razones

**Por qué append-only:**

1. **El día que un abogado pida "el expediente como estaba el 12 de marzo", una tabla mutable convierte al hospital en indefendible.** No es que la respuesta sea difícil: es que no existe.
2. **Alterar el texto original de una nota es alteración de evidencia**, no una corrección de dato.
3. **Un `UPDATE productos SET existencia = 12` borra para siempre la evidencia del faltante.** En un almacén de controlados, esa evidencia es lo que separa un error de conteo de la pérdida de la licencia — el ajuste libre de un estupefaciente es el mecanismo exacto por el cual desaparece el fentanilo.
4. **Un correlativo fiscal borrado o reutilizado es multa y cierre.** Las correcciones son nota de crédito, que consume su propio CAI.
5. **Un resultado de laboratorio sobrescrito es un riesgo clínico directo.** Cambiar un potasio de 7.1 a 4.1 sin dejar rastro ni avisar deja al médico tratando una hiperkalemia que ya no existe.

**Por qué la bitácora de LECTURA, que casi ningún sistema implementa:**

El caso real dominante no es el hacker: **es el empleado que abre el expediente de su ex pareja, de su vecina o de un familiar.** Y cuando se filtra el expediente de una figura pública, sin log de lectura el hospital **no puede identificar al responsable y responde él**. Registrar solo escrituras deja ese riesgo completamente descubierto, porque leer no modifica nada.

**Por qué break-the-glass y no simplemente permisos estrictos:**

Son las 3 de la mañana y entra un politraumatizado. **Un sistema que niega el expediente por un permiso mata pacientes.** Pero uno que lo abre sin dejar rastro destruye la confianza y expone al hospital. La única salida correcta es permitir siempre y auditar siempre, con caducidad: el acceso de emergencia sirve para ese episodio, no para el resto del año.

**Base legal:** Código de Salud de Honduras (Decreto 65-91) Art. 160 (obligación de sistema de registro e información), Art. 180 (notificación epidemiológica) y Art. 181 (confidencialidad). Honduras **no tiene ley de protección de datos vigente**, pero el hábeas data del Art. 182-2 constitucional es accionable judicialmente de inmediato — por eso se construye con derechos ARCO desde ahora, para cumplir sin refactor el día que la ley entre.

## Consecuencias

**Obliga a:**

- **`softDeletes()` solo en catálogos y personas.** Nunca en expediente, cargos, facturas ni kardex. Bulk actions destructivas desactivadas en esas tablas (§9.A17).
- **Dos tiempos en todo evento clínico:** `ocurrido_en` (cuándo pasó) y `registrado_en` (cuándo se digitó). La nota capturada a las 06:00 sobre un evento de las 23:40 destruye la cronología si solo se guarda uno — y la cronología es lo primero que peritan.
- **Al firmar se congela contenido + hash + snapshot renderizado.** Si dos años después se re-renderiza con el catálogo actual, no se puede probar qué decía.
- **El saldo es derivado; si se materializa por rendimiento, se actualiza en la MISMA transacción** que el movimiento, y existe un test que lo recalcula desde cero y compara exacto.
- **Particionar por rango de fecha desde el diseño** (`cargos`, `resultados`, `movimientos_inventario`, `bitacora`, `accesos_expediente`, `signos_vitales`, `asignaciones_cama`). A los dos años son decenas de millones de filas y **en un hospital no hay ventana de mantenimiento a la que apelar**.
- **Archivar moviendo a particiones frías, nunca borrando.** Retención de diseño: 20 años.
- **La bitácora es append-only, no editable ni por el DBA**, idealmente replicada a un destino de solo escritura. Una bitácora que el administrador puede borrar no es evidencia.
- **Etiquetas de sensibilidad en el dato, no en el módulo** (VIH, salud mental, violencia sexual, adicciones): la restricción por pantalla se evade por el reporte, el export o la búsqueda global.

**Costo aceptado:**

- Las tablas crecen y nunca se depuran. Se asume: sobredimensionar retención es barato; perder expedientes no lo es.
- Corregir cuesta más clics que editar. Es deliberado.

## Cómo se verifica

- **Tests obligatorios (§16):** el `UPDATE` sobre nota firmada, resultado validado, cargo facturado y kardex **falla**. Abrir un expediente **deja registro de lectura**. Break-the-glass **exige motivo y expira**.
- **En cada Resource nuevo:** Policy creada, `SoftDeletes` desactivado si la tabla es clínica o financiera, bulk delete deshabilitado.
- **En cada revisión:** ningún Service hace `update()` ni `delete()` sobre las entidades protegidas; las correcciones crean filas.
- **CHECK constraints como defensa profunda** (§12): otra aplicación, un import o un script escribirán en esa base algún día.

## Referencias

- `CLAUDE.md` §8.7 (inventario y kardex), §8.8 (expediente clínico), §9.0.3, §9.E (errores clásicos de expediente), §9.G1–2 (kardex), §9.I6 (corrección de resultados), §9.L6–9 (bitácora de lectura y accesos anómalos), §12 (particionado), §16 (tests exigidos)
- Código de Salud de Honduras, Decreto 65-91, Arts. 160, 180 y 181
- Constitución de Honduras, Art. 182-2 (hábeas data)
- [ADR-0002](0002-multi-sede-single-tenant.md) — el aislamiento por sede se suma a la relación de atención
