<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoItem;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use App\Models\Unidad;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * El catálogo de servicios del Hospital Los Ángeles, con sus dos precios.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTE SEEDER NO VA EN `DatabaseSeeder`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Son los datos de UN hospital y de UNA aseguradora. `DatabaseSeeder`
 * siembra lo que toda instalación necesita —roles, unidades, descuentos
 * de ley, el convenio CONTADO—; esto es carga inicial de un cliente
 * concreto. La clínica 2 escribe el suyo y no borra nada de este.
 *
 * Se corre a mano:
 *
 *     php artisan db:seed --class=CatalogoPaligSeeder
 *
 * Es idempotente: se puede volver a correr sin duplicar nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS DOS PRECIOS, Y DE DÓNDE SALE CADA UNO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La fuente es el tarifario firmado con PALIG. De ahí salen las DOS
 * filas de cada ítem:
 *
 *   · **Precio de PALIG** — el número del papel, tal cual. Fila de
 *     `tarifarios` con `convenio_id` = PALIG.
 *   · **Precio de lista del hospital** — ese número **+ 20 %**. Fila con
 *     `convenio_id` NULL, que es la que responde para el paciente que
 *     paga de su bolsillo y para cualquier pagador sin tarifario propio.
 *
 * Los 20 % están acá y no en una columna del ítem a propósito: es la
 * decisión que se tomó UNA vez para armar la lista inicial, no una regla
 * viva. El día que el hospital suba un precio suelto, lo sube en el
 * tarifario y este seeder no tiene nada que decir al respecto.
 *
 * ⚠️ El precio se guarda SIN ISV. Estos servicios son exentos por el
 * Art. 15 inciso d de la Ley del ISV, así que hoy da igual — pero el día
 * que entre un ítem gravado, el impuesto lo pone la factura y no el
 * tarifario (§8.6.1).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE HAY QUE VERIFICAR CONTRA EL PAPEL ANTES DE COBRAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Todo lo marcado `⚠️ VERIFICAR` salió de una foto y tiene una
 * ambigüedad real en el documento fuente. Están cargados con el valor
 * más probable y el más conservador; hay que confirmarlos con el
 * tarifario en la mano antes de facturarle a nadie.
 */
class CatalogoPaligSeeder extends Seeder
{
    /**
     * Cuánto más caro es el precio de lista respecto del de PALIG.
     *
     * Texto y no float: entra directo a bcmath (§8.6.2-1).
     */
    private const FACTOR_LISTA = '1.20';

    /**
     * Desde cuándo rigen estos precios.
     *
     * Fija y declarada, no `now()`: así el seeder es reproducible y
     * volver a correrlo actualiza la MISMA fila en vez de abrir una
     * vigencia nueva que choque con el EXCLUDE de traslape.
     */
    private const VIGENCIA_DESDE = '2026-08-01';

