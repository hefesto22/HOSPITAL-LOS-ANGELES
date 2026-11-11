{{--
    El menú lateral compacto. Lo explica App\Filament\Navegacion\MenuLateral.

    Va sin capa (layer) a propósito: el CSS de Filament está todo en
    capas, y una regla sin capa le gana sin pelear por especificidad.
    Por eso el estado activo se vuelve a declarar acá: si no, el color
    del grupo le ganaría al del renglón en que se está.
--}}
<style>
.fi-main-sidebar .fi-sidebar-nav{padding:.75rem .75rem 1rem;row-gap:.5rem}
.fi-main-sidebar .fi-sidebar-nav-groups{margin-inline:0;row-gap:.625rem}
.fi-main-sidebar .fi-sidebar-group{row-gap:.125rem}
.fi-main-sidebar .fi-sidebar-group-items{row-gap:1px}
.fi-main-sidebar .fi-sidebar-group[data-grupo]{--grupo:var(--grupo-claro)}
.dark .fi-main-sidebar .fi-sidebar-group[data-grupo]{--grupo:var(--grupo-oscuro)}

.fi-main-sidebar .fi-sidebar-group-btn{padding:.25rem .25rem .25rem .5rem;column-gap:.5rem;border-radius:.375rem}
.fi-main-sidebar .fi-sidebar-group.fi-collapsible>.fi-sidebar-group-btn:hover{background:var(--gray-100)}
.dark .fi-main-sidebar .fi-sidebar-group.fi-collapsible>.fi-sidebar-group-btn:hover{background:rgb(255 255 255 / .05)}
.fi-main-sidebar .fi-sidebar-group-label{display:flex;align-items:center;gap:.5rem;font-size:.6875rem;line-height:1rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--gray-500)}
.dark .fi-main-sidebar .fi-sidebar-group-label{color:var(--gray-400)}
.fi-main-sidebar .fi-sidebar-group[data-grupo] .fi-sidebar-group-label::before{content:"";flex:none;width:.5rem;height:.5rem;border-radius:2px;background:var(--grupo)}
.fi-main-sidebar .fi-sidebar-group-collapse-btn{width:1.5rem;height:1.5rem;margin:0}
.fi-main-sidebar .fi-sidebar-group-collapse-btn .fi-icon{width:1rem;height:1rem}

.fi-main-sidebar .fi-sidebar-item-btn>.fi-icon{width:1.25rem;height:1.25rem}
.fi-main-sidebar .fi-sidebar-group[data-grupo] .fi-sidebar-item-btn>.fi-icon{color:var(--grupo)}
.fi-main-sidebar .fi-sidebar-item-label{font-size:.875rem;line-height:1.25rem}
.fi-main-sidebar .fi-sidebar-item-badge-ctn .fi-badge{min-height:1.125rem;padding-block:0;font-size:.6875rem}
@media (min-width:1024px){
    .fi-main-sidebar .fi-sidebar-item-btn{padding:.25rem .5rem;column-gap:.625rem}
}

.fi-main-sidebar .fi-sidebar-item.fi-active>.fi-sidebar-item-btn{background:color-mix(in oklab,var(--primary-500) 13%,transparent)}
.fi-main-sidebar .fi-sidebar-item.fi-active>.fi-sidebar-item-btn::before{content:"";position:absolute;inset-block:.375rem;inset-inline-start:0;width:3px;border-radius:0 3px 3px 0;background:var(--primary-500)}
.fi-main-sidebar .fi-sidebar-item.fi-active>.fi-sidebar-item-btn>.fi-icon{color:var(--primary-600)}
.fi-main-sidebar .fi-sidebar-item.fi-active>.fi-sidebar-item-btn>.fi-sidebar-item-label{color:var(--primary-700);font-weight:600}
.dark .fi-main-sidebar .fi-sidebar-item.fi-active>.fi-sidebar-item-btn>.fi-icon{color:var(--primary-400)}
.dark .fi-main-sidebar .fi-sidebar-item.fi-active>.fi-sidebar-item-btn>.fi-sidebar-item-label{color:var(--primary-300)}

.sihla-menu-buscar{position:sticky;top:-.75rem;z-index:2;margin:-.75rem -.75rem 0;padding:.75rem .75rem .5rem;background:var(--color-white)}
.dark .sihla-menu-buscar{background:var(--gray-900)}
@media (min-width:1024px){
    .sihla-menu-buscar{background:var(--gray-50)}
    .dark .sihla-menu-buscar{background:var(--gray-950)}
}
.sihla-menu-buscar-caja{display:flex;align-items:center;gap:.5rem;height:2.125rem;padding:0 .375rem 0 .625rem;border-radius:.5rem;background:var(--color-white);box-shadow:0 0 0 1px rgb(0 0 0 / .1);color:var(--gray-400);cursor:text}
.dark .sihla-menu-buscar-caja{background:rgb(255 255 255 / .05);box-shadow:0 0 0 1px rgb(255 255 255 / .1)}
.sihla-menu-buscar-caja:focus-within{box-shadow:0 0 0 2px var(--primary-500)}
.sihla-menu-buscar-caja svg{flex:none;width:1rem;height:1rem}
.sihla-menu-buscar input{flex:1;min-width:0;border:0;padding:0;background:transparent;font-size:.875rem;line-height:1.25rem;color:var(--gray-950);outline:none;box-shadow:none}
.dark .sihla-menu-buscar input{color:var(--color-white)}
.sihla-menu-buscar input::placeholder{color:var(--gray-400)}
.sihla-menu-buscar kbd{flex:none;padding:.125rem .375rem;border-radius:.25rem;border:1px solid var(--gray-200);font:600 .6875rem/1rem ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--gray-500)}
.dark .sihla-menu-buscar kbd{border-color:rgb(255 255 255 / .15);color:var(--gray-400)}
.sihla-menu-nada{margin:.5rem .25rem 0;font-size:.8125rem;line-height:1.25rem;color:var(--gray-500)}

.fi-main-sidebar .sihla-oculto{display:none!important}
.fi-main-sidebar .fi-sidebar-nav.sihla-filtrando .fi-sidebar-group-items{display:flex!important;height:auto!important;overflow:visible!important}
.fi-main-sidebar .fi-sidebar-nav.sihla-filtrando .fi-sidebar-group-collapse-btn{visibility:hidden}
.fi-main-sidebar .sihla-elegido>.fi-sidebar-item-btn{box-shadow:inset 0 0 0 2px var(--primary-500)}
</style>
