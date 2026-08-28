<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Schemas;

use App\Filament\Schemas\Components\CampoMayusculas;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * El formulario de una plantilla quirúrgica.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES SECCIONES APILADAS, Y NO DOS TARJETAS LADO A LADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Las dos columnas se veían mal por una razón concreta: tenían alturas
 * muy distintas —tres campos a la izquierda, cinco con párrafos a la
 * derecha— así que media pantalla quedaba vacía y la vista no sabía
 * dónde caer. Y peor: mezclaban dos preguntas que no se contestan al
 * mismo tiempo. «Qué cirugía es» se llena una vez; «cuánto vale el
 * presupuesto» se revisa cada tanto.
 *
 * Ahora son tres bloques a lo ancho, cada uno con una sola pregunta:
 * QUÉ CIRUGÍA ES · CÓMO SE COTIZA · HASTA CUÁNDO VALE.
 *
 * ⚠️ Y las explicaciones largas se mudaron al encabezado de cada
 * sección. Un párrafo debajo de cada campo hacía que la forma pareciera
 * un examen: el ojo tiene que poder saltar de campo en campo, y el que
 * necesita el porqué lo encuentra arriba, una sola vez.
 *
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE
 * (`$get`, `$set`, `$state`, `$record`). Un parámetro con otro nombre
 * recibe un objeto vacío del contenedor y falla EN SILENCIO.
 */
class PlantillaPresupuestoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Qué cirugía es')
                    ->description('El nombre es lo que la familia va a leer en el presupuesto impreso.')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->columns(1)
                    ->schema([
                        CampoMayusculas::make('nombre')
                            ->label('Nombre de la cirugía')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('APENDICECTOMIA'),

                        Textarea::make('descripcion')
                            ->label('Qué cubre')
                            ->maxLength(300)
                            ->rows(2)
                            ->placeholder('Sin complicaciones, tres días de estancia.')
                            ->helperText('Opcional. Se lee cuando alguien duda de si esta plantilla aplica al caso.'),

                        /*
                         * ─────────────────────────────────────────────
                         * EL CÓDIGO NO SE PIDE
                         * ─────────────────────────────────────────────
                         *
                         * Lo arma el sistema a partir del nombre
                         * —APENDICECTOMIA da CX-APENDICE— y se asigna al
                         * GUARDAR, no mientras se escribe: dos personas
                         * cargando a la vez verían el mismo código
                         * propuesto y la segunda chocaría contra el
                         * índice único al grabar.
                         *
                         * En edición sí se muestra, y apagado: es lo que
                         * la gente se dicta —«usá la CX-CESAREA»— y
                         * cambiarlo dejaría huérfanos los presupuestos
                         * que ya se emitieron con ella.
                         */
                        CampoMayusculas::make('codigo')
                            ->label('Código')
                            ->maxLength(30)
                            ->hiddenOn('create')
                            ->disabledOn('edit')
                            ->unique(ignoreRecord: true)
                            ->helperText('Lo asigna el sistema a partir del nombre. No se cambia.'),
                    ]),

                Section::make('Cómo se cotiza con ella')
                    ->description(
                        'La holgura sale como una línea VISIBLE del presupuesto, no repartida dentro de los '
                        .'precios. El tope no es un precio —esos salen del tarifario—: es el criterio del '
                        .'hospital para atrapar la cotización que se fue de rango antes de que la familia la firme.'
                    )
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(3)
                    ->schema([
                        TextInput::make('dias_vigencia')
                            ->label('Días que vale')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(15)
                            ->required()
                            ->suffix('días')
                            ->helperText('Vencido se recotiza.'),

                        /*
                         * ⚠️ ACÁ VIVE EL CONVERSOR QUE YA MORDIÓ DOS VECES EN
                         * ESTE PROYECTO.
                         *
                         * La base guarda una FRACCIÓN (0.1000). El formulario
                         * habla en PORCENTAJE (10), porque nadie escribe
                         * «0.10» cuando piensa «diez por ciento».
                         *
                         * Las dos conversiones tienen que ser inversas exactas
                         * y usar bcmath, NO float: con `(float) * 100` un
                         * 0.0700 vuelve como 7.000000001 y el `maxValue` lo
                         * rechaza sin explicar por qué.
                         */
                        TextInput::make('holgura_fraccion')
                            ->label('Holgura')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(50)
                            ->default(0)
                            ->formatStateUsing(
                                fn (mixed $state): string => bcmul(self::comoNumero($state), '100', 2)
                            )
                            ->dehydrateStateUsing(
                                fn (mixed $state): string => bcdiv(self::comoNumero($state), '100', 4)
                            )
                            ->helperText('El colchón para lo imprevisto.'),

                        TextInput::make('tope_referencia')
                            ->label('No debería pasar de')
                            ->numeric()
                            ->minValue(1)
                            ->prefix('L')
                            ->helperText('Vacío = no compara. Avisa, pero deja emitir.'),
                    ]),

                Section::make('Hasta cuándo se usa esta plantilla')
                    ->description(
                        'Una plantilla no se borra nunca: se retira poniéndole fecha de fin. Los presupuestos '
                        .'que salieron con ella tienen que seguir explicándose.'
                    )
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(3)
                    ->schema([
                        DatePicker::make('vigencia_desde')
                            ->label('Vigente desde')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now()->toDateString()),

                        DatePicker::make('vigencia_hasta')
                            ->label('Vigente hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('vigencia_desde')
                            ->placeholder('Sigue en uso')
                            ->helperText('Vacío mientras se siga usando.'),
                    ]),
            ]);
    }

    /**
     * `is_scalar()` NO le prueba a PHPStan que el string sea numérico, y
     * `bcmul()` exige `numeric-string`. `is_numeric()` sí lo estrecha.
     *
     * No es purismo del analizador: `bcmul('abc', '100')` lanza
     * `ValueError` en PHP 8, y acá el estado llega de un input que el
     * usuario puede dejar vacío.
     *
     * ⚠️ NO confundir con `App\Support\NumeroDeFormulario`: ese devuelve
     * null cuando no entiende y se usa donde el cero es un dato. Acá una
     * holgura vacía ES cero, que es lo correcto.
     *
     * @return numeric-string
     */
    private static function comoNumero(mixed $estado): string
    {
        return is_numeric($estado) ? (string) $estado : '0';
    }
}
