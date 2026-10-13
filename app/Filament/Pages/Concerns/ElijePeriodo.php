<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use Carbon\CarbonImmutable;

/**
 * El período de un reporte: atajos arriba y dos fechas al lado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ UN TRAIT (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Honorarios por médico y Consumo de productos hacen la misma pregunta
 * —«¿de qué período?»— con atajos distintos: los honorarios se liquidan
 * por quincena; el consumo se mira por día, semana o mes. La lógica de
 * las fechas es una sola y vive acá; cada página dice qué atajos ofrece
 * con `atajosDelPeriodo()`.
 *
 * Las fechas son `Y-m-d` y nulas = el mes en curso. Las escribe el
 * navegador (los dos campos de fecha), así que todo lo que entra se
 * valida con `fechaValida()` antes de tocar una consulta.
 *
 * ⚠️ La página tiene que usar `InteractsWithTable`: cambiar el período
 * vuelve a la primera página de la tabla.
 */
trait ElijePeriodo
{
    public ?string $desde = null;

    public ?string $hasta = null;

    private const MESES_DEL_PERIODO = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * Qué atajos ofrece esta página, en orden: clave => etiqueta. Las
     * claves que se entienden son las de `rangoDelAtajo()`.
     *
     * @return array<string, string>
     */
    abstract protected function atajosDelPeriodo(): array;

    /**
     * Lee `?desde=` y `?hasta=` a mano y no con `#[Url]`: Livewire
     * asignaría el valor crudo a la propiedad tipada, y un enlace mal
     * pegado reventaría con un TypeError en vez de ignorarse (la misma
     * lección de `BasesDePrecios`).
     */
    protected function leerElPeriodoDeLaUrl(): void
    {
        $this->desde = self::fechaValida(request()->query('desde')) ?? now()->startOfMonth()->toDateString();
        $this->hasta = self::fechaValida(request()->query('hasta')) ?? now()->endOfMonth()->toDateString();

        $this->ordenarElRango();
    }

    /**
     * Los atajos de arriba, con su rango y cuál coincide con el actual.
     *
     * @return list<array{clave: string, etiqueta: string, rango: string, activo: bool}>
     */
    public function periodos(): array
    {
        $lista = [];

        foreach ($this->atajosDelPeriodo() as $clave => $etiqueta) {
            [$desde, $hasta] = self::rangoDelAtajo($clave);

            $lista[] = [
                'clave'    => $clave,
                'etiqueta' => $etiqueta,
                'rango'    => $desde === $hasta
                    ? CarbonImmutable::parse($desde)->format('d/m')
                    : CarbonImmutable::parse($desde)->format('d/m').' – '.CarbonImmutable::parse($hasta)->format('d/m'),
                'activo' => $desde === $this->desdeEfectivo() && $hasta === $this->hastaEfectivo(),
            ];
        }

        return $lista;
    }

    public function usarPeriodo(string $clave): void
    {
        if (! array_key_exists($clave, $this->atajosDelPeriodo())) {
            return;
        }

        [$this->desde, $this->hasta] = self::rangoDelAtajo($clave);

        $this->resetPage();
    }

    public function updatedDesde(): void
    {
        $this->ordenarElRango();
        $this->resetPage();
    }

    public function updatedHasta(): void
    {
        $this->ordenarElRango();
        $this->resetPage();
    }

    /**
     * Cómo se lee el período: «Octubre 2026» si es un mes entero, una
     * fecha si es un solo día, o las dos puntas.
     */
    public function periodo(): string
    {
        $desde = CarbonImmutable::parse($this->desdeEfectivo());
        $hasta = CarbonImmutable::parse($this->hastaEfectivo());

        if ($desde->day === 1 && $hasta->isSameDay($desde->endOfMonth())) {
            return ucfirst(self::MESES_DEL_PERIODO[$desde->month]).' '.$desde->year;
        }

        if ($desde->isSameDay($hasta)) {
            return $desde->format('d/m/Y');
        }

        return $desde->format('d/m/Y').' — '.$hasta->format('d/m/Y');
    }

    protected function desdeEfectivo(): string
    {
        return self::fechaValida($this->desde) ?? now()->startOfMonth()->toDateString();
    }

    protected function hastaEfectivo(): string
    {
        return self::fechaValida($this->hasta) ?? now()->endOfMonth()->toDateString();
    }

    /**
     * Deja las dos fechas válidas y en orden: si alguien pone un «hasta»
     * anterior al «desde», se dan vuelta en vez de mostrar una tabla
     * vacía sin explicación.
     */
    protected function ordenarElRango(): void
    {
        $desde = $this->desdeEfectivo();
        $hasta = $this->hastaEfectivo();

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $this->desde = $desde;
        $this->hasta = $hasta;
    }

    /**
     * Las quincenas parten el mes el día 15, que es como se liquidan
     * los honorarios. La semana empieza el lunes.
     *
     * @return array{0: string, 1: string}
     */
    protected static function rangoDelAtajo(string $clave): array
    {
        $hoy = now()->toImmutable()->startOfDay();
        $mes = $hoy->startOfMonth();
        $anterior = $mes->subMonthNoOverflow();
        $enLaPrimera = $hoy->day <= 15;
        $lunes = $hoy->subDays($hoy->dayOfWeekIso - 1);

        [$desde, $hasta] = match ($clave) {
            'hoy'           => [$hoy, $hoy],
            'esta_semana'   => [$lunes, $lunes->addDays(6)],
            'esta_quincena' => $enLaPrimera
                ? [$mes, $mes->addDays(14)]
                : [$mes->addDays(15), $mes->endOfMonth()],
            'quincena_pasada' => $enLaPrimera
                ? [$anterior->addDays(15), $anterior->endOfMonth()]
                : [$mes, $mes->addDays(14)],
            'mes_pasado' => [$anterior, $anterior->endOfMonth()],
            default      => [$mes, $mes->endOfMonth()],
        };

        return [$desde->toDateString(), $hasta->toDateString()];
    }

    protected static function fechaValida(mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) !== 1) {
            return null;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : null;
    }
}
