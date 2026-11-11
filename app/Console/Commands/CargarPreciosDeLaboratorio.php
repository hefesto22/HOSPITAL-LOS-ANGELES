<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exceptions\PrecioNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\AjustadorDeBaseDePrecios;
use App\Services\PreciosDeLaFicha;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * El precio de lista del laboratorio, de las listas que el hospital pasó
 * el 11-nov-2026 («Laboratorios.xlsx» y «Laboratorios Externos.xlsx»).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UN COMANDO Y NO UN SEEDER
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se corre en pruebas Y en producción, donde ya hay facturas. Por eso:
 *
 *   · `--revisar` dice qué cambiaría —código, examen, antes y ahora— sin
 *     escribir nada. En producción se corre primero así.
 *   · Escribe por `AjustadorDeBaseDePrecios`, la misma puerta que
 *     «Bases de precios» cuando se teclea un precio y Enter: si el precio
 *     vigente es de otro día, se CIERRA y se abre uno desde hoy (ADR-0003);
 *     la factura de ayer se sigue explicando con el de ayer.
 *   · No crea exámenes. Busca cada uno por su NOMBRE en el catálogo (código
 *     LAB- o LABEXT-) y si no lo encuentra lo dice al final; inventar un
 *     ítem en producción a partir de un nombre de Excel es como nacen los
 *     duplicados.
 *   · Lo que ya tiene ese mismo precio no se toca: correrlo dos veces no
 *     abre filas de más.
 *   · Todo en UNA transacción: si algo revienta a la mitad, no queda
 *     media lista cargada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ TRAEN LAS HOJAS
 * ─────────────────────────────────────────────────────────────────────
 *
 *   · «LABORATORIO» (48): lo que procesa el laboratorio del hospital. Son
 *     los mismos 48 de PALIG, al mismo precio que la lista de septiembre.
 *   · «LABORATORIO EXTERNOS» (48): esos mismos, mandados afuera
 *     —«HEMOGRAMA (LAB. EXTERNO)»—. Doce cambian contra septiembre
 *     (ALBUMINA 450 → 300, PCR ULTRASENSIBLE 1,900 → 350, DROGAS
 *     1,500 → 500…). ALCOHOLEMIA EN ORINA viene sin precio: el externo no
 *     se toca.
 *   · «OTROS LABORATORIOS» (86): solo se hacen afuera. Igual que en
 *     septiembre salvo dos: HEPATITIS C (L 500) aparece por primera vez,
 *     y ELECTROLITOS EN ORINA confirma los L 6,000 del Militar (septiembre
 *     decía 750).
 *
 * Los nombres se escriben como están en el catálogo, no como en la hoja
 * («CRETININA» → CREATININA, «BUN/UREA» → BUN / UREA…); el comentario de
 * cada renglón dice cómo venía.
 *
 * ⚠️ Cinco exámenes del laboratorio del hospital NO vienen en la hoja y
 * siguen como estén: ANTIGENOS FEBRILES, HELICOBACTER PYLORI EN HECES,
 * ANTICUERPO HEPATITIS B, TARJETA DE SALUD y RECARGO EXAMEN DE
 * LABORATORIO. Tampoco ALCOHOLEMIA EN SANGRE (externo).
 *
 * El precio va SIN ISV: laboratorio clínico es exento (Art. 15 inciso d).
 */
class CargarPreciosDeLaboratorio extends Command
{
    protected $signature = 'precios:laboratorio {--revisar : Muestra lo que cambiaría, sin escribir nada}';

    protected $description = 'Carga el precio de lista del laboratorio del hospital y del externo (listas del 11-nov-2026).';

    private const SUFIJO_EXTERNO = ' (LAB. EXTERNO)';

    private const MOTIVO = 'Lista de precios de laboratorio del hospital (11-nov-2026)';