    /**
     * Cada línea: código, nombre, tipo de ítem, categoría del Art. 30,
     * unidad en la que se cobra, y el precio del tarifario PALIG.
     *
     * @var list<array{0: string, 1: string, 2: TipoItem, 3: CategoriaLegalDeDescuento, 4: string, 5: numeric-string}>
     */
    private const SERVICIOS = [
        // ── Área de hospitalización ───────────────────────────────────
        ['HOS-001', 'ALIMENTACION POR DIA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '300.00'],
        ['HOS-002', 'GASTOS ADMINISTRATIVOS', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '450.00'],
        ['HOS-003', 'HABITACION HOSPITALIZACION POR DIA', TipoItem::Estancia, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '1000.00'],
        ['HOS-004', 'HABITACION CON CAMA ESPECIAL PARA ORTOPEDIA POR DIA', TipoItem::Estancia, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '1400.00'],
        ['HOS-005', 'SALA CUNA / RECIEN NACIDOS', TipoItem::Estancia, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '1350.00'],
        ['HOS-006', 'SALA DE EMERGENCIA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '350.00'],
        ['HOS-007', 'OBSERVACION POR HORA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'HORA', '175.00'],
        ['HOS-008', 'SALA DE RECUPERACION', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '780.00'],
        ['HOS-009', 'SALA DE PROCEDIMIENTOS', TipoItem::Procedimiento, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '350.00'],
        ['HOS-010', 'USO SALA DE OPERACIONES BASICO 2H', TipoItem::Procedimiento, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '3200.00'],
        ['HOS-011', 'USO SALA DE OPERACIONES PAQUETE CESAREA 2H', TipoItem::Paquete, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '3500.00'],
        ['HOS-012', 'SALA DE LABOR Y PARTO USO 2H', TipoItem::Procedimiento, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '2500.00'],

        /*
         * ⚠️ VERIFICAR — «RECARGO HORA EXTRA SALA DE OPERACIONES» aparece
         * DOS veces en el tarifario, con L 1,300.00 y con L 1,500.00, sin
         * nada que las distinga en el papel. Se cargaron como dos ítems
         * separados asumiendo que una acompaña al paquete de cesárea y la
         * otra al uso básico, que es el orden en que están impresas.
         *
         * Si la distinción es otra —hora hábil e inhábil, por ejemplo—
         * hay que renombrarlas. Lo que NO se puede es dejar una sola: el
         * catálogo no puede tener dos precios para el mismo nombre.
         */
        ['HOS-013', 'RECARGO HORA EXTRA SALA DE OPERACIONES PAQUETE CESAREA', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'HORA', '1300.00'],
        ['HOS-014', 'RECARGO HORA EXTRA SALA DE OPERACIONES BASICO', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'HORA', '1500.00'],

        ['HOS-020', 'HONORARIOS MEDICO GENERAL', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral, 'UND', '800.00'],
        ['HOS-021', 'HONORARIOS ENFERMERIA TURNO A', TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '500.00'],
        ['HOS-022', 'HONORARIOS ENFERMERIA TURNO B', TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '500.00'],
        ['HOS-023', 'HONORARIOS ENFERMERIA TURNO C', TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '600.00'],
        ['HOS-024', 'HONORARIOS ENFERMERIA LABOR Y PARTO', TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '700.00'],
        ['HOS-025', 'HONORARIOS ENFERMERIA RECUPERACION', TipoItem::Honorario, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '700.00'],
        ['HOS-026', 'HONORARIOS CIRCULANTE 2 HORAS', TipoItem::Honorario, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '800.00'],
        ['HOS-027', 'HONORARIOS TECNICO INSTRUMENTISTA 2H', TipoItem::Honorario, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '1000.00'],

        // ── Equipo médico ─────────────────────────────────────────────
        ['EQP-001', 'BACINETE POR DIA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '100.00'],
        ['EQP-002', 'BOMBAS DE INFUSION POR DIA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '150.00'],
        ['EQP-003', 'DESFIBRILADOR POR USO', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '800.00'],
        ['EQP-004', 'DOPPLER FETAL', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '100.00'],
        ['EQP-005', 'ELECTROCARDIOGRAMA POR USO', TipoItem::Procedimiento, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '500.00'],
        ['EQP-006', 'ELECTROCAUTERIO', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '300.00'],
        ['EQP-007', 'ELECTROCAUTERIO LIGASURE MAS INSTRUMENTAL', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '2500.00'],
        ['EQP-008', 'INSTRUMENTAL NEUROQUIRURGICO', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '2500.00'],
        ['EQP-009', 'GLUCOMETRIAS POR USO', TipoItem::EstudioLaboratorio, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '70.00'],
        ['EQP-010', 'INCUBADORA CERRADA POR DIA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '300.00'],
        ['EQP-011', 'MAQUINA DE ANESTESIA CON MONITOR', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '500.00'],
        ['EQP-012', 'MONITOR DE SIGNOS MAS O2 MAS EKG', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '700.00'],
        ['EQP-013', 'MONITOR FETAL POR USO', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'UND', '800.00'],
        ['EQP-014', 'NEBULIZADORES POR DIA', TipoItem::Servicio, CategoriaLegalDeDescuento::ServicioHospitalario, 'DIA', '100.00'],
        ['EQP-015', 'TORRE DE VIDEO LAPARASCOPICA MAS INSTRUMENTAL', TipoItem::Servicio, CategoriaLegalDeDescuento::IntervencionQuirurgica, 'UND', '4000.00'],

        // ── Rayos X ───────────────────────────────────────────────────
        ['RX-001', 'PROYECCION DE RAYOS X CON LECTURA', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '500.00'],
        ['RX-002', 'PROYECCION DE RAYOS X CON LECTURA RECARGO HORA INHABIL', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '700.00'],
        ['RX-003', 'PROYECCION DE RAYOS X PORTATIL', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '600.00'],
        ['RX-004', 'PROYECCION DE RAYOS X PORTATIL RECARGO HORA INHABIL', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '800.00'],
        ['RX-005', 'COLANGIOGRAFIA', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '2500.00'],
        ['RX-006', 'COLANGIOGRAFIA RECARGO HORA INHABIL', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '3500.00'],

        // ── Consulta externa ──────────────────────────────────────────
        /*
         * El tarifario lista MÉDICOS con su identidad y su especialidad.
         * Acá van las ESPECIALIDADES: el catálogo cobra «consulta de
         * ginecología», no «consulta con la doctora tal». Quién atendió
         * es el médico tratante del encuentro, no el ítem — si fuera el
         * ítem, cada alta o baja de personal sería un cambio de catálogo
         * y las estadísticas por especialidad no se podrían sacar.
         *
         * ⚠️ VERIFICAR — Medicina Interna aparece con L 900.00 en un
         * médico y con L 1,000.00 en otro. Se cargó el MENOR, que es lo
         * conservador: cobrar de menos se corrige, cobrar de más se
         * devuelve.
         */
        ['CON-001', 'CONSULTA EXTERNA CIRUGIA GENERAL', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '900.00'],
        ['CON-002', 'CONSULTA EXTERNA GINECOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '900.00'],
        ['CON-003', 'CONSULTA EXTERNA MEDICINA INTERNA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '900.00'],
        ['CON-004', 'CONSULTA EXTERNA ORTOPEDIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '900.00'],
        ['CON-005', 'CONSULTA EXTERNA NEUMOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '1000.00'],
        ['CON-006', 'CONSULTA EXTERNA PEDIATRIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '1000.00'],
        ['CON-007', 'CONSULTA EXTERNA REUMATOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '1000.00'],
    ];

    /**
     * Laboratorio, aparte por volumen. Todos son estudio de laboratorio
     * y caen bajo el mismo numeral del Art. 30, así que solo hacen falta
     * el código, el nombre y el precio.
     *
     * @var list<array{0: string, 1: string, 2: numeric-string}>
     */
    private const LABORATORIO = [
        ['LAB-001', 'ACIDO URICO', '160.00'],
        ['LAB-002', 'ALBUMINA', '150.00'],
        ['LAB-003', 'ALCOHOLEMIA EN ORINA', '250.00'],
        ['LAB-004', 'AMILASA', '300.00'],
        ['LAB-005', 'BILIRRUBINA', '300.00'],
        ['LAB-006', 'BUN / UREA', '150.00'],
        ['LAB-007', 'CALCIO', '170.00'],
        ['LAB-008', 'CKMB-P', '500.00'],
        ['LAB-009', 'COLESTEROL HDL', '160.00'],
        ['LAB-010', 'COLESTEROL LDL', '160.00'],
        ['LAB-011', 'COLESTEROL TOTAL', '135.00'],
        ['LAB-012', 'COMBO INFLUENZA MAS COVID 19', '800.00'],
        ['LAB-013', 'CPK', '360.00'],
        ['LAB-014', 'CREATININA', '150.00'],
        ['LAB-015', 'DENGUE ANTICUERPO', '425.00'],
        ['LAB-016', 'DENGUE ANTIGENO', '425.00'],
        ['LAB-017', 'DIMERO D', '850.00'],
        ['LAB-018', 'DROGAS', '500.00'],
        ['LAB-019', 'ELECTROLITOS', '450.00'],
        ['LAB-020', 'EMBARAZO', '125.00'],
        ['LAB-021', 'FERRITINA', '850.00'],
        ['LAB-022', 'FOSFATASA ALCALINA', '500.00'],
        ['LAB-023', 'GENERAL DE HECES', '50.00'],
        ['LAB-024', 'GENERAL DE ORINA', '50.00'],
        ['LAB-025', 'GLUCOSA', '120.00'],
        ['LAB-026', 'GLUCOSA AL AZAR', '120.00'],
        ['LAB-027', 'GLUCOSA POST PRANDIAL', '120.00'],

        /*
         * ⚠️ VERIFICAR — en la foto, «HEMOGLOBINA GLICOSILADA» y
         * «HEMOGRAMA» están en filas consecutivas con L 400.00 y
         * L 130.00, y el orden de las dos columnas no es inequívoco.
         * Se cargó la lectura alfabética: glicosilada primero.
         */
        ['LAB-028', 'HEMOGLOBINA GLICOSILADA', '400.00'],
        ['LAB-029', 'HEMOGRAMA', '130.00'],

        ['LAB-030', 'LDH', '200.00'],
        ['LAB-031', 'LIPASA', '300.00'],
        ['LAB-032', 'PCR', '260.00'],
        ['LAB-033', 'PCR ULTRASENSIBLE', '350.00'],
        ['LAB-034', 'PROBNP', '1100.00'],
        ['LAB-035', 'PROCALCITONINA', '1200.00'],
        ['LAB-036', 'PROTEINAS TOTALES', '150.00'],
        ['LAB-037', 'PSA', '450.00'],
        ['LAB-038', 'RPR', '50.00'],
        ['LAB-039', 'SANGRE OCULTA EN HECES', '100.00'],
        ['LAB-040', 'T3 TOTAL', '475.00'],
        ['LAB-041', 'T4 TOTAL', '475.00'],
        ['LAB-042', 'TGO', '140.00'],
        ['LAB-043', 'TGP', '140.00'],
        ['LAB-044', 'TIPO Y RH', '175.00'],
        ['LAB-045', 'TRIGLICERIDOS', '150.00'],
        ['LAB-046', 'TSH TOTAL', '475.00'],
        ['LAB-047', 'VES', '60.00'],
        ['LAB-048', 'WRIGHT', '100.00'],
    ];

    public function run(): void
    {
        $palig = $this->convenio();
        $sembrados = 0;

        foreach (self::SERVICIOS as [$codigo, $nombre, $tipo, $categoria, $unidad, $precioPalig]) {
            $item = $this->item($codigo, $nombre, $tipo, $categoria, $unidad);
            $this->precios($item, $palig, $precioPalig);
            $sembrados++;
        }

        foreach (self::LABORATORIO as [$codigo, $nombre, $precioPalig]) {
            $item = $this->item(
                $codigo,
                $nombre,
                TipoItem::EstudioLaboratorio,
                CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                'UND',
            );

            $this->precios($item, $palig, $precioPalig);
            $sembrados++;
        }

        $this->command?->info("✓ {$sembrados} servicios del catálogo sembrados, cada uno con su precio de lista y el de PALIG.");
        $this->command?->comment('  El precio de lista es el de PALIG más 20 %. El de PALIG es el del tarifario firmado, tal cual.');
        $this->command?->warn('  ⚠️ Revisá los ítems marcados VERIFICAR en el seeder antes de facturarle a nadie.');
        $this->command?->warn('  ⚠️ El convenio PALIG quedó con cobertura 0 %: cargá el porcentaje real en Convenios.');
    }

    /**
     * El pagador. Se crea con cobertura CERO a propósito.
     *
     * El porcentaje real no está en el tarifario que se fotografió, y un
     * 80 % inventado sería el hospital regalando plata sin que nadie lo
     * haya decidido. Con cero, el paciente aparece debiendo todo —que es
     * visible y se corrige en dos clics— en vez de que la aseguradora
     * aparezca cubriendo lo que no cubre.
     */
    private function convenio(): Convenio
    {
        /** @var Convenio $convenio */
        $convenio = Convenio::query()->updateOrCreate(
            ['codigo' => 'PALIG'],
            [
                'nombre'               => 'PAN-AMERICAN LIFE INSURANCE GROUP',
                'tipo'                 => TipoConvenio::AseguradoraPrivada,
                'base_descuento_legal' => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,
                'fundamento_descuento' => 'El descuento del Art. 30 se aplica sobre el deducible y el '
                    .'coaseguro que desembolsa el paciente, que es lo que él paga de su bolsillo.',
                'requiere_autorizacion' => true,
                'dias_credito'          => 30,
                'vigencia_desde'        => self::VIGENCIA_DESDE,
                'notas'                 => 'Tarifario cargado desde el documento impreso de agosto de 2026. '
                    .'Áreas: hospitalización, equipo médico, rayos X, laboratorio y consulta externa.',

                /*
                 * Cubre el catálogo por defecto —es una aseguradora, no
                 * un contado— pero con porcentaje CERO hasta que alguien
                 * lea el contrato y lo cargue.
                 */
                'cobertura_fraccion' => '0.0000',
                'cubre_por_defecto'  => true,
            ],
        );

        return $convenio;
    }

    private function item(
        string $codigo,
        string $nombre,
        TipoItem $tipo,
        CategoriaLegalDeDescuento $categoria,
        string $unidad,
    ): Item {
        /** @var Item $item */
        $item = Item::query()->updateOrCreate(
            ['codigo' => $codigo],
            [
                'nombre' => $nombre,
                'tipo'   => $tipo,

                /*
                 * Exento por el Art. 15 inciso d de la Ley del ISV:
                 * hospitalización, laboratorio clínico, radiología y
                 * demás servicios médicos y quirúrgicos. La excepción de
                 * ese inciso son los tratamientos de belleza estética, y
                 * en este tarifario no hay ninguno.
                 */
                'regimen_isv'               => RegimenIsv::Exento,
                'politica_cargo'            => PoliticaCargo::Cobrable,
                'categoria_legal_descuento' => $categoria,
                'unidad_dispensacion_id'    => $this->unidad($unidad)->id,
                'requiere_lote'             => false,
                'requiere_receta'           => false,
                'es_controlado'             => false,
                'vigencia_desde'            => self::VIGENCIA_DESDE,
            ],
        );

        return $item;
    }

    /**
     * Las dos filas de tarifario del ítem: la de lista y la de PALIG.
     *
     * @param numeric-string $precioPalig
     */
    private function precios(Item $item, Convenio $palig, string $precioPalig): void
    {
        $lista = bcmul($precioPalig, self::FACTOR_LISTA, 4);

        Tarifario::query()->updateOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => null,
                'sede_id'        => null,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            [
                'precio' => $lista,
                'motivo' => 'Precio de lista del hospital: el tarifario PALIG de agosto de 2026 más 20 %.',
            ],
        );

        Tarifario::query()->updateOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => $palig->id,
                'sede_id'        => null,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            [
                'precio' => bcmul($precioPalig, '1', 4),
                'motivo' => 'Precio firmado con PALIG, tomado del tarifario impreso de agosto de 2026.',
            ],
        );
    }

    private function unidad(string $codigo): Unidad
    {
        $unidad = Unidad::query()->where('codigo', $codigo)->first();

        if (! $unidad instanceof Unidad) {
            throw new RuntimeException(
                "Falta la unidad «{$codigo}». Corré UnidadesSeeder antes que este seeder."
            );
        }

        return $unidad;
    }
}
