/*
 * Punto de entrada del front-end propio.
 *
 * Está casi vacío a propósito, y conviene que siga así. El panel corre
 * sobre Filament, que trae su propio JavaScript —Livewire y Alpine— y no
 * pasa por este bundle. Acá solo iría código de páginas públicas fuera
 * del panel, y hoy no hay ninguna.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ NO VOLVER A AGREGAR AXIOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se quitó el 18-ago-2026, junto con el `resources/js/bootstrap.js` que
 * lo importaba. Venía del andamiaje que trae Laravel y nadie lo usaba:
 * Filament habla por Livewire, y una búsqueda en todo el repo no
 * encontró un solo uso de `window.axios`.
 *
 * Se llevó por delante más de veinte advisories abiertos —SSRF por
 * NO_PROXY, prototype pollution que permite inyección de credenciales,
 * CRLF en multipart, fuga de la cabecera Proxy-Authorization— más los de
 * sus dos dependencias, `form-data` y `follow-redirects`.
 *
 * Lo que se importa acá viaja al navegador de cada máquina del hospital.
 * Una dependencia que nadie usa no es peso muerto: es superficie de
 * ataque gratis, y `window.axios` era además un objeto global que
 * cualquier XSS podía usar para hablar con el backend con la sesión del
 * usuario puesta.
 */
