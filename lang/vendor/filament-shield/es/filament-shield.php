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
| Desde el 11-nov-2026 el grupo, el orden y el ícono de Roles se le dicen
| al plugin en `AdminPanelProvider`, con `GrupoDelMenu::Seguridad`: el
| nombre del grupo sale de un solo lugar. Este archivo queda sin claves.
|
| ⚠️ Laravel MEZCLA este archivo sobre el del paquete: lo que se ponga acá
| pisa `vendor/bezhansalleh/filament-shield/resources/lang/es`.
*/

return [];
