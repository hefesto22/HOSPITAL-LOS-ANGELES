<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\OrigenDelPrecio;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ⚠️ Solo para armar escenarios de permisos y de lectura.
 *
 * Un cargo REAL lo asienta `RegistradorDeCargo`, que resuelve el precio,
 * el descuento de ley, el ISV y la cobertura, y actualiza los totales de
 * la cuenta en la misma transacción. Uno fabricado acá deja la cuenta en
 * cero y **no cuadra con sus totales a propósito**: si un test necesita
 * que cuadren, tiene que pasar por el servicio.
 *
 * Los montos por defecto son de un ítem EXENTO —que es la mayoría de
 * este negocio (§8.6.1)— y cuadran entre sí, porque los CHECK de la
 * tabla no perdonan.
 *
 * @extends Factory<Cargo>
 */
class CargoFactory extends Factory
{
    protected $model = Cargo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * ⚠️ `cuenta_id` va PRIMERO y no es cosmético.
             *
             * `Factory::expandAttributes()` resuelve los closures en orden
             * de aparición y a cada uno le pasa solo las claves ya
             * resueltas. Con `sede_id` arriba, su closure recibiría la
             * instancia de `CuentaFactory` en vez de un id, y `find()`
             * terminaría bindeando un objeto en PDO.
             */
            'cuenta_id'       => Cuenta::factory(),
            'fecha_operacion' => now()->toDateString(),
            'sede_id'         => fn (array $atributos): ?int => self::cuentaDe($atributos)?->sede_id,
            'encuentro_id'    => fn (array $atributos): ?int => self::cuentaDe($atributos)?->encuentro_id,
            'item_id'         => Item::factory(),
            'ocurrido_en'     => now(),
            'registrado_en'   => now(),
            'cantidad'        => '1.0000',
            'texto'           => 'CARGO DE PRUEBA',
            /*
             * Con `?->convenio_id ?? ...` el análisis estático protesta:
             * el `??` ya cubre el nulo, así que el nullsafe sobra. Se
             * resuelve preguntando por la cuenta una sola vez.
             */
            'convenio_id' => function (array $atributos): mixed {
                $cuenta = self::cuentaDe($atributos);

                return $cuenta instanceof Cuenta
                    ? $cuenta->convenio_id
                    : Convenio::factory()->contado();
            },
            'origen_precio'            => OrigenDelPrecio::PrecioDeLista->value,
            'precio_unitario'          => '100.0000',
            'descuento_legal_fraccion' => '0.0000',
            'base_descuento_legal'     => BaseDelDescuentoLegal::NoAplica->value,
            'descuento_legal'          => '0.00',
            'descuento_comercial'      => '0.00',
            'regimen_isv'              => RegimenIsv::Exento->value,
            'tasa_isv'                 => '0.0000',
            'bruto'                    => '100.00',
            'subtotal'                 => '100.00',
            'base_exenta'              => '100.00',
            'base_gravada'             => '0.00',
            'isv'                      => '0.00',
            'total'                    => '100.00',
            'cobertura_fraccion'       => '0.0000',
            'elegible'                 => false,
            'porcion_paciente'         => '100.00',
            'porcion_aseguradora'      => '0.00',
            'politica_cargo'           => PoliticaCargo::Cobrable->value,
            'estado'                   => EstadoCargo::Pendiente->value,
            'es_tardio'                => false,
            'clave_origen'             => (string) Str::uuid(),
            'clave_idempotencia'       => (string) Str::uuid(),
            'created_by'               => User::factory(),
        ];
    }

    /**
     * El casteo a `int` no es adorno: sin él `find()` recibe `mixed` y
     * Larastan lo tipa como `Cuenta|Collection|null`, que en nivel 7 hace
     * fallar todo acceso a propiedad (§9.B5).
     *
     * @param array<string, mixed> $atributos
     */
    private static function cuentaDe(array $atributos): ?Cuenta
    {
        $id = $atributos['cuenta_id'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $cuenta = Cuenta::query()->find((int) $id);

        return $cuenta instanceof Cuenta ? $cuenta : null;
    }

    public function enLaCuenta(Cuenta $cuenta): self
    {
        return $this->state(fn (): array => [
            'cuenta_id'    => $cuenta->id,
            'encuentro_id' => $cuenta->encuentro_id,
            'sede_id'      => $cuenta->sede_id,
            'convenio_id'  => $cuenta->convenio_id,
        ]);
    }

    public function facturado(): self
    {
        return $this->state(fn (): array => ['estado' => EstadoCargo::Facturado->value]);
    }
}
