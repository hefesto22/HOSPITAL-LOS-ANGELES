<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Schemas;

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use App\Models\Ajuste;
use App\Models\Conteo;
use App\Support\UsuarioAutenticado;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * La ficha del conteo: en qué va, qué encontró, y qué pasó al cerrarlo.
 *
 * ⚠️ Las entradas que resumen líneas usan `->state()` con el registro
 * completo y NO notación de punto sobre una relación: sobre una
 * colección, Filament interpreta el punto como una LISTA y renderiza un
 * elemento por valor, sin dar error y sin que ningún test lo note. Es la
 * lección que ya nos costó una tarde en el registro de actividad.
 */
final class ConteoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El conteo')
                ->columns(3)
                ->schema([
                    TextEntry::make('almacen.nombre')
                        ->label('Almacén'),

                    TextEntry::make('estado')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (EstadoConteo $state): string => $state->etiqueta())
                        ->color(fn (EstadoConteo $state): string => $state->color()),

                    TextEntry::make('alcance')
                        ->label('Alcance')
                        ->badge()
                        ->formatStateUsing(fn (AlcanceDeConteo $state): string => $state->etiqueta())
                        ->color(fn (AlcanceDeConteo $state): string => $state->color()),

                    TextEntry::make('descripcion')
                        ->label('¿Por qué se contó?')
                        ->placeholder('sin motivo anotado')
                        ->columnSpan(2),

                    TextEntry::make('tolerancia_recuento')
                        ->label('Tolerancia de recuento')
                        ->numeric(decimalPlaces: 4)
                        ->suffix(' unidades'),

                    TextEntry::make('abierto_en')
                        ->label('Abierto')
                        ->dateTime('d/m/Y H:i'),

                    TextEntry::make('createdBy.name')
                        ->label('Lo abrió')
                        ->placeholder('—'),

                    TextEntry::make('notas')
                        ->label('Notas')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Cómo va')
                ->columns(3)
                ->schema([
                    TextEntry::make('resumen_lineas')
                        ->label('Líneas')
                        ->state(fn (Conteo $record): string => (string) $record->lineas()->count()),

                    TextEntry::make('resumen_pendientes')
                        ->label('Faltan por contar')
                        ->state(fn (Conteo $record): string => (string) $record->cuantasFaltan())
                        ->badge()
                        ->color(fn (Conteo $record): string => $record->cuantasFaltan() > 0 ? 'warning' : 'success'),

                    /*
                     * ⚠️ «No cuadraron: 3» también filtra el conteo a
                     * ciegas: quien está contando deduce enseguida cuáles
                     * son. Se oculta con la misma regla que las columnas
                     * de la tabla de líneas.
                     */
                    TextEntry::make('resumen_diferencias')
                        ->label('No cuadraron')
                        ->state(fn (Conteo $record): string => (string) $record->cuantasNoCuadraron())
                        ->badge()
                        ->color(fn (Conteo $record): string => $record->cuantasNoCuadraron() > 0 ? 'danger' : 'success')
                        ->visible(fn (Conteo $record): bool => self::puedeVerDiferencias($record)),

                    TextEntry::make('resumen_recuentos')
                        ->label('Hay que recontar')
                        ->state(fn (Conteo $record): string => (string) $record->cuantasExigenRecuento())
                        ->badge()
                        ->color(fn (Conteo $record): string => $record->cuantasExigenRecuento() > 0 ? 'warning' : 'gray')
                        ->helperText('Mientras quede una, el conteo no se puede cerrar.'),
                ]),

            Section::make('El cierre')
                ->columns(3)
                ->visible(fn (Conteo $record): bool => ! $record->estaAbierto())
                ->schema([
                    TextEntry::make('cerrado_en')
                        ->label('Cerrado')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('cerradoPor.name')
                        ->label('Lo cerró')
                        ->placeholder('—')
                        ->helperText('Nunca es la misma persona que lo abrió: lo impide la base.'),

                    TextEntry::make('ajuste_generado')
                        ->label('Ajuste generado')
                        ->state(fn (Conteo $record): string => self::ajusteDe($record))
                        ->helperText('Es el documento que movió el kardex con las diferencias.'),

                    /*
                     * Lo más importante de todo el cierre cuando existe:
                     * qué quedó SIN asentar. Va persistido en el conteo y
                     * no solo en una notificación, que muere con la
                     * sesión — y un descuadre de controlados no puede
                     * depender de que alguien no haya cerrado la pestaña.
                     */
                    TextEntry::make('notas_del_cierre')
                        ->label('⚠️ Quedó sin asentar')
                        ->color('danger')
                        ->weight('bold')
                        ->columnSpanFull()
                        ->visible(fn (Conteo $record): bool => $record->notas_del_cierre !== null),

                    TextEntry::make('anulado_en')
                        ->label('Anulado')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—')
                        ->visible(fn (Conteo $record): bool => $record->estado === EstadoConteo::Anulado),

                    TextEntry::make('motivo_anulacion')
                        ->label('¿Por qué se anuló?')
                        ->placeholder('—')
                        ->columnSpan(2)
                        ->visible(fn (Conteo $record): bool => $record->estado === EstadoConteo::Anulado),
                ]),
        ]);
    }

    /**
     * Ver `Conteo::esCiegoPara()`: quien está contando no ve el número.
     */
    private static function puedeVerDiferencias(Conteo $conteo): bool
    {
        return ! $conteo->esCiegoPara(UsuarioAutenticado::id());
    }

    /**
     * El ajuste que salió del cierre, o por qué no salió ninguno.
     *
     * Que no haya ajuste es una buena noticia y hay que decirlo así: un
     * guion suelto se lee como «falta un dato».
     */
    private static function ajusteDe(Conteo $conteo): string
    {
        $conteo->loadMissing('ajuste');

        /*
         * `getRelation()` y no `$conteo->ajuste`: devuelve `mixed`, y
         * estrechar mixed con `instanceof` es siempre correcto. Larastan
         * tipa las relaciones a veces como no-nulas (§9.B1), y entonces
         * un `=== null` queda marcado como comparación imposible.
         */
        $ajuste = $conteo->getRelation('ajuste');

        if (! $ajuste instanceof Ajuste) {
            return $conteo->estado === EstadoConteo::Anulado
                ? 'Ninguno: el conteo se anuló sin asentar nada.'
                : 'Ninguno: todo cuadró.';
        }

        return "Ajuste #{$ajuste->id} · L ".$ajuste->valor()->valor();
    }
}
