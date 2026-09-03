<?php

declare(strict_types=1);

namespace App\Filament\Resources\Descuentos\Actions;

use App\Domain\Enums\AplicacionDeDescuento;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Descuento;
use App\Services\FijadorDeDescuento;
use App\Support\NumeroDeFormulario;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * «Crear un descuento» — la única forma de meter uno, y de cambiarlo.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESCRIBIR EL MISMO NOMBRE NO ES UN ERROR: ES CAMBIARLE EL PORCENTAJE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Poner «Tercera edad» de nuevo con otro número cierra el anterior el
 * día antes y abre el nuevo desde la fecha que se elija. Los ítems que
 * lo tenían marcado siguen teniéndolo: el motor de cargos lo busca por
 * NOMBRE, no por el número de fila.
 *
 * Por eso no hace falta un botón de editar, y por eso el nombre no se
 * puede corregir después: corregirlo cortaría el hilo con todos los
 * ítems marcados.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE ESCRIBE EN PORCENTAJE, SE GUARDA EN FRACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se escribe «25», la base guarda `0.2500`. La conversión se hace en un
 * solo lugar —acá— y con `Decimal`, no con `/ 100` en punto flotante:
 * 12.5 % dividido en float es 0.125000000000000006, y ese arrastre
 * termina en el precio de cada producto del catálogo (§8.6.2).
 */
final class CrearDescuentoAction
{
    public static function make(): Action
    {
        return Action::make('crearDescuento')
            ->label('Crear un descuento')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Crear un descuento')
            ->modalDescription(
                'Ponele un nombre, el porcentaje y a quién se le aplica. Si el nombre ya existe, '
                .'no se duplica: se cierra el porcentaje anterior el día antes y este arranca en la '
                .'fecha que pongas. Los ítems que ya lo tenían marcado no se tocan.'
            )
            ->modalSubmitActionLabel('Crear')
            ->modalWidth('lg')
            ->schema([
                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->minLength(3)
                    ->maxLength(80)
                    ->placeholder('TERCERA EDAD')
                    ->datalist(fn (): array => self::nombresQueYaExisten())
                    ->helperText(
                        'Es el nombre que va a aparecer en cada ítem y en la factura. '
                        .'Repetir uno que ya existe le cambia el porcentaje; no lo duplica.'
                    ),

                TextInput::make('porcentaje')
                    ->label('Porcentaje')
                    ->suffix('%')
                    ->required()
                    /*
                     * `regex` y no `numeric`: `numeric` acepta "1e3" y
                     * "0x1A", y los dos entran a bcmath como cero.
                     */
                    ->rule('regex:/^\d{1,3}(\.\d{1,2})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta dos decimales: 25, o 12.5.',
                    ])
                    ->helperText('Como en el decreto: 25 para 25 %.'),

                Radio::make('aplica_a')
                    ->label('A quién se le aplica')
                    ->required()
                    ->columnSpanFull()
                    ->options(fn (): array => collect(AplicacionDeDescuento::cases())
                        ->mapWithKeys(fn (AplicacionDeDescuento $a): array => [
                            $a->value => $a->etiquetaConTramo(),
                        ])
                        ->all())
                    ->descriptions(fn (): array => collect(AplicacionDeDescuento::cases())
                        ->mapWithKeys(fn (AplicacionDeDescuento $a): array => [
                            $a->value => $a->descripcion(),
                        ])
                        ->all())
                    ->helperText(
                        'Sin esto el sistema no sabe cuándo cobrarle menos a alguien, y el descuento '
                        .'queda dependiendo de que la cajera se acuerde.'
                    ),

                DatePicker::make('vigencia_desde')
                    ->label('Rige desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText(
                        'La fecha del decreto o de la decisión, no la de hoy. Una factura vieja que '
                        .'se reimprima tiene que salir con el porcentaje que regía ese día.'
                    ),

                Toggle::make('exige_receta')
                    ->label('Exige receta original')
                    ->helperText('Art. 34: para medicamentos, firmada y sellada.'),

                Textarea::make('nota')
                    ->label('Nota')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Opcional. Para dejar dicho quién lo aprobó, o contra qué texto se verificó.'),
            ])
            ->action(function (array $data): void {
                self::crear($data);
            });
    }

    /**
     * Los nombres que ya existen, para que se puedan repetir sin volver
     * a teclearlos: repetir el nombre es la forma de cambiar el
     * porcentaje, y un tilde de diferencia crearía un descuento aparte.
     *
     * @return list<string>
     */
    private static function nombresQueYaExisten(): array
    {
        /** @var list<string> $nombres */
        $nombres = Descuento::query()
            ->distinct()
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();

        return $nombres;
    }

    /**
     * El `DatePicker` devuelve la fecha como texto en unas rutas y como
     * Carbon en otras. Se aceptan las dos en vez de tipar una y confiar.
     */
    private static function fechaDe(mixed $valor): CarbonInterface
    {
        if ($valor instanceof CarbonInterface) {
            return $valor;
        }

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : now();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function crear(array $data): void
    {
        $aplicacion = is_string($data['aplica_a'] ?? null)
            ? AplicacionDeDescuento::tryFrom($data['aplica_a'])
            : null;

        $enPorciento = NumeroDeFormulario::aDecimal($data['porcentaje'] ?? null);

        $nombre = is_string($data['nombre'] ?? null) ? trim($data['nombre']) : '';

        if (! $aplicacion instanceof AplicacionDeDescuento
            || ! $enPorciento instanceof Decimal
            || $nombre === '') {
            Notification::make()
                ->danger()
                ->title('No se pudo leer el formulario')
                ->body('Revisá el nombre, el porcentaje y a quién se le aplica.')
                ->send();

            return;
        }

        try {
            $creado = app(FijadorDeDescuento::class)->fijar(
                nombre: $nombre,
                aplicaA: $aplicacion,
                porcentaje: $enPorciento->entre('100'),
                desde: self::fechaDe($data['vigencia_desde'] ?? null),
                exigeReceta: ($data['exige_receta'] ?? false) === true,
                nota: is_string($data['nota'] ?? null) && $data['nota'] !== '' ? $data['nota'] : null,
            );
        } catch (DescuentoNoFijableException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo crear')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $marcados = $creado->cuantosItemsLoTienen();

        Notification::make()
            ->success()
            ->title($creado->nombre.': '.$creado->comoPorcentaje())
            ->body($marcados === 0
                ? 'Ya se puede marcar en los ítems del catálogo, en la pestaña «ISV y descuentos».'
                : sprintf(
                    'Rige desde el %s. Ya lo tienen marcado %d ítems, y les aplica solo.',
                    $creado->vigencia_desde->format('d/m/Y'),
                    $marcados,
                ))
            ->send();
    }
}
