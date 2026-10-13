<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Tables;

use App\Domain\Enums\RangoEdad;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Filament\Resources\Pacientes\Pages\ListPacientes;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Support\NormalizadorDeTexto;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
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
    /**
     * Las bandejas de trabajo. Desde el 12-oct-2026 son PESTAÑAS de
     * «ListPacientes» y no un desplegable debajo del buscador: son colas
     * que se ven enteras, y el número de la pestaña dice cuánto falta sin
     * tener que abrirlas.
     */
    public const BANDEJAS = [
        'sin_identificar' => 'Sin identificar (NN)',
        'en_conflicto'    => 'Documento en conflicto',
        'sin_expediente'  => 'Sin expediente',
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
                // Solo el número: es lo único que la columna muestra.
                $query->with('expedientes:id,persona_id,numero');

                self::aplicarCriterio(
                    $query,
                    self::termino($livewire->getTableFilterState('paciente') ?? []),
                    self::bandejaDe($livewire),
                );
            })
            /*
             * ─────────────────────────────────────────────────────────
             * CUATRO COLUMNAS QUE SE LEEN DE UN VISTAZO (12-oct-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Antes eran seis, y tres decían casi siempre «—»: el rango
             * de edad, «sin identificar» y «fallecido» como íconos. Ahora
             * la edad y su rango van debajo de la fecha, y lo que pide
             * atención —NN, fallecido, documento en conflicto— va en una
             * sola columna que está vacía cuando todo está en orden.
             *
             * El número de expediente en vez de cuántos hay: es lo que
             * admisión le dicta a enfermería y lo que se busca en archivo.
             */
            ->columns([
                TextColumn::make('primer_apellido')
                    ->label('Paciente')
                    ->state(fn (Persona $record): string => $record->nombreParaListado())
                    ->description(fn (Persona $record): string => $record->identificadorVisible() ?? 'Sin documento')
                    ->weight('semibold')
                    ->wrap()
                    ->grow(),

                TextColumn::make('fecha_nacimiento')
                    ->label('Nacimiento')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha')
                    ->description(fn (Persona $record): string => self::edad($record)),

                TextColumn::make('expedientes.numero')
                    ->label('Expediente')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('Sin expediente')
                    ->fontFamily('mono')
                    ->size('sm'),

                TextColumn::make('alertas')
                    ->label('Atención')
                    ->badge()
                    ->state(fn (Persona $record): array => self::alertasDe($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Fallecido'              => 'danger',
                        'Documento en conflicto' => 'danger',
                        default                  => 'warning',
                    })
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Persona $record): string => PacienteResource::getUrl('view', ['record' => $record]))
            ->paginated([25, 50])
            ->filters([
                /*
                 * Este filtro NO consulta: solo dibuja los campos y el
                 * indicador. Ver el encabezado.
                 */
                Filter::make('paciente')
                    ->schema([
                        TextInput::make('termino')
                            ->hiddenLabel()
                            ->placeholder('Buscá por nombre y apellido, o por número de documento')
                            ->prefixIcon('heroicon-o-magnifying-glass')
                            ->autofocus()
                            ->live(debounce: 400)
                            ->columnSpanFull(),
                    ])
                    ->indicateUsing(fn (array $data): ?string => self::termino($data) === ''
                        ? null
                        : 'Buscando: '.self::termino($data)),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(1)
            ->emptyStateIcon(fn (HasTable $livewire): string => self::seBusco($livewire)
                ? 'heroicon-o-user-plus'
                : 'heroicon-o-magnifying-glass')
            ->emptyStateHeading(fn (HasTable $livewire): string => self::seBusco($livewire)
                ? 'No hay nadie con «'.self::terminoDe($livewire).'»'
                : 'Buscá al paciente antes de registrarlo')
            ->emptyStateDescription(fn (HasTable $livewire): string => self::seBusco($livewire)
                ? 'Probá con otro apellido o con el número de documento. Si de verdad no está, registralo: '
                    .'el formulario ya viene con lo que escribiste.'
                : 'Por nombre o por documento. Tolera tildes y errores de digitación.')
            /*
             * Lo que ya se tecleó en el buscador viaja al formulario: buscar
             * «juan perez lopez», no encontrarlo y tener que volver a
             * escribirlo era el paso más lento del registro.
             */
            ->emptyStateActions([
                Action::make('registrarLoBuscado')
                    ->label(fn (HasTable $livewire): string => 'Registrar a «'.mb_strtoupper(self::terminoDe($livewire)).'»')
                    ->icon('heroicon-o-user-plus')
                    ->url(fn (HasTable $livewire): string => PacienteResource::getUrl('create', ['buscado' => self::terminoDe($livewire)]))
                    ->visible(fn (HasTable $livewire): bool => self::seBusco($livewire) && self::bandejaDe($livewire) === null),
            ])
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
     */
    private static function aplicarCriterio(Builder $consulta, string $termino, ?string $bandeja): void
    {
        /*
         * Sin término Y sin bandeja, cero filas. Ver el encabezado: esto
         * ES el comportamiento, no una falta de datos.
         */
        if ($termino === '' && $bandeja === null) {
            $consulta->whereRaw('1 = 0');

            return;
        }

        self::aplicarBandeja($consulta, $bandeja);

        if ($termino === '') {
            return;
        }

        self::aplicarBusqueda($consulta, $termino);
    }

    /**
     * La cola de una bandeja. Pública porque la usa también el número de
     * cada pestaña en «ListPacientes»: misma condición en los dos lados.
     *
     * @param Builder<Persona> $consulta
     */
    public static function aplicarBandeja(Builder $consulta, ?string $bandeja): void
    {
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
    }

    /**
     * @param Builder<Persona> $consulta
     */
    private static function aplicarBusqueda(Builder $consulta, string $termino): void
    {
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
     * La bandeja de la pestaña abierta, o null en «Buscar».
     */
    private static function bandejaDe(HasTable $livewire): ?string
    {
        $pestana = $livewire instanceof ListPacientes ? $livewire->activeTab : null;

        return is_string($pestana) && isset(self::BANDEJAS[$pestana]) ? $pestana : null;
    }

    private static function terminoDe(HasTable $livewire): string
    {
        return self::termino($livewire->getTableFilterState('paciente') ?? []);
    }

    private static function seBusco(HasTable $livewire): bool
    {
        return self::terminoDe($livewire) !== '';
    }

    /**
     * Lo que pide atención, y nada cuando todo está en orden.
     *
     * @return list<string>
     */
    private static function alertasDe(Persona $persona): array
    {
        $alertas = [];

        if ($persona->es_nn) {
            $alertas[] = 'Sin identificar';
        }

        if ($persona->estaFallecida()) {
            $alertas[] = 'Fallecido';
        }

        if ($persona->identificadores->contains(fn (PersonaIdentificador $i): bool => $i->en_conflicto)) {
            $alertas[] = 'Documento en conflicto';
        }

        return $alertas;
    }

    private static function edad(Persona $persona): string
    {
        $anios = $persona->edadEn(now());

        if ($anios === null) {
            return 'Edad desconocida';
        }

        $sufijo = $persona->fechaNacimientoEsExacta() ? '' : ' (estimada)';
        $rango = $persona->rangoDeEdadEn(now());

        return "{$anios} años{$sufijo}".($rango instanceof RangoEdad ? ' · '.$rango->etiqueta() : '');
    }
}
