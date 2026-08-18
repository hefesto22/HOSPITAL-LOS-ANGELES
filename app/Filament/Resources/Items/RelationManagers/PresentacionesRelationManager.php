<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;

/**
 * Presentaciones de compra de un ítem.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SE CARGA ACÁ Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Acá se dice CÓMO VIENE el producto del proveedor: "CAJA X 100
 * AMPOLLAS", "CAJA X 50". No se dice cuánto cuesta ni cuánto hay — el
 * costo vive en el kardex y el precio en el tarifario.
 *
 * El kardex sigue llevándose SIEMPRE en la unidad de dispensación del
 * ítem. Cada fila de acá solo declara cuántas de esas unidades trae el
 * envase, para que quien recibe la compra elija "CAJA X 50" de una lista
 * y el factor lo ponga el sistema. Con una sola equivalencia en el ítem,
 * la segunda compra se convierte a mano — y ahí nace el costo cien veces
 * más alto que nadie nota hasta el cierre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CÓDIGO DE BARRAS: DOS FORMAS DE ENTRARLO, UN SOLO CAMPO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `BarcodeInput` extiende `TextInput`, y de ahí sale que funcione con
 * las dos cosas:
 *
 *   · **Lector de mano USB o Bluetooth** — se comporta como un teclado:
 *     escribe los dígitos en el campo enfocado y manda Enter. No hace
 *     falta nada especial, y es lo que se va a usar en el mostrador.
 *   · **Cámara del celular o la tablet** — el botón del costado abre un
 *     modal y escanea. Es para el conteo caminando entre estanterías,
 *     donde no hay lector enchufado.
 *
 * ⚠️ La cámara **exige contexto seguro** (HTTPS o localhost). Si mañana
 * se entra a la tablet por `http://192.168.x.x`, el navegador bloquea la
 * cámara sin avisar por qué. El lector de mano no tiene ese problema.
 */
class PresentacionesRelationManager extends RelationManager
{
    protected static string $relationship = 'presentaciones';

    protected static ?string $title = 'Presentaciones de compra';

    protected static function getModelLabel(): ?string
    {
        return 'presentación';
    }

    protected static function getPluralModelLabel(): ?string
    {
        return 'presentaciones';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(80)
                    ->placeholder('CAJA X 100 AMPOLLAS')
                    ->helperText('Como lo pide quien compra. Se guarda en mayúsculas.'),

                Select::make('unidad_id')
                    ->label('Unidad del envase')
                    ->options(fn (): array => Unidad::query()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Unidad $u): array => [$u->getKey() => $u->etiqueta()])
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->helperText('CAJA, FRASCO, BLÍSTER — el envase, no la unidad en la que se dispensa.'),

                TextInput::make('unidades_por_presentacion')
                    ->label($this->etiquetaDelContenido())
                    ->required()
                    ->numeric()
                    ->minValue(0.0001)
                    ->step(0.0001)
                    ->helperText($this->ayudaDelContenido())
                    ->rules([
                        fn (?ItemPresentacion $record, Get $get): Closure => function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($record, $get): void {
                            $this->verificarQueNoSeRepita($record, $get, $value, $fail);
                        },
                    ]),

