<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Schemas;

use App\Domain\Enums\EstadoCuenta;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\PlantillaPresupuesto;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Formulario de creación del presupuesto.
 *
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE
 * (`$get`, `$set`, `$state`, `$record`). Con otro nombre reciben un
 * objeto vacío del contenedor y fallan EN SILENCIO.
 *
 * Lo que se llena acá es el ENCABEZADO. Los renglones los arma
 * `CotizadorDePresupuesto` al guardar, y después se editan uno por uno
 * en el panel de abajo.
 */
class PresupuestoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('A quién se le cotiza')
                ->columns(2)
                ->schema([
                    Select::make('expediente_id')
                        ->label('Paciente')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Expediente::query()
                            ->with('persona')
                            ->where(function (Builder $sub) use ($search): void {
                                $sub->where('numero', 'ilike', "%{$search}%")
                                    ->orWhereHas(
                                        'persona',
                                        fn (Builder $p): Builder => $p->where('nombre_busqueda', 'ilike', "%{$search}%")
                                    );
                            })
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Expediente $e): array => [
                                $e->id => $e->numero.' — '.($e->persona?->nombreCompleto() ?? 'SIN NOMBRE'),
                            ])
                            ->all())
                        ->getOptionLabelUsing(function (mixed $value): ?string {
                            $expediente = Expediente::query()->with('persona')->find($value);

                            if (! $expediente instanceof Expediente) {
                                return null;
                            }

                            return $expediente->numero.' — '.($expediente->persona?->nombreCompleto() ?? 'SIN NOMBRE');
                        })
                        /*
                         * 🔴 SI EL PACIENTE YA TIENE CUENTA ABIERTA, EL
                         * INGRESO Y EL PAGADOR SE TOMAN DE AHÍ.
                         *
                         * No es comodidad: es el caso del §1.5. Si se
                         * cotiza bajo CONTADO mientras la cuenta está
                         * abierta con PALIG, los precios del papel salen
                         * de otro tarifario y nadie lo nota hasta que la
                         * familia compara.
                         */
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('encuentro_id', null);

                            if (! is_numeric($state)) {
                                return;
                            }

                            $cuenta = Cuenta::query()
                                ->where('estado', EstadoCuenta::Abierta->value)
                                ->whereHas(
                                    'encuentro',
                                    fn (Builder $e): Builder => $e->where('expediente_id', (int) $state)
                                )
                                ->with('encuentro:id,numero')
                                ->first();

                            if (! $cuenta instanceof Cuenta) {
                                return;
                            }

                            $set('encuentro_id', $cuenta->encuentro_id);
                            $set('convenio_id', $cuenta->convenio_id);

                            Notification::make()
                                ->info()
                                ->title("Tomado de la cuenta {$cuenta->numero}")
                                ->body('El ingreso y el pagador se llenaron con los de la cuenta abierta. Podés cambiarlos si este presupuesto es para otra cosa.')
                                ->send();
                        })
                        ->live()
                        ->helperText('Buscá por número de expediente o por nombre. Tiene que estar registrado: cotizarle a alguien sin identificar es como se pierde el rastro de a quién se le prometió qué.'),

                    Select::make('convenio_id')
                        ->label('Pagador')
                        ->required()
                        ->relationship('convenio', 'nombre')
                        ->searchable()
                        ->preload()
                        ->default(fn (): ?int => Convenio::query()->where('codigo', 'CONTADO')->value('id'))
                        ->helperText('Con qué tarifario se cotiza. Si el caso es PALIG, salen los precios de PALIG.'),

                    /*
                     * Nullable a propósito: mucha gente llega solo a
                     * preguntar cuánto le sale y todavía no ha ingresado.
                     * Se amarra después, al abrir la cuenta.
                     */
                    Select::make('encuentro_id')
                        ->label('Ingreso (opcional)')
                        ->searchable()
                        ->options(function (Get $get): array {
                            $expediente = $get('expediente_id');

                            if (! is_numeric($expediente)) {
                                return [];
                            }

                            return Encuentro::query()
                                ->vivos()
                                ->where('expediente_id', (int) $expediente)
                                ->pluck('numero', 'id')
                                ->all();
                        })
                        ->helperText('Solo si el paciente ya ingresó. Sin ingreso el presupuesto igual se cotiza — el medidor empieza a correr cuando se amarre.'),
                ]),

            Section::make('Qué se cotiza')
                ->columns(2)
                ->schema([
                    Select::make('plantilla_id')
                        ->label('Plantilla')
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => PlantillaPresupuesto::query()
                            ->vigentesEn(now())
                            ->orderBy('nombre')
                            ->get()
                            ->mapWithKeys(fn (PlantillaPresupuesto $p): array => [$p->id => $p->etiqueta()])
                            ->all())
                        ->live()
                        /*
                         * ⚠️ La plantilla PROPONE el título; no lo impone.
                         * Solo lo escribe si el campo está vacío: pisarlo
                         * siempre no dejaba escribir «APENDICECTOMIA
                         * COMPLICADA» ni «CESAREA + SALPINGECTOMIA», que
                         * es justo cuando el título importa.
                         */
                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                            if (! is_numeric($state)) {
                                return;
                            }

                            $titulo = $get('titulo');

                            if (is_string($titulo) && trim($titulo) !== '') {
                                return;
                            }

                            $plantilla = PlantillaPresupuesto::query()->find((int) $state);

                            if ($plantilla instanceof PlantillaPresupuesto) {
                                $set('titulo', $plantilla->nombre);
                            }
                        })
                        ->helperText('Trae los renglones típicos ya cotizados. Después se ajustan uno por uno. Sin plantilla, el presupuesto nace vacío y se arma a mano.'),

                    CampoMayusculas::make('titulo')
                        ->label('Título')
                        ->required()
                        ->maxLength(150)
                        ->helperText('Lo que va a decir el papel: APENDICECTOMIA.'),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->maxLength(500)
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Lo que conviene dejar por escrito: qué NO incluye, qué se acordó con la familia.'),
                ]),
        ]);
    }
}
