<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Especialidad;
use App\Models\Medico;
use App\Support\NormalizadorDeTexto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Los médicos que cobran honorario en el hospital — la lista «Servicios
 * Médicos» que entregó el hospital el 6-nov-2026: 90 médicos en 21
 * especialidades.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTE SEEDER NO VA EN `DatabaseSeeder`
 * ─────────────────────────────────────────────────────────────────────
 *
 *     php artisan db:seed --class=MedicosDelHospitalSeeder
 *
 * Igual que los catálogos del hospital: son los doctores de ESTE
 * hospital, y la clínica siguiente arranca sin ellos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SE TOMÓ DEL PAPEL Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El papel viene del sistema anterior, donde cada médico era un ítem
 * «HONORARIOS MEDICOS DRA. FULANA PEDIATRA» con código MEPA-001 y
 * precio 0. En SIHLA el médico es una ficha y el honorario es un ítem
 * aparte, así que:
 *
 * - El nombre va sin «HONORARIOS MEDICOS», sin «DR./DRA.» y sin la
 *   especialidad pegada al final: `Medico::etiqueta()` ya la agrega, y
 *   con ella adentro saldría dos veces en la cuenta.
 * - La especialidad es la de la HOJA del libro, no la palabra del final
 *   («UROLOGO CIRUJANO» es urología).
 * - El código MEPA-001 no se guarda: la ficha no tiene código.
 * - El precio es 0 en todos: no hay lista propia que cargar. Cobran por
 *   tarifario hasta que alguien les ponga la suya en «Lo que cobra».
 * - Los nombres van COMO VIENEN. Si alguno trae un error de dedo se
 *   corrige en el panel; acá no se adivina el apellido de nadie.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 NO DUPLICA AL QUE YA ESTÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Parte de la lista ya se registró a mano desde la cuenta. Dos fichas
 * del mismo doctor parten en dos la liquidación de sus honorarios, y el
 * reporte por médico le muestra a cada mitad un total que no es el
 * suyo. Por eso, contra lo que YA estaba en la base:
 *
 * - Mismo nombre —sin contar tildes, mayúsculas, «DR.» ni «DE/DEL»—:
 *   no se toca. Ni la especialidad: si alguien la corrigió, la suya
 *   manda.
 * - Dos o más palabras del nombre en común («JUAN PARODI» y «JUAN
 *   PARODI AMAYA»): NO se crea, se lista al final. Si es el mismo doctor
 *   o un pariente lo decide alguien que conoce a los médicos, no un
 *   seeder.
 * - Lo demás se crea.
 *
 * Se compara también contra los BORRADOS: si alguien dio de baja a un
 * médico, correr el seeder no lo resucita.
 *
 * La comparación es SOLO contra lo que había antes de correr: la lista
 * no se compara consigo misma, porque trae hermanas con los dos
 * apellidos iguales (ENAMORADO CRUZ, SANDOVAL FERRUFINO, TABORA CARCAMO)
 * que son personas distintas.
 *
 * Todo en una transacción: si algo revienta a la mitad no quedan 40
 * médicos cargados y 50 faltando.
 */
