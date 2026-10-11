<?php

declare(strict_types=1);

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\TipoItem;

describe('TipoItem — lo que se ve en los botones del formulario', function (): void {
    test('todo tipo tiene nombre corto e ícono', function (TipoItem $tipo): void {
        expect($tipo->etiquetaCorta())->not->toBe('')
            ->and($tipo->icono())->toStartWith('heroicon-o-');
    })->with(TipoItem::cases());

    test('el nombre corto no lleva los ejemplos entre paréntesis', function (TipoItem $tipo): void {
        expect($tipo->etiquetaCorta())->not->toContain('(');
    })->with(TipoItem::cases());

    test('los botones de farmacia no repiten nombre', function (): void {
        $nombres = array_map(
            fn (TipoItem $tipo): string => $tipo->etiquetaCorta(),
            AmbitoCatalogo::Productos->tiposPermitidos(),
        );

        expect($nombres)->toBe(array_values(array_unique($nombres)));
    });
});
