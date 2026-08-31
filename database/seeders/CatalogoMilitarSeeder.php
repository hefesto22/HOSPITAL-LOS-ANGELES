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
use App\Models\Especialidad;
use App\Models\HonorarioMedico;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Tarifario;
use App\Models\Unidad;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * La propuesta de precios presentada al Hospital Militar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTE SEEDER NO VA EN `DatabaseSeeder`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Son los datos de UN pagador concreto, igual que `CatalogoPaligSeeder`.
 * Se corre a mano y es idempotente:
 *
 *     php artisan db:seed --class=CatalogoMilitarSeeder
 *
 * ⚠️ Necesita `EspecialidadesSeeder`, `UnidadesSeeder` y
 * `CatalogoPaligSeeder` corridos antes: 91 de los precios son de ítems
 * que ese último ya creó, y si falta alguno esto revienta con el código
 * en el mensaje en vez de sembrar a medias.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ TRAE EL DOCUMENTO Y QUÉ SE HIZO CON CADA COSA
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · Hospitalización, equipo médico, rayos X y laboratorio — precios
 *     de ítems que ya están en el catálogo. Entran como una fila de
 *     `tarifarios` con `convenio_id` = MILITAR. El precio de lista del
 *     hospital NO se toca: ya lo fijó el seeder de PALIG.
 *   · «Otros laboratorios» — 86 estudios que no estaban en el catálogo.
 *     Se crean con su precio del Militar y, además, con precio de lista
 *     (ese mismo número más 20 %, el mismo criterio con el que se armó
 *     la lista inicial). Sin fila de lista, al paciente que paga de su
 *     bolsillo no se le podría cobrar el examen.
 *   · Consulta externa — el papel lista MÉDICOS con su identidad, su
 *     especialidad y cuánto cobra cada uno. Los ítems son por
 *     ESPECIALIDAD y el precio de cada doctor va en `honorarios_medicos`
 *     con `convenio_id` = MILITAR: es exactamente para lo que existe esa
 *     tabla —dos cirujanos generales, uno cobra 1,000 y el otro 1,500—.
 *   · Ambulancia — las tres filas vienen en cero y el documento dice
 *     «No contamos con el servicio en este momento». No se crea nada:
 *     un ítem con precio cero es un ítem que alguien va a cargar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE HAY QUE DECIDIR ANTES DE FACTURARLE AL MILITAR
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · El convenio queda con cobertura CERO. El Militar no cubre un
 *     porcentaje: aprueba un MONTO por caso, y ese monto se registra en
 *     la cuenta («lo que el seguro autorizó»). Con cero, mientras nadie
 *     autorice nada el paciente aparece debiendo todo —visible y
 *     corregible— en vez de que el Militar aparezca cubriendo lo que no
 *     aprobó.
 *   · `base_descuento_legal` quedó igual que el de PALIG (sobre el total
 *     facturado). Si el criterio del Militar es otro, se cambia en
 *     Seguros y convenios; el cálculo lo sigue.
 *   · Falta el R.T.N. del Hospital Militar. Sin él la factura sale con
 *     un guión donde va el número del cliente. Es dato, no código.
 */
class CatalogoMilitarSeeder extends Seeder
{
    /**
     * Desde cuándo rigen estos precios.
     *
     * Fija y declarada, no `now()`: así el seeder es reproducible y
     * volver a correrlo actualiza la MISMA fila en vez de abrir una
     * vigencia nueva que choque con el EXCLUDE de traslape.
     */
    private const VIGENCIA_DESDE = '2026-08-01';

    /** Cuánto más caro es el precio de lista respecto del del Militar. */
    private const FACTOR_LISTA = '1.20';

