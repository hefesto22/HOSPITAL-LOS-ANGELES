<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\CategoriaItem;
use App\Models\Item;
use App\Models\Unidad;
use Database\Seeders\Support\ListaPendiente;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La lista de laboratorio del hospital — precio de LISTA real, interno y externo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTE SEEDER NO VA EN `DatabaseSeeder`
 * ─────────────────────────────────────────────────────────────────────
 *
 *     php artisan db:seed --class=CatalogoPaligSeeder
 *     php artisan db:seed --class=CatalogoMilitarSeeder
 *     php artisan db:seed --class=CatalogoLaboratorioHlaSeeder
 *     php artisan db:seed --class=CategoriasDelCatalogoSeeder
 *
 * Es idempotente: busca por NOMBRE, solo crea lo que falta y vuelve a
 * escribir el mismo precio de lista si se corre dos veces.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ DICE ESTE DOCUMENTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La fuente es «Servicios Médicos Generales y Lab» (septiembre de 2026),
 * la lista de precios del PROPIO hospital, columna «Precio de Venta».
 * Como `CatalogoRayosXSeeder`, escribe el precio de lista de verdad —lo
 * que paga el particular— y no el centinela de `ListaPendiente`.
 *
 * Trae dos hojas de laboratorio con el mismo examen a dos precios:
 *
 *   · «LABORATORIOS INTERNOS» (HLA-001…052): lo que el laboratorio del
 *     hospital procesa con sus propios equipos.
 *   · «LABORATORIOS EXTERNOS» (LAB-001…138): lo que se manda a un
 *     laboratorio de referencia. Los primeros 52 son los mismos de la
 *     otra hoja, más caros porque el de afuera cobra lo suyo; los 86
 *     restantes solo se procesan afuera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CÓMO SE MODELA: DOS ÍTEMS, NO UN ÍTEM CON DOS PRECIOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El precio en este sistema es función de (ítem, convenio, fecha, sede).
 * «Dónde se procesó la muestra» no es ninguna de esas cuatro cosas, y
 * meterle una quinta dimensión al tarifario por 52 exámenes es pagar
 * una migración para ahorrarse 52 filas. Así que el hospital tiene lo
 * que su propia lista tiene: dos códigos, dos ítems.
 *
 *   · «HEMOGRAMA»                → L 130, el del laboratorio del hospital
 *   · «HEMOGRAMA (LAB. EXTERNO)» → L 175, cuando la muestra se mandó afuera
 *
 * Caja elige el que corresponde al escanear o buscar. El nombre lo dice
 * todo y el reporte de ventas separa solo lo interno de lo externo. Los
 * 86 que solo se hacen afuera quedan con su nombre a secas: no hay con
 * qué confundirlos.
 *
 * Todo lo externo lleva código `LABEXT-` y cae en la categoría
 * LABORATORIO EXTERNO que `CategoriasDelCatalogoSeeder` ya tenía prevista
 * para esto —el margen del hospital en un examen de afuera es la
 * diferencia con lo que cobra el otro laboratorio, y mezclado con lo de
 * adentro no se puede leer—. Los 86 que la propuesta del Militar había
 * creado como `LAB-` se recodifican a `LABEXT-` una sola vez; su código
 * viejo no se reutiliza porque la numeración de `LAB-` se lee ANTES de
 * mover nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LOS PRECIOS INTERNOS COINCIDEN CON LO QUE PAGAN PALIG Y EL MILITAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los 48 exámenes básicos ya estaban en el catálogo por el tarifario de
 * PALIG (`LAB-001`…`LAB-048`) con el mismo número que trae esta lista
 * como precio interno, y los 86 especializados los creó la propuesta del
 * Militar con el número que acá aparece como externo. O sea: el hospital
 * les dio a los dos pagadores su precio de lista sin descuento, y este
 * seeder no hace más que dejar de llamarlo «pendiente».
 *
 * Por eso NO crea los básicos de nuevo: los encuentra por el nombre con
 * que PALIG los cargó —`ALIAS` traduce «NITROGENO DE UREA / BUN» a
 * «BUN / UREA», «CKMP-P» a «CKMB-P», etcétera— y les escribe el precio
 * de lista encima del centinela.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CUATRO RENOMBRES, EXPLÍCITOS Y UNA SOLA VEZ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Tres exámenes que la propuesta del Militar trajo como si solo se
 * hicieran afuera resultan tener versión interna en la lista del
 * hospital: ANTIGENOS FEBRILES, HELICOBACTER PYLORI EN HECES y el
 * anticuerpo de hepatitis B. El ítem que ya existe se queda con el
 * precio del Militar —que es el de afuera— y pasa a llamarse
 * «… (LAB. EXTERNO)»; el interno se crea con el nombre a secas. El
 * cuarto es «TIEMPO PARCIAL DE», que el Militar dejó truncado y acá
 * recupera su nombre completo.
 *
 * Se renombra solo si el ítem todavía tiene el nombre viejo y nadie creó
 * uno con el nuevo: correrlo dos veces no hace nada la segunda. Y
 * `CatalogoMilitarSeeder` ya escribe estos cuatro nombres igual, así que
 * en cualquier orden que se corran los dos, ninguno duplica al otro.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 QUÉ PREGUNTARLE AL HOSPITAL
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · «PRUEBAS DENGUE» viene como UN renglón (L 850 / L 1,000) y PALIG lo
 *     partió en DENGUE ANTICUERPO + DENGUE ANTIGENO a L 425 cada uno. Se
 *     reparte mitad y mitad para no abrir un tercer ítem de dengue; si el
 *     hospital los vende solo juntos, mejor un paquete.
 *   · «ANTIGENOS FEBRILES» aparece dos veces en la hoja de externos
 *     (LAB-052 a L 500 y LAB-062 a L 700). Se tomó L 700, que es lo que
 *     el Militar ya tiene negociado.
 *   · «ELECTROLITOS EN ORINA»: la lista dice L 750; el tarifario del
 *     Militar quedó cargado a L 6,000. Uno de los dos leyó mal la foto.
 *     Acá se escribe la lista; la fila del Militar no se toca.
 *   · «ALCOHOLEMIA EN SANGRE» (LAB-118, L 250) ocupa la posición donde el
 *     seeder del Militar leyó «HEPATITIS C» a L 500. Ese ítem se deja como
 *     está —con centinela— hasta que el hospital diga cuál es.
 *   · «ANTICUERPO HEPATITIS B» cuesta MÁS adentro (L 500) que afuera
 *     (L 450). Puede ser así, o puede ser un dedazo.
 *   · Cuatro exámenes valen lo mismo adentro y afuera (GLUCOSA, BUN / UREA,
 *     TIPO Y RH, ALCOHOLEMIA EN ORINA). Se cargan igual: la modalidad
 *     externa existe aunque no cambie el precio.
 *   · La hoja «MEDICOS GENERALES» trae doce honorarios a L 0.00 y sin
 *     identidad de los médicos. No hay nada que cargar de ahí todavía.
 *
 * ⚠️ El precio se guarda SIN ISV. Laboratorio clínico es exento por el
 * Art. 15 inciso d de la Ley del ISV.
 */
