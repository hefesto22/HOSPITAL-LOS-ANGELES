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
use Illuminate\Support\Facades\DB;
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
 * ⚠️ LOS PRECIOS DEL DOCUMENTO SON EL PRECIO FINAL PARA EL MILITAR, y
 * la lista del hospital es ese número MÁS 20 %. Si el papel dice 500, el
 * Militar paga 500 y el particular 600. De ahí salen las dos filas de
 * `tarifarios` de cada ítem, y por eso este seeder SÍ recalcula la
 * lista: ver `precioDeLista()`.
 *
 *   · Hospitalización, equipo médico, rayos X y laboratorio — precios
 *     de ítems que ya están en el catálogo. Se les pone la fila del
 *     Militar y se les recalcula la de lista.
 *   · «Otros laboratorios» — 86 estudios que no estaban en el catálogo.
 *     Se crean con sus dos filas. Sin la de lista, al paciente que paga
 *     de su bolsillo no se le podría cobrar el examen.
 *   · Consulta externa — el papel lista MÉDICOS con su identidad, su
 *     especialidad y cuánto cobra cada uno. Los ítems son por
 *     ESPECIALIDAD y el precio de cada doctor va en `honorarios_medicos`
 *     con `convenio_id` = MILITAR: es exactamente para lo que existe esa
 *     tabla —cuatro cirujanos generales, tres cobran 1,000 y el otro
 *     1,500—.
 *
 *     ⚠️ El ítem igual lleva precio: el MENOR de sus doctores. Es el que
 *     responde si alguien carga la consulta sin elegir médico, y sin él
 *     esa consulta caería en el precio de lista —el del particular— y se
 *     le cobraría de más a la aseguradora.
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

    /** Cuántos dígitos usar cuando la familia todavía no tiene ninguno. */
    private const ANCHO_POR_DEFECTO = 4;

    /**
     * El próximo número y su ancho, por prefijo. Se llena solo.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $numeracion = [];

    /** Cuántos ítems creó de verdad esta corrida. */
    private int $creados = 0;

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
        
        // ── Consulta externa ───────────────────────────────────────────
        // El documento no le pone precio al item: se lo pone a cada
        // medico. Va el MENOR de los suyos, que es lo conservador —
        // cobrar de menos se corrige, cobrar de mas se devuelve— y es
        // el que responde si alguien carga la consulta sin elegir
        // doctor. Con doctor elegido manda su honorario.
        ['CON-001', '1000.00'],  // CONSULTA EXTERNA CIRUGIA GENERAL
        ['CON-002', '1000.00'],  // CONSULTA EXTERNA GINECOLOGIA
        ['CON-003', '1000.00'],  // CONSULTA EXTERNA MEDICINA INTERNA
        ['CON-004', '1000.00'],  // CONSULTA EXTERNA ORTOPEDIA
        ['CON-005', '1500.00'],  // CONSULTA EXTERNA NEUMOLOGIA
        ['CON-006', '1000.00'],  // CONSULTA EXTERNA PEDIATRIA
        ['CON-007', '1500.00'],  // CONSULTA EXTERNA REUMATOLOGIA
    ];

    /**
     * Lo que el documento del Militar trae y el catálogo no tenía.
     *
     * ⚠️ El primer campo es el PREFIJO del código, no el código: el
     * número se pide en tiempo de ejecución (ver `codigoLibre()`).
     *
     * @var list<array{0: string, 1: string, 2: TipoItem, 3: CategoriaLegalDeDescuento, 4: string, 5: numeric-string}>
     */
    private const NUEVOS = [
        ['RX', 'PROYECCION DIGITAL DE RAYOS X SIN LECTURA', TipoItem::EstudioImagen, CategoriaLegalDeDescuento::RadiologiaYLaboratorio, 'UND', '400.00'],
        ['CON', 'CONSULTA EXTERNA NUTRICION', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '1000.00'],
        ['CON', 'CONSULTA EXTERNA ODONTOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::OdontologiaYOftalmologia, 'UND', '500.00'],
        ['CON', 'CONSULTA EXTERNA PSICOLOGIA', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaEspecializada, 'UND', '850.00'],
        ['CON', 'CONSULTA EXTERNA MEDICINA GENERAL', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral, 'UND', '270.00'],
        ['CON', 'CONSULTA EXTERNA MEDICINA GENERAL HORA INHABIL', TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral, 'UND', '500.00'],
    ];

    /**
     * La hoja «OTROS LABORATORIOS»: 86 estudios.
     *
     * Aparte por volumen: todos son estudio de laboratorio y caen bajo
     * el mismo numeral del Art. 30, así que solo hacen falta el nombre
     * y el precio. El código lo pone `codigoLibre('LAB')`.
     *
     * @var list<array{0: string, 1: numeric-string}>
     */
    private const OTROS_LABORATORIOS = [
        ['TIEMPO DE SANGRADO', '150.00'],
        ['TIEMPO DE COAGULACION', '150.00'],
        ['TIEMPO DE PROTOMBINA', '210.00'],
        ['TIEMPO PARCIAL DE', '210.00'],
        ['FROTIS DE SANGRE PERIFERICA', '350.00'],
        ['RECUENTO DE PLAQUETAS EN FROTIS', '300.00'],
        ['ANTI PEPTIDO CICLICO', '2100.00'],
        ['CURVA DE TOLERANCIA', '700.00'],
        ['TESTOSTERONA', '800.00'],
        ['ANTIGENOS FEBRILES', '700.00'],
        ['HELICOBACTER PYLORI EN HECES', '650.00'],
        ['CRUCE SANGUINEO', '3500.00'],
        ['UROCULTIVO', '600.00'],
        ['TRANSFUSION 1 UNIDAD DE SANGRE', '8000.00'],
        ['HORMONA T4 LIBRE', '700.00'],
        ['LAB MAGNESIO', '450.00'],
        ['FACTOR REUMATOIDEO', '200.00'],
        ['CULTIVO DE SECRECIONES', '600.00'],
        ['VIH ANTICUERPOS', '550.00'],
        ['LAB AMONIO', '550.00'],
        ['TROPONINAS', '1000.00'],
        ['ESTRELTOLISINA ASO', '250.00'],
        ['FIBRINOGENO', '800.00'],
        ['LAB INR', '250.00'],
        ['GASES ARTERIALES', '2000.00'],
        ['PROTEINAS 24 HORAS', '200.00'],
        ['ANTIGENO CA 125', '720.00'],
        ['HORMONA T3 LIBRE', '700.00'],
        ['VITAMINA B12', '2250.00'],
        ['ACIDO FOLICO', '2250.00'],
        ['PANEL RESPIRATORIO', '6300.00'],
        ['CULTIVO KOH', '500.00'],
        ['PROTEINURIA', '175.00'],
        ['PRUEBAS LIQUIDO CEFALORRAQUIDEO', '700.00'],
        ['TRANSFUSION UNIDAD DE PLASMA', '3500.00'],
        ['CORTISOL', '1700.00'],
        ['CITOMEGALOVIRUS', '2400.00'],
        ['EPSTEIN BAR VIRUS', '2400.00'],
        ['LABORATORIO ADA', '2500.00'],
        ['ROTAVIRUS Y ADENOVIRUS', '450.00'],
        ['FOSFORO', '310.00'],
        ['ALCOHOLEMIA EN SALIVA', '175.00'],
        ['COCAINA EN ORINA', '175.00'],
        ['MARIHUANA EN ORINA', '175.00'],
        ['COPROCULTIVO', '600.00'],
        ['HEPATITIS A', '350.00'],
        ['EXAMEN SIFILIS', '500.00'],
        ['GAMA GLUTAMIL TRANSFERASA', '600.00'],
        ['EXAMEN GRAM', '240.00'],
        ['PRUEBA ESPECIAL', '2100.00'],
        ['ANTIGENOS INFLUENZA', '470.00'],
        ['COOMBS DIRECTO E INDIRECTO', '310.00'],
        ['HEMOCULTIVO', '900.00'],
        ['MICRO ALBUMINURIA', '250.00'],
        ['EXAMEN DE LEPTOSPIRA', '1500.00'],
        ['CLOSTRIDIUM DIFFICILE GDH', '2600.00'],
        ['VITAMINA D', '1600.00'],
        ['EXAMEN DE BAAR', '270.00'],
        ['COCIENTE ALBUMINA CREATININA', '440.00'],
        ['ANTICUERPO HEPATITITS C', '450.00'],
        ['INSULINA', '650.00'],
        ['RECUENTO DE RETICULOCITOS', '400.00'],
        ['NIVELES DE HIERRO', '1050.00'],
        ['SUPERFICIE HEPATITIS B', '500.00'],
        ['AC. HEPATITIS B', '450.00'],
        ['HEPATITIS C', '500.00'],
        ['PROLACTINA', '750.00'],
        ['ANTIGENO PROSTATICO LIBRE', '1400.00'],
        ['CITOLOGIA DE HECES', '750.00'],
        ['% ANTIGENO PROTATICO LIBRE', '1400.00'],
        ['HELICOBACTER PILORI EN SANGRE', '500.00'],
        ['EXAMEN CHAGAS', '650.00'],
        ['EXAMEN ANTI-ESTREPTOLISINA O', '300.00'],
        ['CITOQUIMA Y GRAM DE LIQUIDOS', '1000.00'],
        ['HORMONA FOLICULOESTIMULANTE', '1000.00'],
        ['GLOBULINA FIJADORA DE HORMONAS', '1650.00'],
        ['ESTRADIOL', '1700.00'],
        ['HORMONA ADENOCORTICOTROPICA', '3000.00'],
        ['ELECTROLITOS EN ORINA', '6000.00'],
        ['HORMONA LUTEINIZANTE', '1200.00'],
        ['CA 19-9', '1600.00'],
        ['ALFA FETO PROTEINA', '1100.00'],
        ['CA 15-3', '1100.00'],
        ['CA 125', '1100.00'],
        ['MICROALBUMINA ORINA AL AZAR', '480.00'],
        ['TOXOPLASMOSIS', '1400.00'],
    ];

    /**
     * Los médicos de consulta externa: nombre, identidad, código de
     * especialidad, NOMBRE del ítem que se cobra y lo que cobra ESE
     * doctor.
     *
     * ⚠️ El ítem va por nombre y no por código: tres de estas consultas
     * las crea este mismo seeder y su código no se conoce hasta que
     * corre.
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
        ['Dr. Juan Carlos Cardona Medina', '0401-1966-00708', 'CIRGEN', 'CONSULTA EXTERNA CIRUGIA GENERAL', '1000.00'],
        ['Dr. Roberto Abner Sanabria Peña', '0801-1989-00076', 'CIRGEN', 'CONSULTA EXTERNA CIRUGIA GENERAL', '1000.00'],
        ['Dra. Sayda Melissa Mejia Suarez', '0401-1995-01074', 'CIRGEN', 'CONSULTA EXTERNA CIRUGIA GENERAL', '1000.00'],
        ['Dr. Juan Carlos Cardona Contreras', '0801-1990-09368', 'CIRGEN', 'CONSULTA EXTERNA CIRUGIA GENERAL', '1500.00'],  // Urologo
        ['Dra. Nancy Lizzeth Rivera Henriquez', '0401-1984-00074', 'GINECO', 'CONSULTA EXTERNA GINECOLOGIA', '1000.00'],
        ['Dra. Wendy Rosalina Arias Aguilera', '0601-1993-01532', 'MEDINT', 'CONSULTA EXTERNA MEDICINA INTERNA', '1000.00'],
        ['Dra. Laure Mabel Reyes Pineda', '0503-1989-01717', 'NEUMOL', 'CONSULTA EXTERNA NEUMOLOGIA', '1500.00'],
        ['Lic. Bianca Dallanara Ramirez Peña', '0401-1993-00336', 'NUTRIC', 'CONSULTA EXTERNA NUTRICION', '1000.00'],
        ['Dra. Angela Lizeth Cardona Contreras', '0801-1993-14260', 'ODONTO', 'CONSULTA EXTERNA ODONTOLOGIA', '500.00'],
        ['Dra. Elida Argentina Diaz Hernandez', '0401-1983-01302', 'ODONTO', 'CONSULTA EXTERNA ODONTOLOGIA', '500.00'],
        ['Dra. Stefhany Maricela Castillo Mejia', '0401-1994-00347', 'ODONTO', 'CONSULTA EXTERNA ODONTOLOGIA', '500.00'],
        ['Dr. Alexis Oswaldo Franco Mejia', '1401-1993-00036', 'ORTOPE', 'CONSULTA EXTERNA ORTOPEDIA', '1000.00'],
        ['Dra. Fabiola Carolina Ramos Benitez', '0401-1989-00105', 'PEDIAT', 'CONSULTA EXTERNA PEDIATRIA', '1000.00'],
        ['Dra. Ana Polette Valeriano', '0801-1991-25267', 'PEDIAT', 'CONSULTA EXTERNA PEDIATRIA', '1500.00'],  // Neumologa
        ['Lic. Rosa del Carmen Guerra Pineda', '0413-1992-00578', 'PSICOL', 'CONSULTA EXTERNA PSICOLOGIA', '850.00'],
        ['Dra. Silvia Siham Mendoza Kunkar', '0501-1991-03080', 'REUMAT', 'CONSULTA EXTERNA REUMATOLOGIA', '1500.00'],
    ];

    public function run(): void
    {
        $militar = $this->convenio();
        $puestos = 0;

        foreach (self::PRECIOS as [$codigo, $precio]) {
            $item = $this->itemExistente($codigo);
            $this->precioDeLista($item, $precio);
            $this->precioDelMilitar($item, $militar, $precio);
            $puestos++;
        }

        foreach (self::NUEVOS as [$prefijo, $nombre, $tipo, $categoria, $unidad, $precio]) {
            $item = $this->itemDeLaPropuesta($prefijo, $nombre, $tipo, $categoria, $unidad);
            $this->precioDeLista($item, $precio);
            $this->precioDelMilitar($item, $militar, $precio);
            $puestos++;
        }

        foreach (self::OTROS_LABORATORIOS as [$nombre, $precio]) {
            $item = $this->itemDeLaPropuesta(
                'LAB',
                $nombre,
                TipoItem::EstudioLaboratorio,
                CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                'UND',
            );

            $this->precioDeLista($item, $precio);
            $this->precioDelMilitar($item, $militar, $precio);
            $puestos++;
        }

        foreach (self::MEDICOS as [$nombre, $identidad, $especialidad, $nombreItem, $precio]) {
            $medico = $this->medico($nombre, $identidad, $especialidad);
            $this->honorario($medico, $this->itemDeConsulta($nombreItem), $militar, $precio);
        }

        $this->command?->info("✓ {$puestos} precios del Hospital Militar cargados.");
        $this->command?->info('✓ '.count(self::MEDICOS).' médicos de consulta externa con su honorario para el Militar.');
        $this->command?->comment("  {$this->creados} ítems se crearon; el resto del documento ya estaba en el catálogo.");
        $this->command?->comment('  El precio de lista de TODOS ellos quedó en el del Militar más 20 %: si el papel dice 500, el Militar paga 500 y el particular 600.');
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
     * La consulta a la que se le cuelga el honorario del médico.
     *
     * Por NOMBRE: siete las creó el seeder de PALIG con código CON-00x y
     * tres las acaba de crear este —con el número que estuviera libre—.
     * El nombre es lo único que vale para las diez.
     */
    private function itemDeConsulta(string $nombre): Item
    {
        $item = Item::query()->where('nombre', $nombre)->first();

        if (! $item instanceof Item) {
            throw new RuntimeException(
                "Falta el ítem «{$nombre}». Corré CatalogoPaligSeeder antes que este seeder, "
                .'y si lo renombraron desde la pantalla, corregí el nombre en MEDICOS.'
            );
        }

        return $item;
    }

    /**
     * El ítem nuevo de la propuesta: se busca POR NOMBRE y solo se crea
     * si de verdad no está.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 EL CÓDIGO NO SE FIJA ACÁ, SE PIDE
     * ─────────────────────────────────────────────────────────────────
     *
     * La primera versión traía los códigos escritos —LAB-049 en
     * adelante— y reventó en la primera corrida: LAB-049 ya existía y
     * era «EMOGRAMA», un examen que el laboratorio dio de alta desde la
     * pantalla después del seeder de PALIG. Es lo normal: el catálogo
     * está VIVO, y cualquier número que este archivo escriba hoy puede
     * ser de otro mañana.
     *
     * Así que se pide el siguiente libre, igual que hace
     * `AsignadorDeCodigoDeItem` cuando alguien carga un ítem a mano. El
     * seeder ya no compite por códigos con nadie.
     *
     * Buscar primero por nombre hace además que correrlo dos veces no
     * duplique nada ni pise correcciones hechas a mano: el ítem ya está,
     * se le pone el precio y listo.
     */
    private function itemDeLaPropuesta(
        string $prefijo,
        string $nombre,
        TipoItem $tipo,
        CategoriaLegalDeDescuento $categoria,
        string $unidad,
    ): Item {
        $existente = Item::query()->where('nombre', $nombre)->first();

        if ($existente instanceof Item) {
            return $existente;
        }

        $this->creados++;

        /** @var Item $item */
        $item = Item::query()->create(
            [
                'codigo' => $this->codigoLibre($prefijo),
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

    /**
     * El siguiente código libre de la familia, con el ancho que esa
     * familia ya usa.
     *
     * Copia la regla de `AsignadorDeCodigoDeItem` en vez de llamarlo:
     * ese servicio recibe una `CategoriaItem` —que estos ítems no
     * tienen— y devuelve UN código por consulta. Acá se piden 87
     * seguidos, así que el máximo se lee una vez por prefijo y el resto
     * se cuenta en memoria.
     *
     * ⚠️ `DB::table` y no el modelo: cuenta también los borrados. Un
     * código retirado NO se reutiliza —aparece en facturas viejas— y dos
     * ítems distintos con el mismo código a diez años de distancia es
     * exactamente lo que hace que una auditoría no cierre.
     */
    private function codigoLibre(string $prefijo): string
    {
        if (! isset($this->numeracion[$prefijo])) {
            $this->numeracion[$prefijo] = $this->ultimoDe($prefijo);
        }

        [$ultimo, $ancho] = $this->numeracion[$prefijo];
        $this->numeracion[$prefijo] = [$ultimo + 1, $ancho];

        return $prefijo.'-'.str_pad((string) ($ultimo + 1), $ancho, '0', STR_PAD_LEFT);
    }

    /**
     * El número más alto ya usado con ese prefijo, y con cuántos dígitos
     * se escribió.
     *
     * Se filtra por LIKE en la base y se valida el patrón en PHP, igual
     * que en `AsignadorDeCodigoDeItem`: son decenas de filas por
     * prefijo, no miles.
     *
     * @return array{0: int, 1: int}
     */
    private function ultimoDe(string $prefijo): array
    {
        /** @var array<int, string> $codigos */
        $codigos = DB::table('items')
            ->where('codigo', 'like', $prefijo.'-%')
            ->pluck('codigo')
            ->all();

        $patron = '/^'.preg_quote($prefijo, '/').'-(\d+)$/';

        $mayor = 0;
        $ancho = self::ANCHO_POR_DEFECTO;

        foreach ($codigos as $codigo) {
            if (preg_match($patron, $codigo, $partes) !== 1) {
                continue;
            }

            $numero = (int) $partes[1];

            if ($numero >= $mayor) {
                $mayor = $numero;
                $ancho = mb_strlen($partes[1]);
            }
        }

        return [$mayor, $ancho];
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
     * El precio para quien paga de su bolsillo: el del Militar más 20 %.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 SE PISA EL QUE HABÍA, Y ESO ES LO CORRECTO
     * ─────────────────────────────────────────────────────────────────
     *
     * La lista la había armado `CatalogoPaligSeeder` como PALIG + 20 %,
     * porque ese era el único documento que existía. Este es más nuevo
     * —se llama «Propuesta Actualizada»— y trae precios que en varios
     * renglones son más altos que aquellos: alimentación por día iba a
     * L 360 de lista y el Militar paga L 500.
     *
     * Con la lista vieja, el particular pagaba MENOS que la aseguradora.
     * Es al revés de como tiene que ser, y no es un detalle contable: es
     * el hospital vendiéndole más barato al que no tiene seguro que al
     * que sí, sobre el mismo servicio y el mismo día.
     *
     * Así que la lista se recalcula sobre el documento nuevo. El precio
     * de PALIG NO se toca: ese es lo que PALIG negoció y sigue siendo lo
     * que PALIG paga.
     *
     * @param numeric-string $precio
     */
    private function precioDeLista(Item $item, string $precio): void
    {
        Tarifario::query()->updateOrCreate(
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