    /**
     * Precio del Militar para ítems que YA existen en el catálogo.
     *
     * El nombre va de comentario para poder leer la lista contra el
     * papel; lo que manda es el código.
     *
     * @var list<array{0: string, 1: numeric-string}>
     */
    private const PRECIOS = [
        // ── Área de hospitalización ───────────────────────────────────
        ['HOS-001', '500.00'],  // ALIMENTACION DIA
        ['HOS-002', '500.00'],  // GASTOS ADMINISTRATIVOS
        ['HOS-003', '1300.00'],  // HABITACION HOSPITALIZACION POR DIA
        ['HOS-004', '1600.00'],  // HABITACION CON CAMA ESPECIAL PARA ORTOPEDIA POR DIA
        ['HOS-005', '1500.00'],  // SALA CUNA / RECIEN NACIDOS
        ['HOS-006', '350.00'],  // SALA DE EMERGENCIA
        ['HOS-007', '180.00'],  // OBSERVACION POR HORA
        ['HOS-008', '800.00'],  // SALA DE RECUPERACION
        ['HOS-009', '350.00'],  // SALA DE PROCEDIMIENTOS
        ['HOS-010', '3650.00'],  // USO SALA DE OPERACIONES BASICO 2H
        ['HOS-011', '4000.00'],  // USO SALA DE OPERACIONES PAQUETE CESAREA 2H
        ['HOS-012', '3000.00'],  // SALA DE LABOR Y PARTO USO 2H
        ['HOS-013', '1500.00'],  // RECARGO HORA EXTRA SALA DE OPERACIONES
        ['HOS-014', '1750.00'],  // RECARGO HORA EXTRA SALA DE OPERACIONES
        ['HOS-020', '850.00'],  // HONORARIOS MEDICO GENERAL
        ['HOS-021', '570.00'],  // HONORARIOS ENFERMERIA TURNO A
        ['HOS-022', '570.00'],  // HONORARIOS ENFERMERIA TURNO B
        ['HOS-023', '670.00'],  // HONORARIOS ENFERMERIA TURNO C
        ['HOS-024', '750.00'],  // HONORARIOS ENFERMERIA LABOR Y PARTO
        ['HOS-025', '750.00'],  // HONORARIOS ENFERMERIA RECUPERACION
        ['HOS-026', '1050.00'],  // HONORARIOS CIRCULANTE 2 HORAS
        ['HOS-027', '1050.00'],  // HONORARIOS TECNICO INSTRUMENTISTA 2H
        
        // ── Equipo médico ─────────────────────────────────────────────
        ['EQP-001', '120.00'],  // BACINETE X DIA
        ['EQP-002', '170.00'],  // BOMBAS DE INFUSION X DIA
        ['EQP-003', '850.00'],  // DESFRIBRILADOR X USO
        ['EQP-004', '150.00'],  // DOPPLER FETAL
        ['EQP-005', '550.00'],  // ELECTROCARDIOGRAMA X USO
        ['EQP-006', '330.00'],  // ELECTROCAUTERIO
        ['EQP-007', '2600.00'],  // ELECTROCAUTERIO LIGASURE MAS INSTRUMENTAL
        ['EQP-008', '2700.00'],  // INSTRUMENTAL NEUROQUIRURGICO
        ['EQP-009', '75.00'],  // GLUCOMETRIAS X USO
        ['EQP-010', '400.00'],  // INCUBADORA CERRADA X DIA
        ['EQP-011', '550.00'],  // MAQUINA DE ANESTESIA CON MONITOR
        ['EQP-012', '700.00'],  // MONITOR DE SIGNO t O2 t EKG
        ['EQP-013', '800.00'],  // MONITOR FETAL X USO
        ['EQP-014', '110.00'],  // NEBULIZADORES POR DIA
        ['EQP-015', '4000.00'],  // TORRE DE VIDEO LAPARASCOPICA MAS INSTRUMENTAL
        
        // ── Rayos X ───────────────────────────────────────────────────
        ['RX-001', '500.00'],  // PROYECCION DE RAYOS X CON LECTURA
        ['RX-002', '700.00'],  // PROYECCION DE RAYOS X CON LECTURA RECARGO HORA INHABIL
        ['RX-003', '600.00'],  // PROYECCION DE RAYOS X PORTATIL
        ['RX-004', '800.00'],  // PROYECCION DE RAYOS X PORTATIL RECARGO HORA INHABIL
        ['RX-005', '2500.00'],  // COLANGIOGRAFIA
        ['RX-006', '3500.00'],  // COLANGIOGRAFIA RECARGO HORA INHABIL
        
        // ── Laboratorio ───────────────────────────────────────────────
        ['LAB-001', '160.00'],  // ACIDO URICO
        ['LAB-002', '150.00'],  // ALBUMINA
        ['LAB-003', '250.00'],  // ALCOHOLEMIA EN ORINA
        ['LAB-004', '300.00'],  // AMILASA
        ['LAB-005', '300.00'],  // BILIRRUBINA
        ['LAB-006', '150.00'],  // BUN/UREA
        ['LAB-007', '170.00'],  // CALCIO
        ['LAB-008', '500.00'],  // CKMB-P
        ['LAB-009', '160.00'],  // COLESTEROL HDL
        ['LAB-010', '160.00'],  // COLESTEROL LDL
        ['LAB-011', '135.00'],  // COLESTEROL TOTAL
        ['LAB-012', '800.00'],  // COMBO INFLUENZA + COVID 19
        ['LAB-013', '360.00'],  // CPK
        ['LAB-014', '150.00'],  // CRETININA
        ['LAB-015', '425.00'],  // DENGUE ANTICUERPO
        ['LAB-016', '425.00'],  // DENGUE ANTIGENO
        ['LAB-017', '850.00'],  // DIMERO D
        ['LAB-018', '500.00'],  // DROGAS
        ['LAB-019', '450.00'],  // ELECTROLITOS
        ['LAB-020', '125.00'],  // EMBARAZO
        ['LAB-021', '850.00'],  // FERRITINA
        ['LAB-022', '500.00'],  // FOSFATASA ALCALINA
        ['LAB-023', '50.00'],  // GENERAL DE HECES
        ['LAB-024', '50.00'],  // GENERAL DE ORINA
        ['LAB-025', '120.00'],  // GLUCOSA
        ['LAB-026', '120.00'],  // GLUCOSA AL AZAR
        ['LAB-027', '120.00'],  // GLUCOSA POST PANDRIAL
        ['LAB-028', '400.00'],  // HEMOGLOBINA GLICOSILADA
        ['LAB-029', '130.00'],  // HEMOGRAMA
        ['LAB-030', '200.00'],  // LDH
        ['LAB-031', '300.00'],  // LIPASA
        ['LAB-032', '260.00'],  // PCR
        ['LAB-033', '350.00'],  // PCR ULTRASENSIBLE
        ['LAB-034', '1100.00'],  // PROBNP
        ['LAB-035', '1200.00'],  // PROCALCITONINA
        ['LAB-036', '150.00'],  // PROTEINAS TOTALES
        ['LAB-037', '450.00'],  // PSA
        ['LAB-038', '50.00'],  // RPR
        ['LAB-039', '100.00'],  // SANGRE OCULTA EN HECES
        ['LAB-040', '475.00'],  // T3 TOTAL
        ['LAB-041', '475.00'],  // T4 TOTAL
        ['LAB-042', '140.00'],  // TGO
        ['LAB-043', '140.00'],  // TGP
        ['LAB-044', '175.00'],  // TIPO Y RH
        ['LAB-045', '150.00'],  // TRIGLICERIDOS
        ['LAB-046', '475.00'],  // TSH TOTAL
        ['LAB-047', '60.00'],  // VES
        ['LAB-048', '100.00'],  // WRIGHT
    ];

