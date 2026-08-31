<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Especialidad;
use Illuminate\Database\Seeder;

/**
 * Las especialidades con las que arranca el hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ SE SIEMBRAN Y NO SE DEJAN VACÍAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El primer médico se registra el día uno, con el paciente esperando. Si
 * la lista de especialidades está vacía, quien lo registra escribe la
 * que se le ocurre desde el «crear al vuelo» — y a la semana hay
 * «CIRUGIA», «CIRUGÍA GENERAL» y «CIRUJANO» conviviendo.
 *
 * Esta lista es un piso, no un límite: el hospital agrega las que le
 * falten desde la pantalla.
 *
 * ⚠️ `firstOrCreate` por código: correr los seeders dos veces no puede
 * duplicar el catálogo ni pisar un nombre que el hospital haya
 * corregido a mano.
 */
class EspecialidadesSeeder extends Seeder
{
    /**
     * Código => nombre.
     *
     * @var array<string, string>
     */
    private const ESPECIALIDADES = [
        'MEDGEN' => 'MEDICINA GENERAL',
        'MEDINT' => 'MEDICINA INTERNA',
        'CIRGEN' => 'CIRUGIA GENERAL',
        'GINECO' => 'GINECOLOGIA Y OBSTETRICIA',
        'PEDIAT' => 'PEDIATRIA',
        'ANEST'  => 'ANESTESIOLOGIA',
        'ORTOPE' => 'ORTOPEDIA Y TRAUMATOLOGIA',
        'UROLOG' => 'UROLOGIA',
        'CARDIO' => 'CARDIOLOGIA',
        'OFTALM' => 'OFTALMOLOGIA',
        'OTORRI' => 'OTORRINOLARINGOLOGIA',
        'DERMAT' => 'DERMATOLOGIA',
        'PSIQUI' => 'PSIQUIATRIA',
        'NEUROL' => 'NEUROLOGIA',
        'RADIOL' => 'RADIOLOGIA',
        'PATOL'  => 'PATOLOGIA',
        'NEFROL' => 'NEFROLOGIA',
        'GASTRO' => 'GASTROENTEROLOGIA',
        'NEUMOL' => 'NEUMOLOGIA',
        'ODONTO' => 'ODONTOLOGIA',
    ];

    public function run(): void
    {
        foreach (self::ESPECIALIDADES as $codigo => $nombre) {
            Especialidad::query()->firstOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre'         => $nombre,
                    'vigencia_desde' => now()->toDateString(),
                ],
            );
        }
    }
}
