<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Unidad;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Formulario del catálogo — patrón §10.
 *
 * ⚠️ Los closures de Filament reciben sus argumentos POR NOMBRE, no por
 * tipo: `$get`, `$set`, `$state`, `$record`. Un parámetro con otro nombre
 * recibe un objeto vacío resuelto del contenedor y falla EN SILENCIO —
 * sin excepción y sin log. Ya costó un filtro que no filtraba.
 */
final class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('item')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identificacion(),
                    self::dineroYLey(),
                    self::unidades(),
                    self::farmacia(),
                    self::codigosYContabilidad(),
                    self::vigencia(),
                ]),
        ]);
    }

    private static function identificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-rectangle-stack')
            ->schema([
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(30)
                    ->disabledOn('edit')
                    ->helperText('Es lo que se teclea en el mostrador. Corto y estable — no se puede cambiar después.'),

                Select::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => collect(TipoItem::cases())
                        ->mapWithKeys(fn (TipoItem $t): array => [$t->value => $t->etiqueta()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->live()
                    /*
                     * Al elegir el tipo se proponen los campos que se
                     * derivan de él. Se PROPONEN: quien carga el catálogo
                     * los puede cambiar, porque el honorario de un
                     * cardiólogo es especializada y el genérico no.
                     */
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $tipo = TipoItem::tryFrom($state);

                        if (! $tipo instanceof TipoItem) {
                            return;
                        }

                        $set('categoria_legal_descuento', CategoriaLegalDeDescuento::sugeridaPara($tipo)->value);
                        $set('requiere_lote', $tipo->requiereLote());
                    })
                    ->helperText('Define si mueve inventario y si su precio se deriva del costo.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Como debe salir impreso en la cuenta del paciente.'),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Opcional. Para aclarar qué incluye y qué no — se lee cuando alguien duda al cargar.'),
            ])
            ->columns(2);
    }

    private static function dineroYLey(): Tab
    {
        return Tab::make('ISV y descuentos')
            ->icon('heroicon-o-scale')
            ->schema([
                Section::make('Impuesto sobre ventas')
                    ->description(
                        'El ISV se determina POR LÍNEA, nunca por factura: una misma cuenta mezcla '
                        .'hospitalización exenta con una liposucción gravada y con la cafetería. '
                        .'La mayor parte de este negocio es exenta por el Art. 15 de la Ley del ISV.'
                    )
                    ->schema([
                        Select::make('regimen_isv')
                            ->label('Régimen de ISV')
                            ->options(fn (): array => collect(RegimenIsv::cases())
                                ->mapWithKeys(fn (RegimenIsv $r): array => [$r->value => $r->etiqueta()])
                                ->all())
                            ->required()
                            ->default(RegimenIsv::Exento->value)
                            ->native(false)
                            ->helperText(
                                'Medicamentos, material de curación, hospitalización, laboratorio e '
                                .'imagen son EXENTOS. Tratamiento de belleza estética, cafetería y '
                                .'parqueo van gravados.'
                            ),

                        Select::make('politica_cargo')
                            ->label('Cómo se cobra')
                            ->options(fn (): array => collect(PoliticaCargo::cases())
                                ->mapWithKeys(fn (PoliticaCargo $p): array => [$p->value => $p->etiqueta()])
                                ->all())
                            ->required()
                            ->default(PoliticaCargo::Cobrable->value)
                            ->native(false)
                            ->helperText(
                                'Lo que no se le cobra al paciente igual SALE de bodega: se imputa al '
                                .'procedimiento o al centro de costo del área.'
                            ),
                    ])
                    ->columns(2),

                Section::make('Descuento de adulto mayor')
                    ->description(
                        'Es obligación legal, no política comercial: Artículo 30 de la Ley Integral de '
                        .'Protección al Adulto Mayor y Jubilados. El porcentaje depende del numeral bajo '
                        .'el que cae el ítem, y aplica desde los 60 años.'
                    )
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Select::make('categoria_legal_descuento')
                            ->label('Categoría legal')
                            ->options(fn (): array => collect(CategoriaLegalDeDescuento::cases())
                                ->mapWithKeys(fn (CategoriaLegalDeDescuento $c): array => [
                                    $c->value => $c->etiqueta(),
                                ])
                                ->all())
                            ->required()
                            ->native(false)
                            ->live()
                            ->columnSpanFull()
                            ->helperText(function (?string $state): string {
                                $categoria = $state === null
                                    ? null
                                    : CategoriaLegalDeDescuento::tryFrom($state);

                                if (! $categoria instanceof CategoriaLegalDeDescuento) {
                                    return 'El porcentaje sale del numeral del Art. 30 que corresponda.';
                                }

                                $texto = $categoria->explicacion();

                                if ($categoria->exigeReceta()) {
                                    $texto .= ' · Exige receta original firmada y sellada (Art. 34).';
                                }

                                return $texto;
                            }),
                    ]),
            ]);
    }

    private static function unidades(): Tab
    {
        return Tab::make('Unidades')
            ->icon('heroicon-o-beaker')
            ->schema([
                Section::make('Unidad del kardex')
                    ->description(
                        'La existencia se lleva SIEMPRE en la unidad mínima en la que se dispensa. '
                        .'Llevarla en unidad de compra obliga a fracciones en cada salida y hace '
                        .'imposible cuadrar. Cuántas caben en una caja lo dice cada presentación.'
                    )
                    ->schema([
                        Select::make('unidad_dispensacion_id')
                            ->label('Se dispensa en')
                            ->options(fn (): array => self::opcionesDeUnidad())
                            ->searchable()
                            ->native(false)
                            ->required(fn (Get $get): bool => self::esFisico($get))
                            ->helperText('Obligatorio para medicamentos e insumos: sin esto no se puede costear.'),
                    ]),

                Section::make('Fraccionamiento')
                    ->description(
                        'Un frasco de nebulización se puede fraccionar; una ampolla no. Para un ítem '
                        .'fraccionable, quien dispensa elige entre cobrar la dosis aplicada o el envase '
                        .'completo — y el sobrante que se descarta sale del kardex como merma, no como venta.'
                    )
                    ->schema([
                        Toggle::make('fraccionable')
                            ->label('Se puede fraccionar')
                            ->live()
                            ->columnSpanFull(),

                        Select::make('unidad_fraccion_id')
                            ->label('Se fracciona en')
                            ->options(fn (): array => self::opcionesDeUnidad())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('fraccionable') === true)
                            ->required(fn (Get $get): bool => $get('fraccionable') === true),

                        TextInput::make('fracciones_por_unidad')
                            ->label('Fracciones por unidad')
                            ->numeric()
                            ->minValue(0.0001)
                            ->step(0.0001)
                            ->visible(fn (Get $get): bool => $get('fraccionable') === true)
                            ->required(fn (Get $get): bool => $get('fraccionable') === true)
                            ->helperText('Una ampolla de 2 ml lleva 2.'),

                        TextInput::make('horas_caducidad_post_apertura')
                            ->label('Horas de vida una vez abierto')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => $get('fraccionable') === true)
                            ->placeholder('Usar el valor por defecto de la instalación')
                            ->helperText(
                                'Muchos multidosis vencen a las 24-48 h de abiertos, sin importar la '
                                .'fecha impresa en el frasco.'
                            ),
                    ])
                    ->columns(3),
            ]);
    }

    private static function farmacia(): Tab
    {
        return Tab::make('Farmacia')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (Get $get): bool => self::esFisico($get))
            ->schema([
                Section::make('Control sanitario')
                    ->schema([
                        Toggle::make('requiere_lote')
                            ->label('Exige lote y vencimiento')
                            ->helperText('Obligatorio en medicamentos por trazabilidad ante ARSA.'),

                        Toggle::make('es_controlado')
                            ->label('Estupefaciente o psicotrópico')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                /*
                                 * Un controlado sin receta es una
                                 * infracción ante ARSA. La base lo
                                 * rechaza y el modelo lo corrige; acá se
                                 * ahorra el viaje y se ve en pantalla.
                                 */
                                if ($state === true) {
                                    $set('requiere_receta', true);
                                }
                            })
                            ->helperText('Activa libro con saldo corrido y reporte mensual a ARSA.'),

                        Toggle::make('requiere_receta')
                            ->label('Exige receta para dispensar')
                            ->disabled(fn (Get $get): bool => $get('es_controlado') === true)
                            ->dehydrated()
                            ->helperText('Un controlado siempre la exige y no se puede desmarcar.'),
                    ])
                    ->columns(3),

                Section::make('Identificación del producto')
                    ->schema([
                        CampoMayusculas::make('principio_activo')
                            ->label('Principio activo')
                            ->maxLength(255)
                            ->helperText('Se incluye en la búsqueda: escribir "paracetamol" encuentra el acetaminofén.'),

                        CampoMayusculas::make('presentacion_comercial')
                            ->label('Presentación comercial')
                            ->maxLength(255)
                            ->helperText('Como aparece en la caja: "TABLETA 500 MG", "SOLUCIÓN INYECTABLE 2 ML".'),

                        TextInput::make('registro_arsa')
                            ->label('Registro sanitario ARSA')
                            ->maxLength(50)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function codigosYContabilidad(): Tab
    {
        return Tab::make('Códigos y contabilidad')
            ->icon('heroicon-o-hashtag')
            ->schema([
                Section::make('Códigos estándar')
                    ->description(
                        'Opcionales y nunca llave: el ítem se identifica por su código interno. Estos '
                        .'sirven para hablar con afuera — CIE-10 con SESAL y las aseguradoras, LOINC con '
                        .'los analizadores, ATC para clasificar el medicamento.'
                    )
                    ->schema([
                        TextInput::make('codigo_cie10')
                            ->label('CIE-10')
                            ->maxLength(10)
                            ->placeholder('J18.9'),

                        TextInput::make('codigo_loinc')
                            ->label('LOINC')
                            ->maxLength(20)
                            ->placeholder('718-7'),

                        TextInput::make('codigo_atc')
                            ->label('ATC')
                            ->maxLength(10)
                            ->placeholder('N02BE01'),

                        TextInput::make('version_codificacion')
                            ->label('Versión de la codificación')
                            ->maxLength(20)
                            ->placeholder('CIE-10 2019')
                            ->helperText('Migrar a CIE-11 tiene que ser cambio de datos, no de esquema.'),
                    ])
                    ->columns(4),

                Section::make('Contabilidad')
                    ->description(
                        'Se mapea desde el día uno. Hacerlo dos años después es un proyecto de meses '
                        .'sobre millones de filas de cargo.'
                    )
                    ->schema([
                        TextInput::make('cuenta_contable')
                            ->label('Cuenta contable')
                            ->maxLength(30),

                        TextInput::make('centro_de_costo')
                            ->label('Centro de costo')
                            ->maxLength(30),
                    ])
                    ->columns(2),
            ]);
    }

    private static function vigencia(): Tab
    {
        return Tab::make('Vigencia')
            ->icon('heroicon-o-calendar-days')
            ->schema([
                Section::make('Desde cuándo y hasta cuándo se ofrece')
                    ->description(
                        'El catálogo tiene vigencia, no un botón de activo. Un servicio que deja de '
                        .'ofrecerse sigue teniendo que explicar la factura donde aparece.'
                    )
                    ->schema([
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
                            ->helperText('Dejar vacío mientras se siga ofreciendo.'),
                    ])
                    ->columns(2),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creado por')
                            ->placeholder('Sistema'),

                        TextEntry::make('updated_at')
                            ->label('Última modificación')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    private static function opcionesDeUnidad(): array
    {
        return Unidad::query()
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Unidad $u): array => [$u->getKey() => $u->etiqueta()])
            ->all();
    }

    /**
     * ¿El tipo elegido mueve inventario?
     *
     * Se lee del formulario, no del modelo: en el alta todavía no hay
     * modelo, y en la edición el usuario puede estar cambiando el tipo
     * justo ahora.
     */
    private static function esFisico(Get $get): bool
    {
        $valor = $get('tipo');

        if (! is_string($valor)) {
            return false;
        }

        return TipoItem::tryFrom($valor)?->mueveInventario() ?? false;
    }
}
