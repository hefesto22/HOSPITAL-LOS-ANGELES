<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Models\PresupuestoLinea;

/**
 * Toda categoría legal cabe en el renglón del presupuesto.
 *
 * 12-oct-2026: `medicamento_material_quirurgico` mide 31 caracteres y la
 * columna medía 30 — no se podía agregar ningún medicamento al
 * presupuesto. Si mañana se agrega una categoría más larga, este test
 * lo dice antes que la pantalla.
 */
it('cada categoría legal del enum cabe en el renglón del presupuesto', function (CategoriaLegalDeDescuento $categoria): void {
    $linea = PresupuestoLinea::factory()->create(['categoria_legal' => $categoria]);

    expect($linea->refresh()->categoria_legal)->toBe($categoria);
})->with(CategoriaLegalDeDescuento::cases());
