<?php

declare(strict_types=1);

use App\Models\Especialidad;
use App\Models\Medico;
use Database\Seeders\MedicosDelHospitalSeeder;

/**
 * La lista «Servicios Médicos» del hospital (6-nov-2026).
 *
 * Lo que importa no es que cargue 90 nombres —eso lo hace cualquier
 * bucle— sino que corra sobre la base de PRODUCCIÓN, donde parte de la
 * lista ya se registró a mano, sin partir a ningún doctor en dos fichas.
 */
function medicoDeLaLista(string $nombre): Medico
{
    return Medico::query()->with('especialidad')->where('nombre', $nombre)->sole();
}

it('registra a los 90 medicos de la lista, cada uno con la especialidad de su hoja', function (): void {
    $this->seed(MedicosDelHospitalSeeder::class);

    expect(Medico::query()->count())->toBe(90)
        ->and(medicoDeLaLista('KENIA DEL CARMEN ORDOÑEZ')->especialidad->codigo)->toBe('GINECO')
        ->and(medicoDeLaLista('JUAN CARLOS CARDONA CONTRERAS')->especialidad->codigo)->toBe('UROLOG')
        ->and(medicoDeLaLista('JUAN CARLOS CARDONA MEDINA')->especialidad->codigo)->toBe('CIRGEN')
        ->and(medicoDeLaLista('ROBERTO ALVAREZ RODRIGUEZ')->especialidad->nombre)->toBe('NEUROCIRUGIA')
        ->and(medicoDeLaLista('DILCIA PAOLA PAGOAGA VASQUEZ')->especialidad->nombre)->toBe('CARDIOLOGIA PEDIATRICA');
});

it('no guarda el titulo ni la especialidad dentro del nombre', function (): void {
    $this->seed(MedicosDelHospitalSeeder::class);

    expect(Medico::query()->where('nombre', 'ilike', 'DR%')->exists())->toBeFalse()
        ->and(Medico::query()->where('nombre', 'ilike', '%HONORARIO%')->exists())->toBeFalse()
        ->and(Medico::query()->where('nombre', 'ilike', '%PEDIATRA')->exists())->toBeFalse();
})->note('Medico::etiqueta() ya agrega la especialidad: con ella adentro saldría dos veces en la cuenta.');

it('correrlo dos veces no duplica a nadie', function (): void {
    $this->seed(MedicosDelHospitalSeeder::class);
    $this->seed(MedicosDelHospitalSeeder::class);

    expect(Medico::query()->count())->toBe(90);
});

it('🔴 no crea al que ya estaba registrado a mano con un nombre parecido', function (): void {
    $yaEstaba = Medico::factory()->create(['nombre' => 'DR. JUAN PARODI']);

    $this->seed(MedicosDelHospitalSeeder::class);

    expect(Medico::query()->where('nombre', 'JUAN PARODI AMAYA')->exists())->toBeFalse()
        ->and(Medico::query()->where('nombre', 'ilike', '%PARODI%')->count())->toBe(1)
        ->and(Medico::query()->count())->toBe(90)
        ->and($yaEstaba->fresh()?->nombre)->toBe('DR. JUAN PARODI');
})->note('Dos fichas del mismo doctor parten en dos la liquidación de sus honorarios.');

it('no le cambia la especialidad al que ya estaba con el mismo nombre', function (): void {
    $suya = Especialidad::factory()->create(['codigo' => 'PROPIA', 'nombre' => 'LA QUE PUSO EL HOSPITAL']);
    $medico = Medico::factory()->create(['nombre' => 'Dra. Kenia del Carmen Ordóñez', 'especialidad_id' => $suya->id]);

    $this->seed(MedicosDelHospitalSeeder::class);

    expect($medico->fresh()?->especialidad_id)->toBe($suya->id)
        ->and(Medico::query()->where('nombre', 'ilike', '%KENIA%')->count())->toBe(1);
});

it('no resucita al medico que el hospital dio de baja', function (): void {
    Medico::factory()->create(['nombre' => 'RONNY CABRERA'])->delete();

    $this->seed(MedicosDelHospitalSeeder::class);

    expect(Medico::query()->where('nombre', 'RONNY CABRERA')->exists())->toBeFalse()
        ->and(Medico::query()->count())->toBe(89);
});

it('usa la especialidad que el hospital creo a mano con otro codigo en vez de duplicarla', function (): void {
    $aMano = Especialidad::factory()->create(['codigo' => 'NEUROC', 'nombre' => 'NEUROCIRUGÍA']);

    $this->seed(MedicosDelHospitalSeeder::class);

    expect(Especialidad::query()->where('codigo', 'NEUCIR')->exists())->toBeFalse()
        ->and(medicoDeLaLista('ROBERTO ALVAREZ RODRIGUEZ')->especialidad_id)->toBe($aMano->id)
        ->and(medicoDeLaLista('JENNIFER GISSELA TABORA ARITA')->especialidad_id)->toBe($aMano->id);
})->note('Dos «NEUROCIRUGIA» en el selector reparten a los médicos entre las dos.');