class CatalogoLaboratorioHlaSeeder extends Seeder
{
    /**
     * Desde cuándo rige la lista. Fija y declarada, no `now()`, para que
     * volver a correr el seeder actualice la MISMA fila.
     */
    private const VIGENCIA_DESDE = '2026-09-01';

    /** Lo del laboratorio del hospital continúa la familia de PALIG: LAB-001… */
    private const PREFIJO_INTERNO = 'LAB';

    /** Lo que se manda afuera: familia y categoría propias. Ver `CategoriasDelCatalogoSeeder`. */
    private const PREFIJO_EXTERNO = 'LABEXT';

    private const ANCHO_POR_DEFECTO = 3;

    /** Lo que se le pega al nombre cuando la muestra se manda afuera. */
    private const SUFIJO_EXTERNO = ' (LAB. EXTERNO)';

    /**
     * Cada línea: nombre en el catálogo, precio interno, precio externo.
     * `null` donde el hospital no ofrece esa modalidad. Los comentarios
     * son el código de la hoja y, entre «», el nombre tal como viene
     * cuando difiere del que ya tenía el catálogo.
     *
     * @var list<array{0: string, 1: numeric-string|null, 2: numeric-string|null}>
     */
    private const EXAMENES = [
        // ── Los 52 del laboratorio del hospital: hoja «internos» (HLA-001…052) y su precio si se manda afuera (LAB-001…052) ──
        ['HEMOGRAMA', '130.00', '175.00'], // HLA-001 · LAB-001
        ['GLUCOSA', '120.00', '120.00'], // HLA-002 · LAB-002 «GLUCOSA EN AYUNAS»
        ['GLUCOSA POST PRANDIAL', '120.00', '175.00'], // HLA-003 · LAB-003 «GLUSOCA POSPANDRIAL»
        ['GLUCOSA AL AZAR', '120.00', '175.00'], // HLA-004 · LAB-004
        ['COLESTEROL TOTAL', '135.00', '175.00'], // HLA-005 · LAB-005
        ['COLESTEROL HDL', '160.00', '175.00'], // HLA-006 · LAB-006
        ['COLESTEROL LDL', '160.00', '175.00'], // HLA-007 · LAB-007
        ['TRIGLICERIDOS', '150.00', '175.00'], // HLA-008 · LAB-008
        ['CREATININA', '150.00', '175.00'], // HLA-009 · LAB-009
        ['BUN / UREA', '150.00', '150.00'], // HLA-010 · LAB-010 «NITROGENO DE UREA / BUN»
        ['TGP', '140.00', '175.00'], // HLA-011 · LAB-011 «TRANSAMINASA GLUTAMICO PIVURICA / TGP»
        ['TGO', '140.00', '175.00'], // HLA-012 · LAB-012 «TRANSAMINASA GLUTAMICO OXALACETICA / TGO»
        ['BILIRRUBINA', '300.00', '350.00'], // HLA-013 · LAB-013 «BILIRRUBINAS»
        ['ACIDO URICO', '160.00', '175.00'], // HLA-014 · LAB-014
        ['PROTEINAS TOTALES', '150.00', '200.00'], // HLA-015 · LAB-015
        ['AMILASA', '300.00', '500.00'], // HLA-016 · LAB-016
        ['LIPASA', '300.00', '900.00'], // HLA-017 · LAB-017
        ['CALCIO', '170.00', '350.00'], // HLA-018 · LAB-018
        ['LDH', '200.00', '350.00'], // HLA-019 · LAB-019 «LACTATO DESHIDROGENASA / LDH»
        ['ELECTROLITOS', '450.00', '750.00'], // HLA-020 · LAB-020 / ext. «ELECTROLITOS (SODIO - CLORO - POTASIO)»
        ['CKMB-P', '500.00', '900.00'], // HLA-021 · LAB-021 «CKMP-P»
        ['CPK', '360.00', '500.00'], // HLA-022 · LAB-022 «CPK - CREATININA FOSFOCINASA»
        ['PROBNP', '1100.00', '1800.00'], // HLA-023 · LAB-023
        ['FERRITINA', '850.00', '1300.00'], // HLA-024 · LAB-024
        ['DIMERO D', '850.00', '1300.00'], // HLA-025 · LAB-025
        ['FOSFATASA ALCALINA', '500.00', '600.00'], // HLA-026 · LAB-026
        ['VES', '60.00', '150.00'], // HLA-027 · LAB-027 «VES - VELOCIDAD DE ERITROSEDIMENTACION»
        ['SANGRE OCULTA EN HECES', '100.00', '190.00'], // HLA-028 · LAB-028
        ['DENGUE ANTICUERPO', '425.00', '500.00'], // HLA-029 · LAB-029: la lista trae UNA «PRUEBAS DENGUE» a 850.00 / 1000.00; PALIG la partió en dos, mitad y mitad
        ['DENGUE ANTIGENO', '425.00', '500.00'], // HLA-029 · LAB-029: la otra mitad
        ['T3 TOTAL', '475.00', '700.00'], // HLA-030 · LAB-030 «T3 TOTAL / TRIYODOTIRONINA»
        ['T4 TOTAL', '475.00', '700.00'], // HLA-031 · LAB-031 «T4 TOTAL / TIROXINA»
        ['PSA', '450.00', '1000.00'], // HLA-032 · LAB-032 «PSA / ANTIGENO PROSTATICO ESPECIFICO»
        ['TSH TOTAL', '475.00', '650.00'], // HLA-033 · LAB-033 «TSH / HORMONA ESTIMULANTE DE LA TIROIDES»
        ['PCR', '260.00', '300.00'], // HLA-034 · LAB-034 «PCR / PROTEINA C REACTIVA»
        ['PCR ULTRASENSIBLE', '350.00', '1900.00'], // HLA-035 · LAB-035
        ['RPR', '50.00', '120.00'], // HLA-036 · LAB-036 «RPR / REGINA PLASMATICA RAPIDA»
        ['TARJETA DE SALUD', '80.00', '150.00'], // HLA-037 · LAB-037
        ['EMBARAZO', '125.00', '150.00'], // HLA-038 · LAB-038 «PRUEBA DE EMBARAZO EN SANGRE»
        ['GENERAL DE ORINA', '50.00', '100.00'], // HLA-039 · LAB-039 «EXAMEN GENERAL DE ORINA»
        ['GENERAL DE HECES', '50.00', '100.00'], // HLA-040 · LAB-040 «EXAMEN GENERAL DE HECES»
        ['WRIGHT', '100.00', '160.00'], // HLA-041 · LAB-041
        ['RECARGO EXAMEN DE LABORATORIO', '350.00', '650.00'], // HLA-042 · LAB-042
        ['COMBO INFLUENZA MAS COVID 19', '800.00', '1000.00'], // HLA-043 · LAB-043 «COMBO ANTIGENO INFLUENZA + COVID»
        ['HEMOGLOBINA GLICOSILADA', '400.00', '600.00'], // HLA-044 · LAB-044
        ['TIPO Y RH', '175.00', '175.00'], // HLA-045 · LAB-045 «GRUPO SANGUINEO Y TIPO RH»
        ['PROCALCITONINA', '1200.00', '1500.00'], // HLA-046 · LAB-046
        ['ALBUMINA', '150.00', '450.00'], // HLA-047 · LAB-047
        ['DROGAS', '500.00', '1500.00'], // HLA-048 · LAB-048
        ['ALCOHOLEMIA EN ORINA', '250.00', '250.00'], // HLA-049 · LAB-049
        ['ANTICUERPO HEPATITIS B', '500.00', '450.00'], // HLA-050 · LAB-050
        ['HELICOBACTER PYLORI EN HECES', '600.00', '650.00'], // HLA-051 · LAB-051 «HELICOBACTER EN HECES»
        ['ANTIGENOS FEBRILES', '350.00', '700.00'], // HLA-052 · LAB-052: la hoja trae el externo DOS veces (LAB-052 a 500, LAB-062 a 700); se toma 700, que es lo negociado con el Militar

        // ── Solo se procesan afuera: hoja «externos» (LAB-053…138) ──
        ['TIEMPO DE SANGRADO', null, '150.00'], // LAB-053
        ['TIEMPO DE COAGULACION', null, '150.00'], // LAB-054
        ['TIEMPO DE PROTOMBINA', null, '210.00'], // LAB-055
        ['TIEMPO PARCIAL DE TROMBOPLASTINA', null, '210.00'], // LAB-056 · en el catálogo estaba truncado como «TIEMPO PARCIAL DE»; se renombra
        ['FROTIS DE SANGRE PERIFERICA', null, '350.00'], // LAB-057
        ['RECUENTO DE PLAQUETAS EN FROTIS', null, '300.00'], // LAB-058
        ['ANTI PEPTIDO CICLICO', null, '2100.00'], // LAB-059
        ['CURVA DE TOLERANCIA', null, '700.00'], // LAB-060
        ['TESTOSTERONA', null, '800.00'], // LAB-061
        // LAB-062 «ANTIGENOS FEBRILES» 700.00: duplicado de ANTIGENOS FEBRILES — no se carga dos veces
        // LAB-063 «HELICOBACTER PYLORI EN HECES» 650.00: duplicado de HELICOBACTER PYLORI EN HECES (LAB-051) — no se carga dos veces
        ['CRUCE SANGUINEO', null, '3500.00'], // LAB-064
        ['UROCULTIVO', null, '600.00'], // LAB-065
        ['TRANSFUSION 1 UNIDAD DE SANGRE', null, '8000.00'], // LAB-066
        ['HORMONA T4 LIBRE', null, '700.00'], // LAB-067
        ['LAB MAGNESIO', null, '450.00'], // LAB-068
        ['FACTOR REUMATOIDEO', null, '200.00'], // LAB-069
        ['CULTIVO DE SECRECIONES', null, '600.00'], // LAB-070
        ['VIH ANTICUERPOS', null, '550.00'], // LAB-071
        ['LAB AMONIO', null, '550.00'], // LAB-072
        ['TROPONINAS', null, '1000.00'], // LAB-073
        ['ESTRELTOLISINA ASO', null, '250.00'], // LAB-074
        ['FIBRINOGENO', null, '800.00'], // LAB-075
        ['LAB INR', null, '250.00'], // LAB-076
        ['GASES ARTERIALES', null, '2000.00'], // LAB-077
        ['PROTEINAS 24 HORAS', null, '200.00'], // LAB-078
        ['ANTIGENO CA 125', null, '720.00'], // LAB-079
        ['HORMONA T3 LIBRE', null, '700.00'], // LAB-080
        ['VITAMINA B12', null, '2250.00'], // LAB-081
        ['ACIDO FOLICO', null, '2250.00'], // LAB-082
        ['PANEL RESPIRATORIO', null, '6300.00'], // LAB-083
        ['CULTIVO KOH', null, '500.00'], // LAB-084
        ['PROTEINURIA', null, '175.00'], // LAB-085
        ['PRUEBAS LIQUIDO CEFALORRAQUIDEO', null, '700.00'], // LAB-086
        ['TRANSFUSION UNIDAD DE PLASMA', null, '3500.00'], // LAB-087
        ['CORTISOL', null, '1700.00'], // LAB-088
        ['CITOMEGALOVIRUS', null, '2400.00'], // LAB-089
        ['EPSTEIN BAR VIRUS', null, '2400.00'], // LAB-090
        ['LABORATORIO ADA', null, '2500.00'], // LAB-091
        ['ROTAVIRUS Y ADENOVIRUS', null, '450.00'], // LAB-092
        ['FOSFORO', null, '310.00'], // LAB-093
        ['ALCOHOLEMIA EN SALIVA', null, '175.00'], // LAB-094
        ['COCAINA EN ORINA', null, '175.00'], // LAB-095
        ['MARIHUANA EN ORINA', null, '175.00'], // LAB-096
        ['COPROCULTIVO', null, '600.00'], // LAB-097
        ['HEPATITIS A', null, '350.00'], // LAB-098
        ['EXAMEN SIFILIS', null, '500.00'], // LAB-099
        ['GAMA GLUTAMIL TRANSFERASA', null, '600.00'], // LAB-100
        ['EXAMEN GRAM', null, '240.00'], // LAB-101
        ['PRUEBA ESPECIAL', null, '2100.00'], // LAB-102
        ['ANTIGENOS INFLUENZA', null, '470.00'], // LAB-103
        ['COOMBS DIRECTO E INDIRECTO', null, '310.00'], // LAB-104
        ['HEMOCULTIVO', null, '900.00'], // LAB-105
        ['MICRO ALBUMINURIA', null, '250.00'], // LAB-106
        ['EXAMEN DE LEPTOSPIRA', null, '1500.00'], // LAB-107
        ['CLOSTRIDIUM DIFFICILE GDH', null, '2600.00'], // LAB-108
        ['VITAMINA D', null, '1600.00'], // LAB-109
        ['EXAMEN DE BAAR', null, '270.00'], // LAB-110
        ['COCIENTE ALBUMINA CREATININA', null, '440.00'], // LAB-111
        ['ANTICUERPO HEPATITITS C', null, '450.00'], // LAB-112
        ['INSULINA', null, '650.00'], // LAB-113
        ['RECUENTO DE RETICULOCITOS', null, '400.00'], // LAB-114
        ['NIVELES DE HIERRO', null, '1050.00'], // LAB-115
        ['SUPERFICIE HEPATITIS B', null, '500.00'], // LAB-116
        // LAB-117 «AC. HEPATITIS B» 450.00: AC. HEPATITIS B = ANTICUERPO HEPATITIS B (LAB-050) — no se carga dos veces
        ['ALCOHOLEMIA EN SANGRE', null, '250.00'], // LAB-118 · ⚠️ en esa posición el seeder del Militar leyó «HEPATITIS C» a 500
        ['PROLACTINA', null, '750.00'], // LAB-119
        ['ANTIGENO PROSTATICO LIBRE', null, '1400.00'], // LAB-120
        ['CITOLOGIA DE HECES', null, '750.00'], // LAB-121
        ['% ANTIGENO PROTATICO LIBRE', null, '1400.00'], // LAB-122
        ['HELICOBACTER PILORI EN SANGRE', null, '500.00'], // LAB-123
        ['EXAMEN CHAGAS', null, '650.00'], // LAB-124
        ['EXAMEN ANTI-ESTREPTOLISINA O', null, '300.00'], // LAB-125
        ['CITOQUIMA Y GRAM DE LIQUIDOS', null, '1000.00'], // LAB-126
        ['HORMONA FOLICULOESTIMULANTE', null, '1000.00'], // LAB-127
        ['GLOBULINA FIJADORA DE HORMONAS', null, '1650.00'], // LAB-128
        ['ESTRADIOL', null, '1700.00'], // LAB-129
        ['HORMONA ADENOCORTICOTROPICA', null, '3000.00'], // LAB-130
        ['ELECTROLITOS EN ORINA', null, '750.00'], // LAB-131 · ⚠️ el Militar quedó cargado a 6,000.00; la lista dice 750
        ['HORMONA LUTEINIZANTE', null, '1200.00'], // LAB-132
        ['CA 19-9', null, '1600.00'], // LAB-133
        ['ALFA FETO PROTEINA', null, '1100.00'], // LAB-134
        ['CA 15-3', null, '1100.00'], // LAB-135
        ['CA 125', null, '1100.00'], // LAB-136
        ['MICROALBUMINA ORINA AL AZAR', null, '480.00'], // LAB-137
        ['TOXOPLASMOSIS', null, '1400.00'], // LAB-138
    ];

