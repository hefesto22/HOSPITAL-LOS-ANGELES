<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Excepción raíz del dominio de SIHLA (§11).
 *
 * Toda excepción de negocio hereda de acá, y cada módulo define las suyas:
 * `StockInsuficienteException`, `CorrelativoAgotadoException`,
 * `ExpedienteBloqueadoException`.
 *
 * Por qué tipadas y no `RuntimeException` a secas: en un `catch`,
 * `StockInsuficienteException` dice qué pasó y permite responder distinto.
 * Un `RuntimeException` obliga a leer el mensaje, y los mensajes cambian.
 *
 * Reemplaza a `GrupoOlympoException`, que venía de la plantilla y quedó
 * con el nombre del grupo en vez del sistema.
 */
class SihlaException extends RuntimeException {}
