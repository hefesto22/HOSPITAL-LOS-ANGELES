<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Caja;
use App\Filament\Pages\CuentasAbiertas;
use App\Filament\Pages\Existencias;
use App\Filament\Resources\Facturas\FacturaResource;
use App\Filament\Resources\Pacientes\PacienteResource;
use App\Filament\Resources\Prestamos\PrestamoResource;
use App\Filament\Resources\Presupuestos\PresupuestoResource;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Resources\Recepciones\RecepcionResource;
use App\Models\Cuenta;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * El saludo y los botones grandes del escritorio (Mauricio, 21-oct-2026).
 *
 * Cada botón sale de lo que quien entra PUEDE abrir —el mismo
 * `canAccess()` que decide el menú—, no de una lista por rol: si
 * mañana caja deja de ver facturas, el botón se va solo, sin tocar esto.
 *
 * «Abrir cuenta» va primero y destacado para quien la abre: es lo que
 * laboratorio, consulta externa y admisión hacen apenas llega alguien.
 * Lleva `?action=abrirCuenta`, que Filament monta al cargar la página:
 * un clic y ya está el formulario, no dos.
 *
 * Sin `lazy`: no consulta la base (los permisos ya están en memoria),
 * y es lo primero que se tiene que ver.
 */
class AccesosDelEscritorio extends Widget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.accesos-del-escritorio';

    /** @var array<string, string> */
    private const ROLES = [
        'super_admin'      => 'Soporte',
        'direccion'        => 'Dirección',
        'admision'         => 'Admisión',
        'caja'             => 'Caja',
        'medico'           => 'Médico',
        'enfermeria'       => 'Enfermería',
        'farmacia'         => 'Farmacia',
        'laboratorio'      => 'Laboratorio',
        'consulta_externa' => 'Consulta externa',
        'imagenes'         => 'Imágenes',
        'bodega'           => 'Bodega',
        'auditoria'        => 'Auditoría',
    ];

    public static function canView(): bool
    {
        return Auth::user() instanceof User;
    }

    /**
     * «Buenos días» hasta el mediodía, «buenas tardes» hasta las seis y
     * «buenas noches» después: el turno de la noche también entra.
     */
    public function saludo(): string
    {
        $hora = (int) now()->format('G');

        return match (true) {
            $hora < 12 => 'Buenos días',
            $hora < 18 => 'Buenas tardes',
            default    => 'Buenas noches',
        };
    }

    public function nombre(): string
    {
        $usuario = Auth::user();

        if (! $usuario instanceof User) {
            return '';
        }

        $primero = strtok(trim((string) $usuario->name), ' ');

        return mb_convert_case(mb_strtolower($primero === false ? '' : $primero), MB_CASE_TITLE);
    }

    public function roles(): string
    {
        $usuario = Auth::user();

        if (! $usuario instanceof User) {
            return '';
        }

        return $usuario->getRoleNames()
            ->map(fn (mixed $rol): string => is_string($rol) ? (self::ROLES[$rol] ?? $rol) : '')
            ->filter()
            ->implode(' · ');
    }

    public function hoy(): string
    {
        return Str::ucfirst(now()->translatedFormat('l j \d\e F'));
    }

    /**
     * @return list<array{etiqueta: string, detalle: string, url: string, icono: string, principal: bool}>
     */
    public function accesos(): array
    {
        $accesos = [];

        if (CuentasAbiertas::canAccess() && Gate::allows('create', Cuenta::class)) {
            $accesos[] = $this->acceso(
                'Abrir cuenta',
                'Al paciente que acaba de llegar',
                CuentasAbiertas::getUrl(['action' => 'abrirCuenta']),
                'heroicon-o-plus-circle',
                principal: true,
            );
        }

        if (CuentasAbiertas::canAccess()) {
            $accesos[] = $this->acceso('Cuentas abiertas', 'Agregar, abonar y facturar', CuentasAbiertas::getUrl(), 'heroicon-o-rectangle-stack');
        }

        if (PacienteResource::canAccess()) {
            $accesos[] = $this->acceso('Pacientes', 'Buscar o registrar', PacienteResource::getUrl(), 'heroicon-o-user-group');
        }

        if (Caja::canAccess()) {
            $accesos[] = $this->acceso('Caja', 'Turno, gaveta y recibos', Caja::getUrl(), 'heroicon-o-banknotes');
        }

        if (FacturaResource::canAccess()) {
            $accesos[] = $this->acceso('Facturas', 'Lo emitido y lo anulado', FacturaResource::getUrl(), 'heroicon-o-document-text');
        }

        if (PresupuestoResource::canAccess()) {
            $accesos[] = $this->acceso('Presupuestos', 'Cirugías y paquetes', PresupuestoResource::getUrl(), 'heroicon-o-calculator');
        }

        if (Existencias::canAccess()) {
            $accesos[] = $this->acceso('Existencias', 'Qué hay y en qué estante', Existencias::getUrl(), 'heroicon-o-squares-2x2');
        }

        if (RecepcionResource::canCreate()) {
            $accesos[] = $this->acceso('Recibir mercadería', 'Lo que entró hoy', RecepcionResource::getUrl('create'), 'heroicon-o-inbox-arrow-down');
        }

        if (ProductoResource::canAccess()) {
            $accesos[] = $this->acceso('Productos', 'Medicamentos e insumos', ProductoResource::getUrl(), 'heroicon-o-beaker');
        }

        if (PrestamoResource::canAccess()) {
            $accesos[] = $this->acceso('Préstamos', 'Lo que nos prestaron', PrestamoResource::getUrl(), 'heroicon-o-arrows-right-left');
        }

        return $accesos;
    }

    /**
     * @return array{etiqueta: string, detalle: string, url: string, icono: string, principal: bool}
     */
    private function acceso(string $etiqueta, string $detalle, string $url, string $icono, bool $principal = false): array
    {
        return [
            'etiqueta'  => $etiqueta,
            'detalle'   => $detalle,
            'url'       => $url,
            'icono'     => $icono,
            'principal' => $principal,
        ];
    }
}
