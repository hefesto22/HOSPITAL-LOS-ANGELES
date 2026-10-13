<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Schemas;

use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\RangoEdad;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\PersonaVersion;
use App\Support\AtencionesDelPaciente;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * La ficha del paciente.
 *
 * ⚠️ La pestaña "Expedientes" muestra los de TODAS las sedes, y eso es
 * deliberado: la historia clínica se arma por persona. Abrir esta ficha
 * queda registrado en la bitácora de lectura (§9) — ver VerPaciente.
 */
final class PacienteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('ficha')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identidad(),
                    self::documentos(),
                    self::expedientes(),
                    self::historial(),
                ]),
        ]);
    }

    private static function identidad(): Tab
    {
        return Tab::make('Identidad')
            ->icon('heroicon-o-identification')
            ->schema([
                TextEntry::make('nombre')
                    ->label('Nombre')
                    ->state(fn (Persona $record): string => $record->nombreCompleto())
                    ->weight('bold')
                    ->size('lg')
                    ->columnSpanFull(),

                TextEntry::make('apellido_casada')
                    ->label('Apellido de casada')
                    ->placeholder('—'),

                TextEntry::make('sexo_biologico')
                    ->label('Sexo biológico')
                    ->formatStateUsing(fn (Persona $record): string => $record->sexo_biologico->etiqueta())
                    ->helperText('Define rangos de laboratorio y dosis.'),

                TextEntry::make('genero')
                    ->label('Género')
                    ->placeholder('No registrado')
                    ->formatStateUsing(fn (Persona $record): string => $record->genero?->etiqueta() ?? 'No registrado'),

                TextEntry::make('fecha_nacimiento')
                    ->label('Fecha de nacimiento')
                    ->date('d/m/Y')
                    ->placeholder('Desconocida'),

                TextEntry::make('edad')
                    ->label('Edad hoy')
                    ->state(function (Persona $record): string {
                        $anios = $record->edadEn(now());

                        if ($anios === null) {
                            return 'Desconocida';
                        }

                        return $record->fechaNacimientoEsExacta()
                            ? "{$anios} años"
                            : "{$anios} años (estimada)";
                    }),

                TextEntry::make('rango_edad')
                    ->label('Rango legal')
                    ->badge()
                    ->state(fn (Persona $record): ?RangoEdad => $record->rangoDeEdadEn(now()))
                    ->placeholder('Sin fecha de nacimiento')
                    ->formatStateUsing(fn (?RangoEdad $state): string => $state?->etiqueta() ?? 'Sin fecha de nacimiento')
                    ->color(fn (?RangoEdad $state): string => $state?->color() ?? 'gray'),

                Section::make('Alertas')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (Persona $record): bool => $record->es_nn || $record->estaFallecida())
                    ->schema([
                        TextEntry::make('alerta_nn')
                            ->label('Sin identificar')
                            ->badge()
                            ->color('warning')
                            ->state('Pendiente de identificar antes del alta')
                            ->visible(fn (Persona $record): bool => $record->es_nn),

                        TextEntry::make('nota_identificacion')
                            ->label('Rasgos registrados')
                            ->placeholder('—')
                            ->visible(fn (Persona $record): bool => $record->es_nn)
                            ->columnSpanFull(),

                        TextEntry::make('fecha_defuncion')
                            ->label('Fallecido el')
                            ->badge()
                            ->color('danger')
                            ->date('d/m/Y')
                            ->visible(fn (Persona $record): bool => $record->estaFallecida()),
                    ])
                    ->columnSpanFull(),

                Section::make('Contacto')
                    ->schema([
                        TextEntry::make('telefono')->label('Teléfono')->placeholder('—'),
                        TextEntry::make('telefono_alterno')->label('Alterno')->placeholder('—'),
                        TextEntry::make('email')->label('Correo')->placeholder('—'),
                        TextEntry::make('departamento')->label('Departamento')->placeholder('—'),
                        TextEntry::make('municipio')->label('Municipio')->placeholder('—'),
                        TextEntry::make('nacionalidad')->label('Nacionalidad')->placeholder('—'),
                        TextEntry::make('direccion')->label('Dirección')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    private static function documentos(): Tab
    {
        return Tab::make('Documentos')
            ->icon('heroicon-o-rectangle-stack')
            ->schema([
                TextEntry::make('identificadores')
                    ->label('Documentos presentados')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Sin documentos — es un estado válido: el NN y el recién nacido no tienen.')
                    ->state(fn (Persona $record): array => $record->identificadores
                        ->map(fn (PersonaIdentificador $i): string => self::describirDocumento($i))
                        ->all())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * El expediente: las carpetas y las atenciones cerradas, cada una con
     * la descripción automática de a qué vino y qué se le hizo.
     *
     * Rediseño del 12-oct-2026 (Mauricio: «que se vaya guardando cada que
     * se le da el alta»). La atención entra cuando se factura su cuenta y
     * sale si la factura se anula — ver `AtencionesDelPaciente`.
     */
    private static function expedientes(): Tab
    {
        return Tab::make('Expediente')
            ->icon('heroicon-o-folder-open')
            ->badge(fn (Persona $record): ?int => self::cuantasAtenciones($record))
            ->schema([
                View::make('filament.pacientes.atenciones')
                    ->viewData(fn (Persona $record): array => [
                        'atenciones' => app(AtencionesDelPaciente::class)->de($record),
                        'carpetas'   => $record->expedientes()
                            ->deTodasLasSedes()
                            ->with('sede:id,codigo,nombre')
                            ->get()
                            ->map(fn (Expediente $e): array => [
                                'numero' => $e->numero,
                                'sede'   => $e->sede->nombre ?? 'Sede desconocida',
                                'estado' => $e->estado->etiqueta(),
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * El número de la pestaña: cuántas facturas vigentes tiene. Una sola
     * consulta de conteo, no el armado entero.
     */
    private static function cuantasAtenciones(Persona $persona): ?int
    {
        $cuantas = Factura::query()
            ->deTodasLasSedes()
            ->where('persona_id', $persona->id)
            ->where('estado', EstadoFactura::Emitida->value)
            ->whereNotNull('encuentro_id')
            ->distinct()
            ->count('encuentro_id');

        return $cuantas > 0 ? $cuantas : null;
    }

    private static function historial(): Tab
    {
        return Tab::make('Historial de cambios')
            ->icon('heroicon-o-clock')
            ->schema([
                TextEntry::make('versiones')
                    ->label('Versiones de los datos')
                    ->listWithLineBreaks()
                    ->placeholder('Sin cambios registrados.')
                    ->state(fn (Persona $record): array => $record->versiones()
                        ->with('registradoPor:id,name')
                        ->orderByDesc('version')
                        ->limit(20)
                        ->get()
                        ->map(fn (PersonaVersion $v): string => sprintf(
                            'v%d · %s · %s · %s',
                            $v->version,
                            $v->registrado_en->format('d/m/Y H:i'),
                            $v->motivo ?? 'Sin motivo registrado',
                            $v->registradoPor->name ?? 'Sistema',
                        ))
                        ->all())
                    ->helperText(
                        'Append-only: una versión no se modifica ni se borra, lo impide un trigger de '
                        .'la base. Corregir se hace insertando otra versión, como en contabilidad.'
                    )
                    ->columnSpanFull(),
            ]);
    }

    private static function describirDocumento(PersonaIdentificador $identificador): string
    {
        $partes = [$identificador->tipo->etiqueta(), $identificador->formateado()];

        if ($identificador->es_principal) {
            $partes[] = '(principal)';
        }

        if ($identificador->en_conflicto) {
            $partes[] = '⚠ EN CONFLICTO';
        }

        if (! $identificador->estaVerificado()) {
            $partes[] = '— sin verificar contra el documento físico';
        }

        return implode(' ', $partes);
    }
}