class MedicosDelHospitalSeeder extends Seeder
{
    /**
     * Código de la especialidad (de `EspecialidadesSeeder`) => médicos.
     *
     * @var array<string, list<string>>
     */
    private const MEDICOS = [
        'ANEST' => [
            'JUAN PARODI AMAYA',
            'RONNY CABRERA',
            'SANDRA MELANY OTERO',
            'ILTSA MEDINA SANABRIA',
            'MYNDA NATHALIE MUNGUIA',
            'JEIMY CAROLINA MUNGUIA',
            'RAFAEL ANGEL GARCIA',
            'CAROLINA YAQUELIN VASQUEZ',
        ],
        'CARDIO' => [
            'DAVID EDUARDO DUBON',
        ],
        'UROLOG' => [
            'JUAN CARLOS CARDONA CONTRERAS',
        ],
        'CIRGEN' => [
            'JUAN CARLOS CARDONA MEDINA',
            'CARLOS BLADIMIR IRIAS',
            'ROBERTO ABNER SANABRIA',
            'FLORENTINO MARTINEZ OCHOA',
            'GERSON TOBAR LEONOR',
            'CARLOS HUMBERTO ROMERO',
            'SAYDA MELISSA MEJIA SUAREZ',
            'ASHLY CAROLINA LOPEZ',
        ],
        'CIRPLA' => [
            'DANNY PATRYCK VASQUEZ',
            'JUAN OSORIO',
        ],
        'ENDOCR' => [
            'IRMA ISABEL DE VICENTE',
            'KAREN CARACAMO',
        ],
        'GASTRO' => [
            'CANDIDA MARIA LEIVA',
            'JOSE SANDOVAL BUESO',
        ],
        'GINECO' => [
            'NANCY LIZZETH RIVERA',
            'NORMA ARACELY AGUILAR',
            'CLAUDIA MONTOYA ALDANA',
            'ALEJANDRA BAQUEDANO FIGUEROA',
            'MELVIN RAFAEL ORTEGA HERNANDEZ',
            'REINA ADELA ENAMORADO CRUZ',
            'ALLISON JULISSA TABORA CARCAMO',
            'AURORA CAROLINA LOPEZ VALLADARES',
            'KAREN VANESA ESPINOZA LEON',
            'MARLON HERMERSON BAUTISTA QUINTERO',
            'MARIA DEL ROSARIO VALDIVIEZO ORTEGA',
            'MARCIA GABRIELA PINEDA PEREZ',
            'FREDDIE FERNANDO MIRANDA RAMIREZ',
            'MARCO ANTONIO URQUIA LAZO',
            'MARIBEL VEGA ZALDIVAR',
            'KENIA DEL CARMEN ORDOÑEZ',
            'ROSA BERENICE DELCID SANABRIA',
            'MARIO ALEJANDRO CERRATI VARELA',
            'CARLOS MANUEL PADILLA LOPEZ',
            'MARIA DIONELSY ENAMORADO CRUZ',
            'CARLOS EDGARDO CLAUDINO FAJARDO',
            'MARIA AMANDA LOPEZ ROMERO',
        ],
        'MAXILO' => [
            'LUIS FELIPE REYES',
        ],
        'MEDINT' => [
            'BELKYS ORELLANA PEREIRA',
            'OSCAR ORLANDO DURON RIVAS',
            'RAUL ALBERTO MADRID MEJIA',
            'WENDY ROSALINA ARIAS AGUILERA',
            'SHARON HEFZIBA PINEDA GUVARA',
            'MONICA SANDOVAL FERRUFINO',
            'JORGE LEONARDO VALLE SANCHEZ',
            'LEONEL STEVEN ORELLANA MENDOZA',
            'MOISES HENRIQUEZ',
        ],
        'INFECT' => [
            'OSCAR ENRIQUE ALVARENGA MALDONADO',
        ],
        'INTENS' => [
            'GABRIELA MARIA PINEDA TABORA',
        ],
        'NEUCIR' => [
            'ROBERTO ALVAREZ RODRIGUEZ',
            'JENNIFER GISSELA TABORA ARITA',
        ],
        'NEUROL' => [
            'LESTER JOSE COELLO LOPEZ',
        ],
        'ORTOPE' => [
            'GUSTAVO ADOLFO MONGE TRIGUEROS',
            'LENNIN REYNALDO PAZ SUAZO',
            'ALEXIS OSWALDO FRANCO MEJIA',
            'DANERY ALEJANDRO LOPEZ ALVARENGA',
            'OSCAR MAURICIO ARGUETA PEREZ',
            'MARCO TULIO RODRIGUEZ GAVARRETE',
            'ARNULFO MADRID BANEGAS',
            'ELIX OMAR CASTILLO TABORA',
        ],
        'PEDIAT' => [
            'FABIOLA CAROLINA RAMOS BENITEZ',
            'JESUS ANTONIO GOMEZ BONILLA',
            'LITZA MARIELA SAUCEDA AYESTAS',
            'CARMEN SANDOVAL FERRUFINO',
            'NELSON PENMAN PINTO',
            'MARY GABRIELA RAMIREZ PORTILLO',
            'CLAUDIA LORENA FUENTES UMAÑA',
            'JOHANA MICHELLE TABORA CARCAMO',
            'KARINA RODRIGUEZ ROMERO',
            'LILIAM ISMENIA ROMERO LANZA',
            'BELKIS EUNICE MATINEZ LOPEZ',
            'JORGE ALBERTO MALDONADO ESPINOZA',
            'JUAN RAMON VALENZUELA BOQUIN',
            'KARLA PATRICIA ESCOLAN RODEZNO',
            'LINDA JULISSA LOPEZ FLORES',
        ],
        'NEUPED' => [
            'ANA POLETTE VALERIANO',
        ],
        'INTPED' => [
            'EDWIN EDGARDO BAUTISTA',
        ],
        'GASPED' => [
            'KARLA GABRIELA DUARTE',
        ],
        'NEONAT' => [
            'YASMIN CAROLINA CHAVEZ CONTRERAS',
            'MOISES EDGARDO MELGAR GONZALES',
        ],
        'CARPED' => [
            'DILCIA PAOLA PAGOAGA VASQUEZ',
        ],
    ];

    /**
     * Lo que no identifica a nadie: el título y las partículas. Sin
     * sacarlas, «DR. JUAN PARODI» y «JUAN PARODI» serían dos médicos.
     *
     * @var list<string>
     */
    private const PALABRAS_QUE_NO_CUENTAN = ['dr', 'dra', 'doctor', 'doctora', 'de', 'del', 'la', 'las', 'los', 'y'];

    private const PALABRAS_EN_COMUN_PARA_SOSPECHAR = 2;

    private int $creados = 0;

