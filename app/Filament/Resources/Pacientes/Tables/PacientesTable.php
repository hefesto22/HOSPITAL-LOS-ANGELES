<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Tables;

use App\Domain\Enums\RangoEdad;
use App\Models\Persona;
use App\Support\NormalizadorDeTexto;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * El buscador de admisión.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN TÉRMINO NO HAY RESULTADOS, Y ESO ES LA FUNCIONALIDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * La tabla arranca VACÍA. No es un detalle de estilo: es lo único que de
 * verdad evita duplicados, porque obliga a mirar antes de crear. Si la
 * pantalla abriera con el padrón completo, el botón "registrar" estaría
 * ahí desde el primer segundo y nadie buscaría primero.
 *
 * El filtro se implementa con `Filter` y no con la búsqueda nativa de la
 * tabla por una razón concreta: la búsqueda nativa arma un `LIKE` sobre
 * las columnas marcadas `searchable`, y acá la comparación es por
 * TRIGRAMAS contra `nombre_busqueda` —que es lo que tolera "jose antonyo
 * pena" cuando el paciente está como "JOSÉ ANTONIO PEÑA"—. Un LIKE no
 * encuentra eso.
 *
 * Busca por las dos vías a la vez porque admisión no siempre sabe cuál
 * tiene a mano:
 *   · por NOMBRE, con trigramas, tolerante a tildes y a dedazos
 *   · por NÚMERO de documento, por prefijo, para el que llega con el DNI
 */
final class PacientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('primer_apellido')
                    ->label('Paciente')
                    ->state(fn (Persona $record): string => $record->nombreParaListado())
                    ->description(fn (Persona $record): ?string => $record->identificadorVisible())
                    ->weight('medium')
                    ->wrap(),

                TextColumn::make('fecha_nacimiento')
                    ->label('Nacimiento')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha')
                    ->description(fn (Persona $record): string => self::edad($record)),

                TextColumn::make('rango')
                    ->label('Rango')
                    ->badge()
                    ->state(fn (Persona $record): ?RangoEdad => $record->rangoDeEdadEn(now()))
                    /*
                     * Los cierres aceptan null a propósito: un NN sin fecha
                     * de nacimiento no tiene rango, y Filament igual evalúa
                     * el color de la celda vacía.
                     */
                    ->formatStateUsing(fn (?RangoEdad $state): string => $state?->etiqueta() ?? '—')
                    ->color(fn (?RangoEdad $state): string => $state?->color() ?? 'gray'),

                TextColumn::make('expedientes_count')
                    ->label('Expedientes')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->tooltip('Uno por sede donde se le ha atendido.'),

                IconColumn::make('es_nn')
                    ->label('Sin identificar')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-question-mark-circle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip('Pendiente de identificar antes del alta.'),

                IconColumn::make('fecha_defuncion')
                    ->label('Fallecido')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-circle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->getStateUsing(fn (Persona $record): bool => $record->estaFallecida()),
            ])
            ->paginated([25, 50])
            ->filters([
                Filter::make('paciente')
                    ->schema([
                        TextInput::make('termino')
                            ->label('Buscar paciente')
                            ->placeholder('Nombre y apellido, o número de documento')
                            ->autofocus()
                            ->live(debounce: 400)
                            ->columnSpanFull(),
                    ])
                    ->query(function (Builder $consulta, array $data): void {
                        $termino = trim((string) ($data['termino'] ?? ''));

                        /*
                         * Sin término, cero filas. Ver el encabezado: esto
                         * ES el comportamiento, no una falta de datos.
                         */
                        if ($termino === '') {
                            $consulta->whereRaw('1 = 0');

                            return;
                        }

                        $clave = NormalizadorDeTexto::clave($termino);
                        $digitos = preg_replace('/\D+/', '', $termino) ?? '';

                        /*
                         * Un solo whereRaw con los parentesis escritos a
                         * mano, y NO un where(Closure) anidado.
                         *
                         * El cierre anidado revienta acá: Filament ya
                         * envuelve los filtros en su propio grupo y el
                         * builder que llega no siempre trae el modelo
                         * asociado, asi que Eloquent falla al abrir un
                         * segundo nivel ("newQueryWithoutRelationships()
                         * on null"). Se vio en el navegador, no en las
                         * pruebas: el filtro solo se arma dentro de una
                         * peticion Livewire.
                         *
                         * `%>` compara contra la MEJOR PALABRA del nombre
                         * y `%` contra el nombre completo. Los dos hacen
                         * falta: con `%` solo, un termino corto contra un
                         * nombre largo da similitud baja y el paciente no
                         * aparece.
                         */
                        $condiciones = 'nombre_busqueda %> ? OR nombre_busqueda % ?';
                        $valores = [$clave, $clave];

                        if ($digitos !== '') {
                            $condiciones .= ' OR EXISTS ('
                                .'SELECT 1 FROM persona_identificadores pi '
                                .'WHERE pi.persona_id = personas.id '
                                .'AND pi.deleted_at IS NULL '
                                .'AND pi.valor LIKE ?)';
                            $valores[] = $digitos.'%';
                        }

                        $consulta->whereRaw('('.$condiciones.')', $valores);

                        $consulta->orderByRaw('similarity(nombre_busqueda, ?) desc', [$clave]);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $termino = trim((string) ($data['termino'] ?? ''));

                        return $termino === '' ? null : "Buscando: {$termino}";
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(1)
            ->emptyStateIcon('heroicon-o-magnifying-glass')
            ->emptyStateHeading('Buscá al paciente antes de registrarlo')
            ->emptyStateDescription(
                'Escribí el nombre o el número de documento. La búsqueda tolera tildes y errores de '
                .'digitación. Si después de buscar no aparece, ahí sí registralo como nuevo.'
            )
            ->recordActions([
                ViewAction::make()->label('Abrir'),
                EditAction::make()->label('Editar'),
            ])
            ->toolbarActions([]);
    }

    private static function edad(Persona $persona): string
    {
        $anios = $persona->edadEn(now());

        if ($anios === null) {
            return 'Edad desconocida';
        }

        $sufijo = $persona->fechaNacimientoEsExacta() ? '' : ' (estimada)';

        return "{$anios} años{$sufijo}";
    }
}
