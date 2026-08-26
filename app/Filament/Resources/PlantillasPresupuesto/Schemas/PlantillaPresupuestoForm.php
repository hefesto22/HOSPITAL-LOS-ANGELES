<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlantillasPresupuesto\Schemas;

use App\Filament\Schemas\Components\CampoMayusculas;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE
 * (`$get`, `$set`, `$state`, `$record`). Un parámetro con otro nombre
 * recibe un objeto vacío del contenedor y falla EN SILENCIO.
 */
class PlantillaPresupuestoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Qué cirugía es')
                ->columns(2)
                ->schema([
                    CampoMayusculas::make('codigo')
                        ->label('Código')
                        ->required()
                        ->maxLength(30)
                        ->unique(ignoreRecord: true)
                        ->helperText('Como CX-APENDICE. Es interno: sirve para encontrarla, no sale impreso.'),

                    CampoMayusculas::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150)
                        ->helperText('Como se le va a decir a la familia: APENDICECTOMIA.'),

                    Textarea::make('descripcion')
                        ->label('Descripción')
                        ->maxLength(300)
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Opcional. Qué caso cubre esta plantilla y qué da por supuesto — «sin complicaciones, tres días de estancia».'),
                ]),

            Section::make('Cómo se cotiza con ella')
                ->columns(3)
                ->schema([
                    TextInput::make('dias_vigencia')
                        ->label('Días que vale el presupuesto')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(365)
                        ->default(15)
                        ->required()
                        ->helperText('Vencido se recotiza. Una electiva aguanta 30 días; una urgencia, tres.'),

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
                        ->helperText('El colchón. Sale como una LÍNEA VISIBLE del presupuesto, no repartido dentro de los precios.'),

                    /*
                     * «No más de cuánto» debería costar esta cirugía.
                     * NO es un precio: los precios salen del tarifario.
                     * Es el criterio del hospital, para atrapar la
                     * cotización que se fue de rango ANTES de que se
                     * imprima y la familia la firme.
                     */
                    TextInput::make('tope_referencia')
                        ->label('No más de')
                        ->numeric()
                        ->minValue(1)
                        ->prefix('L')
                        ->helperText('Lo que esta cirugía NO debería pasar. Al cotizar avisa si se excede, pero deja emitir: un caso puede costar más de verdad. Vacío = no compara.'),

                    DatePicker::make('vigencia_desde')
                        ->label('Vigente desde')
                        ->required()
                        ->default(now()->toDateString()),

                    DatePicker::make('vigencia_hasta')
                        ->label('Vigente hasta')
                        ->afterOrEqual('vigencia_desde')
                        ->helperText('Vacío mientras se siga usando. Retirarla es ponerle fecha acá — nunca borrarla.'),
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
