<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Enums\QuienCobraElHonorario;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Pages\Concerns\ElijePeriodo;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Medico;
use App\Services\ReporteDeHonorarios;
use App\Support\NormalizadorDeTexto;
use App\Support\Periodo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * Cuánto facturó cada médico en el período, en qué y a quién.
 *
 * La consulta y su regla —🔴 solo lo FACTURADO en el período, por la
 * fecha de la factura; una factura anulada sale entera de su mes— viven
 * en `ReporteDeHonorarios`, que es también lo que imprimen los reportes.
 * La pantalla y el papel no pueden decir dos números distintos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TARJETAS Y NO UNA LISTA (Mauricio, 11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Cuando sean 400 honorarios se verá feo uno encima de otro … sería
 * bueno que los médicos se vean en cards así como las cuentas, solo con
 * cuántos honorarios llevan.» La vista general son tarjetas de la A a la
 * Z —cuánto lleva, cuántos honorarios, qué parte del período—; el
 * detalle aparece al tocar una, con su reparto por pagador y el botón
 * para imprimirle su reporte. Los renglones de todos se ven solo si se
 * piden: auditoría los usa para buscar un paciente.
 */
class HonorariosPorMedico extends Page implements HasTable
{
    use ElijePeriodo;
    use InteractsWithTable;

    /**
     * El permiso de esta pantalla (31-oct-2026). Se llama como lo
     * nombraría Shield para una página —`View:` y la clase— y lo crea
     * `MatrizDePermisosSeeder`, que se lo da a dirección y auditoría. Lo
     * piden también las dos rutas de impresión.
     */
    public const PERMISO = 'View:HonorariosPorMedico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'honorarios-por-medico';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.honorarios-por-medico';

    /**
     * Lo que se está mirando: un médico, un turno o área del personal
     * (el id del ítem), o los honorarios de médico que quedaron sin
     * médico. A lo sumo uno a la vez.
     *
     * `#[Locked]`: solo los cambian `verSoloEste()`, `verTurno()`,
     * `verSinMedico()` y `verTodos()`.
     */
    #[Locked]
    public ?int $medico = null;

    #[Locked]
    public ?int $turno = null;

    #[Locked]
    public bool $sinMedico = false;

    /**
     * El orden de las tarjetas: de la A a la Z, o de la que más lleva a
     * la que menos. Lo escribe un `<select>`, así que todo lo que no sea
     * `total` se lee como `nombre` (ver `ordenElegido()`).
     */
    public string $orden = 'nombre';

    /**
     * Los renglones de TODOS los médicos, debajo de las tarjetas. Apagado:
     * con 400 honorarios del mes era la lista que no se podía leer. Lo
     * prende quien busca un paciente sin saber de qué médico es.
     */
    public bool $verRenglones = false;

    /**
     * Las cifras del período —por médico, por turno y sin médico—, tres
     * consultas agrupadas por pintada. Privadas: Livewire no las guarda
     * entre pedidos y nunca quedan viejas.
     *
     * @var array{medicos: array<int, array{cuantos: int, importe: Decimal}>, personal: array<int, array{cuantos: int, importe: Decimal}>, sinMedico: array{cuantos: int, importe: Decimal}}|null
     */
    private ?array $cifras = null;

    private ?string $cifrasDe = null;

    public static function getNavigationLabel(): string
    {
        return 'Honorarios por médico';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Consultas';
    }

    public function getTitle(): string
    {
        return 'Honorarios por médico';
    }

    public function getSubheading(): string
    {
        return 'Los honorarios de las facturas emitidas en el período, de cada médico y de cada turno o área '
            .'del personal. Si una factura se anula, sus honorarios salen del mes.';
    }

    /**
     * Solo dirección y auditoría (Mauricio, 31-oct-2026: «honorarios por
     * médico no deberían poder verlo las cajeras»). Dice cuánto facturó
     * cada médico, y eso no es trabajo del turno.
     *
     * Antes se armaba con dos permisos ajenos —ver médicos y ver cargos—
     * y caja tenía los dos por otras razones: elige al médico al cargar
     * el honorario y ve los cargos de la cuenta. Una pantalla que se
     * decide con permisos prestados se abre sola el día que otro rol los
     * necesita; por eso tiene el suyo.
     */
    public static function canAccess(): bool
    {
        return Gate::allows(self::PERMISO);
    }

    /**
     * `?medico=` se lee a mano y no con `#[Url]`, por la misma razón que
     * el período (ver `leerElPeriodoDeLaUrl()`).
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->leerElPeriodoDeLaUrl();

        $medico = request()->query('medico');
        $this->medico = is_string($medico) && ctype_digit($medico) ? (int) $medico : null;

        $turno = request()->query('turno');
        $this->turno = $this->medico === null && is_string($turno) && ctype_digit($turno) ? (int) $turno : null;
    }

    // ── El período ────────────────────────────────────────────────────

    /**
     * Los honorarios se liquidan por quincena o por mes.
     *
     * @return array<string, string>
     */
    protected function atajosDelPeriodo(): array
    {
        return [
            'esta_quincena'   => 'Esta quincena',
            'quincena_pasada' => 'Quincena pasada',
            'este_mes'        => 'Este mes',
            'mes_pasado'      => 'Mes pasado',
        ];
    }

    // ── Qué se mira ───────────────────────────────────────────────────

    public function verSoloEste(int $medicoId): void
    {
        $this->medico = $this->medico === $medicoId ? null : $medicoId;
        $this->turno = null;
        $this->sinMedico = false;

        $this->resetPage();
    }

    public function verTurno(int $itemId): void
    {
        $this->turno = $this->turno === $itemId ? null : $itemId;
        $this->medico = null;
        $this->sinMedico = false;

        $this->resetPage();
    }

    public function verSinMedico(): void
    {
        $this->sinMedico = ! $this->sinMedico;
        $this->medico = null;
        $this->turno = null;

        $this->resetPage();
    }

    public function verTodos(): void
    {
        $this->medico = null;
        $this->turno = null;
        $this->sinMedico = false;

        $this->resetPage();
    }

    public function alternarRenglones(): void
    {
        $this->verRenglones = ! $this->verRenglones;

        $this->resetPage();
    }

    // ── El total, los médicos y el personal ───────────────────────────

    /**
     * El total del período: médicos, turnos del personal y lo que quedó
     * sin médico. Entre los tres está todo lo facturado en honorarios.
     *
     * @return array{total: string, renglones: int, medicos: int, personal: int, totalMedicos: string, totalPersonal: string}
     */
    public function resumen(): array
    {
        $medicos = self::suma($this->cifras()['medicos']);
        $personal = self::suma($this->cifras()['personal']);
        $sinMedico = $this->cifras()['sinMedico'];

        return [
            'total'         => ReporteDeHonorarios::formatear($this->totalDelTodo()),
            'renglones'     => $medicos['cuantos'] + $personal['cuantos'] + $sinMedico['cuantos'],
            'medicos'       => count($this->cifras()['medicos']),
            'personal'      => count($this->cifras()['personal']),
            'totalMedicos'  => ReporteDeHonorarios::formatear($medicos['importe']),
            'totalPersonal' => ReporteDeHonorarios::formatear($personal['importe']),
        ];
    }

    public function totalDelPeriodo(): string
    {
        return $this->resumen()['total'];
    }

    /**
     * Una tarjeta por médico con honorarios facturados en el período, en
     * el orden elegido.
     *
     * `clave` es el nombre sin tildes y en minúsculas, la misma forma en
     * que el buscador de arriba normaliza lo que se escribe: «peña»
     * encuentra a PENA y «jose» a JOSÉ, sin ir al servidor.
     *
     * @return list<array{id: int, nombre: string, iniciales: string, especialidad: string, clave: string, renglones: int, total: string, parte: string, barra: int, activo: bool}>
     */
    public function medicos(): array
    {
        $fichas = app(ReporteDeHonorarios::class)->medicos($this->cifras()['medicos']);

        if ($this->ordenElegido() === 'total') {
            usort($fichas, fn (array $una, array $otra): int => $otra['importe']->comparar($una['importe'])
                ?: strcmp($una['nombre'], $otra['nombre']));
        }

        $tarjetas = [];

        foreach ($fichas as $ficha) {
            $tarjetas[] = [
                'id'           => $ficha['id'],
                'nombre'       => $ficha['nombre'],
                'iniciales'    => self::iniciales($ficha['nombre']),
                'especialidad' => $ficha['especialidad'],
                'clave'        => NormalizadorDeTexto::clave($ficha['nombre'].' '.$ficha['especialidad']),
                'renglones'    => $ficha['cuantos'],
                'total'        => ReporteDeHonorarios::formatear($ficha['importe']),
                'parte'        => ReporteDeHonorarios::parte($ficha['importe'], $this->totalDelTodo()),
                'barra'        => $this->barra($ficha['importe']),
                'activo'       => $this->medicoElegido() === $ficha['id'],
            ];
        }

        return $tarjetas;
    }

    /**
     * Una tarjeta por turno o área del personal —por ítem— con honorarios
     * facturados en el período (Mauricio, 11-nov-2026: «en el reporte
     * pertenece a X turno, sin enfermeras»).
     *
     * @return list<array{id: int, nombre: string, item: string, clave: string, renglones: int, total: string, parte: string, barra: int, activo: bool}>
     */
    public function personal(): array
    {
        $grupos = app(ReporteDeHonorarios::class)->delPersonal($this->cifras()['personal']);

        if ($this->ordenElegido() === 'total') {
            usort($grupos, fn (array $uno, array $otro): int => $otro['importe']->comparar($uno['importe'])
                ?: strcmp($uno['nombre'], $otro['nombre']));
        }

        $tarjetas = [];

        foreach ($grupos as $grupo) {
            $tarjetas[] = [
                'id'        => $grupo['id'],
                'nombre'    => $grupo['nombre'],
                'item'      => $grupo['item'],
                'clave'     => NormalizadorDeTexto::clave($grupo['item']),
                'renglones' => $grupo['cuantos'],
                'total'     => ReporteDeHonorarios::formatear($grupo['importe']),
                'parte'     => ReporteDeHonorarios::parte($grupo['importe'], $this->totalDelTodo()),
                'barra'     => $this->barra($grupo['importe']),
                'activo'    => $this->turnoElegido() === $grupo['id'],
            ];
        }

        return $tarjetas;
    }

    /**
     * Los honorarios de médico que se cargaron sin elegir al médico, si
     * hay en el período. No son de nadie y no se inventa a quién: se
     * muestran aparte para que el total cuadre y alguien los revise.
     *
     * @return array{renglones: int, total: string}|null
     */
    public function sinMedicoDelPeriodo(): ?array
    {
        $cifras = $this->cifras()['sinMedico'];

        return $cifras['cuantos'] > 0
            ? ['renglones' => $cifras['cuantos'], 'total' => ReporteDeHonorarios::formatear($cifras['importe'])]
            : null;
    }

    /**
     * Lo que se está mirando, listo para la ficha de arriba: el médico, el
     * turno o los «sin médico», con lo suyo y de qué pagadores salió.
     * Nulo si no se eligió nada —o lo elegido no tiene honorarios en el
     * período—.
     *
     * @return array{tipo: string, id: int|null, nombre: string, iniciales: string, subtitulo: string, renglones: int, total: string, parte: string, pagadores: list<array{nombre: string, cuantos: int, total: string, parte: string}>, enlace: string|null}|null
     */
    public function ficha(): ?array
    {
        $reporte = app(ReporteDeHonorarios::class);

        $medico = $this->medicoElegido();

        if ($medico !== null) {
            $suyo = $this->cifras()['medicos'][$medico];
            $tarjeta = collect($this->medicos())->firstWhere('id', $medico);

            return [
                'tipo'      => 'medico',
                'id'        => $medico,
                'nombre'    => is_array($tarjeta) ? $tarjeta['nombre'] : '',
                'iniciales' => is_array($tarjeta) ? $tarjeta['iniciales'] : '·',
                'subtitulo' => is_array($tarjeta) && $tarjeta['especialidad'] !== '' ? $tarjeta['especialidad'] : 'Médico',
                'renglones' => $suyo['cuantos'],
                'total'     => ReporteDeHonorarios::formatear($suyo['importe']),
                'parte'     => ReporteDeHonorarios::parte($suyo['importe'], $this->totalDelTodo()),
                'pagadores' => self::pagadoresConParte(
                    $reporte->porPagador($this->desdeEfectivo(), $this->hastaEfectivo(), $medico)[$medico] ?? [],
                    $suyo['importe'],
                ),
                'enlace' => $this->sePuedeImprimir() ? $this->enlaceDelReporte($medico) : null,
            ];
        }

        $turno = $this->turnoElegido();

        if ($turno !== null) {
            $suyo = $this->cifras()['personal'][$turno];
            $tarjeta = collect($this->personal())->firstWhere('id', $turno);

            return [
                'tipo'      => 'personal',
                'id'        => $turno,
                'nombre'    => is_array($tarjeta) ? $tarjeta['nombre'] : '',
                'iniciales' => '',
                'subtitulo' => 'Enfermería y personal',
                'renglones' => $suyo['cuantos'],
                'total'     => ReporteDeHonorarios::formatear($suyo['importe']),
                'parte'     => ReporteDeHonorarios::parte($suyo['importe'], $this->totalDelTodo()),
                'pagadores' => self::pagadoresConParte(
                    $reporte->porPagadorDelPersonal($this->desdeEfectivo(), $this->hastaEfectivo(), $turno)[$turno] ?? [],
                    $suyo['importe'],
                ),
                'enlace' => $this->sePuedeImprimir() ? $this->enlaceDelReporte(null, $turno) : null,
            ];
        }

        if ($this->sinMedicoElegido()) {
            $suyo = $this->cifras()['sinMedico'];

            return [
                'tipo'      => 'sin_medico',
                'id'        => null,
                'nombre'    => 'Honorarios de médico sin médico',
                'iniciales' => '',
                'subtitulo' => 'Se cobraron sin elegir al médico. No entran en la liquidación de nadie.',
                'renglones' => $suyo['cuantos'],
                'total'     => ReporteDeHonorarios::formatear($suyo['importe']),
                'parte'     => ReporteDeHonorarios::parte($suyo['importe'], $this->totalDelTodo()),
                'pagadores' => [],
                'enlace'    => null,
            ];
        }

        return null;
    }

    // ── Los reportes impresos ─────────────────────────────────────────

    /**
     * El enlace al reporte impreso del período que se está viendo: de un
     * médico, de un turno o área, o el general de todos.
     *
     * `$incrustada` es para el modal: la hoja sale sin su barra de botones
     * porque los pone el modal. `$conDetalle`, solo el general: el resumen
     * y después la hoja de cada uno.
     */
    public function enlaceDelReporte(
        ?int $medicoId = null,
        ?int $turnoId = null,
        bool $incrustada = false,
        bool $conDetalle = false,
    ): string {
        $periodo = ['desde' => $this->desdeEfectivo(), 'hasta' => $this->hastaEfectivo()]
            + ($conDetalle && $medicoId === null && $turnoId === null ? ['detalle' => 1] : [])
            + ($incrustada ? ['incrustada' => 1] : []);

        if ($medicoId !== null) {
            return route('honorarios.reporte.medico', ['medico' => $medicoId] + $periodo);
        }

        if ($turnoId !== null) {
            return route('honorarios.reporte.turno', ['item' => $turnoId] + $periodo);
        }

        return route('honorarios.reporte', $periodo);
    }

    /**
     * El período que se ve, ¿se puede imprimir? Más de un año, no (ver
     * `ReporteDeHonorarios::DIAS_MAXIMOS`): el botón lo dice antes de
     * abrir un reporte que va a contestar con un error.
     */
    public function sePuedeImprimir(): bool
    {
        return Periodo::dias($this->desdeEfectivo(), $this->hastaEfectivo()) <= ReporteDeHonorarios::DIAS_MAXIMOS;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL REPORTE SE IMPRIME DESDE UN MODAL (Mauricio, 11-nov-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * «Que no me redirija a una ventana: que se abra un modal y la
     * ventana de impresión.» Antes cada botón abría una pestaña nueva con
     * la hoja y había que darle «Imprimir» y después volver.
     *
     * Ahora es como la factura: la hoja va en un iframe adentro del
     * modal, la ventana de imprimir se abre sola cuando terminó de
     * cargar, y al cerrarla se sigue en la misma pantalla. La hoja es la
     * MISMA ruta de antes con `?incrustada=1` —sin su barra—, así que lo
     * que sale en papel no cambia y cada vez que se abre queda en la
     * bitácora de lectura igual que antes.
     *
     * Argumentos: `medico` o `turno` (un id); ninguno = el general. Se
     * llama desde los botones con `mountAction('imprimirReporte', …)`.
     */
    public function imprimirReporteAction(): Action
    {
        return Action::make('imprimirReporte')
            ->modalHeading(fn (array $arguments): string => $this->tituloDelReporte($arguments))
            ->modalDescription(fn (): string => $this->periodo()
                .' · La ventana de imprimir se abre sola. Si la cerrás, «Imprimir» la vuelve a abrir.')
            ->modalWidth('7xl')
            ->modalContent(fn (array $arguments): View|HtmlString => $this->sePuedeImprimir()
                ? view('honorarios.modal', $this->hojaDelModal($arguments))
                : new HtmlString('<p>El reporte impreso cubre como máximo un año: elegí un período más corto.</p>'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }

    /**
     * Lo que necesita `honorarios.modal`: la hoja a mostrar y, en el
     * general, la otra versión —con la hoja de cada uno— para cambiar
     * sin cerrar el modal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{enlace: string, enlaceConDetalle: string|null, titulo: string}
     */
    private function hojaDelModal(array $arguments): array
    {
        ['medico' => $medico, 'turno' => $turno] = self::aQuienSeImprime($arguments);
        $general = $medico === null && $turno === null;

        return [
            'enlace'           => $this->enlaceDelReporte($medico, $turno, incrustada: true),
            'enlaceConDetalle' => $general ? $this->enlaceDelReporte(incrustada: true, conDetalle: true) : null,
            'titulo'           => $this->tituloDelReporte($arguments),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tituloDelReporte(array $arguments): string
    {
        ['medico' => $medico, 'turno' => $turno] = self::aQuienSeImprime($arguments);

        if ($medico !== null) {
            $nombre = Medico::withTrashed()->whereKey($medico)->value('nombre');

            return 'Honorarios · '.(is_string($nombre) ? $nombre : 'médico');
        }

        if ($turno !== null) {
            $nombre = Item::withTrashed()->whereKey($turno)->value('nombre');

            return 'Honorarios · '.(is_string($nombre) ? ReporteDeHonorarios::nombreDelGrupo($nombre) : 'turno o área');
        }

        return 'Reporte general de honorarios';
    }

    /**
     * Los argumentos vienen del navegador: solo se aceptan ids, y si
     * vienen los dos manda el médico.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{medico: int|null, turno: int|null}
     */
    private static function aQuienSeImprime(array $arguments): array
    {
        $medico = $arguments['medico'] ?? null;
        $turno = $arguments['turno'] ?? null;

        $medico = is_int($medico) || (is_string($medico) && ctype_digit($medico)) ? (int) $medico : null;
        $turno = is_int($turno) || (is_string($turno) && ctype_digit($turno)) ? (int) $turno : null;

        return ['medico' => $medico, 'turno' => $medico === null ? $turno : null];
    }

    // ── La tabla ──────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->consultaDeLaTabla())
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('facturas.fecha_operacion')
                ->orderByDesc('cargos.id'))
            ->paginated([25, 50, 100])
            ->searchPlaceholder('Paciente u honorario')
            ->columns([
                /*
                 * La fecha de la FACTURA, que es la que decide el mes, y
                 * debajo su número para buscarla en caja.
                 */
                TextColumn::make('facturada_el')
                    ->label('Facturado')
                    ->state(fn (Cargo $record): string => ReporteDeHonorarios::fechaCorta($record->getAttribute('facturada_el')))
                    ->description(fn (Cargo $record): string => (string) $record->getAttribute('factura_numero'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('facturas.fecha_operacion', $direction === 'desc' ? 'desc' : 'asc'))
                    ->grow(false),

                /*
                 * Solo cuando se ven todos: con un médico o un turno
                 * elegido, la columna repetiría su nombre en cada
                 * renglón.
                 */
                TextColumn::make('a_quien')
                    ->label('A quién va')
                    ->state(fn (Cargo $record): string => self::aQuienVa($record))
                    ->wrap()
                    ->visible(fn (): bool => $this->nadaElegido()),

                TextColumn::make('paciente')
                    ->label('Paciente')
                    ->state(fn (Cargo $record): string => $record->cuenta->encuentro->persona->nombreCompleto())
                    ->description(fn (Cargo $record): string => $record->cuenta->numero)
                    /*
                     * Se busca contra `nombre_busqueda`, la columna
                     * generada de `personas`: el nombre ya normalizado
                     * —sin tildes, en una sola pieza—, la única forma de
                     * que «peña» encuentre a PENA.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'cuenta.encuentro.persona',
                        fn (Builder $persona): Builder => $persona->where('nombre_busqueda', 'ilike', '%'.$search.'%'),
                    ))
                    ->wrap(),

                /*
                 * El TEXTO del cargo y no el nombre del ítem: es el que
                 * quedó congelado el día del cobro y el que salió impreso
                 * en la factura.
                 */
                TextColumn::make('texto')
                    ->label('Honorario')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('cargos.texto', 'ilike', '%'.$search.'%')),

                TextColumn::make('convenio.nombre')
                    ->label('Pagador')
                    ->wrap(),

                TextColumn::make('cantidad')
                    ->label('Cant.')
                    ->alignEnd()
                    ->formatStateUsing(fn (Cargo $record): string => rtrim(rtrim($record->cantidad, '0'), '.'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Importe')
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono)
                    ->weight('semibold')
                    ->formatStateUsing(fn (Cargo $record): string => $record->totalParaMostrar())
                    ->grow(false)
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn (mixed $state): string => ReporteDeHonorarios::formatear(ReporteDeHonorarios::comoDecimal($state)))
                    ),
            ])
            /*
             * Un solo filtro, en el botón de al lado del buscador:
             * período y médico ya están arriba.
             *
             * ⚠️ Con su propia consulta y la columna calificada: la tabla
             * cruza `cargos` con `facturas`, y las dos tienen
             * `convenio_id`.
             */
            ->filters([
                SelectFilter::make('convenio_id')
                    ->label('Pagador')
                    ->native(false)
                    ->options(fn (): array => Convenio::query()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): void {
                        $valor = $data['value'] ?? null;

                        if (is_numeric($valor)) {
                            $query->where('cargos.convenio_id', (int) $valor);
                        }
                    }),
            ])
            ->deferFilters(false)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Pagador'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('Sin honorarios facturados en el período')
            ->emptyStateDescription(
                'Solo cuentan los honorarios de facturas emitidas en estas fechas. Un honorario cobrado '
                .'sin elegir médico no aparece: no tiene a quién atribuirse.'
            )
            ->recordActions([])
            ->toolbarActions([]);
    }

    // ── Consultas ─────────────────────────────────────────────────────

    /**
     * Lo que lee la tabla: el cargo con la fecha y el número de su
     * factura, y el nombre del ítem para decir a qué turno va.
     *
     * ⚠️ El `select` es explícito: sin él el cruce trae `id`, `total` y
     * `estado` repetidos de cuatro tablas, y el resumen del pie —que mete
     * esta consulta en una subconsulta— reventaría por columnas
     * duplicadas.
     *
     * ⚠️ Sin enumerar columnas en el `with`: una columna que falte no da
     * error, devuelve NULL y la pantalla miente en silencio.
     *
     * @return Builder<Cargo>
     */
    private function consultaDeLaTabla(): Builder
    {
        $consulta = app(ReporteDeHonorarios::class)
            ->facturados($this->desdeEfectivo(), $this->hastaEfectivo())
            ->select([
                'cargos.*',
                'facturas.numero as factura_numero',
                'facturas.fecha_operacion as facturada_el',
                'items.nombre as item_nombre',
                'items.cobra_el_honorario as item_cobra',
            ])
            ->with([
                'medico' => fn ($relacion) => $relacion->withTrashed(),
                'convenio',
                'cuenta.encuentro.persona',
            ]);

        $medico = $this->medicoElegido();
        $turno = $this->turnoElegido();

        if ($medico !== null) {
            ReporteDeHonorarios::soloDeMedicos($consulta)->where('cargos.medico_id', $medico);
        } elseif ($turno !== null) {
            ReporteDeHonorarios::soloDelPersonal($consulta)->where('cargos.item_id', $turno);
        } elseif ($this->sinMedicoElegido()) {
            ReporteDeHonorarios::soloSinMedico($consulta);
        }

        return $consulta;
    }

    /**
     * Las cifras del período, una vez por pintada.
     *
     * @return array{medicos: array<int, array{cuantos: int, importe: Decimal}>, personal: array<int, array{cuantos: int, importe: Decimal}>, sinMedico: array{cuantos: int, importe: Decimal}}
     */
    private function cifras(): array
    {
        $clave = $this->desdeEfectivo().'|'.$this->hastaEfectivo();

        if ($this->cifras !== null && $this->cifrasDe === $clave) {
            return $this->cifras;
        }

        $reporte = app(ReporteDeHonorarios::class);
        $this->cifrasDe = $clave;

        return $this->cifras = [
            'medicos'   => $reporte->totalesPorMedico($this->desdeEfectivo(), $this->hastaEfectivo()),
            'personal'  => $reporte->totalesDelPersonal($this->desdeEfectivo(), $this->hastaEfectivo()),
            'sinMedico' => $reporte->sinMedico($this->desdeEfectivo(), $this->hastaEfectivo()),
        ];
    }

    private function totalDelTodo(): Decimal
    {
        return self::suma($this->cifras()['medicos'])['importe']
            ->sumar(self::suma($this->cifras()['personal'])['importe'])
            ->sumar($this->cifras()['sinMedico']['importe']);
    }

    /**
     * El médico elegido, si tiene honorarios en el período. Si no tiene
     * —se eligió y después se cambió de mes—, se ven todos: si no, el
     * detalle quedaría vacío sin nada arriba que explique por qué. Lo
     * mismo con el turno y con los «sin médico».
     */
    private function medicoElegido(): ?int
    {
        return $this->medico !== null && array_key_exists($this->medico, $this->cifras()['medicos'])
            ? $this->medico
            : null;
    }

    private function turnoElegido(): ?int
    {
        return $this->turno !== null && array_key_exists($this->turno, $this->cifras()['personal'])
            ? $this->turno
            : null;
    }

    private function sinMedicoElegido(): bool
    {
        return $this->sinMedico && $this->cifras()['sinMedico']['cuantos'] > 0;
    }

    private function nadaElegido(): bool
    {
        return $this->medicoElegido() === null && $this->turnoElegido() === null && ! $this->sinMedicoElegido();
    }

    /**
     * El ancho de la barrita de la tarjeta, de 0 a 100. Al menos 2 si
     * facturó algo: una barra vacía en una tarjeta con plata se lee como
     * un error.
     */
    private function barra(Decimal $importe): int
    {
        $total = $this->totalDelTodo();

        return $total->mayorQue('0') && $importe->mayorQue('0')
            ? max(2, (int) $importe->entre($total)->por('100')->redondeado(0))
            : 0;
    }

    /**
     * @param array<int, array{cuantos: int, importe: Decimal}> $cifras
     *
     * @return array{cuantos: int, importe: Decimal}
     */
    private static function suma(array $cifras): array
    {
        $importe = Decimal::cero();
        $cuantos = 0;

        foreach ($cifras as $suyo) {
            $importe = $importe->sumar($suyo['importe']);
            $cuantos += $suyo['cuantos'];
        }

        return ['cuantos' => $cuantos, 'importe' => $importe];
    }

    /**
     * @param list<array{nombre: string, cuantos: int, importe: Decimal}> $pagadores
     *
     * @return list<array{nombre: string, cuantos: int, total: string, parte: string}>
     */
    private static function pagadoresConParte(array $pagadores, Decimal $suyo): array
    {
        $lista = [];

        foreach ($pagadores as $pagador) {
            $lista[] = [
                'nombre'  => $pagador['nombre'],
                'cuantos' => $pagador['cuantos'],
                'total'   => ReporteDeHonorarios::formatear($pagador['importe']),
                'parte'   => ReporteDeHonorarios::parte($pagador['importe'], $suyo),
            ];
        }

        return $lista;
    }

    /**
     * A quién va el renglón, en la tabla de todos: el médico, el turno o
     * área del personal, o «SIN MÉDICO».
     */
    private static function aQuienVa(Cargo $cargo): string
    {
        if ($cargo->getAttribute('item_cobra') === QuienCobraElHonorario::Personal->value) {
            return ReporteDeHonorarios::nombreDelGrupo((string) $cargo->getAttribute('item_nombre'));
        }

        $medico = $cargo->medico;

        return $medico instanceof Medico ? $medico->nombre : 'SIN MÉDICO';
    }

    private function ordenElegido(): string
    {
        return $this->orden === 'total' ? 'total' : 'nombre';
    }

    /**
     * Las dos letras del círculo de la tarjeta: la del nombre y la del
     * primer apellido. Con un solo nombre, sus dos primeras letras.
     */
    private static function iniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);

        if ($partes === false || $partes === []) {
            return '·';
        }

        /*
         * Con cuatro o más partes el primer apellido es el penúltimo
         * («JUAN CARLOS PÉREZ LÓPEZ» es JP); con dos o tres, la segunda.
         */
        $iniciales = count($partes) === 1
            ? mb_substr($partes[0], 0, 2)
            : mb_substr($partes[0], 0, 1).mb_substr($partes[count($partes) >= 4 ? count($partes) - 2 : 1], 0, 1);

        return mb_strtoupper($iniciales);
    }
}