    /**
     * Los 48 básicos: nombre en el catálogo, precio en el laboratorio del
     * hospital y precio mandándolo afuera (`null` si la hoja no lo trae).
     * El comentario es el nombre como viene en la hoja, cuando difiere.
     *
     * @var list<array{0: string, 1: numeric-string, 2: numeric-string|null}>
     */
    private const BASICOS = [
        ['ACIDO URICO', '160.00', '175.00'],
        ['ALBUMINA', '150.00', '300.00'],
        ['ALCOHOLEMIA EN ORINA', '250.00', null], // la hoja de externos lo trae sin precio: el externo no se toca
        ['AMILASA', '300.00', '500.00'],
        ['BILIRRUBINA', '300.00', '350.00'],
        ['BUN / UREA', '150.00', '175.00'], // «BUN/UREA»
        ['CALCIO', '170.00', '350.00'],
        ['CKMB-P', '500.00', '900.00'],
        ['COLESTEROL HDL', '160.00', '175.00'],
        ['COLESTEROL LDL', '160.00', '175.00'],
        ['COLESTEROL TOTAL', '135.00', '175.00'],
        ['COMBO INFLUENZA MAS COVID 19', '800.00', '1000.00'], // «COMBO INFLUENZA + COVID 19»
        ['CPK', '360.00', '500.00'],
        ['CREATININA', '150.00', '175.00'], // «CRETININA»
        ['DENGUE ANTICUERPO', '425.00', '800.00'],
        ['DENGUE ANTIGENO', '425.00', '800.00'],
        ['DIMERO D', '850.00', '1300.00'],
        ['DROGAS', '500.00', '500.00'],
        ['ELECTROLITOS', '450.00', '600.00'],
        ['EMBARAZO', '125.00', '150.00'],
        ['FERRITINA', '850.00', '1300.00'],
        ['FOSFATASA ALCALINA', '500.00', '600.00'],
        ['GENERAL DE HECES', '50.00', '80.00'],
        ['GENERAL DE ORINA', '50.00', '80.00'],
        ['GLUCOSA', '120.00', '175.00'],
        ['GLUCOSA AL AZAR', '120.00', '175.00'],
        ['GLUCOSA POST PRANDIAL', '120.00', '175.00'], // «GLUCOSA POST PANDRIAL»
        ['HEMOGRAMA', '130.00', '175.00'],
        ['HEMOGLOBINA GLICOSILADA', '400.00', '600.00'],
        ['LDH', '200.00', '350.00'],
        ['LIPASA', '300.00', '900.00'],
        ['PCR', '260.00', '300.00'],
        ['PCR ULTRASENSIBLE', '350.00', '350.00'],
        ['PROBNP', '1100.00', '1800.00'],
        ['PROCALCITONINA', '1200.00', '1500.00'],
        ['PROTEINAS TOTALES', '150.00', '200.00'],
        ['PSA', '450.00', '1000.00'],
        ['RPR', '50.00', '120.00'],
        ['SANGRE OCULTA EN HECES', '100.00', '190.00'],
        ['T3 TOTAL', '475.00', '475.00'],
        ['T4 TOTAL', '475.00', '475.00'],
        ['TGO', '140.00', '175.00'],
        ['TGP', '140.00', '175.00'],
        ['TIPO Y RH', '175.00', '175.00'],
        ['TRIGLICERIDOS', '150.00', '175.00'],
        ['TSH TOTAL', '475.00', '650.00'],
        ['VES', '60.00', '150.00'],
        ['WRIGHT', '100.00', '160.00'],
    ];

    /**
     * Los 86 de la hoja «OTROS LABORATORIOS»: solo se procesan afuera. Los
     * tres que también se hacen adentro llevan el sufijo del externo.
     *
     * @var list<array{0: string, 1: numeric-string}>
     */
    private const SOLO_AFUERA = [
        ['TIEMPO DE SANGRADO', '150.00'],
        ['TIEMPO DE COAGULACION', '150.00'],
        ['TIEMPO DE PROTOMBINA', '210.00'],
        ['TIEMPO PARCIAL DE TROMBOPLASTINA', '210.00'], // «TIEMPO PARCIAL DE»
        ['FROTIS DE SANGRE PERIFERICA', '350.00'],
        ['RECUENTO DE PLAQUETAS EN FROTIS', '300.00'],
        ['ANTI PEPTIDO CICLICO', '2100.00'],
        ['CURVA DE TOLERANCIA', '700.00'],
        ['TESTOSTERONA', '800.00'],
        ['ANTIGENOS FEBRILES (LAB. EXTERNO)', '700.00'], // «ANTIGENOS FEBRILES»
        ['HELICOBACTER PYLORI EN HECES (LAB. EXTERNO)', '650.00'], // «HELICOBACTER PYLORI EN HECES»
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
        ['ANTICUERPO HEPATITIS B (LAB. EXTERNO)', '450.00'], // «AC. HEPATITIS B»
        ['HEPATITIS C', '500.00'], // nueva en la lista: el Militar ya la tenía; la de septiembre traía ALCOHOLEMIA EN SANGRE en su lugar
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
        ['ELECTROLITOS EN ORINA', '6000.00'], // la lista de septiembre decía 750; esta confirma los 6,000 del Militar
        ['HORMONA LUTEINIZANTE', '1200.00'],
        ['CA 19-9', '1600.00'],
        ['ALFA FETO PROTEINA', '1100.00'],
        ['CA 15-3', '1100.00'],
        ['CA 125', '1100.00'],
        ['MICROALBUMINA ORINA AL AZAR', '480.00'],
        ['TOXOPLASMOSIS', '1400.00'],
    ];

