<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Los roles no son un módulo aparte
|--------------------------------------------------------------------------
|
| Shield trae su recurso bajo un grupo propio llamado «Filament Shield»,
| que aparecía ARRIBA DE TODO en el menú —antes que «Cuentas abiertas»—
| y con el nombre de un paquete de PHP, que no le dice nada a nadie en un
| hospital.
|
| Roles va donde va: junto a usuarios y a la bitácora, en seguridad.
|
| ⚠️ Laravel MEZCLA este archivo sobre el del paquete, así que acá solo
| van las claves que se cambian. Las demás siguen saliendo de
| `vendor/bezhansalleh/filament-shield/resources/lang/es`.
*/

return [
    'nav.group' => 'Seguridad y auditoría',
];