                BarcodeInput::make('codigo_barras')
                    ->label('Código de barras')
                    ->maxLength(50)
                    ->helperText(
                        'Escaneá con el lector de mano —escribe solo— o con la cámara, tocando el '
                        .'ícono. Es el código del ENVASE, no el del contenido.'
                    )
                    /*
                     * El índice de la base es PARCIAL sobre lo no borrado.
                     * Sin el `whereNull`, una presentación dada de baja
                     * bloquearía su código para siempre y nadie entendería
                     * por qué el escáner "dice que ya existe".
                     */
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->whereNull('deleted_at'),
                    )
                    ->validationMessages([
                        'unique' => 'Ese código de barras ya está en otra presentación del catálogo.',
                    ]),

                Toggle::make('es_predeterminada')
                    ->label('Es la presentación habitual de compra')
                    ->columnSpanFull()
                    ->helperText(
                        'La que propone el formulario de compra. Solo puede haber una: al marcar '
                        .'esta, la anterior se desmarca sola.'
                    ),

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
                    ->helperText('Dejar vacío mientras el proveedor la siga surtiendo.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                TextColumn::make('nombre')
                    ->label('Presentación')
                    ->weight('medium')
                    ->description(fn (ItemPresentacion $record): ?string => $record->codigo_barras),

                TextColumn::make('unidad.codigo')
                    ->label('Envase')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('unidades_por_presentacion')
                    ->label('Contiene')
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.'))
                    ->suffix(fn (): string => ' '.$this->codigoDeDispensacion())
                    ->tooltip(fn (): string => 'En '.$this->unidadDeDispensacion()),

                IconColumn::make('es_predeterminada')
                    ->label('Habitual')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip('La que propone el formulario de compra.'),

                TextColumn::make('vigencia_hasta')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('es_predeterminada', 'desc')
            ->paginated(false)
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar presentación')
                    ->modalHeading('Nueva presentación de compra')
                    ->modalWidth('2xl'),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Editar presentación')
                    ->modalWidth('2xl'),
            ])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('Sin presentaciones cargadas')
            ->emptyStateDescription(
                'Mientras no haya ninguna, quien reciba una compra tiene que convertir a mano de '
                .'caja a unidad — y esa cuenta hecha a ojo es el costo cien veces más alto que '
                .'aparece recién en el cierre.'
            );
    }

    /**
     * Solo se ofrece en ítems físicos: una consulta médica no viene en caja.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Item && $ownerRecord->mueveInventario();
    }

    // ── Textos que dependen del ítem ──────────────────────────────────

    private function unidadDeDispensacion(): string
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return 'unidades';
        }

        /*
         * Sin nullsafe: el analizador tipa la relación como no nula y
         * `?->` sobra, pero la columna SÍ es nullable. `instanceof`
         * describe la realidad y no discute con nadie.
         */
        $unidad = $duenio->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->nombre : 'unidades';
    }

    /**
     * El CÓDIGO de la unidad, no su nombre.
     *
     * Se usa en la etiqueta y en la columna a propósito: un código es un
     * símbolo y no se declina. Con el nombre salía «Cuántas tableta
     * trae» y «100 TABLETA», y pluralizar en español no se resuelve
     * agregando una ese — UNIDAD INTERNACIONAL, MILILITRO y VIAL hacen
     * cada uno lo suyo.
     */
    private function codigoDeDispensacion(): string
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return 'unidades';
        }

        $unidad = $duenio->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->codigo : 'unidades';
    }

    private function etiquetaDelContenido(): string
    {
        return 'Cuánto trae, en '.$this->codigoDeDispensacion();
    }

    private function ayudaDelContenido(): string
    {
        return 'Una caja de 100 ampollas lleva 100. El kardex se lleva SIEMPRE en la unidad de '
            .'dispensación del ítem —acá '.$this->unidadDeDispensacion().'—, nunca en cajas.';
    }

    // ── Validación ────────────────────────────────────────────────────

    /**
     * Dos filas con la misma unidad y el mismo contenido son la misma
     * presentación cargada dos veces.
     *
     * La base ya lo impide con un índice único, pero ese camino termina
     * en un error de SQL crudo en la cara de quien carga el catálogo. Acá
     * se dice qué pasó y cómo arreglarlo.
     */
    private function verificarQueNoSeRepita(
        ?ItemPresentacion $record,
        Get $get,
        mixed $value,
        Closure $fail,
    ): void {
        $unidadId = $get('unidad_id');

        if (! is_numeric($value) || ! is_numeric($unidadId)) {
            return;
        }

        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return;
        }

        $repetida = ItemPresentacion::query()
            ->where('item_id', $duenio->getKey())
            ->where('unidad_id', (int) $unidadId)
            ->where('unidades_por_presentacion', (string) $value)
            ->when(
                $record instanceof ItemPresentacion,
                fn ($consulta) => $consulta->whereKeyNot($record?->getKey()),
            )
            ->first();

        if ($repetida instanceof ItemPresentacion) {
            $fail("Ya existe esa presentación: «{$repetida->nombre}». Editala en vez de crear otra igual.");
        }
    }
}
