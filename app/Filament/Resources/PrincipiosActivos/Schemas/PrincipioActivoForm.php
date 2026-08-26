<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrincipiosActivos\Schemas;

use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\PrincipioActivo;
use App\Support\CodigoDeBarras;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class PrincipioActivoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                /*
                 * El correlativo se propone en el FORMULARIO y no en la
                 * pantalla que lo abre. Este formulario se usa por dos
                 * puertas —el listado de principios y el «+» del selector
                 * en la ficha del producto— y una propuesta que viva en
                 * una de las dos deja a la otra pidiendo un PA-0007
                 * inventado a mano.
                 *
                 * Se propone, no se impone: hay hospitales que ya numeran
                 * su vademécum y pueden pisarlo.
                 *
                 * ⚠️ `default()` solo corre al crear, así que editar un
                 * principio existente no le toca el código.
                 */
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(20)
                    ->default(fn (): string => PrincipioActivo::siguienteCodigo())
                    ->helperText('Se propone solo. Es lo que va codificado en la etiqueta del estante.'),

                CampoMayusculas::make('nombre')
                    ->label('Principio activo')
                    ->required()
                    ->maxLength(255)
                    ->helperText('El nombre canónico: ACETAMINOFÉN, AMOXICILINA, IBUPROFENO.'),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 LOS SINÓNIMOS NO SON UN ADORNO
                 * ─────────────────────────────────────────────────────
                 *
                 * El médico prescribe en el nombre que aprendió, y en
                 * Honduras conviven los dos: acetaminofén y paracetamol
                 * son la misma molécula. Sin este campo, media plantilla
                 * busca algo que el catálogo no sabe que tiene.
                 *
                 * Entra en la búsqueda del principio Y en la del
                 * producto: el texto derivado de `items.principio_activo`
                 * se arma con los nombres y los sinónimos juntos.
                 */
                CampoMayusculas::make('tambien_llamado')
                    ->label('También llamado')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Los otros nombres con los que se prescribe, separados por coma: PARACETAMOL. Se busca por todos.'),

                TextInput::make('codigo_atc')
                    ->label('Código ATC')
                    ->maxLength(10)
                    ->helperText('Clasificación internacional. El acetaminofén es N02BE01.'),

                DatePicker::make('vigencia_desde')
                    ->label('Vigente desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                DatePicker::make('vigencia_hasta')
                    ->label('Vigente hasta')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->afterOrEqual('vigencia_desde')
                    ->helperText('Dejar vacío mientras se siga usando. Acá no se borra: se retira con fecha.'),

                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2)
                    ->columnSpanFull(),

                /*
                 * La etiqueta solo existe cuando el registro ya existe:
                 * antes de guardar no hay código estable que codificar, y
                 * una etiqueta que muestra algo que después cambia es
                 * peor que no mostrar nada.
                 */
                Placeholder::make('etiqueta_de_barras')
                    ->label('Etiqueta del estante')
                    ->visibleOn('edit')
                    ->columnSpanFull()
                    ->content(fn (?PrincipioActivo $record): HtmlString => self::etiqueta($record)),
            ]);
    }

    private static function etiqueta(?PrincipioActivo $record): HtmlString
    {
        if (! $record instanceof PrincipioActivo) {
            return new HtmlString('');
        }

        $svg = CodigoDeBarras::svg($record->codigo, modulo: 2, alto: 60);

        $grande = route('etiquetas.principio', ['principio' => $record->getKey(), 'formato' => 'media']);
        $hoja = route('etiquetas.principio', ['principio' => $record->getKey(), 'formato' => 'hoja']);

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;gap:.5rem;align-items:flex-start">'
            .'<div style="background:#fff;padding:.6rem .8rem;border-radius:.5rem;">'.$svg.'</div>'
            .'<div style="display:flex;gap:1rem;font-size:.8rem">'
            .'<a href="'.$grande.'" target="_blank" style="text-decoration:underline">Etiqueta grande (media A4)</a>'
            .'<a href="'.$hoja.'" target="_blank" style="text-decoration:underline">Hoja de 30 chicas</a>'
            .'</div></div>'
        );
    }
}
