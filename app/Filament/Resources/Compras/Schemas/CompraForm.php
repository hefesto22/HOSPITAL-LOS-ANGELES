<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\TipoDocumentoFiscal;
use App\Domain\ValueObjects\Decimal;
use App\Models\Proveedor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Registro de una compra — el papel, no la mercadería.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PRIMERA PREGUNTA DECIDE TODO LO DEMÁS
 * ─────────────────────────────────────────────────────────────────────
 *
 * «¿Qué te dio el proveedor?» va primera y sola, porque de ahí depende si
 * el impuesto se puede acreditar. Con **factura** se piden las tres
 * casillas del desglose y el total se suma solo; con **recibo** solo se
 * pide el total, y las otras tres ni aparecen.
 *
 * No es cosmética: si un recibo dejara escribir ISV, ese monto entraría a
 * la declaración mensual como crédito fiscal que no existe. La base lo
 * rechaza con un CHECK, pero llegar hasta ahí sería un error de SQL en la
 * cara de quien captura. Acá directamente no se puede teclear.
 *
 * ⚠️ Esta pantalla NO mueve inventario. Lo que entra al estante se
 * registra en Recepciones, que es otra cosa y otra velocidad.
 */
final class CompraForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::queTeDioElProveedor(),
            self::elProveedor(),
            self::losMontos(),
            self::lasNotas(),
        ]);
    }

    private static function queTeDioElProveedor(): Section
    {
        return Section::make('1 · ¿Qué te dio el proveedor?')
            ->description('De esto depende si el ISV se puede descontar o no.')
            ->schema([
                ToggleButtons::make('tipo_documento')
                    ->hiddenLabel()
                    ->inline()
                    ->grouped()
                    ->options(fn (): array => collect(TipoDocumentoFiscal::cases())
                        ->mapWithKeys(fn (TipoDocumentoFiscal $tipo): array => [
                            $tipo->value => $tipo->etiqueta(),
                        ])
                        ->all())
                    ->colors(fn (): array => collect(TipoDocumentoFiscal::cases())
                        ->mapWithKeys(fn (TipoDocumentoFiscal $tipo): array => [
                            $tipo->value => $tipo->color(),
                        ])
                        ->all())
                    ->default(TipoDocumentoFiscal::Factura->value)
                    ->required()
                    ->live()
                    /*
                     * Al pasar a recibo se limpian las tres casillas del
                     * desglose. El modelo lo vuelve a hacer al guardar
                     * —un import no pasa por acá— pero dejarlas con el
                     * valor viejo escondido sería mostrar un total que no
                     * corresponde a lo que se va a guardar.
                     */
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if (self::tipoDe($state)?->acreditaIsv() === true) {
                            return;
                        }

                        $set('gravado_quince', '0');
                        $set('isv', '0');
                        $set('exento', '0');
                    }),

                Placeholder::make('que_significa')
                    ->hiddenLabel()
                    ->content(fn (Get $get): string => self::tipoDe($get('tipo_documento'))?->explicacion()
                        ?? 'Elegí qué documento te dio el proveedor.'),
            ]);
    }

    private static function elProveedor(): Section
    {
        return Section::make('2 · Proveedor')
            ->columns(3)
            ->schema([
                DatePicker::make('fecha_compra')
                    ->label('Fecha de compra')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now()),

                TextInput::make('numero_documento')
                    ->label(fn (Get $get): string => self::tipoDe($get('tipo_documento'))?->acreditaIsv() === true
                        ? 'N.° de factura'
                        : 'N.° de recibo (si tiene)')
                    ->placeholder('000-001-01-00000657')
                    ->maxLength(40)
                    ->required(fn (Get $get): bool => self::tipoDe($get('tipo_documento'))?->acreditaIsv() === true)
                    ->helperText(fn (Get $get): string => self::tipoDe($get('tipo_documento'))?->acreditaIsv() === true
                        ? 'Es lo que la identifica ante el SAR, y lo que impide cargarla dos veces.'
                        : 'Opcional: hay recibos que no traen número.'),

                Select::make('categoria_gasto')
                    ->label('¿En qué se gastó?')
                    ->options(fn (): array => collect(CategoriaDeGasto::cases())
                        ->mapWithKeys(fn (CategoriaDeGasto $categoria): array => [
                            $categoria->value => $categoria->etiqueta(),
                        ])
                        ->all())
                    ->default(CategoriaDeGasto::Otros->value)
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->helperText('Es lo que contesta el reporte de fin de mes.'),

                Select::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre', fn ($query) => $query->activos())
                    ->getOptionLabelFromRecordUsing(fn (Proveedor $record): string => $record->etiqueta())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->columnSpan(2)
                    ->helperText('Escribí las primeras letras. Si no está, se da de alta en Proveedores.'),

                Placeholder::make('rtn_del_proveedor')
                    ->label('RTN del proveedor')
                    ->content(fn (Get $get): string => self::rtnDe($get('proveedor_id')))
                    ->helperText(fn (Get $get): string => self::tipoDe($get('tipo_documento'))?->acreditaIsv() === true
                        ? 'Necesario para que el SAR acepte el descuento del ISV.'
                        : 'Opcional en un recibo.'),
            ]);
    }

    private static function losMontos(): Section
    {
        return Section::make('3 · Montos')
            ->description(fn (Get $get): string => self::tipoDe($get('tipo_documento'))?->acreditaIsv() === true
                ? 'Copiá los montos tal como vienen en la factura, cada uno en su casilla. El total se suma solo.'
                : 'Solo el total de lo que pagaste.')
            ->columns(3)
            ->schema([
                TextInput::make('gravado_quince')
                    ->label('Gravado 15 %')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('L')
                    ->default('0')
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::acreditaIsv($get))
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::sumarElTotal($get, $set))
                    ->helperText('El importe gravado SIN el impuesto, como lo separa la factura.'),

                TextInput::make('isv')
                    ->label('I.S.V. 15 %')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('L')
                    ->default('0')
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::acreditaIsv($get))
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::sumarElTotal($get, $set))
                    ->helperText('El impuesto tal como lo dice la factura. Es el que se descuenta.'),

                TextInput::make('exento')
                    ->label('Exento')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('L')
                    ->default('0')
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::acreditaIsv($get))
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::sumarElTotal($get, $set))
                    ->helperText('Lo que no paga impuesto, si la factura lo separa.'),

                TextInput::make('total')
                    ->label('Total')
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('L')
                    ->required()
                    ->default('0')
                    /*
                     * En factura NO se teclea: sale de la suma, y la base
                     * exige que cuadre. En recibo sí, porque es el único
                     * monto que hay.
                     */
                    ->readOnly(fn (Get $get): bool => self::acreditaIsv($get))
                    ->helperText(fn (Get $get): string => self::acreditaIsv($get)
                        ? 'Gravado + ISV + exento. Se suma solo.'
                        : 'Lo que pagaste en total.'),
            ]);
    }

    private static function lasNotas(): Section
    {
        return Section::make('Notas')
            ->collapsed()
            ->schema([
                Textarea::make('notas')
                    ->hiddenLabel()
                    ->rows(3)
                    ->placeholder('Opcional: número de orden, a qué área se entregó, cualquier cosa que sirva después.'),
            ]);
    }

    // ── Ayudantes ─────────────────────────────────────────────────────

    /**
     * La suma con bcmath y no con `+`.
     *
     * Son montos de dinero (§8.6.2): `1000.10 + 150.05` en punto flotante
     * no da exactamente `1150.15`, y este total lo compara la base contra
     * el desglose con un CHECK de igualdad. Un centavo de deriva ahí es un
     * error de SQL al guardar.
     */
    private static function sumarElTotal(Get $get, Set $set): void
    {
        $total = Decimal::de(self::comoNumero($get('gravado_quince')))
            ->sumar(self::comoNumero($get('isv')))
            ->sumar(self::comoNumero($get('exento')));

        $set('total', $total->redondeado(2));
    }

    private static function acreditaIsv(Get $get): bool
    {
        return self::tipoDe($get('tipo_documento'))?->acreditaIsv() === true;
    }

    private static function tipoDe(mixed $valor): ?TipoDocumentoFiscal
    {
        if ($valor instanceof TipoDocumentoFiscal) {
            return $valor;
        }

        return is_string($valor) ? TipoDocumentoFiscal::tryFrom($valor) : null;
    }

    private static function rtnDe(mixed $proveedorId): string
    {
        if (! is_numeric($proveedorId)) {
            return 'Elegí un proveedor.';
        }

        $proveedor = Proveedor::query()->find((int) $proveedorId);

        return $proveedor->rtn ?? 'Sin RTN registrado.';
    }

    /**
     * @return numeric-string
     */
    private static function comoNumero(mixed $valor): string
    {
        if (is_int($valor)) {
            return (string) $valor;
        }

        /*
         * 🔴 Un `<input type="number">` llega desde Livewire como float,
         * y sin esta rama caía en el `return '0'` de abajo: el total de
         * la compra se calculaba con los importes en cero, en silencio,
         * mostrando L 0.00 con la pantalla llena de números.
         *
         * Es la misma lección del bloque 5d-1 que ya nos costó una vez.
         * Cuatro decimales y por texto: un float no entra a `Decimal`
         * como float (§8.6.2).
         */
        if (is_float($valor)) {
            return is_finite($valor) ? number_format($valor, 4, '.', '') : '0';
        }

        if (! is_string($valor)) {
            return '0';
        }

        $texto = trim($valor);

        return preg_match('/^-?\d+(\.\d+)?$/', $texto) === 1 && is_numeric($texto)
            ? $texto
            : '0';
    }
}