    /**
     * Ítems que ya existen con otro nombre. Llave: cómo se tienen que
     * llamar; valor: cómo se llaman hoy. Ver el encabezado.
     *
     * @var array<string, string>
     */
    private const RENOMBRAR = [
        'TIEMPO PARCIAL DE TROMBOPLASTINA'                  => 'TIEMPO PARCIAL DE',
        'ANTIGENOS FEBRILES'.self::SUFIJO_EXTERNO           => 'ANTIGENOS FEBRILES',
        'HELICOBACTER PYLORI EN HECES'.self::SUFIJO_EXTERNO => 'HELICOBACTER PYLORI EN HECES',
        'ANTICUERPO HEPATITIS B'.self::SUFIJO_EXTERNO       => 'AC. HEPATITIS B',
    ];

    /**
     * Los dos renglones de la lista que no son un examen sino un servicio.
     *
     * @var list<string>
     */
    private const SERVICIOS = ['RECARGO EXAMEN DE LABORATORIO', 'TARJETA DE SALUD'];

    /**
     * El próximo número y su ancho, por familia. Se llena en la primera
     * consulta de cada prefijo.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $numeracion = [];

    private ?CategoriaItem $categoriaExterna = null;

    private int $creados = 0;

    private int $renombrados = 0;

    private int $recodificados = 0;

    private int $precios = 0;

    public function run(): void
    {
        /*
         * La numeración de LAB- se lee ANTES de recodificar nada: si se
         * leyera después, los números que los externos dejan libres al
         * pasar a LABEXT- se reasignarían a ítems nuevos, y un código
         * retirado no se reutiliza (ver `ultimoDeLaFamilia`).
         */
        $this->numeracion[self::PREFIJO_INTERNO] = $this->ultimoDeLaFamilia(self::PREFIJO_INTERNO);