    public function handle(AjustadorDeBaseDePrecios $ajustador): int
    {
        $revisar = (bool) $this->option('revisar');
        $hoy = now()->startOfDay();

        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $cambios */
        $cambios = [];
        $iguales = 0;

        /** @var list<string> $faltan */
        $faltan = [];

        /** @var list<string> $errores */
        $errores = [];

        DB::transaction(function () use ($ajustador, $revisar, $hoy, &$cambios, &$iguales, &$faltan, &$errores): void {
            foreach ($this->precios() as [$nombre, $precio, $modalidad]) {
                $item = $this->item($nombre);

                if (! $item instanceof Item) {
                    $faltan[] = $nombre;

                    continue;
                }

                $nuevo = Decimal::de($precio);
                $vigente = $this->precioDeListaVigente($item, $hoy);

                if ($vigente instanceof Tarifario
                    && ! self::esCentinela($vigente)
                    && Decimal::de((string) $vigente->precio)->igualA($nuevo)) {
                    $iguales++;

                    continue;
                }

                $antes = match (true) {
                    ! $vigente instanceof Tarifario => 'sin precio',
                    self::esCentinela($vigente)     => 'pendiente',
                    default                         => self::lempiras((string) $vigente->precio),
                };

                if (! $revisar) {
                    try {
                        $ajustador->ajustar(
                            item: $item,
                            convenio: null,
                            precio: Monto::de($nuevo->redondeado(4)),
                            motivo: self::MOTIVO.' — '.$modalidad.'.',
                        );
                    } catch (PrecioNoFijableException $e) {
                        $errores[] = $item->codigo.' '.$item->nombre.': '.$e->getMessage();

                        continue;
                    }
                }

                $cambios[] = [$item->codigo, $item->nombre, $antes, self::lempiras($nuevo->redondeado(2))];
            }
        });

        if ($cambios !== []) {
            $this->table(['Código', 'Examen', 'Antes', 'Ahora'], $cambios);
        }

        $cuantos = count($cambios);

        $this->info($revisar
            ? "{$cuantos} precio(s) cambiarían. No se escribió nada: corré sin --revisar para cargarlos."
            : "✓ {$cuantos} precio(s) de lista de laboratorio cargados.");
        $this->line("{$iguales} ya tenían ese mismo precio y no se tocaron.");

        if ($faltan !== []) {
            $this->warn(count($faltan).' examen(es) de la lista no están en el catálogo con ese nombre; no se cargaron:');

            foreach ($faltan as $nombre) {
                $this->line('  · '.$nombre);
            }
        }

        foreach ($errores as $error) {
            $this->error($error);
        }

        return $errores === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * La lista aplanada: un renglón por precio, en el orden de las hojas.
     *
     * @return list<array{0: string, 1: numeric-string, 2: string}>
     */
    private function precios(): array
    {
        $precios = [];

        foreach ($this->basicos() as [$nombre, $interno, $externo]) {
            $precios[] = [$nombre, $interno, 'laboratorio del hospital'];

            if ($externo !== null) {
                $precios[] = [$nombre.self::SUFIJO_EXTERNO, $externo, 'laboratorio externo'];
            }
        }

        foreach (self::SOLO_AFUERA as [$nombre, $precio]) {
            $precios[] = [$nombre, $precio, 'laboratorio externo'];
        }

        return $precios;
    }

    /**
     * Tipado como lo que es: el externo puede faltar. PHPStan lee la
     * constante por su valor literal y no por su `@var` (ver
     * `CatalogoLaboratorioHlaSeeder::examenes()`).
     *
     * @return list<array{0: string, 1: numeric-string, 2: numeric-string|null}>
     */
    private function basicos(): array
    {
        return self::BASICOS;
    }

    /**
     * El examen por su nombre, entre los de laboratorio (LAB- y LABEXT-).
     */
    private function item(string $nombre): ?Item
    {
        $item = Item::query()
            ->where('nombre', $nombre)
            ->where('codigo', 'like', 'LAB%')
            ->orderBy('id')
            ->first();

        return $item instanceof Item ? $item : null;
    }

    /**
     * El precio de lista de hoy: sin pagador, sin sede y sin envase.
     */
    private function precioDeListaVigente(Item $item, CarbonInterface $hoy): ?Tarifario
    {
        $fila = Tarifario::query()
            ->where('item_id', $item->id)
            ->whereNull('convenio_id')
            ->whereNull('sede_id')
            ->whereNull('item_presentacion_id')
            ->vigentesEn($hoy)
            ->first();

        return $fila instanceof Tarifario ? $fila : null;
    }

    /**
     * El L 10 de relleno (`ListaPendiente`) no es un precio: siempre se
     * reemplaza, aunque la lista dijera 10.
     */
    private static function esCentinela(Tarifario $fila): bool
    {
        return str_starts_with(mb_strtoupper(trim((string) $fila->motivo)), PreciosDeLaFicha::MARCA_PENDIENTE);
    }

    private static function lempiras(string $monto): string
    {
        return 'L '.number_format((float) $monto, 2);
    }
}
