<?php

declare(strict_types=1);

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Activity Log — alineado a spatie/laravel-activitylog 5.x
|--------------------------------------------------------------------------
|
| v5 renombró y eliminó claves. Lo que cambió respecto de la v4 que traía
| la plantilla:
|
|   v4                                    v5
|   ------------------------------------  --------------------------------
|   delete_records_older_than_days        clean_after_days
|   subject_returns_soft_deleted_models   include_soft_deleted_subjects
|   ACTIVITY_LOGGER_ENABLED (env)         ACTIVITYLOG_ENABLED (env)
|   table_name                            ELIMINADA (fija: activity_log)
|   database_connection                   ELIMINADA
|   —                                     default_except_attributes (nueva)
|   —                                     buffer.enabled (nueva)
|   —                                     actions.* (nueva)
|
| Las dos claves ELIMINADAS son las que rompen callado: las migraciones v4
| las consultaban con config(), y en v5 devuelven null — Schema::connection(null)
| ->create(null, ...). Por eso la migración de esta tabla se reescribió.
|
| ⚠️ Esta bitácora es la del panel (cambios administrativos). NO es la
| bitácora clínica ni la de LECTURA de expediente del §9.L6 — esas se
| construyen aparte en la Etapa 1, particionadas y append-only.
|
*/

return [

    /*
     * Si es false, no se guarda ninguna actividad.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * Al ejecutar el comando de limpieza, se borran las actividades más
     * viejas que esta cantidad de días.
     *
     * ⚠️ 365 días es el default del paquete y aplica solo a esta bitácora
     * administrativa. La retención del expediente clínico es de 20 años
     * (§8.8-8) y NO se gestiona con este comando.
     */
    'clean_after_days' => 365,

    /*
     * Nombre de log por defecto cuando no se pasa uno al helper activity().
     */
    'default_log_name' => 'default',

    /*
     * Driver de auth para resolver el usuario que causa la actividad.
     * null = el driver por defecto de Laravel.
     */
    'default_auth_driver' => null,

    /*
     * Si es true, la relación subject incluye modelos borrados con
     * SoftDeletes. Se deja en false: el registro guarda subject_type y
     * subject_id, así que la trazabilidad no se pierde.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * Modelo usado para registrar la actividad. Debe implementar
     * Spatie\Activitylog\Contracts\Activity y extender Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * Atributos excluidos del log en TODOS los modelos. Se combinan con
     * los logExcept() de cada modelo.
     *
     * Acá va todo lo que nunca debe quedar escrito en una bitácora
     * (§9.L10: cero PII/PHI en logs).
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
    ],

    /*
     * Con buffer activo, las actividades se acumulan en memoria y se
     * insertan en un solo INSERT después de enviar la respuesta.
     *
     * Se deja en FALSE a propósito. El buffer difiere la escritura y las
     * actividades no tienen ID hasta que se vacía: para una bitácora que
     * tiene que ser evidencia, "se guarda después, si todo sale bien" no
     * es aceptable. Se reevalúa si aparece una pantalla que genere
     * decenas de actividades por request.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * Clases de acción sobrescribibles para personalizar cómo se registra
     * y cómo se limpia. Una clase propia debe extender la original.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log'    => CleanActivityLogAction::class,
    ],
];