        $this->renombrar();

        foreach ($this->examenes() as [$nombre, $interno, $externo]) {
            if ($interno !== null) {
                $this->precioDeLista($this->item($nombre, externo: false), $interno, 'laboratorio del hospital');
            }

            if ($externo !== null) {
                /*
                 * Sin versión interna el nombre va a secas; con ella,
                 * el externo lleva el sufijo para que caja distinga.
                 */
                $nombreExterno = $interno === null ? $nombre : $nombre.self::SUFIJO_EXTERNO;

                $this->precioDeLista($this->item($nombreExterno, externo: true), $externo, 'procesado por laboratorio externo');
            }
        }

        $this->command?->info("✓ {$this->precios} precios de lista de laboratorio escritos.");
        $this->command?->comment(
            "  {$this->creados} ítems se crearon; {$this->renombrados} se renombraron; "
            ."{$this->recodificados} pasaron de LAB- a LABEXT-; el resto ya estaba."
        );
        $this->command?->comment('  Es precio de LISTA real —lo que paga el particular—, no el centinela de L 10.');
        $this->command?->warn('  ⚠️ ELECTROLITOS EN ORINA: lista L 750, tarifario del Militar L 6,000. Confirmar con el hospital.');
        $this->command?->warn('  ⚠️ «HEPATITIS C» (del Militar) no aparece en la lista del hospital; quedó con centinela.');
        $this->command?->warn('  ⚠️ PRUEBAS DENGUE se repartió mitad y mitad entre ANTICUERPO y ANTIGENO.');
        $this->command?->warn('  ⚠️ Corré CategoriasDelCatalogoSeeder después si los ítems nuevos quedaron sin categoría.');
    }

    /**
     * La lista, tipada como lo que es: cualquiera de los dos precios puede
     * faltar.
     *
     * PHPStan lee una constante privada por su VALOR literal y no por su
     * `@var`. Como hoy ningún examen viene sin precio externo, concluía
     * que `$externo !== null` siempre es verdadero y la puerta se caía.
     * Pero ese `if` es justamente lo que impide cargarle un precio nulo
     * al día que el hospital agregue un examen que solo se hace adentro:
     * se conserva, y la forma de los datos se declara acá.
     *
     * @return list<array{0: string, 1: numeric-string|null, 2: numeric-string|null}>
     */
    private function examenes(): array
    {
        return self::EXAMENES;
    }

    /**
     * Los renombres del encabezado, antes de tocar cualquier precio.
     *
     * El orden importa: si se buscara «ANTIGENOS FEBRILES» a secas antes
     * de renombrar, se adoptaría el ítem del Militar —que es el de
     * afuera— como si fuera el interno, y le quedaría el precio de
     * adentro con la tarifa del Militar colgando.
     */
    private function renombrar(): void
    {
        foreach (self::RENOMBRAR as $nuevo => $viejo) {
            if (Item::query()->where('nombre', $nuevo)->exists()) {
                continue;
            }

            $item = Item::query()->where('nombre', $viejo)->first();

            if (! $item instanceof Item) {
                continue;
            }

            $item->update(['nombre' => $nuevo]);
            $this->renombrados++;
        }
    }

    /**
     * El ítem: por NOMBRE, y solo se crea si de verdad no está.
     *
     * Lo externo que ya existía con código `LAB-` se lleva a su familia
     * y a su categoría; lo que se crea nace ahí directamente.
     */
    private function item(string $nombre, bool $externo): Item
    {
        $existente = Item::query()->where('nombre', $nombre)->first();

        if ($existente instanceof Item) {
            if ($externo) {
                $this->llevarAlLaboratorioExterno($existente);
            }

            return $existente;
        }

        $this->creados++;

        $esServicio = in_array(
            str_replace(self::SUFIJO_EXTERNO, '', $nombre),
            self::SERVICIOS,
            true,
        );

        /** @var Item $item */
        $item = Item::query()->create(
            [
                'codigo' => $this->codigoLibre($externo ? self::PREFIJO_EXTERNO : self::PREFIJO_INTERNO),
                'nombre' => $nombre,
                'tipo'   => $esServicio ? TipoItem::Servicio : TipoItem::EstudioLaboratorio,

                /*
                 * Exento por el Art. 15 inciso d de la Ley del ISV:
                 * laboratorio clínico es servicio médico.
                 */
                'regimen_isv'               => RegimenIsv::Exento,
                'politica_cargo'            => PoliticaCargo::Cobrable,
                'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
                'unidad_dispensacion_id'    => $this->unidad('UND')->id,
                'requiere_lote'             => false,
                'requiere_receta'           => false,
                'es_controlado'             => false,
                'vigencia_desde'            => self::VIGENCIA_DESDE,
            ],
        );

        if ($externo) {
            $this->clasificarComoExterno($item);
        }

        return $item;
    }

    /**
     * Un externo que la propuesta del Militar creó como `LAB-`: código
     * nuevo en `LABEXT-` y categoría LABORATORIO EXTERNO. Una sola vez;
     * la segunda corrida ya lo encuentra en su familia.
     */
    private function llevarAlLaboratorioExterno(Item $item): void
    {
        if (! str_starts_with($item->codigo, self::PREFIJO_EXTERNO.'-')) {
            $item->update(['codigo' => $this->codigoLibre(self::PREFIJO_EXTERNO)]);
            $this->recodificados++;
        }

        $this->clasificarComoExterno($item);
    }

    /**
     * La categoría se pone acá y no se deja a `CategoriasDelCatalogoSeeder`
     * porque ese solo clasifica lo que NO tiene categoría, y los 86 del
     * Militar ya cayeron en LABORATORIO por su viejo prefijo. Si la
     * categoría todavía no existe, se deja en null y el otro seeder la
     * pone después por el prefijo `LABEXT-`.
     */
    private function clasificarComoExterno(Item $item): void
    {
        $categoria = $this->categoriaExterna ??= CategoriaItem::query()
            ->where('codigo', self::PREFIJO_EXTERNO)
            ->first();

        if (! $categoria instanceof CategoriaItem || $item->categoria_id === $categoria->id) {
            return;
        }

        DB::table('items')
            ->where('id', $item->id)
            ->update([
                'categoria_id'     => $categoria->id,
                'categoria_ambito' => $categoria->ambito->value,
            ]);
    }

    /**
     * El precio de lista de verdad: `convenio_id` nulo, encima del
     * centinela si lo había. Ver `CatalogoRayosXSeeder::precioDeLista`
     * para por qué va por `ListaPendiente` y no por el modelo directo.
     *
     * @param numeric-string $precio
     */
    private function precioDeLista(Item $item, string $precio, string $modalidad): void
    {
        ListaPendiente::precioReal(
            item: $item,
            precio: bcmul($precio, '1', 4),
            motivo: 'Precio de venta de la lista de laboratorio del hospital '
                .'(documento «Servicios Médicos Generales y Lab», septiembre de 2026) — '
                .$modalidad.'.',
            vigenciaDesde: self::VIGENCIA_DESDE,
        );

        $this->precios++;
    }

    /** El siguiente código libre de la familia, pedido y no escrito. */
    private function codigoLibre(string $prefijo): string
    {
        [$ultimo, $ancho] = $this->numeracion[$prefijo] ?? $this->ultimoDeLaFamilia($prefijo);

        $this->numeracion[$prefijo] = [$ultimo + 1, $ancho];

        return $prefijo.'-'.str_pad((string) ($ultimo + 1), $ancho, '0', STR_PAD_LEFT);
    }

    /**
     * El número más alto de la familia y con cuántos dígitos se escribe.
     *
     * `DB::table` y no el modelo: cuenta también los borrados. Un código
     * retirado no se reutiliza.
     *
     * @return array{0: int, 1: int}
     */
    private function ultimoDeLaFamilia(string $prefijo): array
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
