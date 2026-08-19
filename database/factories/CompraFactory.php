<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\TipoDocumentoFiscal;
use App\Models\Compra;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    /**
     * Una factura que cuadra: L 1.000 gravado + L 150 de ISV.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor_id'     => Proveedor::factory(),
            'tipo_documento'   => TipoDocumentoFiscal::Factura,
            'numero_documento' => '000-001-01-'.$this->faker->unique()->numerify('########'),
            'fecha_compra'     => '2026-08-17',
            'categoria_gasto'  => CategoriaDeGasto::Medicamentos,
            'gravado_quince'   => '1000.00',
            'isv'              => '150.00',
            'exento'           => '0.00',
            'total'            => '1150.00',
        ];
    }

    public function delProveedor(Proveedor $proveedor): self
    {
        return $this->state(fn (): array => ['proveedor_id' => $proveedor->id]);
    }

    /**
     * Sin factura: solo el total, y no acredita ISV.
     */
    /**
     * @param numeric-string $total
     */
    public function recibo(string $total = '500.00'): self
    {
        return $this->state(fn (): array => [
            'tipo_documento'   => TipoDocumentoFiscal::ReciboDeCompra,
            'numero_documento' => null,
            'gravado_quince'   => '0.00',
            'isv'              => '0.00',
            'exento'           => '0.00',
            'total'            => $total,
        ]);
    }

    /**
     * @param numeric-string $gravado
     * @param numeric-string $isv
     * @param numeric-string $exento
     */
    public function conMontos(string $gravado, string $isv, string $exento): self
    {
        return $this->state(fn (): array => [
            'gravado_quince' => $gravado,
            'isv'            => $isv,
            'exento'         => $exento,
            'total'          => bcadd(bcadd($gravado, $isv, 2), $exento, 2),
        ]);
    }

    public function de(CategoriaDeGasto $categoria): self
    {
        return $this->state(fn (): array => ['categoria_gasto' => $categoria]);
    }
}
