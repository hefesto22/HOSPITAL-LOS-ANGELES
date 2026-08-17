# Registro de decisiones de arquitectura (ADR)

Este directorio es la **fuente de verdad #3** del proyecto, después del `CLAUDE.md` y de la memoria (§0 del `CLAUDE.md`).

> **Una decisión registrada acá NO se re-discute sin una razón técnica nueva.**
> Si aparece esa razón, no se edita el ADR viejo: se escribe uno nuevo que lo supersede y se marca el anterior como *Superseded by ADR-XXXX*.

## Índice

| ADR | Decisión | Estado |
|---|---|---|
| [0001](0001-arquitectura.md) | Laravel tradicional (Services + Models + Filament), no Clean Architecture | **Aceptado** — heredado de la plantilla Grupo Olympo y adoptado sin cambios para SIHLA |
| [0002](0002-multi-sede-single-tenant.md) | Multi-sede single-tenant; `sede_id` desde la primera migración | **Aceptado** |
| [0003](0003-catalogo-unico-y-tarifario-con-vigencia.md) | Catálogo único de ítems + tarifario por convenio con vigencia | **Aceptado** |
| [0004](0004-append-only-expediente-y-kardex.md) | Expediente y kardex append-only, con bitácora de lectura y break-the-glass | **Aceptado** |
| [0005](0005-infraestructura-de-produccion.md) | Infraestructura de producción sobre el VPS compartido | **🚧 ABIERTO** — se cierra al terminar la Etapa 1 |

## Por qué existen estos documentos

Las cuatro decisiones aceptadas comparten una propiedad: **revertirlas después de tener datos reales cuesta meses, no días.**

- Agregar `sede_id` a 200 tablas con expedientes, cargos e inventario histórico es un proyecto completo.
- Sacar la columna `precio` del catálogo cuando ya hay cuatro catálogos paralelos por aseguradora obliga a reconstruir el histórico de facturación.
- Convertir a append-only una tabla clínica que se editó durante dos años es imposible: la evidencia ya se perdió.

Por eso se escriben antes de que exista el código que las usa, y no después.

## Formato

Cada ADR lleva: **Contexto** (qué problema se estaba resolviendo) · **Decisión** (qué se resolvió, en una frase) · **Razones** (por qué, con el costo concreto de la alternativa) · **Consecuencias** (qué obliga a hacer y qué cierra) · **Cómo se verifica** (qué revisar en cada code review y qué test lo protege) · **Referencias**.

La sección **"Cómo se verifica"** es la que hace que el ADR sirva: sin ella, un ADR es una intención y no una regla.
