<?php

declare(strict_types=1);

use App\Models\Sede;
use Illuminate\Database\QueryException;

it('resuelve la vigencia contra la fecha que se le pasa, no contra hoy', function (): void {
    $cerrada = Sede::factory()->cerrada()->create();

    expect($cerrada->estaVigenteEn(now()))->toBeFalse()
        ->and($cerrada->estaVigenteEn(now()->subYears(2)))->toBeTrue();
})->note('Una sede que cerró debe seguir explicando una factura de hace dos años (ADR-0003).');

it('excluye del selector de hoy a las sedes cerradas', function (): void {
    Sede::factory()->create(['codigo' => 'VIG']);
    Sede::factory()->cerrada()->create(['codigo' => 'OLD']);

    $vigentes = Sede::query()->vigentesEn(now())->pluck('codigo')->all();

    expect($vigentes)->toContain('VIG')
        ->and($vigentes)->not->toContain('OLD');
});

it('no permite dos sedes con el mismo codigo de establecimiento del SAR', function (): void {
    Sede::factory()->create(['codigo_establecimiento' => '001']);

    Sede::factory()->create(['codigo_establecimiento' => '001']);
})->throws(QueryException::class)
    ->note('El correlativo fiscal se arma con este código; duplicarlo produce dos facturas con el mismo número.');

it('permite varias sedes sin codigo de establecimiento', function (): void {
    Sede::factory()->create(['codigo_establecimiento' => null]);
    Sede::factory()->create(['codigo_establecimiento' => null]);

    expect(Sede::query()->whereNull('codigo_establecimiento')->count())->toBe(2);
})->note('El índice único es parcial: NULL no colisiona, porque una sede sin trámite del SAR es un estado válido.');
