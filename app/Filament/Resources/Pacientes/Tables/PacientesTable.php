<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Tables;

use App\Domain\Enums\RangoEdad;
use App\Models\Persona;
use App\Support\NormalizadorDeTexto;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * El buscador de admisión.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SIN CRITERIO NO HAY RESULTADOS, Y ESO ES LA FUNCIONALIDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 * La tabla arranca VACÍA. No es un detalle de estilo: es lo único que de
 * verdad evita duplicados, porque obliga a mirar antes de crear. Si la
 * pantalla abriera con el padrón completo, el botón "registrar" estaría
 * ahí desde el primer segundo y nadie buscaría primero.
 *
 * Hay dos formas de dar un criterio, y cualquiera de las dos alcanza:
 *   · BUSCAR por nombre o número de documento;
 *   · abrir una BANDEJA de trabajo, que es una cola y se ve entera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ LA CONSULTA VIVE EN `modifyQueryUsing` Y NO EN `Filter::query()`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Esto se descubrió probando en el navegador, y cuesta encontrarlo de
 * otra forma: el `Builder` que Filament le entrega al callback de un
 * filtro **no lleva modelo asociado** —`$query->getModel()` devuelve
 * null—, y las condiciones que se le agregan ahí no llegan a la consulta
 * final. El callback corre (se comprobó con un log), pero filtrar desde
 * ahí no tenía ningún efecto: la tabla mostraba a todos.
 *
 * `Table::modifyQueryUsing()` se aplica sobre la consulta REAL de la
 * tabla, y el estado del filtro se lee del componente Livewire con
 * `getTableFilterState()`. El `Filter` queda solo como interfaz: dibuja
 * los campos y el indicador.
 *
 * El síntoma de la versión anterior era engañoso —la búsqueda "parecía"
 * funcionar porque solo había un paciente en la base— y por eso vale la
 * pena que quede escrito: sin dos filas distintas, un filtro roto y un
 * filtro correcto se ven igual.
 */
final class PacientesTable
{
    private const BANDEJAS = [
        'sin_identificar' => 'Pendientes de identificar (NN)',
        'en_conflicto'    => 'Con documento en conflicto',
        'sin_expediente'  => 'Sin expediente en ninguna sede',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            /*
             * ⚠️ EL PARAMETRO SE TIENE QUE LLAMAR `$query`.
             *
             * Filament inyecta los argumentos de sus cierres POR NOMBRE de
             * parametro, no por tipo. Con cualquier otro nombre —$consulta,
             * por ejemplo— no reconoce que pide la consulta de la tabla y
             * resuelve un Builder vacio del contenedor: uno SIN MODELO. Las
             * condiciones se agregan a ese builder fantasma, no llegan a la
             * consulta real, y la tabla muestra todo como si no hubiera
             * filtro. No falla, no avisa: simplemente no filtra.
             *
             * El mismo error, con el mismo sintoma, aparecia antes dentro
             * del callback de un Filter. Vale para TODO cierre de Filament:
             * $query, $record, $state, $data, $livewire, $get, $set.
             */
            ->modifyQueryUsing(function (Builder $query, HasTable $livewire): void {
                self::aplicarCriterio($query, $livewire->getTableFilterState('paciente') ?? []);
            })
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
                    ->placeholder('Sin fecha de nacimiento')
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
                /*
                 * Este filtro NO consulta: solo dibuja los campos y el
                 * indicador. Ver el encabezado.
                 */
                Filter::make('paciente')
                    ->schema([
                        TextInput::make('termino')
                            ->label('Buscar paciente')
                            ->placeholder('Nombre y apellido, o número de documento')
                            ->autofocus()
                            ->live(debounce: 400)
                            ->columnSpanFull(),

                        Select::make('bandeja')
                            ->label('O ver una bandeja de trabajo')
                            ->placeholder('Ninguna — buscar por nombre o documento')
                            ->native(false)
                            ->live()
                            ->options(self::BANDEJAS)
                            ->columnSpanFull(),
                    ])
                    ->indicateUsing(function (array $data): ?string {
                        $partes = array_filter([
                            self::termino($data) === '' ? null : 'Buscando: '.self::termino($data),
                            self::BANDEJAS[self::bandeja($data)] ?? null,
                        ]);

                        return $partes === [] ? null : implode(' · ', $partes);
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(1)
            ->emptyStateIcon('heroicon-o-magnifying-glass')
            ->emptyStateHeading('Buscá al paciente antes de registrarlo')
            ->emptyStateDescription(
                'Escribí el nombre o el número de documento, o abrí una bandeja de trabajo. La '
                .'búsqueda tolera tildes y errores de digitación. Si después de buscar no aparece, '
                .'ahí sí registralo como nuevo.'
            )
            ->recordActions([
                ViewAction::make()->label('Abrir'),
                EditAction::make()->label('Editar'),
            ])
            ->toolbarActions([]);
    }

    /**
     * Acá el parámetro SÍ puede llamarse `$consulta`: este es un método
     * normal, no un cierre que Filament resuelva por nombre.
     *
     * @param Builder<Persona> $consulta
     * @param array<string, mixed> $estado
     */
    private static function aplicarCriterio(Builder $consulta, array $estado): void
    {
        $termino = self::termino($estado);
        $bandeja = self::bandeja($estado);

        /*
         * Sin término Y sin bandeja, cero filas. Ver el encabezado: esto
         * ES el comportamiento, no una falta de datos.
         */
        if ($termino === '' && $bandeja === null) {
            $consulta->whereRaw('1 = 0');

            return;
        }

        match ($bandeja) {
            'sin_identificar' => $consulta->where('es_nn', true),
            'en_conflicto'    => $consulta->whereRaw(
                'EXISTS (SELECT 1 FROM persona_identificadores pi '
                .'WHERE pi.persona_id = personas.id '
                .'AND pi.deleted_at IS NULL '
                .'AND pi.en_conflicto = true)'
            ),
            'sin_expediente' => $consulta->whereRaw(
                'NOT EXISTS (SELECT 1 FROM expedientes e '
                .'WHERE e.persona_id = personas.id '
                .'AND e.deleted_at IS NULL)'
            ),
            default => null,
        };

        if ($termino === '') {
            return;
        }

        $clave = NormalizadorDeTexto::clave($termino);
        $digitos = preg_replace('/\D+/', '', $termino) ?? '';

        /*
         * Un solo whereRaw con los paréntesis escritos a mano.
         *
         * `%>` compara contra la MEJOR PALABRA del nombre y `%` contra el
         * nombre completo. Los dos hacen falta: con `%` solo, un término
         * corto contra un nombre largo da similitud baja y el paciente no
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
    }

    /**
     * @param array<string, mixed> $estado
     */
    private static function termino(array $estado): string
    {
        return trim((string) ($estado['termino'] ?? ''));
    }

    /**
     * @param array<string, mixed> $estado
     */
    private static function bandeja(array $estado): ?string
    {
        $bandeja = $estado['bandeja'] ?? null;

        return is_string($bandeja) && isset(self::BANDEJAS[$bandeja]) ? $bandeja : null;
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
