<?php

declare(strict_types=1);

use App\Models\PrincipioActivo;

/**
 * EL CATÁLOGO DE LO QUE DE VERDAD CURA.
 *
 * Se da de alta por dos puertas —la pantalla de Principios activos y el
 * «+» del selector en la ficha del producto— y las dos tienen que
 * proponer el mismo correlativo. Ese código va impreso en la etiqueta de
 * la gaveta: dos principios con el mismo `PA-####` harían que escanear
 * la gaveta del acetaminofén liste ibuprofeno.
 */
it('propone el primer PA libre', function (): void {
    expect(PrincipioActivo::siguienteCodigo())->toBe('PA-0001');
})->note('Con el catálogo vacío arranca en uno. Nadie debería tener que inventar un PA-0007 a mano.');

it('salta el codigo que ya esta tomado', function (): void {
    PrincipioActivo::create([
        'codigo'         => 'PA-0001',
        'nombre'         => 'ACETAMINOFÉN',
        'vigencia_desde' => now(),
    ]);

    expect(PrincipioActivo::siguienteCodigo())->toBe('PA-0002');
})->note('Se busca el primero LIBRE y no «cuántos hay + 1»: con códigos puestos a mano, contar filas choca contra el índice único.');

it('🔴 no recicla el codigo de un principio retirado', function (): void {
    $retirado = PrincipioActivo::create([
        'codigo'         => 'PA-0001',
        'nombre'         => 'DIPIRONA',
        'vigencia_desde' => now(),
    ]);

    $retirado->delete();

    expect(PrincipioActivo::siguienteCodigo())->toBe('PA-0002');
})->note('🔴 El código va impreso en la etiqueta de la gaveta. Reasignarlo haría que esa etiqueta —que sigue pegada— señale otra molécula.');

it('lo encuentra por el nombre con el que lo prescribieron', function (): void {
    $acetaminofen = PrincipioActivo::create([
        'codigo'          => 'PA-0001',
        'nombre'          => 'ACETAMINOFÉN',
        'tambien_llamado' => 'PARACETAMOL',
        'vigencia_desde'  => now(),
    ]);

    expect(PrincipioActivo::buscar('paracetamol')->pluck('id'))
        ->toContain($acetaminofen->getKey());
})->note('El médico prescribe en el nombre que aprendió, y en Honduras conviven los dos. Sin los sinónimos, media plantilla busca algo que el catálogo sí tiene.');

it('distingue su codigo del de un producto al escanear', function (): void {
    expect(PrincipioActivo::pareceUnCodigoSuyo('PA-0001'))->toBeTrue()
        ->and(PrincipioActivo::pareceUnCodigoSuyo('pa-0001'))->toBeTrue()
        ->and(PrincipioActivo::pareceUnCodigoSuyo('MED-0708'))->toBeFalse()
        ->and(PrincipioActivo::pareceUnCodigoSuyo('MED-0708-01'))->toBeFalse();
})->note('Es lo que hace que el mismo campo de escaneo sirva para las dos cosas sin preguntarle nada a quien escanea.');
