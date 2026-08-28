<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\Genero;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoEgreso;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\PosibleDuplicadoException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Item;
use App\Models\Persona;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use App\Services\AnuladorDeCargo;
use App\Services\RegistradorDeCargo;
use App\Services\RegistradorDePacientes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deja la pantalla lista para la capacitación: cuatro cuentas abiertas
 * con cargos adentro, y el talonario de facturas otra vez en cero.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ HACE FALTA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `sihla:demo-descuentos` deja tres pacientes con la cuenta ABIERTA Y
 * VACÍA, que es lo correcto para mostrar el descuento desde cero. Pero
 * para enseñar cobrar, abonar, facturar e imprimir hace falta que ya
 * haya algo cargado: una cuenta en cero no se puede facturar y la
 * demostración arranca con una pantalla que no dice nada.
 *
 * La cuarta cuenta va con PALIG y no de contado: es la que muestra que
 * el precio no sale de un solo tarifario, sino del que le corresponde al
 * pagador (ADR-0003). El mismo servicio le sale distinto al particular
 * que al asegurado, y eso es lo que hay que poder explicar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO SERVICIOS, NUNCA FARMACIA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Los cargos que siembra son de lo que NO lleva existencia: habitación,
 * consulta, sala de emergencia. Un medicamento saldría de bodega, y
 * bodega está en cero hasta que alguien reciba la primera compra —que
 * es, además, algo que conviene hacer EN VIVO durante la capacitación,
 * porque es la pantalla que farmacia va a usar todos los días—.
 *
 * ⚠️ Cada cargo va en su propio `try`: si un ítem del catálogo no tiene
 * precio vigente para ese pagador, se salta y sigue con el siguiente. Un
 * catálogo a medio cargar no puede dejar la demostración sin datos media
 * hora antes de empezar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 BORRA LAS FACTURAS. SOLO FUERA DE PRODUCCIÓN.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Una factura emitida NO se borra nunca: el SAR audita la secuencia y un
 * hueco es una factura que alguien escondió. Acá se borran porque las
 * que hay son de prueba, emitidas con un CAI falso, y arrancar la
 * capacitación desde el número 1 vale más que conservarlas.
 *
 * El comando se niega a correr en producción, y no por prolijidad: en
 * producción esto sería destruir documentos fiscales.
 */
class SembrarDemoDeCapacitacion extends Command
{
    protected $signature = 'sihla:demo-capacitacion
                            {--sin-reiniciar : Cargar sobre lo que ya hay, sin volver a sembrar los pacientes}
                            {--conservar-facturas : No borrar las facturas ni devolver el correlativo a 1}';

    protected $description = 'Deja cuatro cuentas abiertas con cargos y el talonario en cero, listo para la capacitación.';

    /**
     * Cuántos renglones lleva cada cuenta. Distintos a propósito: en el
     * listado se ve de un vistazo que la columna «Ítems» y los totales
     * son de verdad y no el mismo número repetido.
     *
     * @var list<int>
     */
    private const RENGLONES = [3, 2, 1, 2];

    public function handle(
        RegistradorDeCargo $registrador,
        RegistradorDePacientes $pacientes,
        AbridorDeEncuentro $abridor,
        AnuladorDeCargo $anulador,
    ): int {
        if (App::isProduction()) {
            $this->error('✗ No. Esto borra facturas y siembra pacientes falsos, y esto es producción.');

            return self::FAILURE;
        }

        if (! $this->option('conservar-facturas')) {
            $this->vaciarElTalonario();
        }

        if (! $this->option('sin-reiniciar')) {
            $this->call('sihla:demo-descuentos');
            $this->laDelSeguro($pacientes, $abridor, $anulador);
        }

        $servicios = Item::query()
            ->where('se_almacena', false)
            ->vigentesEn(now())
            ->orderBy('codigo')
            ->get();

        if ($servicios->isEmpty()) {
            $this->error('✗ El catálogo de servicios está vacío. Corré: php artisan db:seed --class=CatalogoPaligSeeder');

            return self::FAILURE;
        }

        $abiertas = Cuenta::query()
            ->where('estado', EstadoCuenta::Abierta->value)
            ->with(['encuentro.persona', 'convenio'])
            ->orderBy('id')
            ->get();

        if ($abiertas->isEmpty()) {
            $this->error('✗ No hay cuentas abiertas. Corré: php artisan sihla:demo-descuentos');

            return self::FAILURE;
        }

        $filas = [];
        $desde = 0;

        foreach ($abiertas->values() as $i => $cuenta) {
            $cuantos = self::RENGLONES[$i % count(self::RENGLONES)];

            /*
             * Cada cuenta arranca en un punto distinto del catálogo para
             * que no salgan todas con los mismos renglones. Si se acaba,
             * vuelve al principio.
             */
            $puestos = $this->cargarle($registrador, $cuenta, $servicios->all(), $desde, $cuantos);
            $desde += $cuantos;

            $cuenta->refresh();
            $persona = $cuenta->encuentro->persona;

            $filas[] = [
                $cuenta->numero,
                $persona instanceof Persona ? $persona->nombreCompleto() : '—',
                $cuenta->convenio->nombre,
                (string) $puestos,
                'L '.number_format((float) $cuenta->total, 2),
            ];
        }

        $this->newLine();
        $this->table(['Cuenta', 'Paciente', 'Pagador', 'Ítems', 'Total'], $filas);
        $this->newLine();
        $this->info('Listo. Las cuentas están en «Atención → Cuentas abiertas», con qué cobrar.');
        $this->warn('  ⚠ Farmacia sigue en cero: la primera compra se recibe en vivo, que es lo que conviene mostrar.');
        $this->warn('  ⚠ La cuenta de PALIG cobra con el tarifario de PALIG, pero el reparto seguro/paciente');
        $this->warn('    sale en cero hasta que le cargues el porcentaje de cobertura en «Seguros y convenios».');

        return self::SUCCESS;
    }

