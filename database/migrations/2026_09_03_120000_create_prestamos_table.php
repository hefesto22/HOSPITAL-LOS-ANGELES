<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoPrestamo;
use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\Enums\TipoMovimiento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el hospital no tenía y alguien le prestó.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES UN AJUSTE NI UNA COMPRA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hoy, cuando no hay existencia, la única salida es registrar una entrada
 * por «Ajustes y bajas» y cobrar. Eso deja la cantidad correcta y pierde
 * lo único que importa: **a quién hay que devolvérselo**. A la semana
 * nadie se acuerda, y la farmacia de la esquina sí.
 *
 * Tampoco es una compra: no hay factura, no hay precio pactado
 * necesariamente, y puede que nunca haya plata de por medio porque se
 * devuelve en especie.
 *
 * Es un documento propio con una deuda propia. El kardex dice QUÉ HAY;
 * esta tabla dice A QUIÉN SE LE DEBE.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA DEUDA SE MIDE EN UNIDADES, NO EN PLATA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `cantidad` y `cantidad_saldada` van en la unidad del KARDEX, la misma
 * en la que se mueve la existencia. Un préstamo de «una caja de 100» se
 * guarda como 100 tabletas, porque se puede devolver de a poco y porque
 * comparar contra el saldo del almacén tiene que ser una resta y no una
 * conversión.
 *
 * `monto_acordado` solo tiene sentido cuando se pactó pagar, y por eso un
 * CHECK lo ata a la forma de saldo: un monto en un préstamo que se
 * devuelve en especie es un número que alguien va a leer como deuda y
 * cobrar dos veces.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ LO QUE TRAE LA FAMILIA DEL PACIENTE NO ES DEUDA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se registra igual —el medicamento entra al kardex, se administra y
 * tiene que quedar trazado— pero `QuienPresta::MedicoOFamiliar` no genera
 * deuda y no sale en la lista de lo que se debe. Sin esa distinción, la
 * pantalla de préstamos pendientes se llena de cosas que nadie va a
 * devolver y a la semana deja de mirarse, que es como se pierde la que sí
 * importaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── PRIMERO EL KARDEX ─────────────────────────────────────────
         *
         * `kardex_signo_coherente` se construyó con la lista de tipos que
         * existía el 19-ago. Los dos tipos nuevos no están adentro, así
         * que sin esto el primer préstamo lo rechaza la base con un
         * mensaje que no explica nada.
         *
         * Se reconstruye DESDE EL ENUM, igual que la original: una lista
         * escrita a mano acá se desincroniza el día que alguien agregue
         * el tipo once.
         */
        $this->rehacerElCheckDelKardex();

        Schema::create('prestamos', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();

            // ── Qué se prestó ─────────────────────────────────────────
            $tabla->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $tabla->foreignId('item_presentacion_id')->nullable()
                ->constrained('item_presentaciones')->nullOnDelete();
            $tabla->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();

            /*
             * El lote de lo prestado. Un medicamento lo exige —ARSA— y
             * además es el que se va a devolver o el que se va a
             * dispensar: sin él, la caja que entró y la que sale son dos
             * cosas distintas para el sistema.
             */
            $tabla->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();

            $tabla->decimal('cantidad', 14, 4);
            $tabla->decimal('cantidad_saldada', 14, 4)->default(0);

            // ── Quién prestó ──────────────────────────────────────────
            $tabla->string('presta_tipo', 30);

            /*
             * El proveedor cuando está registrado, y el nombre SIEMPRE.
             * El nombre no es redundante: quien presta suele ser la
             * farmacia de la esquina, que no es proveedor de nada y no se
             * va a dar de alta como tal para prestar veinte tabletas.
             */
            $tabla->foreignId('proveedor_id')->nullable()
                ->constrained('proveedores')->nullOnDelete();
            $tabla->string('presta_nombre', 160);
            $tabla->string('presta_telefono', 40)->nullable();

            // ── Cómo se salda ─────────────────────────────────────────
            $tabla->string('forma_de_saldo', 30);
            $tabla->decimal('monto_acordado', 14, 4)->nullable();

            $tabla->string('estado', 20)->default(EstadoPrestamo::Pendiente->value);

            /*
             * De qué cuenta salió el apuro. No es obligatorio —se puede
             * pedir prestado para reponer el estante, sin paciente de por
             * medio— pero cuando lo hay es la mitad de la explicación.
             */
            $tabla->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();

            $tabla->string('motivo', 255)->nullable();

            /*
             * §7.5-3: cuándo pasó y cuándo se digitó. Y la fecha de
             * operación explícita (§7.5-4), que es por donde filtran los
             * reportes — nunca por `created_at`.
             */
            $tabla->timestampTz('ocurrido_en');
            $tabla->timestampTz('registrado_en');
            $tabla->date('fecha_operacion');

            $tabla->timestampTz('saldado_en')->nullable();
            $tabla->foreignId('saldado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->string('referencia_del_saldo', 255)->nullable();

            $tabla->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $tabla->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestamps();
            $tabla->softDeletes();
        });

        // ── Defensa en profundidad ────────────────────────────────────

        DB::statement(
            'ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_cantidad_positiva
             CHECK (cantidad > 0)'
        );

        /*
         * No se puede haber devuelto más de lo que se debía. Sin esto, un
         * doble clic en «devolver» deja una deuda negativa, que en la
         * suma de la pantalla se lee como que el hospital tiene crédito.
         */
        DB::statement(
            'ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_saldo_dentro_de_rango
             CHECK (cantidad_saldada >= 0 AND cantidad_saldada <= cantidad)'
        );

        $tipos = $this->comoLista(QuienPresta::valores());
        DB::statement(
            "ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_tipo_conocido
             CHECK (presta_tipo IN ({$tipos}))"
        );

        $formas = $this->comoLista(FormaDeSaldo::valores());
        DB::statement(
            "ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_forma_conocida
             CHECK (forma_de_saldo IN ({$formas}))"
        );

        $estados = $this->comoLista(EstadoPrestamo::valores());
        DB::statement(
            "ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_estado_conocido
             CHECK (estado IN ({$estados}))"
        );

        /*
         * El monto solo existe cuando se pactó pagar. En un préstamo que
         * se devuelve en especie, un monto guardado es una deuda fantasma:
         * alguien la ve en la pantalla, la paga, y además devuelve el
         * producto.
         */
        $pagar = FormaDeSaldo::Pagar->value;
        DB::statement(
            "ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_monto_solo_si_se_paga
             CHECK (
                 (forma_de_saldo = '{$pagar}' AND monto_acordado IS NOT NULL AND monto_acordado >= 0)
                 OR
                 (forma_de_saldo <> '{$pagar}' AND monto_acordado IS NULL)
             )"
        );

        /*
         * Quien prestó tiene nombre, siempre. Un préstamo sin nombre es
         * una entrada de inventario disfrazada: cuadra el saldo y no se
         * le puede devolver a nadie.
         */
        DB::statement(
            'ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_presta_nombre_escrito
             CHECK (length(btrim(presta_nombre)) >= 3)'
        );

        /*
         * Saldado y anulado exigen fecha; pendiente y parcial no pueden
         * tenerla. Es lo que hace que «¿cuándo se cerró esto?» tenga una
         * sola respuesta posible.
         */
        $cerrados = $this->comoLista([
            EstadoPrestamo::Saldado->value,
            EstadoPrestamo::Anulado->value,
        ]);
        DB::statement(
            "ALTER TABLE prestamos
             ADD CONSTRAINT prestamos_cierre_fechado
             CHECK (
                 (estado IN ({$cerrados}) AND saldado_en IS NOT NULL)
                 OR
                 (estado NOT IN ({$cerrados}) AND saldado_en IS NULL)
             )"
        );

        // ── Índices de consulta ───────────────────────────────────────

        /*
         * La consulta que va a correr todo el día: «¿qué le debemos a
         * alguien de este ítem?», tanto para la pantalla de pendientes
         * como para el aviso al recibir mercadería. Parcial a propósito:
         * los saldados son la mayoría con el tiempo y no interesan acá.
         */
        $abiertos = $this->comoLista([
            EstadoPrestamo::Pendiente->value,
            EstadoPrestamo::Parcial->value,
        ]);
        DB::statement(
            "CREATE INDEX prestamos_abiertos_por_item
             ON prestamos (item_id, sede_id, ocurrido_en DESC)
             WHERE estado IN ({$abiertos}) AND deleted_at IS NULL"
        );

        DB::statement(
            'CREATE INDEX prestamos_por_fecha
             ON prestamos (sede_id, fecha_operacion DESC)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamos');

        /*
         * El CHECK del kardex NO se revierte a la lista vieja: si quedara
         * algún movimiento de préstamo asentado, la base lo rechazaría al
         * recrear la restricción y la migración se caería a la mitad. La
         * lista ancha acepta lo viejo y lo nuevo.
         */
    }

    /**
     * Reconstruye `kardex_signo_coherente` con TODOS los tipos del enum.
     */
    private function rehacerElCheckDelKardex(): void
    {
        $entradas = $this->comoLista(TipoMovimiento::entradas());
        $salidas = $this->comoLista(TipoMovimiento::salidas());

        DB::statement('ALTER TABLE movimientos_kardex DROP CONSTRAINT IF EXISTS kardex_signo_coherente');

        DB::statement(
            "ALTER TABLE movimientos_kardex
             ADD CONSTRAINT kardex_signo_coherente
             CHECK (
                 (tipo IN ({$entradas}) AND cantidad > 0)
                 OR
                 (tipo IN ({$salidas}) AND cantidad < 0)
             )"
        );
    }

    /**
     * @param list<string> $valores
     */
    private function comoLista(array $valores): string
    {
        return implode(', ', array_map(
            static fn (string $valor): string => "'{$valor}'",
            $valores,
        ));
    }
};
