<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\EstadoFactura;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Decimal;
use App\Models\Cargo;
use App\Models\Cie10;
use App\Models\Diagnostico;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Item;
use App\Models\Medico;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * ─────────────────────────────────────────────────────────────────────
 * LAS ATENCIONES DEL PACIENTE, PARA SU EXPEDIENTE (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «que se vaya guardando cada que se le da el alta a un
 * paciente, con una descripción automática de a qué vino y qué se le
 * hizo según la cuenta». Y: «al facturar; si se anula, se quita del
 * expediente».
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO HAY UNA TABLA DE «RESÚMENES»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Lo que se guarda ya está guardado, y de forma inmutable: la factura
 * emitida y los cargos que factura no se editan nunca (§9.0.3). Una
 * atención está en el expediente mientras tenga una factura VIGENTE; si
 * la factura se anula, sale sola — y vuelve si se refactura. Una copia
 * en otra tabla habría que mantenerla al día con cada anulación, y el
 * día que se desincronice el expediente diría algo que la caja no.
 *
 * La descripción se arma al leer, siempre igual a partir de las mismas
 * filas. Lo único vivo son los diagnósticos: si el médico corrige uno,
 * el expediente muestra el corregido, que es lo que corresponde.
 *
 * Se leen las de TODAS las sedes, como las carpetas: la historia clínica
 * se arma por persona.
 */
final class AtencionesDelPaciente
{
    /**
     * Cuántos renglones se muestran por grupo antes de «y N más».
     */
    private const POR_GRUPO = 8;

    /**
     * Los grupos, en el orden en que se cuentan. Cada tipo de ítem cae en
     * uno; lo que no tiene grupo propio va a «Otros servicios».
     *
     * @var array<string, array{titulo: string, icono: string, tipos: list<TipoItem>}>
     */
    private const GRUPOS = [
        'cirugia'       => ['titulo' => 'Cirugía', 'icono' => 'heroicon-o-scissors', 'tipos' => [TipoItem::Paquete]],
        'procedimiento' => ['titulo' => 'Procedimientos', 'icono' => 'heroicon-o-hand-raised', 'tipos' => [TipoItem::Procedimiento]],
        'laboratorio'   => ['titulo' => 'Laboratorio', 'icono' => 'heroicon-o-beaker', 'tipos' => [TipoItem::EstudioLaboratorio]],
        'imagen'        => ['titulo' => 'Imágenes', 'icono' => 'heroicon-o-photo', 'tipos' => [TipoItem::EstudioImagen]],
        'medicamento'   => ['titulo' => 'Medicamentos', 'icono' => 'heroicon-o-eye-dropper', 'tipos' => [TipoItem::Medicamento]],
        'estancia'      => ['titulo' => 'Estancia', 'icono' => 'heroicon-o-home-modern', 'tipos' => [TipoItem::Estancia]],
        'honorario'     => ['titulo' => 'Consultas y honorarios', 'icono' => 'heroicon-o-user', 'tipos' => [TipoItem::Honorario]],
        'insumo'        => ['titulo' => 'Material médico', 'icono' => 'heroicon-o-archive-box', 'tipos' => [TipoItem::Insumo]],
        'otro'          => ['titulo' => 'Otros servicios', 'icono' => 'heroicon-o-clipboard-document-list', 'tipos' => [TipoItem::Servicio, TipoItem::Otro]],
    ];

    /**
     * Las atenciones con factura vigente, la más reciente primero.
     *
     * @return list<AtencionDelPaciente>
     */
    public function de(Persona $persona): array
    {
        $facturas = Factura::query()
            ->deTodasLasSedes()
            ->where('persona_id', $persona->id)
            ->where('estado', EstadoFactura::Emitida->value)
            ->whereNotNull('encuentro_id')
            ->get(['id', 'encuentro_id', 'emitida_en']);

        if ($facturas->isEmpty()) {
            return [];
        }

        $encuentros = Encuentro::query()
            ->deTodasLasSedes()
            ->whereIn('id', $facturas->pluck('encuentro_id')->filter()->unique()->values()->all())
            ->with([
                'medicoTratante:id,name',
                'sede:id,nombre',
                /*
                 * Sin el filtro de sede: la carpeta de una atención en la
                 * otra sede también es parte de la historia del paciente.
                 */
                'expediente' => fn ($relacion) => $relacion->withoutGlobalScope('sede')->select(['id', 'numero']),
            ])
            ->get();

        /*
         * Los cargos se buscan por ENCUENTRO, no por `factura_id`: el
         * emisor pasa los cargos a `facturado` pero no les escribe la
         * factura, y además los «incluidos en el paquete» siguen en
         * `pendiente` aunque se hayan hecho. Lo que se le hizo es todo lo
         * vivo de la atención: fuera quedan el cargo anulado, su reversa
         * y el que se trasladó a otra cuenta.
         */
        $cargos = Cargo::query()
            ->deTodasLasSedes()
            ->whereIn('encuentro_id', $encuentros->pluck('id')->all())
            ->whereNotIn('estado', [
                EstadoCargo::Anulado->value,
                EstadoCargo::Anulacion->value,
                EstadoCargo::Trasladado->value,
            ])
            ->with(['item:id,tipo', 'medico:id,nombre'])
            ->orderBy('id')
            ->get(['id', 'encuentro_id', 'item_id', 'medico_id', 'texto', 'cantidad']);

        $diagnosticos = Diagnostico::query()
            ->vigentes()
            ->whereIn('encuentro_id', $encuentros->pluck('id')->all())
            ->with('cie10:id,codigo,descripcion')
            ->orderBy('id')
            ->get();

        $atenciones = [];

        foreach ($encuentros as $encuentro) {
            $suyas = $facturas->where('encuentro_id', $encuentro->id);
            $egreso = $suyas->max('emitida_en');

            if (! $egreso instanceof CarbonInterface) {
                continue;
            }

            $atenciones[] = $this->armar(
                $encuentro,
                $egreso,
                array_values($cargos->where('encuentro_id', $encuentro->id)->all()),
                array_values($diagnosticos->where('encuentro_id', $encuentro->id)->all()),
            );
        }

        usort($atenciones, static fn (AtencionDelPaciente $una, AtencionDelPaciente $otra): int => $otra->egreso <=> $una->egreso);

        return $atenciones;
    }

    /**
     * @param list<Cargo> $cargos
     * @param list<Diagnostico> $diagnosticos
     */
    private function armar(Encuentro $encuentro, CarbonInterface $egreso, array $cargos, array $diagnosticos): AtencionDelPaciente
    {
        $grupos = $this->agrupar($cargos);
        $diagnosticosLeidos = $this->diagnosticos($diagnosticos);
        $medicos = $this->medicos($encuentro, $cargos);
        $estancia = self::estancia($encuentro->abierto_en, $egreso);

        $expediente = $encuentro->getRelationValue('expediente');
        $sede = $encuentro->getRelationValue('sede');

        return new AtencionDelPaciente(
            encuentro: $encuentro->numero,
            tipo: $encuentro->tipo,
            expediente: $expediente instanceof Expediente ? $expediente->numero : '',
            sede: $sede instanceof Sede ? $sede->nombre : '',
            ingreso: $encuentro->abierto_en,
            egreso: $egreso,
            estancia: $estancia,
            motivo: filled($encuentro->motivo) ? $encuentro->motivo : null,
            diagnosticos: $diagnosticosLeidos,
            medicos: $medicos,
            grupos: array_values(array_map(
                static fn (array $grupo): array => [
                    'titulo' => $grupo['titulo'],
                    'icono'  => $grupo['icono'],
                    'items'  => array_slice($grupo['items'], 0, self::POR_GRUPO),
                    'mas'    => max(0, count($grupo['items']) - self::POR_GRUPO),
                ],
                $grupos,
            )),
            descripcion: $this->describir($encuentro, $egreso, $estancia, $diagnosticosLeidos, $medicos, $grupos),
        );
    }

    /**
     * Los cargos por grupo, juntando los repetidos: diez veces el mismo
     * medicamento es un renglón «×10», no diez.
     *
     * @param list<Cargo> $cargos
     *
     * @return array<string, array{titulo: string, icono: string, items: list<string>, distintos: int, cantidad: Decimal}>
     */
    private function agrupar(array $cargos): array
    {
        /** @var array<string, array<string, Decimal>> $porGrupo */
        $porGrupo = [];

        foreach ($cargos as $cargo) {
            $grupo = self::grupoDe($cargo);
            $texto = $cargo->texto;
            $porGrupo[$grupo][$texto] = ($porGrupo[$grupo][$texto] ?? Decimal::cero())->sumar($cargo->cantidad);
        }

        $grupos = [];

        foreach (self::GRUPOS as $clave => $definicion) {
            if (! isset($porGrupo[$clave])) {
                continue;
            }

            $items = [];
            $total = Decimal::cero();

            foreach ($porGrupo[$clave] as $texto => $cantidad) {
                $total = $total->sumar($cantidad);
                $legible = self::cantidad($cantidad);
                $items[] = $legible === '1' ? (string) $texto : $texto.' ×'.$legible;
            }

            $grupos[$clave] = [
                'titulo'    => $definicion['titulo'],
                'icono'     => $definicion['icono'],
                'items'     => $items,
                'distintos' => count($items),
                'cantidad'  => $total,
            ];
        }

        return $grupos;
    }

    /**
     * @param list<Diagnostico> $diagnosticos
     *
     * @return list<array{codigo: string, descripcion: string, principal: bool}>
     */
    private function diagnosticos(array $diagnosticos): array
    {
        $leidos = [];

        foreach ($diagnosticos as $diagnostico) {
            $cie10 = $diagnostico->getRelationValue('cie10');

            if (! $cie10 instanceof Cie10) {
                continue;
            }

            $leidos[] = [
                'codigo'      => $cie10->codigo,
                'descripcion' => $cie10->descripcion,
                'principal'   => $diagnostico->esPrincipal(),
            ];
        }

        // El principal primero.
        usort($leidos, static fn (array $uno, array $otro): int => (int) $otro['principal'] <=> (int) $uno['principal']);

        return $leidos;
    }

    /**
     * El médico tratante, y después los que cobraron honorarios.
     *
     * @param list<Cargo> $cargos
     *
     * @return list<string>
     */
    private function medicos(Encuentro $encuentro, array $cargos): array
    {
        $nombres = [];

        $tratante = $encuentro->getRelationValue('medicoTratante');

        if ($tratante instanceof User) {
            $nombres[] = $tratante->name;
        }

        foreach ($cargos as $cargo) {
            $medico = $cargo->getRelationValue('medico');

            if ($medico instanceof Medico) {
                $nombres[] = $medico->nombre;
            }
        }

        return array_values(array_unique($nombres));
    }

    /**
     * El párrafo de arriba: a qué vino y qué se le hizo, en una lectura.
     *
     * @param list<array{codigo: string, descripcion: string, principal: bool}> $diagnosticos
     * @param list<string> $medicos
     * @param array<string, array{titulo: string, icono: string, items: list<string>, distintos: int, cantidad: Decimal}> $grupos
     */
    private function describir(
        Encuentro $encuentro,
        CarbonInterface $egreso,
        string $estancia,
        array $diagnosticos,
        array $medicos,
        array $grupos,
    ): string {
        $mismoDia = $encuentro->abierto_en->isSameDay($egreso);

        $frases = [
            $encuentro->tipo->etiqueta()
                .($mismoDia
                    ? ' el '.$encuentro->abierto_en->format('d/m/Y')
                    : ' del '.$encuentro->abierto_en->format('d/m/Y').' al '.$egreso->format('d/m/Y').' ('.$estancia.')')
                .'.',
        ];

        if (filled($encuentro->motivo)) {
            $frases[] = 'Vino por: '.mb_strtolower((string) $encuentro->motivo).'.';
        }

        if ($diagnosticos !== []) {
            $primero = $diagnosticos[0];
            $otros = count($diagnosticos) - 1;
            $frases[] = 'Diagnóstico: '.$primero['descripcion'].' ('.$primero['codigo'].')'
                .($otros > 0 ? ' y '.($otros === 1 ? 'otro más' : "{$otros} más") : '').'.';
        } else {
            $frases[] = 'Sin diagnóstico registrado.';
        }

        $hecho = [];

        foreach (['cirugia', 'procedimiento'] as $clave) {
            if (isset($grupos[$clave])) {
                $hecho[] = self::lista(array_map(static fn (string $item): string => mb_strtolower($item), $grupos[$clave]['items']), 3);
            }
        }

        $contables = [
            'laboratorio' => ['examen de laboratorio', 'exámenes de laboratorio'],
            'imagen'      => ['estudio de imagen', 'estudios de imagen'],
            'medicamento' => ['medicamento', 'medicamentos'],
            'honorario'   => ['consulta u honorario médico', 'consultas u honorarios médicos'],
        ];

        foreach ($contables as $clave => [$uno, $varios]) {
            if (isset($grupos[$clave])) {
                $n = $grupos[$clave]['distintos'];
                $hecho[] = $n.' '.($n === 1 ? $uno : $varios);
            }
        }

        if (isset($grupos['estancia'])) {
            $dias = (int) floor((float) $grupos['estancia']['cantidad']->redondeado(0));
            $hecho[] = $dias === 1 ? '1 día de estancia' : "{$dias} días de estancia";
        }

        if ($hecho !== []) {
            $frases[] = 'Se le hizo: '.self::lista($hecho, count($hecho)).'.';
        }

        if ($medicos !== []) {
            $frases[] = (count($medicos) === 1 ? 'Lo atendió ' : 'Lo atendieron ').self::lista($medicos, 3).'.';
        }

        return implode(' ', $frases);
    }

    private static function grupoDe(Cargo $cargo): string
    {
        $item = $cargo->getRelationValue('item');
        $tipo = $item instanceof Item ? $item->tipo : TipoItem::Otro;

        foreach (self::GRUPOS as $clave => $definicion) {
            if (in_array($tipo, $definicion['tipos'], true)) {
                return $clave;
            }
        }

        return 'otro';
    }

    /**
     * «a, b y c»; con más de `$tope`, «a, b, c y 4 más».
     *
     * @param list<string> $partes
     */
    private static function lista(array $partes, int $tope): string
    {
        $mostradas = array_slice($partes, 0, max(1, $tope));
        $resto = count($partes) - count($mostradas);

        if ($resto > 0) {
            return implode(', ', $mostradas).' y '.$resto.' más';
        }

        if (count($mostradas) === 1) {
            return $mostradas[0];
        }

        $ultima = array_pop($mostradas);

        return implode(', ', $mostradas).' y '.$ultima;
    }

    private static function estancia(CarbonInterface $desde, CarbonInterface $hasta): string
    {
        $dias = (int) $desde->diffInDays($hasta);

        if ($dias >= 1) {
            return $dias === 1 ? '1 día' : "{$dias} días";
        }

        $horas = (int) $desde->diffInHours($hasta);

        return $horas <= 1 ? 'menos de 2 horas' : "{$horas} horas";
    }

    private static function cantidad(Decimal $cantidad): string
    {
        $texto = $cantidad->redondeado(4);

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }
}
