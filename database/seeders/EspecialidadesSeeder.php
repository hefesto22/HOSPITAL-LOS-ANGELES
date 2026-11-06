<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Especialidad;
use App\Support\NormalizadorDeTexto;
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
 * ⚠️ Idempotente por código Y por nombre: correr los seeders dos veces
 * no puede duplicar el catálogo ni pisar un nombre que el hospital haya
 * corregido a mano. Y si el hospital ya creó la especialidad desde el
 * «crear al vuelo» con OTRO código —«NEUROCIRUGÍA» con NEUROC—, no se
 * crea una segunda con el código de acá: dos «NEUROCIRUGIA» en el
 * selector reparten a los médicos entre las dos al azar.
 */
class EspecialidadesSeeder extends Seeder
{
    /**
     * Código => nombre.
     *
     * Pública porque `MedicosDelHospitalSeeder` busca por nombre la que
     * el hospital haya creado con otro código.
     *
     * @var array<string, string>
     */
    public const ESPECIALIDADES = [
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

        /*
         * Reumatología, nutrición y psicología pasan consulta externa en
         * el hospital —están en el tarifario que se le presentó al
         * Hospital Militar— y no estaban acá.
         *
         * ⚠️ Las dos últimas no son médicas: son licenciadas. La ficha
         * de `medicos` igual las contempla, porque lo que la tabla
         * modela es «quién cobra honorario», no «quién tiene título de
         * médico».
         */
        'REUMAT' => 'REUMATOLOGIA',
        'NUTRIC' => 'NUTRICION',
        'PSICOL' => 'PSICOLOGIA',

        /*
         * Las que trae la lista «Servicios Médicos» del hospital
         * (6-nov-2026) y no estaban: cada hoja del libro es una
         * especialidad, y estas once tienen médicos que cobran honorario
         * hoy. Sin ellas, el neonatólogo queda como «PEDIATRIA» y el
         * reporte por especialidad miente.
         */
        'CIRPLA' => 'CIRUGIA PLASTICA',
        'MAXILO' => 'CIRUGIA MAXILOFACIAL',
        'NEUCIR' => 'NEUROCIRUGIA',
        'ENDOCR' => 'ENDOCRINOLOGIA',
        'INFECT' => 'INFECTOLOGIA',
        'INTENS' => 'MEDICINA INTENSIVA',
        'NEONAT' => 'NEONATOLOGIA',
        'CARPED' => 'CARDIOLOGIA PEDIATRICA',
        'GASPED' => 'GASTROENTEROLOGIA PEDIATRICA',
        'NEUPED' => 'NEUMOLOGIA PEDIATRICA',
        'INTPED' => 'MEDICINA INTENSIVA PEDIATRICA',
    ];

    public function run(): void
    {
        $codigos = [];
        $nombres = [];

        foreach (Especialidad::query()->get(['id', 'codigo', 'nombre']) as $especialidad) {
            $codigos[$especialidad->codigo] = true;
            $nombres[NormalizadorDeTexto::clave($especialidad->nombre)] = true;
        }

        foreach (self::ESPECIALIDADES as $codigo => $nombre) {
            $clave = NormalizadorDeTexto::clave($nombre);

            if (isset($codigos[$codigo]) || isset($nombres[$clave])) {
                continue;
            }

            Especialidad::query()->create([
                'codigo'         => $codigo,
                'nombre'         => $nombre,
                'vigencia_desde' => now()->toDateString(),
            ]);

            $codigos[$codigo] = true;
            $nombres[$clave] = true;
        }
    }
}