    /**
     * Borra las facturas de prueba y devuelve el correlativo al principio.
     *
     * ⚠️ En este orden: primero el detalle y después la cabecera. Al
     * revés, la FK de `factura_lineas` rechaza el borrado.
     *
     * ⚠️ El rango vuelve a `desde` y no a 1: un rango del SAR no empieza
     * necesariamente en uno, y devolverlo a uno lo dejaría numerando
     * fuera de lo autorizado.
     */
    private function vaciarElTalonario(): void
    {
        $cuantas = DB::table('facturas')->count();

        DB::table('factura_lineas')->delete();
        DB::table('facturas')->delete();
        DB::table('rangos_cai')->update(['siguiente' => DB::raw('desde')]);

        $this->info('✓ Talonario en cero: '.$cuantas.' factura(s) de prueba borradas, correlativo devuelto al inicio del rango.');
    }

    /**
     * La cuarta cuenta: la asegurada.
     *
     * Idempotente igual que las otras tres: si la persona ya está, se le
     * abre el expediente que corresponda y no se crea una segunda —que
     * es justo el duplicado que el módulo de MPI existe para evitar—.
     */
    private function laDelSeguro(
        RegistradorDePacientes $pacientes,
        AbridorDeEncuentro $abridor,
        AnuladorDeCargo $anulador,
    ): void {
        $sede = Sede::query()->orderBy('id')->first();

        if (! $sede instanceof Sede) {
            $this->error('✗ No hay sede. Corré primero: php artisan db:seed --class=SedeSeeder');

            return;
        }

        $convenio = Convenio::query()->where('codigo', 'PALIG')->first();

        if (! $convenio instanceof Convenio) {
            $this->warn('⚠ No existe el convenio PALIG. Corré: php artisan db:seed --class=CatalogoPaligSeeder');

            return;
        }

        $datos = new DatosDePaciente(
            primerNombre: 'MARIA',
            primerApellido: 'GOMEZ',
            segundoNombre: 'JOSE',
            segundoApellido: 'GARCIA',
            sexoBiologico: SexoBiologico::Femenino,
            genero: Genero::Femenino,
            fechaNacimiento: now()->subYears(41)->subDays(9),
        );

        $persona = Persona::query()
            ->where('primer_nombre', 'MARIA')
            ->where('primer_apellido', 'GOMEZ')
            ->orderBy('id')
            ->first();

        if ($persona instanceof Persona) {
            $expediente = $pacientes->abrirExpedienteEn($persona, $sede);
        } else {
            $expediente = $this->registrar($pacientes, $datos, $sede);
            $persona = $expediente->persona;
        }

        if (! $persona instanceof Persona) {
            $this->error('✗ El expediente quedó sin persona. Eso no debería poder pasar.');

            return;
        }

        /*
         * ⚠️ ANTES DE ABRIR, CERRAR LO QUE HAYA QUEDADO ABIERTO.
         *
         * `sihla:demo-descuentos` cierra las CUENTAS abiertas, pero un
         * encuentro sobrevive a su cuenta: facturar cierra la cuenta y
         * deja el ingreso vivo. Así que en la segunda corrida esta
         * paciente ya tenía un ingreso de hospitalización abierto y
         * `AbridorDeEncuentro` se negaba —bien negado: dos ingresos
         * vivos del mismo paciente producen dos cuentas y dos censos—.
         */
        $this->cerrarLoQueTengaAbierto($persona, $anulador);

        $abridor->abrir(
            persona: $persona,
            expediente: $expediente,
            tipo: TipoEncuentro::Hospitalizacion,
            convenio: $convenio,
            sede: $sede,
            motivo: 'Paciente asegurada, para la capacitación',
        );
    }