    private int $yaEstaban = 0;

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $sospechosos = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(EspecialidadesSeeder::class);
            $this->sembrar();
        });

        $this->informar();
    }

    private function sembrar(): void
    {
        $existentes = $this->medicosQueYaEstaban();
        $hoy = now()->toDateString();

        foreach (self::MEDICOS as $codigo => $nombres) {
            $especialidad = $this->especialidad($codigo);

            foreach ($nombres as $nombre) {
                $palabras = $this->palabras($nombre);

                if ($this->buscarIgual($palabras, $existentes) !== null) {
                    $this->yaEstaban++;

                    continue;
                }

                $parecido = $this->buscarParecido($palabras, $existentes);

                if ($parecido !== null) {
                    $this->sospechosos[] = [$nombre, $especialidad->nombre, $parecido];

                    continue;
                }

                Medico::query()->create([
                    'nombre'          => $nombre,
                    'especialidad_id' => $especialidad->getKey(),
                    'vigencia_desde'  => $hoy,
                ]);

                $this->creados++;
            }
        }
    }

    /**
     * @return list<array{nombre: string, palabras: list<string>}>
     */
    private function medicosQueYaEstaban(): array
    {
        $existentes = [];

        foreach (Medico::query()->withTrashed()->get(['id', 'nombre', 'deleted_at']) as $medico) {
            $existentes[] = [
                'nombre'   => $medico->trashed() ? $medico->nombre.' (BORRADO)' : $medico->nombre,
                'palabras' => $this->palabras($medico->nombre),
            ];
        }

        return $existentes;
    }

    /**
     * Por código; si no, por nombre. `EspecialidadesSeeder` no crea una
     * especialidad cuyo nombre ya exista con OTRO código —la que el
     * hospital creó a mano desde «crear al vuelo»—, así que la búsqueda
     * por código sola no la encontraría y el médico quedaría sin
     * especialidad.
     */
    private function especialidad(string $codigo): Especialidad
    {
        $porCodigo = Especialidad::query()->where('codigo', $codigo)->first();

        if ($porCodigo instanceof Especialidad) {
            return $porCodigo;
        }

        $clave = NormalizadorDeTexto::clave($this->nombreDeLaEspecialidad($codigo));

        foreach (Especialidad::query()->get(['id', 'codigo', 'nombre']) as $especialidad) {
            if (NormalizadorDeTexto::clave($especialidad->nombre) === $clave) {
                return $especialidad;
            }
        }

        throw new RuntimeException("La especialidad {$codigo} no existe y EspecialidadesSeeder no la creó.");
    }

    private function nombreDeLaEspecialidad(string $codigo): string
    {
        foreach (EspecialidadesSeeder::ESPECIALIDADES as $suCodigo => $nombre) {
            if ($suCodigo === $codigo) {
                return $nombre;
            }
        }

        throw new RuntimeException("La especialidad {$codigo} no está en EspecialidadesSeeder.");
    }

    /**
     * «DRA. KENIA DEL CARMEN ORDÓÑEZ» → kenia, carmen, ordonez.
     *
     * @return list<string>
     */
    private function palabras(string $nombre): array
    {
        $soloLetras = preg_replace('/[^a-z0-9]+/', ' ', NormalizadorDeTexto::clave($nombre)) ?? '';

        $palabras = array_filter(
            explode(' ', trim($soloLetras)),
            static fn (string $palabra): bool => $palabra !== '' && ! in_array($palabra, self::PALABRAS_QUE_NO_CUENTAN, true),
        );

        return array_values(array_unique($palabras));
    }

    /**
     * @param list<string> $palabras
     * @param list<array{nombre: string, palabras: list<string>}> $existentes
     */
    private function buscarIgual(array $palabras, array $existentes): ?string
    {
        foreach ($existentes as $existente) {
            if ($existente['palabras'] === $palabras) {
                return $existente['nombre'];
            }
        }

        return null;
    }

    /**
     * @param list<string> $palabras
     * @param list<array{nombre: string, palabras: list<string>}> $existentes
     */
    private function buscarParecido(array $palabras, array $existentes): ?string
    {
        foreach ($existentes as $existente) {
            if (count(array_intersect($palabras, $existente['palabras'])) >= self::PALABRAS_EN_COMUN_PARA_SOSPECHAR) {
                return $existente['nombre'];
            }
        }

        return null;
    }

    private function informar(): void
    {
        $this->command?->info("✓ {$this->creados} médicos registrados.");

        if ($this->yaEstaban > 0) {
            $this->command?->comment("  {$this->yaEstaban} ya estaban con el mismo nombre: no se tocaron.");
        }

        if ($this->sospechosos === []) {
            return;
        }

        $cuantos = count($this->sospechosos);

        $this->command?->warn("  ⚠️ {$cuantos} NO se crearon porque se parecen a un médico que ya estaba. Revisalos a mano:");
        $this->command?->table(['De la lista', 'Especialidad', 'Ya registrado como'], $this->sospechosos);
    }
}