    /**
     * Lo que el documento del Militar trae y el catálogo no tenía.
     *
     * @var list<array{0: string, 1: string, 2: TipoItem, 3: CategoriaLegalDeDescuento, 4: string, 5: numeric-string}>
     */
    private const NUEVOS = [
        ['RX-007', 'PROYECCION DIGITAL DE RAYOS X SIN LECTURA', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '400.00'],
        ['CON-008', 'CONSULTA EXTERNA NUTRICION', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '1000.00'],
        ['CON-009', 'CONSULTA EXTERNA ODONTOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::OdontologiaYOftalmologia, 'UND', '500.00'],
        ['CON-010', 'CONSULTA EXTERNA PSICOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '850.00'],
        ['CON-011', 'CONSULTA EXTERNA MEDICINA GENERAL', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral, 'UND', '270.00'],
        ['CON-012', 'CONSULTA EXTERNA MEDICINA GENERAL HORA INHABIL', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral, 'UND', '500.00'],
    ];

    /**
     * La hoja «OTROS LABORATORIOS»: 86 estudios, todos nuevos.
     *
     * Aparte por volumen: todos son estudio de laboratorio y caen bajo
     * el mismo numeral del Art. 30, así que solo hacen falta el código,
     * el nombre y el precio.
     *
     * @var list<array{0: string, 1: string, 2: numeric-string}>
     */
    private const OTROS_LABORATORIOS = [
        ['LAB-049', 'TIEMPO DE SANGRADO', '150.00'],
        ['LAB-050', 'TIEMPO DE COAGULACION', '150.00'],
        ['LAB-051', 'TIEMPO DE PROTOMBINA', '210.00'],
        ['LAB-052', 'TIEMPO PARCIAL DE', '210.00'],
        ['LAB-053', 'FROTIS DE SANGRE PERIFERICA', '350.00'],
        ['LAB-054', 'RECUENTO DE PLAQUETAS EN FROTIS', '300.00'],
        ['LAB-055', 'ANTI PEPTIDO CICLICO', '2100.00'],
        ['LAB-056', 'CURVA DE TOLERANCIA', '700.00'],
        ['LAB-057', 'TESTOSTERONA', '800.00'],
        ['LAB-058', 'ANTIGENOS FEBRILES', '700.00'],
        ['LAB-059', 'HELICOBACTER PYLORI EN HECES', '650.00'],
        ['LAB-060', 'CRUCE SANGUINEO', '3500.00'],
        ['LAB-061', 'UROCULTIVO', '600.00'],
        ['LAB-062', 'TRANSFUSION 1 UNIDAD DE SANGRE', '8000.00'],
        ['LAB-063', 'HORMONA T4 LIBRE', '700.00'],
        ['LAB-064', 'LAB MAGNESIO', '450.00'],
        ['LAB-065', 'FACTOR REUMATOIDEO', '200.00'],
        ['LAB-066', 'CULTIVO DE SECRECIONES', '600.00'],
        ['LAB-067', 'VIH ANTICUERPOS', '550.00'],
        ['LAB-068', 'LAB AMONIO', '550.00'],
        ['LAB-069', 'TROPONINAS', '1000.00'],
        ['LAB-070', 'ESTRELTOLISINA ASO', '250.00'],
        ['LAB-071', 'FIBRINOGENO', '800.00'],
        ['LAB-072', 'LAB INR', '250.00'],
        ['LAB-073', 'GASES ARTERIALES', '2000.00'],
        ['LAB-074', 'PROTEINAS 24 HORAS', '200.00'],
        ['LAB-075', 'ANTIGENO CA 125', '720.00'],
        ['LAB-076', 'HORMONA T3 LIBRE', '700.00'],
        ['LAB-077', 'VITAMINA B12', '2250.00'],
        ['LAB-078', 'ACIDO FOLICO', '2250.00'],
        ['LAB-079', 'PANEL RESPIRATORIO', '6300.00'],
        ['LAB-080', 'CULTIVO KOH', '500.00'],
        ['LAB-081', 'PROTEINURIA', '175.00'],
        ['LAB-082', 'PRUEBAS LIQUIDO CEFALORRAQUIDEO', '700.00'],
        ['LAB-083', 'TRANSFUSION UNIDAD DE PLASMA', '3500.00'],
        ['LAB-084', 'CORTISOL', '1700.00'],
        ['LAB-085', 'CITOMEGALOVIRUS', '2400.00'],
        ['LAB-086', 'EPSTEIN BAR VIRUS', '2400.00'],
        ['LAB-087', 'LABORATORIO ADA', '2500.00'],
        ['LAB-088', 'ROTAVIRUS Y ADENOVIRUS', '450.00'],
        ['LAB-089', 'FOSFORO', '310.00'],
        ['LAB-090', 'ALCOHOLEMIA EN SALIVA', '175.00'],
        ['LAB-091', 'COCAINA EN ORINA', '175.00'],
        ['LAB-092', 'MARIHUANA EN ORINA', '175.00'],
        ['LAB-093', 'COPROCULTIVO', '600.00'],
        ['LAB-094', 'HEPATITIS A', '350.00'],
        ['LAB-095', 'EXAMEN SIFILIS', '500.00'],
        ['LAB-096', 'GAMA GLUTAMIL TRANSFERASA', '600.00'],
        ['LAB-097', 'EXAMEN GRAM', '240.00'],
        ['LAB-098', 'PRUEBA ESPECIAL', '2100.00'],
        ['LAB-099', 'ANTIGENOS INFLUENZA', '470.00'],
        ['LAB-100', 'COOMBS DIRECTO E INDIRECTO', '310.00'],
        ['LAB-101', 'HEMOCULTIVO', '900.00'],
        ['LAB-102', 'MICRO ALBUMINURIA', '250.00'],
        ['LAB-103', 'EXAMEN DE LEPTOSPIRA', '1500.00'],
        ['LAB-104', 'CLOSTRIDIUM DIFFICILE GDH', '2600.00'],
        ['LAB-105', 'VITAMINA D', '1600.00'],
        ['LAB-106', 'EXAMEN DE BAAR', '270.00'],
        ['LAB-107', 'COCIENTE ALBUMINA CREATININA', '440.00'],
        ['LAB-108', 'ANTICUERPO HEPATITITS C', '450.00'],
        ['LAB-109', 'INSULINA', '650.00'],
        ['LAB-110', 'RECUENTO DE RETICULOCITOS', '400.00'],
        ['LAB-111', 'NIVELES DE HIERRO', '1050.00'],
        ['LAB-112', 'SUPERFICIE HEPATITIS B', '500.00'],
        ['LAB-113', 'AC. HEPATITIS B', '450.00'],
        ['LAB-114', 'HEPATITIS C', '500.00'],
        ['LAB-115', 'PROLACTINA', '750.00'],
        ['LAB-116', 'ANTIGENO PROSTATICO LIBRE', '1400.00'],
        ['LAB-117', 'CITOLOGIA DE HECES', '750.00'],
        ['LAB-118', '% ANTIGENO PROTATICO LIBRE', '1400.00'],
        ['LAB-119', 'HELICOBACTER PILORI EN SANGRE', '500.00'],
        ['LAB-120', 'EXAMEN CHAGAS', '650.00'],
        ['LAB-121', 'EXAMEN ANTI-ESTREPTOLISINA O', '300.00'],
        ['LAB-122', 'CITOQUIMA Y GRAM DE LIQUIDOS', '1000.00'],
        ['LAB-123', 'HORMONA FOLICULOESTIMULANTE', '1000.00'],
        ['LAB-124', 'GLOBULINA FIJADORA DE HORMONAS', '1650.00'],
        ['LAB-125', 'ESTRADIOL', '1700.00'],
        ['LAB-126', 'HORMONA ADENOCORTICOTROPICA', '3000.00'],
        ['LAB-127', 'ELECTROLITOS EN ORINA', '6000.00'],
        ['LAB-128', 'HORMONA LUTEINIZANTE', '1200.00'],
        ['LAB-129', 'CA 19-9', '1600.00'],
        ['LAB-130', 'ALFA FETO PROTEINA', '1100.00'],
        ['LAB-131', 'CA 15-3', '1100.00'],
        ['LAB-132', 'CA 125', '1100.00'],
        ['LAB-133', 'MICROALBUMINA ORINA AL AZAR', '480.00'],
        ['LAB-134', 'TOXOPLASMOSIS', '1400.00'],
    ];

    /**
     * Los médicos de consulta externa: nombre, identidad, código de
     * especialidad, ítem que se cobra y lo que cobra ESE doctor.
     *
     * ⚠️ La especialidad es la que dice la columna «Especialidad» del
     * documento. Los dos que traen sub-especialidad —un urólogo y una
     * neumóloga pediatra— quedan en su especialidad principal y la
     * diferencia de precio la lleva su honorario, que es justo lo que
     * `honorarios_medicos` resuelve. Si el hospital quiere cobrar
     * «consulta de urología» aparte, es un ítem nuevo y una decisión
     * suya, no una que este seeder pueda tomar.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: numeric-string}>
     */
    private const MEDICOS = [
        ['Dr. Juan Carlos Cardona Medina', '0401-1966-00708', 'CIRGEN', 'CON-001', '1000.00'],
        ['Dr. Roberto Abner Sanabria Peña', '0801-1989-00076', 'CIRGEN', 'CON-001', '1000.00'],
        ['Dra. Sayda Melissa Mejia Suarez', '0401-1995-01074', 'CIRGEN', 'CON-001', '1000.00'],
        ['Dr. Juan Carlos Cardona Contreras', '0801-1990-09368', 'CIRGEN', 'CON-001', '1500.00'],  // Urologo
        ['Dra. Nancy Lizzeth Rivera Henriquez', '0401-1984-00074', 'GINECO', 'CON-002', '1000.00'],
        ['Dra. Wendy Rosalina Arias Aguilera', '0601-1993-01532', 'MEDINT', 'CON-003', '1000.00'],
        ['Dra. Laure Mabel Reyes Pineda', '0503-1989-01717', 'NEUMOL', 'CON-005', '1500.00'],
        ['Lic. Bianca Dallanara Ramirez Peña', '0401-1993-00336', 'NUTRIC', 'CON-008', '1000.00'],
        ['Dra. Angela Lizeth Cardona Contreras', '0801-1993-14260', 'ODONTO', 'CON-009', '500.00'],
        ['Dra. Elida Argentina Diaz Hernandez', '0401-1983-01302', 'ODONTO', 'CON-009', '500.00'],
        ['Dra. Stefhany Maricela Castillo Mejia', '0401-1994-00347', 'ODONTO', 'CON-009', '500.00'],
        ['Dr. Alexis Oswaldo Franco Mejia', '1401-1993-00036', 'ORTOPE', 'CON-004', '1000.00'],
        ['Dra. Fabiola Carolina Ramos Benitez', '0401-1989-00105', 'PEDIAT', 'CON-006', '1000.00'],
        ['Dra. Ana Polette Valeriano', '0801-1991-25267', 'PEDIAT', 'CON-006', '1500.00'],  // Neumologa
        ['Lic. Rosa del Carmen Guerra Pineda', '0413-1992-00578', 'PSICOL', 'CON-010', '850.00'],
        ['Dra. Silvia Siham Mendoza Kunkar', '0501-1991-03080', 'REUMAT', 'CON-007', '1500.00'],
    ];

    public function run(): void
    {
        $militar = $this->convenio();
        $puestos = 0;

        foreach (self::PRECIOS as [$codigo, $precio]) {
            $this->precioDelMilitar($this->itemExistente($codigo), $militar, $precio);
            $puestos++;
        }

        foreach (self::NUEVOS as [$codigo, $nombre, $tipo, $categoria, $unidad, $precio]) {
            $item = $this->itemDeLaPropuesta($codigo, $nombre, $tipo, $categoria, $unidad);
            $this->precioDeLista($item, $precio);
            $this->precioDelMilitar($item, $militar, $precio);
            $puestos++;
        }

        foreach (self::OTROS_LABORATORIOS as [$codigo, $nombre, $precio]) {
            $item = $this->itemDeLaPropuesta(
                $codigo,
                $nombre,
                TipoItem::EstudioLaboratorio,
                CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                'UND',
            );

            $this->precioDeLista($item, $precio);
            $this->precioDelMilitar($item, $militar, $precio);
            $puestos++;
        }

        foreach (self::MEDICOS as [$nombre, $identidad, $especialidad, $codigoItem, $precio]) {
            $medico = $this->medico($nombre, $identidad, $especialidad);
            $this->honorario($medico, $this->itemExistente($codigoItem), $militar, $precio);
        }

        $this->command?->info("✓ {$puestos} precios del Hospital Militar cargados.");
        $this->command?->info('✓ '.count(self::MEDICOS).' médicos de consulta externa con su honorario para el Militar.');
        $this->command?->comment('  Los 86 estudios de «otros laboratorios» son nuevos: llevan precio de lista (el del Militar más 20 %) además del del convenio.');
        $this->command?->warn('  ⚠️ Ambulancia no se cargó: las tres filas vienen en cero y el documento dice que no hay servicio.');
        $this->command?->warn('  ⚠️ El convenio quedó con cobertura 0 %: el Militar aprueba un MONTO por caso, y ese monto se registra en la cuenta al facturar.');
        $this->command?->warn('  ⚠️ Falta el R.T.N. del Hospital Militar: cargalo en Seguros y convenios antes de emitirle una factura.');
    }

    /**
     * El pagador.
     *
     * `Institucional` y no `AseguradoraPrivada`: el Militar no vende
     * pólizas, cubre a su personal contra un convenio. La diferencia se
     * nota en los reportes, que es donde alguien la va a buscar.
     */
    private function convenio(): Convenio
    {
        /** @var Convenio $convenio */
        $convenio = Convenio::query()->updateOrCreate(
            ['codigo' => 'MILITAR'],
            [
                'nombre'               => 'HOSPITAL MILITAR',
                'tipo'                 => TipoConvenio::Institucional,
                'base_descuento_legal' => BaseDelDescuentoLegal::SobreElTotalFacturado,
                'fundamento_descuento' => 'Mismo criterio con el que se cargó PALIG. Confirmar contra el '
                    .'convenio firmado con el Hospital Militar antes de la primera factura.',
                'requiere_autorizacion' => true,
                'dias_credito'          => 30,
                'vigencia_desde'        => self::VIGENCIA_DESDE,
                'notas'                 => 'Propuesta de precios presentada al Hospital Militar. Cubre por '
                    .'MONTO APROBADO, no por porcentaje: cada caso se autoriza y el resto se le factura '
                    .'al paciente. Áreas: hospitalización, equipo médico, rayos X, laboratorio, otros '
                    .'laboratorios y consulta externa. Ambulancia: sin servicio.',
                'cobertura_fraccion' => '0.0000',
                'cubre_por_defecto'  => true,
            ],
        );

        return $convenio;
    }

    /**
     * ⚠️ Falla fuerte y con el código adentro. Un ítem que no está es un
     * catálogo a medio sembrar, y seguir de largo dejaría precios del
     * Militar cargados solo para una parte de la lista — que es peor que
     * no cargar ninguno, porque no se nota.
     */
    private function itemExistente(string $codigo): Item
    {
        $item = Item::query()->where('codigo', $codigo)->first();

        if (! $item instanceof Item) {
            throw new RuntimeException(
                "Falta el ítem «{$codigo}». Corré CatalogoPaligSeeder antes que este seeder."
            );
        }

        return $item;
    }

    /**
     * El ítem nuevo de la propuesta: se busca POR NOMBRE y solo se crea
     * si de verdad no está.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 POR QUÉ NO ES UN `updateOrCreate` POR CÓDIGO
     * ─────────────────────────────────────────────────────────────────
     *
     * Los códigos que este seeder propone —LAB-049 en adelante— son los
     * MISMOS que `AsignadorDeCodigoDeItem` reparte cuando alguien crea
     * un examen desde la pantalla: toma el máximo con ese prefijo y le
     * suma uno. Si el laboratorio dio de alta tres exámenes suyos entre
     * el seeder de PALIG y hoy, LAB-049 ya existe y es de ellos — y un
     * `updateOrCreate` por código le cambiaría el nombre y la categoría
     * a un ítem que alguien está usando, sin decir nada.
     *
     * Buscar primero por nombre además hace que correrlo dos veces no
     * pise correcciones hechas a mano: el ítem ya está, se le pone el
     * precio y listo.
     *
     * ⚠️ Si el código está tomado por OTRO ítem, esto revienta con los
     * dos nombres en el mensaje. Es a propósito: quién se queda con el
     * código es una decisión del hospital, no del seeder.
     */
    private function itemDeLaPropuesta(
        string $codigo,
        string $nombre,
        TipoItem $tipo,
        CategoriaLegalDeDescuento $categoria,
        string $unidad,
    ): Item {
        $porNombre = Item::query()->where('nombre', $nombre)->first();

        if ($porNombre instanceof Item) {
            return $porNombre;
        }

        $ocupado = Item::query()->where('codigo', $codigo)->first();

        if ($ocupado instanceof Item) {
            throw new RuntimeException(
                "El código «{$codigo}» ya lo usa «{$ocupado->nombre}», y la propuesta del Militar lo "
                ."quiere para «{$nombre}». Renombrá el código en el seeder o en el catálogo antes de seguir."
            );
        }

        /** @var Item $item */
        $item = Item::query()->create(
            [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'tipo'   => $tipo,

                /*
                 * Exento por el Art. 15 inciso d de la Ley del ISV:
                 * laboratorio clínico, radiología y demás servicios
                 * médicos. En esta lista no hay ningún tratamiento de
                 * belleza estética, que es la excepción de ese inciso.
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

    /** @param numeric-string $precio */
    private function precioDelMilitar(Item $item, Convenio $militar, string $precio): void
    {
        Tarifario::query()->updateOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => $militar->id,
                'sede_id'        => null,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            [
                'precio' => bcmul($precio, '1', 4),
                'motivo' => 'Propuesta de precios presentada al Hospital Militar.',
            ],
        );
    }

    /**
     * El precio para quien paga de su bolsillo, solo para los ítems que
     * este seeder crea.
     *
     * ⚠️ `firstOrCreate` y no `updateOrCreate`: los ítems que ya existían
     * tienen su precio de lista puesto por el seeder de PALIG, y algunos
     * pudieron corregirse a mano desde la pantalla. Pisarlos con una
     * cuenta hecha sobre otro tarifario sería cambiar precios que nadie
     * pidió cambiar.
     *
     * @param numeric-string $precio
     */
    private function precioDeLista(Item $item, string $precio): void
    {
        Tarifario::query()->firstOrCreate(
            [
                'item_id'        => $item->id,
                'convenio_id'    => null,
                'sede_id'        => null,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            [
                'precio' => bcmul($precio, self::FACTOR_LISTA, 4),
                'motivo' => 'Precio de lista del hospital: el de la propuesta al Militar más 20 %.',
            ],
        );
    }

    /**
     * ⚠️ La identidad es la llave, no el nombre. «Dr. Juan Carlos
     * Cardona Medina» y «Dr. Juan Carlos Cardona Contreras» son dos
     * médicos distintos que comparten tres de cuatro nombres; buscar por
     * nombre los fusionaría, y con ellos sus honorarios.
     */
    private function medico(string $nombre, string $identidad, string $codigoEspecialidad): Medico
    {
        /** @var Medico $medico */
        $medico = Medico::query()->updateOrCreate(
            ['identidad' => $identidad],
            [
                'nombre'          => $nombre,
                'especialidad_id' => $this->especialidad($codigoEspecialidad)->id,
                'vigencia_desde'  => self::VIGENCIA_DESDE,
            ],
        );

        return $medico;
    }

    /** @param numeric-string $precio */
    private function honorario(Medico $medico, Item $item, Convenio $militar, string $precio): void
    {
        HonorarioMedico::query()->updateOrCreate(
            [
                'medico_id'      => $medico->id,
                'item_id'        => $item->id,
                'convenio_id'    => $militar->id,
                'vigencia_desde' => self::VIGENCIA_DESDE,
            ],
            ['precio' => bcmul($precio, '1', 4)],
        );
    }

    private function especialidad(string $codigo): Especialidad
    {
        $especialidad = Especialidad::query()->where('codigo', $codigo)->first();

        if (! $especialidad instanceof Especialidad) {
            throw new RuntimeException(
                "Falta la especialidad «{$codigo}». Corré EspecialidadesSeeder antes que este seeder."
            );
        }

        return $especialidad;
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
