<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 CINCO COLUMNAS QUE SE GUARDABAN EN MAYÚSCULAS «CASI SIEMPRE»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Pedido de Mauricio (3-sep-2026): *«que al escribir siempre se vea en
 * mayúsculas todo»*.
 *
 * Al ir a poner el CSS apareció que el problema no era de vista. Estas
 * columnas quedaban en mayúsculas o no según por qué puerta hubiera
 * entrado el dato:
 *
 *   · `item_presentaciones.nombre`     el modal usaba un TextInput pelado
 *   · `plantillas_presupuesto.nombre`  el formulario canonizaba y la
 *                                      acción «Guardar como plantilla» no
 *   · `descuentos.nombre`              ninguna de las dos
 *   · `lotes.numero`                   `ResolutorDeLote` sí, un import no
 *   · `turnos_de_caja.nombre`          ninguna
 *
 * En tres es cosmética. En dos NO:
 *
 * 🔴 `descuentos.nombre` — `FijadorDeDescuento` busca el descuento
 *    vigente POR NOMBRE con un `where` exacto. «Tercera edad» y «TERCERA
 *    EDAD» no se encontraban entre sí: el segundo no cerraba al primero y
 *    quedaban DOS vigentes con el mismo significado, los dos saliendo
 *    impresos en facturas, y el que gana depende del ORDER BY.
 *
 * 🔴 `lotes.numero` — «lot-1» y «LOT-1» son dos lotes del mismo producto,
 *    con dos existencias y dos vencimientos. FEFO sugiere el que vence
 *    primero DE LOS QUE VE, así que con el saldo partido en dos sugiere
 *    el que no era.
 *
 * Desde hoy los cinco modelos lo declaran con `GuardaEnMayusculas`, que
 * corre en `saving` y por lo tanto vale para toda escritura —formulario,
 * seeder, import, comando—. Esto pone al día lo que ya estaba escrito.
 */
return new class extends Migration
{
    /**
     * La misma forma canónica que `TextoCanonico::mayusculas()`, escrita
     * en SQL. `%s` se reemplaza por el nombre de la columna.
     */
    private const CANONICO = "upper(btrim(regexp_replace(%s, '\\s+', ' ', 'g')))";

    /**
     * tabla => [columna, llave única que la acompaña o null].
     *
     * La llave importa: pasar a mayúsculas puede volver iguales dos filas
     * que hoy se distinguen solo por eso, y ahí un índice único las
     * rechaza en medio del `migrate`.
     *
     * @var array<string, array{0: string, 1: list<string>|null}>
     */
    private const COLUMNAS = [
        'item_presentaciones'    => ['nombre', null],
        'plantillas_presupuesto' => ['nombre', null],
        'turnos_de_caja'         => ['nombre', null],
        'descuentos'             => ['nombre', []],
        'lotes'                  => ['numero', ['item_id']],
    ];

    public function up(): void
    {
        foreach (self::COLUMNAS as $tabla => [$columna, $llave]) {
            if ($llave !== null) {
                $this->verificarQueNoChoque($tabla, $columna, $llave);
            }
        }

        foreach (self::COLUMNAS as $tabla => [$columna, $_]) {
            /*
             * `upper()` de PostgreSQL y el mismo colapso de espacios que
             * hace `TextoCanonico`: recorta las puntas y deja un solo
             * espacio adentro.
             *
             * ⚠️ Sin `unaccent`. El nombre guardado es el que sale
             * impreso, y la factura tiene que coincidir letra por letra
             * con el documento: «PENA» y «PEÑA» son dos apellidos
             * distintos para el RNP. Buscar sin tildes ya está resuelto
             * aparte, en `nombre_busqueda`.
             */
            $canonico = sprintf(self::CANONICO, $columna);

            $tocadas = DB::update(
                "UPDATE {$tabla}
                    SET {$columna} = {$canonico}
                  WHERE {$columna} IS NOT NULL
                    AND {$columna} <> {$canonico}"
            );

            if ($tocadas > 0) {
                echo '  ✓ '.$tabla.'.'.$columna.': '.$tocadas.' fila(s) puestas al día.'.PHP_EOL;
            }
        }
    }

    /**
     * ¿Hay filas que solo se distinguen por las mayúsculas y comparten la
     * llave única?
     *
     * ⚠️ Se detecta ANTES y se dice cuáles. No es algo que una migración
     * pueda contestar sola: son dos descuentos que ya se le aplicaron a
     * pacientes distintos, o dos lotes con dos vencimientos y dos saldos
     * físicos. Fusionarlos es una decisión de alguien que sabe qué había
     * en el estante.
     *
     * Sin esto, el `migrate` moriría con un 23P01 crudo, sin decir cuáles
     * eran y con la mitad de las tablas ya convertidas.
     *
     * @param list<string> $llave columnas que acompañan a la única
     *
     * @throws RuntimeException
     */
    private function verificarQueNoChoque(string $tabla, string $columna, array $llave): void
    {
        $canonico = sprintf(self::CANONICO, '%s.'.$columna);
        $canonicoA = sprintf($canonico, 'a');
        $canonicoB = sprintf($canonico, 'b');

        $mismaLlave = implode('', array_map(
            static fn (string $col): string => "\n               AND b.{$col} = a.{$col}",
            $llave,
        ));

        /*
         * Las dos únicas de este sistema son parciales —`WHERE deleted_at
         * IS NULL`— así que lo dado de baja no choca con nada y no tiene
         * por qué frenar la migración.
         */
        /** @var list<object> $choques */
        $choques = DB::select(
            "SELECT DISTINCT {$canonicoA} AS repetido
               FROM {$tabla} AS a
               JOIN {$tabla} AS b
                 ON b.id <> a.id
                AND {$canonicoB} = {$canonicoA}{$mismaLlave}
                AND b.deleted_at IS NULL
              WHERE a.deleted_at IS NULL
                AND a.{$columna} <> {$canonicoA}"
        );

        if ($choques === []) {
            return;
        }

        $nombres = implode(', ', array_map(
            static fn (object $fila): string => '«'.(is_string($fila->repetido ?? null) ? $fila->repetido : '?').'»',
            $choques,
        ));

        throw new RuntimeException(
            "En {$tabla} hay filas que solo se distinguían por mayúsculas y comparten la misma "
            ."llave: {$nombres}. Cuál se queda no lo puede contestar una migración —son datos "
            .'que ya se usaron—. Unificalas o dá de baja una de cada par desde su pantalla, y '
            .'volvé a correr esto.'
        );
    }

    public function down(): void
    {
        /*
         * A propósito, vacío. No hay a qué volver: nadie guardó cuál era
         * la mezcla de mayúsculas y minúsculas de cada fila, y
         * reinventarla sería peor que dejarla canónica.
         */
    }
};
