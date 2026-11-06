<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\TipoItem;
use App\Models\Especialidad;
use App\Models\Item;
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

    /**
     * Código de la especialidad => el ítem de su honorario.
     *
     * Son las consultas externas que el hospital ya les cobra a los
     * seguros por especialidad —el Militar paga L 1,000 la de cirugía
     * general a cualquier cirujano— (Mauricio, 6-nov-2026). Solo se
     * enlaza la especialidad que todavía no tiene: lo que el hospital
     * eligió en la ficha no se pisa.
     *
     * @var array<string, string>
     */
    public const HONORARIO_POR_ESPECIALIDAD = [
        'MEDGEN' => 'CONSULTA EXTERNA MEDICINA GENERAL',
        'MEDINT' => 'CONSULTA EXTERNA MEDICINA INTERNA',
        'CIRGEN' => 'CONSULTA EXTERNA CIRUGIA GENERAL',
        'GINECO' => 'CONSULTA EXTERNA GINECOLOGIA',
        'PEDIAT' => 'CONSULTA EXTERNA PEDIATRIA',
        'ORTOPE' => 'CONSULTA EXTERNA ORTOPEDIA',
        'NEUMOL' => 'CONSULTA EXTERNA NEUMOLOGIA',
        'REUMAT' => 'CONSULTA EXTERNA REUMATOLOGIA',
        'NUTRIC' => 'CONSULTA EXTERNA NUTRICION',
        'ODONTO' => 'CONSULTA EXTERNA ODONTOLOGIA',
        'PSICOL' => 'CONSULTA EXTERNA PSICOLOGIA',
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

        $this->enlazarLosHonorarios();
    }

    private function enlazarLosHonorarios(): void
    {
        foreach (self::HONORARIO_POR_ESPECIALIDAD as $codigo => $nombreDelItem) {
            $especialidad = Especialidad::query()
                ->where('codigo', $codigo)
                ->whereNull('item_id')
                ->first();

            if (! $especialidad instanceof Especialidad) {
                continue;
            }

            $item = Item::query()
                ->where('nombre', $nombreDelItem)
                ->where('tipo', TipoItem::Honorario->value)
                ->vigentesEn(now())
                ->orderBy('id')
                ->first();

            if ($item instanceof Item) {
                $especialidad->update(['item_id' => $item->id]);
            }
        }
    }
}