    /**
     * Cierra los ingresos vivos de una persona, con sus cuentas.
     *
     * 🔴 Cerrar NO es cambiar una palabra en una columna:
     * `cuentas_cierre_completo` exige `cerrada_en` y
     * `encuentros_cierre_completo` exige además `tipo_egreso`. La base se
     * niega a guardar un encuentro «cerrado» sin decir CÓMO se fue el
     * paciente. Es la misma rutina de `sihla:demo-descuentos`.
     */
    private function cerrarLoQueTengaAbierto(Persona $persona, AnuladorDeCargo $anulador): void
    {
        $vivos = Encuentro::query()
            ->where('persona_id', $persona->getKey())
            ->whereNotIn('estado', [EstadoEncuentro::Cerrado->value, EstadoEncuentro::Anulado->value])
            ->with('cuentas')
            ->get();

        foreach ($vivos as $encuentro) {
            foreach ($encuentro->cuentas as $cuenta) {
                if ($cuenta->estado === EstadoCuenta::Cerrada || $cuenta->estado === EstadoCuenta::Anulada) {
                    continue;
                }

                foreach ($cuenta->cargos()->get() as $cargo) {
                    if (! $cargo instanceof Cargo || ! $cargo->admiteAnulacionDirecta()) {
                        continue;
                    }

                    $anulador->anular($cargo, 'Reinicio de los datos antes de la capacitación');
                }

                $cuenta->refresh();
                $cuenta->forceFill([
                    'estado'     => EstadoCuenta::Cerrada->value,
                    'cerrada_en' => now(),
                ])->save();
            }

            $encuentro->forceFill([
                'estado'      => EstadoEncuentro::Cerrado->value,
                'cerrado_en'  => now(),
                'tipo_egreso' => TipoEgreso::Domicilio->value,
            ])->save();

            $this->line('  · cerrado el ingreso '.$encuentro->numero.' de '.$persona->nombreCompleto().'.');
        }
    }

    private function registrar(RegistradorDePacientes $pacientes, DatosDePaciente $datos, Sede $sede): Expediente
    {
        try {
            return $pacientes->registrar($datos, $sede);
        } catch (PosibleDuplicadoException) {
            /*
             * Es una paciente de prueba: el conflicto queda declarado en
             * vez de frenar la demostración, que es exactamente para lo
             * que existe esa salida (§8.2).
             */
            return $pacientes->registrarPeseAlConflicto(
                $datos,
                $sede,
                'Paciente de prueba creada para la capacitación',
            );
        }
    }

    /**
     * Le pone renglones a una cuenta, salteando lo que no se pueda.
     *
     * @param array<int, Item> $servicios
     */
    private function cargarle(
        RegistradorDeCargo $registrador,
        Cuenta $cuenta,
        array $servicios,
        int $desde,
        int $cuantos,
    ): int {
        /*
         * ⚠️ `array_values` y no el arreglo tal cual: `Collection::all()`
         * devuelve `array<int, Item>`, que para el analizador puede
         * tener huecos en las claves. Indexarlo con `[$n]` sería leer una
         * clave que quizá no está.
         */
        $servicios = array_values($servicios);

        $puestos = 0;
        $total = count($servicios);

        for ($paso = 0; $paso < $total && $puestos < $cuantos; $paso++) {
            $item = $servicios[($desde + $paso) % $total];

            try {
                $registrador->registrar($cuenta, new LineaDeCargo(
                    item: $item,
                    cantidad: Decimal::de($puestos === 0 ? '2' : '1'),
                    claveIdempotencia: (string) Str::uuid(),
                ));

                $puestos++;
            } catch (Throwable $e) {
                /*
                 * Casi siempre es «este ítem no tiene precio vigente para
                 * este pagador». Se dice cuál y se sigue: media hora
                 * antes de una capacitación, un catálogo incompleto no
                 * puede ser un comando que se cae.
                 */
                $this->line('  · se saltó '.$item->codigo.' — '.$e->getMessage());
            }
        }

        return $puestos;
    }
}
