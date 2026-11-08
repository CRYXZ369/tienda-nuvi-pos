    <?php

    require_once("auth.php"); // Asegúrate de que auth.php verifique la sesión
    require_once("csrf_utils.php");
    require_once("app_header.php");
    $puedeAjustarSaldo = in_array($_SESSION['rol'] ?? '', ['admin', 'supervisor'], true);
    // La información visible en tickets se mantiene local al servidor. El
    // archivo local es opcional y no se publica en GitHub.
    $configTicket = [
        'nombre' => 'Miscelánea Nuvi',
        'direccion' => '',
        'telefono' => '',
        'leyenda' => 'Gracias por su compra',
    ];
    $archivoTicketLocal = __DIR__ . '/config/ticket.local.php';
    if (is_file($archivoTicketLocal)) {
        $ticketLocal = require $archivoTicketLocal;
        if (is_array($ticketLocal)) {
            foreach ($configTicket as $claveTicket => $valorPredeterminado) {
                if (isset($ticketLocal[$claveTicket]) && is_scalar($ticketLocal[$claveTicket])) {
                    $textoTicket = trim((string) $ticketLocal[$claveTicket]);
                    $configTicket[$claveTicket] = function_exists('mb_substr')
                        ? mb_substr($textoTicket, 0, 180)
                        : substr($textoTicket, 0, 180);
                }
            }
        }
    }
    ?>

    <!DOCTYPE html>
    <html lang="es">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Punto de Venta - Tienda Nuvi</title>
        <style>
            * {
                box-sizing: border-box;
                font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            }

            body {
                margin: 0;
                padding: 10px;
                background-color: #f4f6f8;
                /* Gris muy suave y limpio, no cansa la vista */
                color: #334155;
                /* Texto oscuro, pero no negro puro */
            }

            /* HEADER - Barra de navegación tipo App */
            header {
                background-color: #ffffff;
                border-radius: 12px;
                padding: 15px;
                text-align: center;
                margin-bottom: 25px;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                /* Sombra muy sutil */
            }

            header a {
                text-decoration: none;
                color: #475569;
                font-weight: 600;
                padding: 10px 18px;
                border-radius: 8px;
                background-color: #f8fafc;
                border: 1px solid #e2e8f0;
                transition: all 0.2s ease-in-out;
            }

            header a:hover {
                background-color: #2563eb;
                /* Azul primario moderno */
                color: #ffffff;
                border-color: #2563eb;
                transform: translateY(-2px);
                /* Pequeño salto al pasar el mouse */
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            }

            /* CONTENEDOR PRINCIPAL */
            .contenedor {
                max-width: 1050px;
                margin: auto;
                background: #ffffff;
                padding: 30px;
                border-radius: 16px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            }

            h1 {
                color: #1e293b;
                text-align: center;
                font-size: 2rem;
                font-weight: 700;
                margin: 0 0 25px 0;
                padding-bottom: 15px;
                border-bottom: 2px solid #e2e8f0;
            }

            /* INPUTS (Escáner y buscadores) */
            .input-escaneo,
            input[type="text"],
            input[type="number"],
            select {
                width: 100%;
                padding: 14px;
                font-size: 1.1rem;
                border: 2px solid #cbd5e1;
                border-radius: 10px;
                background-color: #f8fafc;
                color: #334155;
                outline: none;
                transition: all 0.2s ease;
            }

            /* El efecto visual cuando el cajero está escribiendo */
            .input-escaneo:focus,
            input:focus,
            select:focus {
                border-color: #2563eb;
                background-color: #ffffff;
                box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
                /* Anillo de enfoque */
            }

            /* Confirmación breve para lector físico: verde + bip. */
            #inputUniversal.lector-confirmado {
                border-color: #16a34a !important;
                background: #f0fdf4 !important;
                box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.24) !important;
            }

            /* TABLA DEL CARRITO */
            .table-wrapper {
                overflow-x: auto;
                margin-top: 20px;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                background: #ffffff;
            }

            th,
            td {
                padding: 14px 16px;
                text-align: left;
                border-bottom: 1px solid #e2e8f0;

            }

            th {
                background-color: #f8fafc;
                color: #64748b;
                text-transform: uppercase;
                font-size: 0.85rem;
                font-weight: 700;
                letter-spacing: 0.5px;
                display: sticky;
            }

            tr:last-child td {
                border-bottom: none;
            }

            tr:hover td {
                background-color: #f1f5f9;
            }

            /* TOTALES Y DINERO */
            .total-container {
                text-align: right;
                margin-top: 30px;
                font-size: 1.8rem;
                font-weight: 800;
                color: #1e293b;
                background-color: #f8fafc;
                padding: 20px;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
            }

            .total-container span {
                color: #10b981;
                /* Verde esmeralda para destacar el dinero */
            }

            /* BOTONES GLOBALES */
            button {
                font-family: inherit;
                font-size: 1rem;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.2s ease;
                border: none;
            }

            /* BOTÓNES OPCIONES */

            .opciones-celda {
                /*os botones deben ir en fila horiontal, no apilados*/
                display: flex;
                justify-content: center;
                /* Centra los botones en la columna */
                align-items: center;
                gap: 8px;
                /* Espacio de separación entre cada botón */
                white-space: nowrap;
                /* Evita que bajen a otra línea */


            }

            .btn-opciones {
                background-color: #fee2e2;
                color: #a2bad8;
                padding: 8px 12px;
                border-radius: 6px;
                font-size: 0.9rem;
                font-weight: bold;
                border: none;
                cursor: pointer;
                transition: background-color 0.2s;
                /* Hace que el hover se vea suave */
            }

            .btn-opciones:hover {
                background-color: #44ef4d;
                color: #ffffff;
                box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);
            }

            .btn-eliminar:hover {
                background-color: #ef4444;
                color: white;
                box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);

            }

            .btn-eliminar {
                background-color: #fee2e2;
                color: #ff0101;
                padding: 8px 12px;
                border-radius: 6px;
                font-size: 0.9rem;
                font-weight: bold;
                border: none;
                cursor: pointer;
                transition: background-color 0.2s;
                /* Hace que el hover se vea suave */
            }

            /* MENSAJES DE ERROR */
            .error-msg {
                color: #991b1b;
                font-weight: 600;
                margin-bottom: 15px;
                padding: 12px 15px;
                border-radius: 8px;
                background-color: #fef2f2;
                border: 1px solid #fecaca;
                display: none;
            }

            /* ========================================= */
            /* MEDIA QUERIES (Tu excelente diseño responsivo) */
            /* ========================================= */
            @media (max-width: 480px) {
                body {
                    padding: 5px;
                    margin: 0;
                }

                .contenedor {
                    padding: 15px;
                    border-radius: 12px;
                }

                h1 {
                    font-size: 1.5rem;
                    margin: 0 0 15px 0;
                }

                header {
                    flex-direction: column;
                    padding: 12px;
                    gap: 8px;
                }

                header a {
                    width: 100%;
                    text-align: center;
                }

                .input-escaneo,
                input,
                select {
                    font-size: 1rem;
                    padding: 12px;
                }

                th,
                td {
                    padding: 10px;
                    font-size: 0.8rem;
                }

                .total-container {
                    font-size: 1.4rem;
                    padding: 15px;
                }
            }

            @media (min-width: 481px) and (max-width: 768px) {
                body {
                    padding: 10px;
                }

                .contenedor {
                    padding: 20px;
                }

                h1 {
                    font-size: 1.7rem;
                }

                header {
                    flex-direction: row;
                    padding: 15px;
                }

                header a {
                    flex: 1;
                    text-align: center;
                }

                .input-escaneo,
                input,
                select {
                    font-size: 1.05rem;
                }

                th,
                td {
                    padding: 12px;
                    font-size: 0.9rem;
                }

                .total-container {
                    font-size: 1.6rem;
                }
            }

            @media (min-width: 769px) and (max-width: 1024px) {
                body {
                    padding: 15px;
                }

                .contenedor {
                    padding: 25px;
                }

                h1 {
                    font-size: 1.8rem;
                }

                header a {
                    flex: 1;
                }

                th,
                td {
                    padding: 14px;
                    font-size: 0.95rem;
                }
            }

            @media (orientation: landscape) and (max-height: 500px) {
                header {
                    margin-bottom: 15px;
                    padding: 10px;
                }

                .contenedor {
                    padding: 15px;
                }

                h1 {
                    font-size: 1.4rem;
                    margin: 0 0 10px 0;
                    padding-bottom: 10px;
                }

                th,
                td {
                    padding: 8px;
                    font-size: 0.85rem;
                }

                .total-container {
                    font-size: 1.3rem;
                    margin-top: 15px;
                    padding: 10px;
                }

            }

            /* Estilos para el diferencial de cambios/rembolsos */
            .badge-diferencial {
                padding: 12px;
                border-radius: 10px;
                font-weight: 800;
                text-align: center;
                margin-top: 15px;
                font-size: 1.1rem;
            }

            .diff-devolver {
                background: #fee2e2;
                color: #b91c1c;
                border: 1px solid #f87171;
            }

            .diff-cobrar {
                background: #dcfce7;
                color: #15803d;
                border: 1px solid #4ade80;
            }

            .diff-neutro {
                background: #f1f5f9;
                color: #475569;
            }

            #tablaEditarVenta input[type="number"] {
                width: 70px;
                padding: 5px;
                border-radius: 5px;
                border: 1px solid #ddd;
            }
        </style>
        <?php renderAppHeaderStyles(); ?>
        <style>
            /* Ajustes específicos del POS: hay varias tarjetas y modales
               heredados con estilos en línea que requieren contraste propio. */
            html.nuvi-tema-oscuro .contenedor,
            html.nuvi-tema-oscuro .table-wrapper,
            html.nuvi-tema-oscuro .total-container,
            html.nuvi-tema-oscuro #sugerenciasBusqueda,
            html.nuvi-tema-oscuro #resultados-busqueda,
            html.nuvi-tema-oscuro #panel-granel,
            html.nuvi-tema-oscuro #detalleCliente,
            html.nuvi-tema-oscuro #formNuevoCliente,
            html.nuvi-tema-oscuro #comision-tarjeta-info,
            html.nuvi-tema-oscuro #offlineQueuePanel,
            html.nuvi-tema-oscuro #offlineQueueList,
            html.nuvi-tema-oscuro .contenedor [style*="background:white"],
            html.nuvi-tema-oscuro .contenedor [style*="background: white"],
            html.nuvi-tema-oscuro .contenedor [style*="background:#f8fafc"],
            html.nuvi-tema-oscuro .contenedor [style*="background: #f8fafc"],
            html.nuvi-tema-oscuro .contenedor [style*="background: #e9ecef"],
            html.nuvi-tema-oscuro .contenedor [style*="background:#eff6ff"],
            html.nuvi-tema-oscuro .contenedor [style*="background-color: #f0fdf4"],
            html.nuvi-tema-oscuro .contenedor [style*="background: #fff3cd"] {
                background: #172033 !important;
                color: #e2e8f0 !important;
                border-color: #334155 !important;
            }

            html.nuvi-tema-oscuro .contenedor td,
            html.nuvi-tema-oscuro .contenedor tr:hover td,
            html.nuvi-tema-oscuro .contenedor [style*="color: #1e293b"],
            html.nuvi-tema-oscuro .contenedor [style*="color: #334155"],
            html.nuvi-tema-oscuro .contenedor [style*="color: #0f172a"],
            html.nuvi-tema-oscuro .contenedor [style*="color: #475569"],
            html.nuvi-tema-oscuro .contenedor [style*="color: #64748b"],
            html.nuvi-tema-oscuro .contenedor [style*="color: #666"],
            html.nuvi-tema-oscuro .contenedor [style*="color: #1e3a8a"] {
                color: #e2e8f0 !important;
            }

            html.nuvi-tema-oscuro .contenedor [style*="color: #065f46"] { color: #86efac !important; }
            html.nuvi-tema-oscuro .contenedor [style*="color: #1d4ed8"] { color: #bfdbfe !important; }
            html.nuvi-tema-oscuro .contenedor [style*="color: #9a3412"] { color: #fdba74 !important; }
            html.nuvi-tema-oscuro #offlineQueuePanel [style*="background:#fff"],
            html.nuvi-tema-oscuro #offlineQueuePanel [style*="background: #fff"] { background: #172033 !important; }
            html.nuvi-tema-oscuro #offlineQueuePanel [style*="color:#0f172a"],
            html.nuvi-tema-oscuro #offlineQueuePanel [style*="color:#334155"],
            html.nuvi-tema-oscuro #offlineQueuePanel [style*="color:#64748b"] { color: #e2e8f0 !important; }

            html.nuvi-tema-oscuro .contenedor input,
            html.nuvi-tema-oscuro .contenedor select,
            html.nuvi-tema-oscuro .contenedor textarea {
                background: #0f172a !important;
                color: #f8fafc !important;
                border-color: #475569 !important;
            }

            html.nuvi-tema-oscuro .contenedor input::placeholder,
            html.nuvi-tema-oscuro .contenedor textarea::placeholder { color: #94a3b8 !important; }
            html.nuvi-tema-oscuro .contenedor .total-container { background: #1e293b !important; }
            html.nuvi-tema-oscuro .contenedor #panel-granel { border-color: #a16207 !important; }

            /* El cobro mixto tiene paneles anidados y estilos en línea: se
               declara explícitamente para evitar texto claro sobre fondo claro. */
            html.nuvi-tema-oscuro .panel-cobro,
            html.nuvi-tema-oscuro #pago-mixto-grupo {
                background: #172033 !important;
                color: #e2e8f0 !important;
                border-color: #475569 !important;
            }
            html.nuvi-tema-oscuro #pago-mixto-grupo strong,
            html.nuvi-tema-oscuro #pago-mixto-grupo label,
            html.nuvi-tema-oscuro #pago-mixto-grupo p {
                color: #e2e8f0 !important;
            }
            html.nuvi-tema-oscuro #pago-mixto-grupo #mixto-efectivo-aplicado { color: #bfdbfe !important; }

            .teclado-cobro { max-width: 420px; margin: 14px auto 0; }
            .teclado-cobro p { margin: 0 0 8px; font-weight: 700; }
            .teclado-cobro-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .teclado-cobro button {
                min-height: 56px; border: 2px solid #94a3b8; border-radius: 10px;
                background: #fff; color: #0f172a; font-size: 1.4rem; font-weight: 700; cursor: pointer;
            }
            .teclado-cobro button:focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
            html.nuvi-tema-oscuro .teclado-cobro button { background: #334155; color: #fff; border-color: #94a3b8; }
            .campo-teclado-activo { outline: 3px solid #2563eb; outline-offset: 2px; }

            /* El editor se monta fuera del contenedor principal y su contenido
               se genera dinámicamente; estas reglas evitan celdas claras con
               texto claro en el tema oscuro. */
            html.nuvi-tema-oscuro #modalEditarVenta > div,
            html.nuvi-tema-oscuro #contenidoEditarVenta,
            html.nuvi-tema-oscuro #sugerenciasEditar,
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background: #f0f4f8"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background:#f0f4f8"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background: #f0f4ff"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background:#f0f4ff"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background: #fff3cd"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background:#fff3cd"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background: #eff6ff"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background:#eff6ff"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background: #fffbeb"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="background:#fffbeb"] {
                background: #172033 !important;
                color: #e2e8f0 !important;
                border-color: #334155 !important;
            }
            html.nuvi-tema-oscuro #contenidoEditarVenta td,
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #1e293b"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #64748b"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #666"] {
                color: #e2e8f0 !important;
            }
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #1e40af"] { color: #bfdbfe !important; }
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #856404"] { color: #fde68a !important; }
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #059669"] { color: #86efac !important; }
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #1e3a8a"] { color: #bfdbfe !important; }
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #b91c1c"] { color: #fca5a5 !important; }
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color:#92400e"],
            html.nuvi-tema-oscuro #contenidoEditarVenta [style*="color: #92400e"] { color: #fde68a !important; }
            html.nuvi-tema-oscuro #correccionTarjetaEditar {
                background: #172033 !important;
                color: #e2e8f0 !important;
                border-color: #3b82f6 !important;
            }
            html.nuvi-tema-oscuro #correccionTarjetaEditar strong,
            html.nuvi-tema-oscuro #correccionTarjetaEditar label { color: #bfdbfe !important; }
            html.nuvi-tema-oscuro #correccionTarjetaEditar p { color: #cbd5e1 !important; }
            html.nuvi-tema-oscuro #resumenCorreccionTarjetaEditar { color: #bfdbfe !important; }
            html.nuvi-tema-oscuro #contenidoEditarVenta input,
            html.nuvi-tema-oscuro #contenidoEditarVenta select {
                background: #0f172a !important;
                color: #f8fafc !important;
                border-color: #475569 !important;
            }
            html.nuvi-tema-oscuro #inputUniversal.lector-confirmado {
                background: #123b2d !important;
                border-color: #4ade80 !important;
                color: #f0fdf4 !important;
                box-shadow: 0 0 0 4px rgba(74, 222, 128, 0.20) !important;
            }
            html.nuvi-tema-oscuro #controles-camara {
                background: #1e293b !important;
                border-color: #475569 !important;
                color: #cbd5e1 !important;
            }
            html.nuvi-tema-oscuro #estado-camara { color: #cbd5e1 !important; }
            html.nuvi-tema-oscuro #btn-modo-poca-luz {
                background: #26364f !important;
                color: #dbeafe !important;
                border-color: #475569 !important;
            }

            /* El historial de libreta se construye dinámicamente y varios de
               sus colores no llevan espacio tras ":". Se define su propia
               paleta para que abonos, créditos y recargos sigan legibles en
               oscuro sin depender de selectores genéricos. */
            html.nuvi-tema-oscuro #modalLibreta > div,
            html.nuvi-tema-oscuro #modalLibreta #detalleCliente {
                background: #172033 !important;
                color: #e2e8f0 !important;
                border-color: #334155 !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #nombreDetalle,
            html.nuvi-tema-oscuro #modalLibreta [style*="color:#1e3a8a"],
            html.nuvi-tema-oscuro #modalLibreta [style*="color: #1e3a8a"] {
                color: #bfdbfe !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="background"] {
                background: #1e293b !important;
                border-color: #475569 !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="background:#ecfdf5"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="background: #ecfdf5"] {
                background: #123b2d !important;
                border-color: #2f855a !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="background:#fff7ed"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="background: #fff7ed"] {
                background: #3b2e1f !important;
                border-color: #a16207 !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color:"] {
                color: #cbd5e1 !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color:#059669"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color: #059669"] {
                color: #86efac !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color:#c2410c"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color: #c2410c"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color:#9a3412"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color: #9a3412"] {
                color: #fdba74 !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color:#1d4ed8"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color: #1d4ed8"] {
                color: #bfdbfe !important;
            }
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color:#dc2626"],
            html.nuvi-tema-oscuro #modalLibreta #tablaHistorial [style*="color: #dc2626"] {
                color: #fca5a5 !important;
            }
        </style>
    </head>

    <body>
        <?php renderAppHeader('pos'); ?>

        <div class="contenedor">


            <h1>🏪 Punto de Venta - Nuvi</h1>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin:-8px 0 18px 0; align-items:center; flex-wrap:wrap;">
                <button onclick="abrirLibreta()" type="button" style="background:#0e5a77; color:white; padding:10px 14px; border:none; border-radius:10px; font-weight:700;">
                    📖 Libreta
                </button>
                <button onclick="abrirVentasRecientes()" type="button" style="background:#f59e0b; color:white; padding:10px 16px; border:none; border-radius:10px; font-weight:700;">
                    📋 Ver Ventas Recientes
                </button>
                <button id="btn-escaner" type="button" style="background:#475569; color:white; padding:10px 14px; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
                    📷 Usar cámara
                </button>
                <button id="btn-imprimir-ultimo-ticket" type="button" style="display:none; background:#0f766e; color:white; padding:10px 14px; border:none; border-radius:10px; font-weight:700;">
                    🧾 Último ticket
                </button>
                <button id="btn-imprimir-copia-ticket" type="button" style="display:none; background:#475569; color:white; padding:10px 14px; border:none; border-radius:10px; font-weight:700;">
                    📄 Imprimir copia
                </button>
                <button onclick="recargarCatalogoPos()" type="button" style="background:transparent; color:#475569; padding:8px 12px; border:1px solid #cbd5e1; border-radius:10px; font-weight:600; opacity:0.75; font-size:0.95rem;">
                    🔁
                </button>
            </div>

            <div id="mensaje-error" class="error-msg"></div>

            <div id="reader" style="width: 100%; max-width: 500px; margin: auto; display: none; border: 2px solid #007bff; border-radius: 8px; overflow: hidden;"></div>
            <div id="controles-camara" style="display:none; width:100%; max-width:500px; margin:8px auto 14px; padding:10px; border:1px solid #bfdbfe; border-radius:10px; background:#eff6ff; color:#1e3a8a;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <button id="btn-modo-poca-luz" type="button" title="Mejora la lectura de la cámara con poca luz" aria-label="Activar modo poca luz" style="background:transparent; color:#1d4ed8; padding:5px 8px; border:1px solid #93c5fd; border-radius:7px; font-weight:600; font-size:.82rem; cursor:pointer;">💡 Poca luz</button>
                    <span id="estado-camara" style="font-size:.84rem; line-height:1.35;">Mejora enfoque e intenta encender la linterna de la cámara.</span>
                </div>
            </div>

            <div style="position: relative; margin-bottom: 20px;">
                <input type="text" id="inputUniversal"
                    placeholder="Escanear código o buscar por Nombre, Marca, Categoría..."
                    style="width: 100%; padding: 15px; border-radius: 10px; border: 2px solid #3b82f6; font-size: 1.1em;"
                    autocomplete="off">
                <div id="sugerenciasBusqueda"
                    style="display:none; position: absolute; width:100%; background:white; z-index:100; border:1px solid #ddd; border-radius:0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                </div>
            </div>

            <div id="modalLibreta" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
                <div style="background:white; width:90%; max-width:600px; margin:50px auto; padding:20px; border-radius:15px; max-height:80vh; overflow-y:auto;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h2>Libreta Digital - Cuentas</h2>
                        <button onclick="cerrarLibreta()" style="background:red; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer;">X</button>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
                        <input type="text" id="buscarClienteLibreta" placeholder="Buscar cliente por nombre o teléfono..."
                            style="flex:1; min-width:0; padding:10px; border:1px solid #ddd; border-radius:8px;">
                        <button id="btn-ver-clientes-archivados" type="button" aria-pressed="false" style="background:#64748b; color:white; border:none; padding:10px 12px; border-radius:8px; cursor:pointer; white-space:nowrap;">
                            🗃️ Archivados
                        </button>
                    </div>
                    <p id="estado-clientes-libreta" style="margin:8px 0 0; font-size:.84rem; color:#64748b;">Mostrando clientes activos.</p>

                    <div id="listaClientesLibreta" style="margin-top:20px;">
                    </div>

                    <hr>
                    <button onclick="mostrarFormNuevoCliente()" style="background:#10b981; color:white; border:none; padding:10px; width:100%; border-radius:8px; cursor:pointer;">
                        + Registrar Nuevo Cliente
                    </button>
                    <div id="detalleCliente" style="display:none; margin-top: 20px; padding: 15px; border: 1px solid #3b82f6; border-radius: 10px; background: #f8fafc;">
                        <h3 id="nombreDetalle" style="margin: 0 0 10px 0; color: #1e3a8a;"></h3>
                        <div id="tablaHistorial" style="max-height: 200px; overflow-y: auto; font-size: 0.85em;">
                        </div>
                        <button onclick="cerrarDetalle()" style="margin-top: 10px; width: 100%; padding: 5px; background: #94a3b8; color: white; border: none; border-radius: 5px;">Cerrar Historial</button>
                    </div>

                    <div id="formNuevoCliente" style="display:none; margin-top: 20px; padding: 15px; border: 1px solid #10b981; border-radius: 10px; background-color: #f0fdf4;">
                        <h3 id="titulo-form-cliente" style="color: #065f46; margin-top: 0;">Registrar Nuevo Cliente</h3>
                        <div style="display: grid; gap: 10px;">
                            <input type="text" id="nom_cliente" placeholder="Nombre completo" style="padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <input type="text" id="tel_cliente" placeholder="Teléfono (10 dígitos)" style="padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                            <input type="number" id="limite_cliente" min="0" max="999999.99" step="0.01" placeholder="Límite de crédito (Ej: 500)" style="padding: 8px; border-radius: 5px; border: 1px solid #ccc;">

                            <div style="display: flex; gap: 10px;">
                                <button id="btn-guardar-cliente" onclick="guardarCliente()" style="flex: 1; background: #10b981; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">Guardar</button>
                                <button onclick="cancelarNuevoCliente()" style="flex: 1; background: #94a3b8; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">Cancelar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="modalVentasRecientes" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
                <div style="background:white; width:90%; max-width:800px; margin:50px auto; padding:20px; border-radius:15px; max-height:80vh; overflow-y:auto;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h2>Ventas Recientes</h2>
                        <button onclick="cerrarVentasRecientes()" style="background:red; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer;">X</button>
                    </div>
                    <div id="listaVentasRecientes" style="margin-top:20px;">
                        <!-- Ventas se cargarán aquí -->
                    </div>
                </div>
            </div>

            <div id="modalEditarVenta" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;" onclick="if(event.target.id==='modalEditarVenta') cerrarEditarVenta();">
                <div style="background:white; width:95%; max-width:1000px; margin:30px auto; padding:0; border-radius:15px; max-height:85vh; overflow:hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); display:flex; flex-direction:column;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding: 20px 25px; background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); color: white;">
                        <h2 style="margin: 0; font-size: 1.5rem;">✏️ Editar Venta #<span id="idVentaEditar"></span></h2>
                        <button onclick="cerrarEditarVenta()" style="background: rgba(255,255,255,0.2); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 1.2rem; font-weight: bold; transition: all 0.2s;">✕</button>
                    </div>
                    <div id="contenidoEditarVenta" style="margin: 0; padding: 25px; overflow-y: auto; flex: 1;">
                        <!-- Contenido se cargará aquí -->
                    </div>
                </div>
            </div>

            <button id="btn-abrir-granel" type="button" style="width: 100%; padding: 12px; margin-bottom: 10px; background-color: #f39c12; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer;">
                ⚖️ Venta a Granel (Jamón, Queso, etc.)
            </button>

            <div id="panel-granel" style="display: none; background: #fff3cd; padding: 15px; border: 2px solid #ffeeba; border-radius: 8px; margin-bottom: 20px;">
                <h3>⚖️ Registro de Granel</h3>

                <p>Selecciona un producto:</p>
                <div id="granel-accesos" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:12px;"></div>
                <label>Buscar Producto:</label>
                <input type="text" id="buscar-granel" placeholder="Escribe el nombre del producto..." style="width: 100%; padding: 8px; margin-bottom: 10px;">

                <div id="resultados-busqueda" style="background: white; border: 1px solid #ddd; display: none; max-height: 150px; overflow-y: auto; margin-bottom: 10px;">
                </div>

                <div id="seccion-calculo" style="display: none;">
                    <p><strong>Producto:</strong> <span id="nombre-prod-sel">-</span></p>
                    <p><strong>Precio x <span id="unidad-prod-sel">kg</span>:</strong> $<span id="precio-prod-sel">0.00</span></p>

                    <label>¿Cómo vas a cobrar?</label>
                    <select id="tipo-cobro" style="width: 100%; padding: 8px; margin-bottom: 10px;">
                        <option value="dinero">Por Monto (Dinero $)</option>
                        <option value="peso">Por Peso (Kilos/Gramos)</option>
                    </select>

                    <label id="label-cantidad">Monto a vender ($):</label>
                    <input type="number" id="cantidad-granel" step="0.01" placeholder="0.00" style="width: 100%; padding: 8px; margin-bottom: 10px;">

                    <div id="redondeo-granel-grupo" style="display:none; margin-bottom:10px;">
                        <label for="redondeo-granel">Importe a cobrar según báscula:</label>
                        <select id="redondeo-granel" style="width:100%; padding:8px; margin-top:4px;">
                            <option value="exacto">Cobrar importe exacto</option>
                            <option value="abajo">Redondear al peso hacia abajo</option>
                            <option value="arriba">Redondear al peso hacia arriba</option>
                        </select>
                    </div>

                    <button id="btn-agregar-granel" type="button" style="background-color: #28a745; color: white; padding: 10px; width: 100%; border: none; border-radius: 5px; font-weight: bold;">
                        ➕ Agregar a la lista
                    </button>
                </div>
            </div>



            <div class="table-wrapper">
                <table id="tabla-carrito">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th>Precio</th>
                            <th>Cant.</th>
                            <th>SubT</th>
                            <th>Opcio.</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <div class="total-container">
                Total: $<span id="gran-total">0.00</span>

            </div>
            <div class="panel-cobro" style="background: #e9ecef; padding: 15px; border-radius: 8px; margin-top: 20px;">
                <label><strong>Nota de la venta:</strong></label>
                <textarea id="nota-venta" rows="2" placeholder="Ej. Se quedan 3 envases retornables, pago parcial, pedido especial..." style="width: 100%; padding: 10px; font-size: 0.98rem; border: 2px solid #cbd5e1; border-radius: 8px; margin: 8px 0 14px 0; resize: vertical;"></textarea>

                <label><strong>Método de Pago:</strong></label>
                <select id="metodo-pago" style="width: 100%; padding: 10px; font-size: 1rem; border: 2px solid #3b82f6; border-radius: 5px; margin: 8px 0 14px 0;">
                    <option value="efectivo">Efectivo</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="otro">Otro</option>
                    <option value="mixto">Efectivo + otro método</option>
                </select>

                <div id="pago-mixto-grupo" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; padding:12px; margin:0 0 14px 0;">
                    <strong style="display:block; margin-bottom:8px; color:#1e3a8a;">Cobro mixto</strong>
                    <label for="mixto-efectivo-parte"><strong>Parte de la venta en efectivo ($):</strong></label>
                    <input type="text" id="mixto-efectivo-parte" inputmode="none" autocomplete="off" placeholder="0.00" style="width:100%; padding:9px; font-size:1.05rem; border:2px solid #28a745; border-radius:5px; margin:5px 0 10px 0;">
                    <label for="pago-mixto-efectivo"><strong>Efectivo entregado ($, opcional):</strong></label>
                    <input type="text" id="pago-mixto-efectivo" inputmode="none" autocomplete="off" placeholder="Igual a la parte en efectivo" style="width:100%; padding:9px; font-size:1.05rem; border:2px solid #28a745; border-radius:5px; margin:5px 0 10px 0;">
                    <label for="metodo-mixto-secundario"><strong>El resto se paga con:</strong></label>
                    <select id="metodo-mixto-secundario" style="width:100%; padding:9px; margin:5px 0 10px 0; border-radius:5px; border:1px solid #94a3b8;">
                        <option value="tarjeta">Tarjeta</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="otro">Otro</option>
                    </select>
                    <label for="monto-mixto-secundario"><strong>Resto con el segundo método ($):</strong></label>
                    <input type="number" id="monto-mixto-secundario" min="0" step="0.01" value="0.00" readonly style="width:100%; padding:9px; font-size:1.05rem; border:2px solid #3b82f6; border-radius:5px; margin:5px 0 8px 0;">
                    <p style="margin:7px 0 0; font-size:.94rem; color:#334155;">Efectivo aplicado a productos: <strong id="mixto-efectivo-aplicado">$0.00</strong></p>
                    <p style="margin:4px 0 0; font-size:.94rem; color:#334155;">Cambio a dar: <strong id="mixto-cambio">$0.00</strong></p>
                </div>

                <div id="terminal-pago-grupo" style="display:none; margin: 0 0 14px 0;">
                    <label for="terminal-pago"><strong>Terminal a utilizar:</strong></label>
                    <select id="terminal-pago" style="width: 100%; padding: 10px; font-size: 1rem; border: 2px solid #2563eb; border-radius: 5px; margin-top: 8px;"></select>
                </div>
                <div id="terminal-pago-estado" role="status" aria-live="polite" style="display:none; margin: 0 0 14px 0; padding: 10px 12px; border-radius: 8px; font-size: 0.9rem;"></div>

                <div id="comision-tarjeta-info" style="display:none; background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; padding:12px; margin-bottom:14px; color:#1e3a8a;">
                    <div style="display:flex; justify-content:space-between; gap:12px; font-size:0.9rem;"><span id="comisionEtiquetaBase">Total de productos:</span><strong id="comisionMontoProductos">$0.00</strong></div>
                    <div style="display:flex; justify-content:space-between; gap:12px; color:#9a3412; font-size:0.9rem; margin-top:5px;"><span>Comisión de terminal (la paga el cliente):</span><strong id="comisionMontoComision">+$0.00</strong></div>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:10px; padding:10px; border-radius:8px; background:#1d4ed8; color:#fff;"><span style="font-weight:700;">Cobrar en terminal</span><strong id="comisionMontoTerminal" style="font-size:1.35rem;">$0.00</strong></div>
                    <div style="display:flex; justify-content:space-between; gap:12px; margin-top:9px; font-size:0.88rem;"><span>El negocio recibe al menos:</span><strong id="comisionMontoNeto">$0.00</strong></div>
                    <div style="margin-top:8px; color:#475569; font-size:0.78rem;"><strong id="comisionTerminalNombre">Terminal</strong> · Tasa configurada: <span id="comisionTasaTexto">3.50%</span> + IVA</div>
                </div>

                <div id="efectivo-recibido-grupo">
                    <label for="pago-con"><strong>Efectivo Recibido ($):</strong></label>
                    <input type="text" id="pago-con" inputmode="none" autocomplete="off" placeholder="0.00" style="width: 100%; padding: 10px; font-size: 1.2rem; border: 2px solid #28a745; border-radius: 5px;">

                    <p style="font-size: 1.2rem; margin-top: 10px;">
                        <strong id="label-cambio">Pago exacto: </strong>
                        <span id="cambio-cliente" style="color: #6b7280; font-weight: bold;">$0.00</span>
                    </p>
                </div>
                <div id="teclado-cobro" class="teclado-cobro" aria-label="Teclado para introducir efectivo">
                    <p id="teclado-cobro-destino" aria-live="polite">Escribir: efectivo recibido</p>
                    <div class="teclado-cobro-grid">
                        <button type="button" data-tecla-cobro="1">1</button>
                        <button type="button" data-tecla-cobro="2">2</button>
                        <button type="button" data-tecla-cobro="3">3</button>
                        <button type="button" data-tecla-cobro="4">4</button>
                        <button type="button" data-tecla-cobro="5">5</button>
                        <button type="button" data-tecla-cobro="6">6</button>
                        <button type="button" data-tecla-cobro="7">7</button>
                        <button type="button" data-tecla-cobro="8">8</button>
                        <button type="button" data-tecla-cobro="9">9</button>
                        <button type="button" data-tecla-cobro="." aria-label="Punto decimal">.</button>
                        <button type="button" data-tecla-cobro="0">0</button>
                        <button type="button" data-tecla-cobro="borrar" aria-label="Borrar último dígito">⌫</button>
                        <button type="button" data-tecla-cobro="limpiar" style="grid-column:span 3; font-size:1rem;">Borrar cantidad</button>
                    </div>
                </div>
            </div>
            <button id="btn-finalizar-venta" style="width: 100%; padding: 15px; background-color: #28a745; color: white; border: none; border-radius: 8px; font-size: 1.2rem; font-weight: bold; cursor: pointer; margin-top: 20px;">
                💰 Finalizar Venta y Cobrar
            </button>
        </div>

        <script src="js/nuvi-emergencia.js?v=19"></script>
        <script>
            // ============================================
            // 🚀 ESTADO GLOBAL Y CONSTANTES
            // ============================================
            let PRODUCTOS_LOCALES = [];
            let PRODUCTOS_POR_CODIGO = new Map();
            let timerBusquedaUniversal = null;
            let timerConfirmacionLector = null;
            let timerBusquedaGranel = null;
            let html5QrCode;
            let escanerActivo = false;
            let bloqueado = false;
            const DEBUG_LECTOR = false;
            const PUEDE_AJUSTAR_SALDO = <?php echo $puedeAjustarSaldo ? 'true' : 'false'; ?>;
            const PUEDE_GESTIONAR_CLIENTES = <?php echo $puedeAjustarSaldo ? 'true' : 'false'; ?>;
            const LONGITUDES_CODIGO_BARRAS = [8, 12, 13, 14];
            const ESPERA_ESCANER_MS = 3000;
            const INTERVALO_MAXIMO_LECTOR_MS = 70;
            // Bluetooth añade una pequeña latencia entre teclas. Este margen
            // se usa solamente fuera del buscador, donde un cajero no captura
            // códigos largos a mano; así el lector HID no se parte a mitad.
            const INTERVALO_MAXIMO_LECTOR_BLUETOOTH_MS = 260;
            const ESPERA_AUTOPROCESAR_LECTOR_MS = 85;
            const ID_USUARIO_ACTUAL = <?php echo (int) ($_SESSION['id_usuario'] ?? 0); ?>;
            let NUVI_CSRF_TOKEN = <?php echo json_encode(nuviTokenCsrf()); ?>;
            const NUVI_CONFIG_TICKET = <?php echo json_encode($configTicket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            const POS_CATALOGO_CACHE_KEY = 'nuvi_pos_catalogo_cache_v2';
            const POS_CATALOGO_CACHE_TTL_MS = 1000 * 60 * 60 * 8;
            const LIBRETA_CLIENTES_CACHE_KEY = `nuvi_libreta_clientes_cache_v3_${ID_USUARIO_ACTUAL}`;
            const NUVI_COMISIONES_CACHE_KEY = 'nuvi_terminales_cache_v1';
            let clientesLibreta = [];
            let estadoClientesLibreta = 'activos';
            let clienteEditandoLibreta = null;
            let secuenciaCargaLibreta = 0;
            let cargadorEscaner = null;
            let nuviErrorTimer = null;
            let contextoAudioLector = null;
            let lecturaAnterior = '';
            let lecturaInicioMs = 0;
            let lecturaUltimoTeclaMs = 0;
            let lecturaRapidaActiva = false;
            let capturaLectorFisicoGlobal = null;
            let timerCapturaLectorFisico = null;
            let modoPocaLuzActivo = false;
            let ultimoTicketEnMemoria = null;
            let NUVI_IMPRESORA_RED = { activa: false, nombre: '' };

            // ============================================
            // 📦 ELEMENTOS DEL DOM
            // ============================================
            const DOM = {
                btnEscaner: document.getElementById('btn-escaner'),
                readerDiv: document.getElementById('reader'),
                controlesCamara: document.getElementById('controles-camara'),
                btnModoPocaLuz: document.getElementById('btn-modo-poca-luz'),
                estadoCamara: document.getElementById('estado-camara'),
                btnImprimirUltimoTicket: document.getElementById('btn-imprimir-ultimo-ticket'),
                btnImprimirCopiaTicket: document.getElementById('btn-imprimir-copia-ticket'),
                inputUniversal: document.getElementById('inputUniversal'),
                sugerenciasBusqueda: document.getElementById('sugerenciasBusqueda'),
                btnAbrirGranel: document.getElementById('btn-abrir-granel'),
                panelGranel: document.getElementById('panel-granel'),
                btnAbrirLibreta: document.querySelector('button[onclick="abrirLibreta()"]'),
                modalLibreta: document.getElementById('modalLibreta'),
                tablaCarritoBody: document.querySelector("#tabla-carrito tbody"),
                errorDiv: document.getElementById('mensaje-error'),
                btnFinalizar: document.getElementById('btn-finalizar-venta'),
                notaVentaInput: document.getElementById('nota-venta'),
                metodoPagoSelect: document.getElementById('metodo-pago'),
                pagoConInput: document.getElementById('pago-con'),
                pagoMixtoParteInput: document.getElementById('mixto-efectivo-parte'),
                pagoMixtoGrupo: document.getElementById('pago-mixto-grupo'),
                pagoMixtoEfectivoInput: document.getElementById('pago-mixto-efectivo'),
                metodoMixtoSecundario: document.getElementById('metodo-mixto-secundario'),
                montoMixtoSecundarioInput: document.getElementById('monto-mixto-secundario'),
                mixtoEfectivoAplicado: document.getElementById('mixto-efectivo-aplicado'),
                mixtoCambio: document.getElementById('mixto-cambio'),
                cambioClienteSpan: document.getElementById('cambio-cliente'),
                granTotalSpan: document.getElementById('gran-total'),
                tipoCobro: document.getElementById('tipo-cobro'),
                labelCantidad: document.getElementById('label-cantidad'),
                inputCantidad: document.getElementById('cantidad-granel'),
                redondeoGranelGrupo: document.getElementById('redondeo-granel-grupo'),
                redondeoGranel: document.getElementById('redondeo-granel'),
                inputBusquedaGranel: document.getElementById('buscar-granel'),
                resultadosBusquedaGranel: document.getElementById('resultados-busqueda'),
                seccionCalculoGranel: document.getElementById('seccion-calculo'),
                btnAgregarGranel: document.getElementById('btn-agregar-granel'),
                buscarClienteLibreta: document.getElementById('buscarClienteLibreta'),
                btnVerClientesArchivados: document.getElementById('btn-ver-clientes-archivados'),
                estadoClientesLibreta: document.getElementById('estado-clientes-libreta'),
                listaClientesLibreta: document.getElementById('listaClientesLibreta'),
                detalleCliente: document.getElementById('detalleCliente'),
                formNuevoCliente: document.getElementById('formNuevoCliente'),
                tituloFormCliente: document.getElementById('titulo-form-cliente'),
                btnGuardarCliente: document.getElementById('btn-guardar-cliente'),
                nomCliente: document.getElementById('nom_cliente'),
                telCliente: document.getElementById('tel_cliente'),
                limiteCliente: document.getElementById('limite_cliente'),
                labelCambio: document.getElementById('label-cambio'),
                modalVentasRecientes: document.getElementById('modalVentasRecientes'),
                modalEditarVenta: document.getElementById('modalEditarVenta'),
                comisionTarjetaInfo: document.getElementById('comision-tarjeta-info'),
                comisionMontoProductos: document.getElementById('comisionMontoProductos'),
                comisionMontoTerminal: document.getElementById('comisionMontoTerminal'),
                comisionTasaTexto: document.getElementById('comisionTasaTexto'),
                comisionMontoComision: document.getElementById('comisionMontoComision'),
                comisionMontoNeto: document.getElementById('comisionMontoNeto'),
                comisionEtiquetaBase: document.getElementById('comisionEtiquetaBase'),
                terminalPagoGrupo: document.getElementById('terminal-pago-grupo'),
                terminalPagoSelect: document.getElementById('terminal-pago'),
                terminalPagoEstado: document.getElementById('terminal-pago-estado'),
                comisionTerminalNombre: document.getElementById('comisionTerminalNombre'),
                efectivoRecibidoGrupo: document.getElementById('efectivo-recibido-grupo')
            };
            let campoTecladoCobro = DOM.pagoConInput;

            // ============================================
            // 🔄 PRECARGA DE PRODUCTOS
            // ============================================
            function normalizarCatalogo(productos) {
                const catalogo = (Array.isArray(productos) ? productos : []).map(p => ({
                    ...p,
                    _searchText: `${p.nombre || ''} ${p.marca || ''} ${p.categoria || ''} ${p.codigo_barras || ''}`.toLowerCase()
                }));
                indexarCodigosCatalogo(catalogo);
                return catalogo;
            }

            function indexarCodigosCatalogo(catalogo) {
                PRODUCTOS_POR_CODIGO = new Map();
                (Array.isArray(catalogo) ? catalogo : []).forEach(producto => {
                    const codigo = String(producto.codigo_barras || '').trim();
                    if (codigo) PRODUCTOS_POR_CODIGO.set(codigo, producto);
                });
            }

            function leerCatalogoCachePos(permitirVencido = false) {
                try {
                    const raw = localStorage.getItem(POS_CATALOGO_CACHE_KEY);
                    if (!raw) return [];
                    const data = JSON.parse(raw);
                    const ts = Number(data.ts || 0);
                    const productos = Array.isArray(data.productos) ? data.productos : [];
                    if (!ts || !productos.length) return [];
                    if (!permitirVencido && (Date.now() - ts) > POS_CATALOGO_CACHE_TTL_MS) return [];
                    return productos;
                } catch (e) {
                    return [];
                }
            }

            function guardarCatalogoCachePos(productos) {
                try {
                    localStorage.setItem(POS_CATALOGO_CACHE_KEY, JSON.stringify({
                        ts: Date.now(),
                        productos: Array.isArray(productos) ? productos : []
                    }));
                } catch (e) {
                    console.warn('No se pudo guardar cache de catálogo POS:', e);
                }
            }

            async function precargarProductos(forzarActualizacion = false) {
                const catalogoFresco = leerCatalogoCachePos();
                // Aunque el catálogo tenga más de ocho horas, primero se carga
                // al instante. Así una caja con batería sigue vendiendo si el
                // servidor o la red se apagaron; después se intenta refrescar.
                const catalogoCache = catalogoFresco.length ? catalogoFresco : leerCatalogoCachePos(true);
                let seCargoDesdeCache = false;

                if (catalogoCache.length > 0) {
                    PRODUCTOS_LOCALES = normalizarCatalogo(catalogoCache);
                    mostrarAccesosGranel();
                    seCargoDesdeCache = true;
                    logLector('Catalogo cargado desde cache local:', PRODUCTOS_LOCALES.length, 'productos');
                    revisarAlertasStock(PRODUCTOS_LOCALES);
                }

                try {
                    const controller = new AbortController();
                    const timer = setTimeout(() => controller.abort(), seCargoDesdeCache ? 2500 : 7000);
                    const recurso = forzarActualizacion
                        ? `obtener_todo.php?_=${Date.now()}`
                        : 'obtener_todo.php';
                    const response = await fetch(recurso, {
                        signal: controller.signal,
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    clearTimeout(timer);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const productos = await response.json();
                    PRODUCTOS_LOCALES = normalizarCatalogo(productos);
                    mostrarAccesosGranel();
                    console.log("✅ " + PRODUCTOS_LOCALES.length + " productos cargados.");
                    logLector('Catalogo precargado:', PRODUCTOS_LOCALES.length, 'productos');
                    guardarCatalogoCachePos(productos);
                    await NuviEmergencia.guardarCatalogo(ID_USUARIO_ACTUAL, <?php echo json_encode((string) ($_SESSION['nombre'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, productos)
                        .catch(error => console.error('No se pudo preparar la caja de emergencia:', error));
                    revisarAlertasStock(PRODUCTOS_LOCALES);
                    await nuvi_loadRemoteScores();
                } catch (e) {
                    console.error("Error en precarga:", e);
                    if (seCargoDesdeCache) {
                        mostrarToast("Modo sin servidor: se usa el catálogo guardado en esta caja.", "warning");
                    } else {
                        mostrarError("No se pudo cargar el catálogo. Revisa la conexión con el servidor local.");
                    }
                }
            }

            window.addEventListener('storage', (event) => {
                if (event.key === 'nuvi_productos_actualizados') {
                    precargarProductos();
                }
            });

            function recargarCatalogoPos() {
                mostrarToast('Recargando catálogo de productos...', 'info');
                precargarProductos(true);
            }

            precargarProductos();

            // Escuchar cambios desde la página de inventario (cross-tab via localStorage)
            window.addEventListener('storage', (e) => {
                try {
                    if (!e.key || !e.newValue) return;
                    if (e.key === 'nuvi_product_deactivated') {
                        const payload = JSON.parse(e.newValue);
                        if (payload && payload.id) removeProductById(payload.id);
                    } else if (e.key === 'nuvi_product_reactivated') {
                        // Para reactivación, recargar catálogo para traer cambios
                        precargarProductos();
                    }
                } catch (err) { console.error('Error procesando storage event:', err); }
            });

            function removeProductById(id) {
                try {
                    const sid = String(id);
                    // Eliminar del catálogo local
                    for (let i = PRODUCTOS_LOCALES.length - 1; i >= 0; i--) {
                        const p = PRODUCTOS_LOCALES[i];
                        if (String(p.id) === sid || String(p.codigo_barras) === sid) {
                            PRODUCTOS_LOCALES.splice(i, 1);
                        }
                    }
                    indexarCodigosCatalogo(PRODUCTOS_LOCALES);
                    // Una venta a granel tiene un id de fila único. El atributo
                    // común conserva el id real del producto y permite retirar
                    // todas sus líneas, no sólo la primera fila normal.
                    document.querySelectorAll('#tabla-carrito tbody tr').forEach(fila => {
                        if (String(fila.dataset.productoId || '') === sid) fila.remove();
                    });
                    actualizarGranTotal();
                    revisarAlertasStock(PRODUCTOS_LOCALES);
                    mostrarToast('Producto desactivado: catálogo actualizado', 'warning');
                } catch (err) { console.error('removeProductById error', err); }
            }

            // ============================================
            // 🎨 FUNCIONES AUXILIARES
            // ============================================
            function nuvi_headersMutacion(headers = {}) {
                return { ...headers, 'X-Nuvi-CSRF': NUVI_CSRF_TOKEN };
            }

            function mostrarError(msg) {
                if (nuviErrorTimer) clearTimeout(nuviErrorTimer);
                DOM.errorDiv.innerText = "🚨 " + msg;
                DOM.errorDiv.style.display = 'block';
                // En tablet el botón de cobro queda bastante abajo. El aviso
                // permanece visible y se enfoca para que no se pierda el detalle.
                DOM.errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                nuviErrorTimer = setTimeout(() => {
                    DOM.errorDiv.style.display = 'none';
                    nuviErrorTimer = null;
                }, 12000);
            }

            function mostrarToast(msg, tipo = 'info') {
                let container = document.getElementById('nuvi-toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'nuvi-toast-container';
                    Object.assign(container.style, {
                        position: 'fixed',
                        right: '16px',
                        bottom: '16px',
                        zIndex: 99999,
                        display: 'flex',
                        flexDirection: 'column-reverse',
                        gap: '8px',
                        pointerEvents: 'none',
                        maxWidth: '360px'
                    });
                    document.body.appendChild(container);
                }

                const toast = document.createElement('div');
                toast.textContent = msg;
                Object.assign(toast.style, {
                    pointerEvents: 'auto',
                    padding: '12px 14px',
                    borderRadius: '10px',
                    color: '#fff',
                    background: tipo === 'warning' ? '#f59e0b' : tipo === 'error' ? '#ef4444' : '#2563eb',
                    boxShadow: '0 8px 24px rgba(15, 23, 42, 0.18)',
                    maxWidth: '360px',
                    lineHeight: '1.35'
                });
                container.appendChild(toast);
                setTimeout(() => toast.remove(), 4000);
            }

            // Local POS personalization: store per-device sales counts to surface most-sold products first
            function _nuvi_getPosScores() {
                try {
                    return JSON.parse(localStorage.getItem('nuvi_pos_scores') || '{}');
                } catch (e) { return {}; }
            }

            function _nuvi_savePosScores(scores) {
                try { localStorage.setItem('nuvi_pos_scores', JSON.stringify(scores)); } catch (e) { console.error('Error saving scores', e); }
            }

            async function nuvi_loadRemoteScores() {
                try {
                    const response = await fetch('pos_scores_get.php');
                    if (!response.ok) return;
                    const data = await response.json();
                    if (!Array.isArray(data)) return;

                    const scores = _nuvi_getPosScores();
                    data.forEach(item => {
                        const key = String(item.producto_id || item.id || item.codigo || '').trim();
                        if (!key) return;
                        const remoteScore = parseFloat(item.score) || 0;
                        scores[key] = Math.max(scores[key] || 0, remoteScore);
                    });
                    _nuvi_savePosScores(scores);
                    logLector('Remote POS scores loaded:', Object.keys(scores).length);
                } catch (e) {
                    console.warn('Error cargando puntuaciones POS remotas:', e);
                }
            }

            async function nuvi_syncPosScores(productos) {
                try {
                    const payload = { productos: Array.isArray(productos) ? productos : [] };
                    await fetch('pos_scores_inc.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify(payload)
                    });
                } catch (e) {
                    console.warn('Error sincronizando puntuaciones POS:', e);
                }
            }

            function nuvi_incrementScores(productos) {
                if (!Array.isArray(productos)) return;
                const scores = _nuvi_getPosScores();
                productos.forEach(p => {
                    const key = String(p.id || p.codigo || p.codigo_barras || p.id_producto || p.nombre || '').trim();
                    if (!key) return;
                    const inc = parseFloat(p.cantidad || p.qty || p.cantidad_vendida || p.subtotal || 1) || 1;
                    scores[key] = (scores[key] || 0) + Math.abs(inc);
                });
                _nuvi_savePosScores(scores);
                nuvi_syncPosScores(productos);
            }

            function revisarAlertasStock(productos) {
                const storageKey = 'nuvi_stock_alerts';
                let alertas = {};
                try { alertas = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) {}

                (productos || []).forEach(producto => {
                    const stock = parseFloat(producto.stock) || 0;
                    const idClave = producto.id || producto.codigo_barras;
                    if (!idClave) return;

                    if (stock <= 0) {
                        if (alertas[idClave] !== 'agotado') {
                            alertas[idClave] = 'agotado';
                            mostrarToast(`${producto.nombre || 'Producto'} se agotó (${stock} unidades).`, 'error');
                            if ('Notification' in window && Notification.permission === 'granted') {
                                new Notification('Producto agotado', { body: `${producto.nombre || 'Producto'} se agotó.` });
                            }
                        }
                    } else if (stock <= 3) {
                        if (alertas[idClave] !== 'bajo') {
                            alertas[idClave] = 'bajo';
                            mostrarToast(`${producto.nombre || 'Producto'} está por agotarse (${stock} unidades).`, 'warning');
                            if ('Notification' in window && Notification.permission === 'granted') {
                                new Notification('Stock bajo', { body: `${producto.nombre || 'Producto'} está por agotarse.` });
                            }
                        }
                    } else {
                        delete alertas[idClave];
                    }
                });

                try { localStorage.setItem(storageKey, JSON.stringify(alertas)); } catch (e) {}
            }

            function logLector(...args) {
                if (!DEBUG_LECTOR) return;
                console.log('[Lector]', ...args);
            }

            function esCodigoBarras(texto) {
                return /^\d+$/.test(texto) && LONGITUDES_CODIGO_BARRAS.includes(texto.length);
            }

            function esTextoCorto(texto) {
                return /^\d+$/.test(texto) && texto.length > 0 && texto.length < 8;
            }

            function prepararAudioLector() {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) return null;

                try {
                    if (!contextoAudioLector) contextoAudioLector = new AudioContextClass();
                    if (contextoAudioLector.state === 'suspended') {
                        contextoAudioLector.resume().catch(() => {});
                    }
                    return contextoAudioLector;
                } catch (error) {
                    console.warn('No se pudo preparar el sonido del lector:', error);
                    return null;
                }
            }

            function emitirBipLector() {
                const contexto = prepararAudioLector();
                if (!contexto) return;

                const reproducir = () => {
                    if (contexto.state !== 'running') return;
                    try {
                        const ahora = contexto.currentTime;
                        const ganancia = contexto.createGain();
                        const oscilador = contexto.createOscillator();
                        // Onda cuadrada y ganancia mayor que el bip anterior: se
                        // percibe mejor en una caja con ruido sin ser estridente.
                        oscilador.type = 'square';
                        oscilador.frequency.setValueAtTime(1180, ahora);
                        ganancia.gain.setValueAtTime(0.0001, ahora);
                        ganancia.gain.exponentialRampToValueAtTime(0.34, ahora + 0.008);
                        ganancia.gain.exponentialRampToValueAtTime(0.0001, ahora + 0.115);
                        oscilador.connect(ganancia);
                        ganancia.connect(contexto.destination);
                        oscilador.start(ahora);
                        oscilador.stop(ahora + 0.125);
                    } catch (error) {
                        console.warn('No se pudo reproducir el bip del lector:', error);
                    }
                };

                // En algunas tablets el primer permiso de audio se resuelve
                // milisegundos después de la tecla del lector. Esperarlo hace
                // audible también ese primer escaneo.
                if (contexto.state === 'running') {
                    reproducir();
                } else {
                    contexto.resume().then(reproducir).catch(() => {});
                }
            }

            function confirmarLecturaLector() {
                emitirBipLector();
                if (!DOM.inputUniversal) return;
                clearTimeout(timerConfirmacionLector);
                DOM.inputUniversal.classList.remove('lector-confirmado');
                void DOM.inputUniversal.offsetWidth;
                DOM.inputUniversal.classList.add('lector-confirmado');
                timerConfirmacionLector = setTimeout(() => {
                    DOM.inputUniversal.classList.remove('lector-confirmado');
                }, 230);
            }

            // El navegador sólo permite audio después de una interacción real.
            // Prepararlo al primer toque o tecla evita que el primer escaneo se
            // quede silencioso por una política de la tablet.
            document.addEventListener('pointerdown', prepararAudioLector, { passive: true });
            document.addEventListener('keydown', prepararAudioLector, { capture: true, passive: true });

            function reiniciarRitmoLector() {
                lecturaAnterior = '';
                lecturaInicioMs = 0;
                lecturaUltimoTeclaMs = 0;
                lecturaRapidaActiva = false;
            }

            function nuviHayModalPosAbierto() {
                return [DOM.modalLibreta, DOM.modalVentasRecientes, DOM.modalEditarVenta].some(modal =>
                    modal && window.getComputedStyle(modal).display !== 'none'
                );
            }

            function nuviDestinoCapturaLector(elemento) {
                if (!(elemento instanceof Element)) return false;
                // El cobro se protege: una lectura ahí no puede sumar una línea
                // a una venta que ya se está finalizando. El valor temporal se
                // restaura al reconocer el código completo.
                if (elemento.closest('#pago-con, #mixto-efectivo-parte, #pago-mixto-efectivo, #nota-venta, #metodo-pago, #terminal-pago')) {
                    return 'protegido';
                }
                // Cuando granel está abierto, el lector sirve para localizar el
                // artículo a granel, no para meter números en peso o importe.
                if (DOM.panelGranel && window.getComputedStyle(DOM.panelGranel).display !== 'none') {
                    return 'granel';
                }
                return 'pos';
            }

            function nuviRestaurarCampoAntesDeLectura(captura) {
                const campo = captura?.campo;
                if (!(campo instanceof HTMLInputElement || campo instanceof HTMLTextAreaElement) || !document.contains(campo)) return;
                campo.value = captura.valorInicial;
                try {
                    const posicion = Math.min(captura.posicionInicial, campo.value.length);
                    campo.setSelectionRange(posicion, posicion);
                } catch (error) {}
            }

            function nuviCancelarCapturaLectorFisico(restaurarCampo = false) {
                if (timerCapturaLectorFisico) clearTimeout(timerCapturaLectorFisico);
                timerCapturaLectorFisico = null;
                if (restaurarCampo && capturaLectorFisicoGlobal) {
                    nuviRestaurarCampoAntesDeLectura(capturaLectorFisicoGlobal);
                }
                capturaLectorFisicoGlobal = null;
            }

            function nuviProcesarCapturaLectorFisico() {
                const captura = capturaLectorFisicoGlobal;
                if (!captura || !esCodigoBarras(captura.codigo)) return;
                nuviCancelarCapturaLectorFisico(true);
                clearTimeout(timerBusquedaUniversal);

                if (captura.destino === 'protegido') {
                    // Al restaurar un importe de efectivo también actualizamos
                    // el cambio mostrado, pues el listener de input ya vio
                    // temporalmente los dígitos enviados por el lector. El
                    // código se manda al buscador en vez de perderse: así el
                    // cajero puede agregar otro artículo aun estando en cobro.
                    if (captura.campo instanceof HTMLInputElement || captura.campo instanceof HTMLTextAreaElement) {
                        captura.campo.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    procesarEntradaUniversal(captura.codigo, true);
                    return;
                }
                if (captura.destino === 'granel') {
                    if (!DOM.inputBusquedaGranel) return;
                    DOM.inputBusquedaGranel.value = captura.codigo;
                    DOM.inputBusquedaGranel.dispatchEvent(new Event('keyup', { bubbles: true }));
                    DOM.inputBusquedaGranel.focus({ preventScroll: true });
                    return;
                }
                procesarEntradaUniversal(captura.codigo, true);
            }

            function nuviProgramarCapturaLectorFisico() {
                if (!capturaLectorFisicoGlobal || !esCodigoBarras(capturaLectorFisicoGlobal.codigo)) return;
                if (timerCapturaLectorFisico) clearTimeout(timerCapturaLectorFisico);
                // También funciona con lectores a los que aún no se les ha
                // configurado Enter como sufijo. Un lector físico escribe el
                // código mucho más rápido que una persona.
                timerCapturaLectorFisico = setTimeout(() => {
                    if (capturaLectorFisicoGlobal && esCodigoBarras(capturaLectorFisicoGlobal.codigo)) {
                        nuviProcesarCapturaLectorFisico();
                    }
                }, ESPERA_AUTOPROCESAR_LECTOR_MS);
            }

            function nuviDigitoDeEventoLector(evento) {
                if (/^\d$/.test(evento.key)) return evento.key;
                // Algunas tablets Android reportan el código físico en vez de
                // un carácter, especialmente con teclados Bluetooth HID.
                const coincidencia = String(evento.code || '').match(/^(?:Digit|Numpad)(\d)$/);
                return coincidencia ? coincidencia[1] : '';
            }

            function nuviCapturarLectorFisicoGlobal(evento) {
                if (evento.defaultPrevented || evento.ctrlKey || evento.altKey || evento.metaKey || evento.isComposing) return;
                const objetivo = evento.target;
                if (objetivo === DOM.inputUniversal) return; // El buscador ya maneja este caso directamente.
                if (nuviHayModalPosAbierto()) {
                    nuviCancelarCapturaLectorFisico(false);
                    return;
                }
                const destino = nuviDestinoCapturaLector(objetivo);

                const tecla = evento.key;
                const digito = nuviDigitoDeEventoLector(evento);
                const ahora = performance.now();
                if (digito) {
                    const esContinuacion = capturaLectorFisicoGlobal
                        && (ahora - capturaLectorFisicoGlobal.ultimaTeclaMs) <= INTERVALO_MAXIMO_LECTOR_BLUETOOTH_MS;

                    if (!esContinuacion) {
                        nuviCancelarCapturaLectorFisico(false);
                        const campo = objetivo instanceof HTMLInputElement || objetivo instanceof HTMLTextAreaElement ? objetivo : null;
                        capturaLectorFisicoGlobal = {
                            codigo: '',
                            ultimaTeclaMs: ahora,
                            destino,
                            campo,
                            valorInicial: campo ? campo.value : '',
                            posicionInicial: campo && typeof campo.selectionStart === 'number' ? campo.selectionStart : 0
                        };
                    }

                    capturaLectorFisicoGlobal.codigo += digito;
                    capturaLectorFisicoGlobal.ultimaTeclaMs = ahora;
                    if (capturaLectorFisicoGlobal.codigo.length > Math.max(...LONGITUDES_CODIGO_BARRAS)) {
                        nuviCancelarCapturaLectorFisico(false);
                        return;
                    }
                    nuviProgramarCapturaLectorFisico();
                    return;
                }

                if ((tecla === 'Enter' || tecla === 'Tab') && capturaLectorFisicoGlobal && esCodigoBarras(capturaLectorFisicoGlobal.codigo)) {
                    // Evita que el Enter del lector envíe un formulario o cambie
                    // de campo; el producto se agrega una sola vez al carrito.
                    evento.preventDefault();
                    evento.stopPropagation();
                    nuviProcesarCapturaLectorFisico();
                    return;
                }

                nuviCancelarCapturaLectorFisico(false);
            }

            // El lector HID se comporta como teclado. Esta captura permite
            // escanear desde cualquier parte del POS principal sin tocar el
            // buscador, pero protege modales, notas e importes de cobro.
            document.addEventListener('keydown', nuviCapturarLectorFisicoGlobal, true);

            function actualizarRitmoLector(valor) {
                const texto = String(valor || '').trim();
                const ahora = performance.now();
                const agregoUnCaracter = texto.length === lecturaAnterior.length + 1 && texto.startsWith(lecturaAnterior);
                const dentroDelIntervalo = lecturaUltimoTeclaMs > 0 && (ahora - lecturaUltimoTeclaMs) <= INTERVALO_MAXIMO_LECTOR_MS;

                if (texto.length === 1) {
                    lecturaInicioMs = ahora;
                    lecturaRapidaActiva = true;
                } else if (!agregoUnCaracter || !dentroDelIntervalo) {
                    lecturaInicioMs = ahora;
                    lecturaRapidaActiva = false;
                }

                lecturaAnterior = texto;
                lecturaUltimoTeclaMs = ahora;
                return lecturaRapidaActiva && lecturaInicioMs > 0 && (ahora - lecturaInicioMs) <= 1200;
            }

            function buscarProductoLocalPorCodigo(codigo) {
                const codigoNormalizado = String(codigo || '').trim();
                if (!codigoNormalizado) return null;

                const producto = PRODUCTOS_POR_CODIGO.get(codigoNormalizado) || null;
                if (!producto) {
                    logLector('Sin coincidencia local para codigo:', codigoNormalizado);
                }

                return producto;
            }

            function ocultarSugerencias() {
                DOM.sugerenciasBusqueda.style.display = 'none';
            }

            // ============================================
            // 💳 COBRO CON TARJETA: EL CLIENTE CUBRE LA COMISIÓN DE TERMINAL
            // ============================================
            let NUVI_CONFIG_COMISIONES = { terminales: [], configuracion_lista: false };
            let NUVI_ESTADO_CONFIG_COMISIONES = 'inicial';
            let nuviCargaComisionesActiva = null;

            function nuvi_aplicarConfigComisiones(data) {
                if (!data || typeof data !== 'object') return false;
                const terminales = Array.isArray(data.terminales) ? data.terminales : [];
                const configuracionLista = data.configuracion_lista === true || data.configuracion_lista === 1 || data.configuracion_lista === '1';
                NUVI_CONFIG_COMISIONES = {
                    terminales: terminales.map(t => ({
                        id: Number(t.id) || 0,
                        nombre: String(t.nombre || 'Terminal'),
                        comision_porcentaje: Number(t.comision_porcentaje) || 0,
                        iva_porcentaje: Number(t.iva_porcentaje) || 0,
                        cargo_fijo: Number(t.cargo_fijo) || 0,
                        es_predeterminada: Number(t.es_predeterminada) || 0
                    })).filter(t => t.id > 0),
                    configuracion_lista: configuracionLista
                };
                nuvi_poblarTerminales();
                return true;
            }

            function nuvi_leerConfigComisionesCache() {
                try {
                    const raw = localStorage.getItem(NUVI_COMISIONES_CACHE_KEY);
                    const cache = raw ? JSON.parse(raw) : null;
                    return cache && cache.data ? cache.data : null;
                } catch (e) {
                    return null;
                }
            }

            function nuvi_guardarConfigComisionesCache(data) {
                try {
                    localStorage.setItem(NUVI_COMISIONES_CACHE_KEY, JSON.stringify({ data, actualizadoEn: Date.now() }));
                } catch (e) {
                    console.warn('No se pudo guardar la configuración local de terminales:', e);
                }
            }

            function nuvi_mostrarEstadoTerminal(mensaje = '', tipo = 'info') {
                const estado = DOM.terminalPagoEstado;
                if (!estado) return;

                if (!mensaje) {
                    estado.textContent = '';
                    estado.style.display = 'none';
                    return;
                }

                const estilos = {
                    info: { fondo: '#eff6ff', borde: '#93c5fd', texto: '#1e3a8a' },
                    advertencia: { fondo: '#fffbeb', borde: '#fcd34d', texto: '#92400e' },
                    error: { fondo: '#fef2f2', borde: '#fca5a5', texto: '#991b1b' }
                };
                const estilo = estilos[tipo] || estilos.info;
                estado.textContent = mensaje;
                estado.style.display = 'block';
                estado.style.background = estilo.fondo;
                estado.style.border = `1px solid ${estilo.borde}`;
                estado.style.color = estilo.texto;
            }

            function nuvi_terminalActual() {
                const id = Number(DOM.terminalPagoSelect?.value || 0);
                return NUVI_CONFIG_COMISIONES.terminales.find(t => Number(t.id) === id)
                    || NUVI_CONFIG_COMISIONES.terminales.find(t => Number(t.es_predeterminada) === 1)
                    || NUVI_CONFIG_COMISIONES.terminales[0]
                    || null;
            }

            function nuvi_poblarTerminales() {
                if (!DOM.terminalPagoSelect) return;
                const seleccionado = Number(DOM.terminalPagoSelect.value || 0);
                DOM.terminalPagoSelect.replaceChildren();
                NUVI_CONFIG_COMISIONES.terminales.forEach(terminal => {
                    const opcion = document.createElement('option');
                    opcion.value = String(terminal.id);
                    opcion.textContent = terminal.nombre + (Number(terminal.es_predeterminada) === 1 ? ' (predeterminada)' : '');
                    DOM.terminalPagoSelect.appendChild(opcion);
                });
                const conservar = NUVI_CONFIG_COMISIONES.terminales.some(t => Number(t.id) === seleccionado);
                const predeterminada = NUVI_CONFIG_COMISIONES.terminales.find(t => Number(t.es_predeterminada) === 1);
                if (conservar) DOM.terminalPagoSelect.value = String(seleccionado);
                else if (predeterminada) DOM.terminalPagoSelect.value = String(predeterminada.id);
            }

            async function nuvi_cargarConfigComisiones(forzarActualizacion = false) {
                if (nuviCargaComisionesActiva) return nuviCargaComisionesActiva;

                // Las terminales y sus porcentajes también deben estar
                // disponibles cuando el POS está trabajando sin servidor.
                if (!forzarActualizacion) {
                    nuvi_aplicarConfigComisiones(nuvi_leerConfigComisionesCache());
                }

                NUVI_ESTADO_CONFIG_COMISIONES = 'cargando';
                nuviCargaComisionesActiva = (async () => {
                    try {
                        // El parámetro evita recibir una lista antigua justo después
                        // de configurar una terminal, aun con caché intermedia.
                        const respuesta = await fetch(`obtener_config_comisiones.php?_=${Date.now()}`, {
                            cache: 'no-store',
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' }
                        });
                        if (!respuesta.ok) throw new Error(`HTTP ${respuesta.status}`);
                        const tipoContenido = respuesta.headers.get('content-type') || '';
                        if (!tipoContenido.includes('application/json')) {
                            throw new Error('La respuesta no es JSON');
                        }

                        const data = await respuesta.json();
                        if (!nuvi_aplicarConfigComisiones(data)) {
                            throw new Error('Configuración de terminal inválida');
                        }
                        nuvi_guardarConfigComisionesCache(data);
                        NUVI_ESTADO_CONFIG_COMISIONES = NUVI_CONFIG_COMISIONES.configuracion_lista
                            ? 'lista'
                            : 'incompleta';
                    } catch (e) {
                        console.warn('No se pudo cargar la lista de terminales:', e);
                        NUVI_ESTADO_CONFIG_COMISIONES = NUVI_CONFIG_COMISIONES.terminales.length > 0 ? 'cache' : 'error';
                    } finally {
                        nuviCargaComisionesActiva = null;
                        actualizarCambio();
                    }
                })();

                return nuviCargaComisionesActiva;
            }

            // Debe ejecutarse después de declarar nuviCargaComisionesActiva.
            // Llamarla antes activa la zona temporal muerta de `let` y rompe
            // la carga de terminales en algunas tablets.
            nuvi_cargarConfigComisiones();

            function nuvi_calcularCobroTerminal(total, terminal = nuvi_terminalActual()) {
                if (!terminal) return null;
                const { comision_porcentaje, iva_porcentaje, cargo_fijo } = terminal;
                const tasaEfectiva = (comision_porcentaje / 100) * (1 + (iva_porcentaje / 100));
                if (!Number.isFinite(total) || total <= 0 || tasaEfectiva >= 1) return null;

                // Gross-up: la comisión se calcula sobre el cargo final. Redondear
                // hacia arriba garantiza que la fracción de centavo no la absorba el negocio.
                const montoCobrado = Math.ceil((((total + cargo_fijo) / (1 - tasaEfectiva)) * 100) - 0.000001) / 100;
                const comisionProcesador = Math.round(((montoCobrado * tasaEfectiva) + cargo_fijo) * 100) / 100;
                const montoNeto = Math.round((montoCobrado - comisionProcesador) * 100) / 100;
                const recargoCliente = Math.round((montoCobrado - total) * 100) / 100;
                return { montoCobrado, recargoCliente, comisionProcesador, montoNeto };
            }

            function actualizarCambio() {
                const total = parseFloat(DOM.granTotalSpan.innerText) || 0;
                const metodoPago = DOM.metodoPagoSelect ? DOM.metodoPagoSelect.value : 'efectivo';
                const esEfectivo = metodoPago === 'efectivo';
                const esMixto = metodoPago === 'mixto';
                const pago = parseFloat(DOM.pagoConInput.value) || 0;
                const metodoSecundario = DOM.metodoMixtoSecundario ? DOM.metodoMixtoSecundario.value : 'tarjeta';
                const efectivoAplicadoMixto = Math.max(0, parseFloat(DOM.pagoMixtoParteInput.value) || 0);
                const montoSecundario = Math.max(0, Math.round((total - efectivoAplicadoMixto) * 100) / 100);
                const efectivoRecibidoMixto = DOM.pagoMixtoEfectivoInput.value.trim() === ''
                    ? efectivoAplicadoMixto
                    : Math.max(0, parseFloat(DOM.pagoMixtoEfectivoInput.value) || 0);
                DOM.montoMixtoSecundarioInput.value = montoSecundario.toFixed(2);
                const esTarjeta = metodoPago === 'tarjeta';
                const usaTarjeta = esTarjeta || (esMixto && metodoSecundario === 'tarjeta');
                const importeTarjeta = esTarjeta ? total : (usaTarjeta ? montoSecundario : 0);

                if (DOM.efectivoRecibidoGrupo) {
                    DOM.efectivoRecibidoGrupo.style.display = esEfectivo ? 'block' : 'none';
                }
                if (DOM.pagoMixtoGrupo) {
                    DOM.pagoMixtoGrupo.style.display = esMixto ? 'block' : 'none';
                }
                document.getElementById('teclado-cobro').style.display = esEfectivo || esMixto ? 'block' : 'none';
                if (DOM.terminalPagoGrupo) {
                    DOM.terminalPagoGrupo.style.display = usaTarjeta && NUVI_CONFIG_COMISIONES.terminales.length > 0 ? 'block' : 'none';
                }
                if (!usaTarjeta) {
                    nuvi_mostrarEstadoTerminal();
                } else if (NUVI_CONFIG_COMISIONES.configuracion_lista && NUVI_CONFIG_COMISIONES.terminales.length > 0) {
                    nuvi_mostrarEstadoTerminal();
                } else if (NUVI_ESTADO_CONFIG_COMISIONES === 'cargando') {
                    nuvi_mostrarEstadoTerminal('Cargando las terminales y la comisión configurada…');
                } else if (NUVI_CONFIG_COMISIONES.terminales.length > 0) {
                    nuvi_mostrarEstadoTerminal('Las terminales están registradas, pero falta preparar su estructura. Abre Configuración de terminales y usa “Preparar terminales ahora”.', 'advertencia');
                } else if (NUVI_ESTADO_CONFIG_COMISIONES === 'error') {
                    nuvi_mostrarEstadoTerminal('No se pudieron cargar las terminales. Revisa la conexión con el servidor e intenta seleccionar Tarjeta nuevamente.', 'error');
                } else {
                    nuvi_mostrarEstadoTerminal('No hay una terminal activa disponible para cobrar con tarjeta.', 'advertencia');
                }

                if (DOM.comisionTarjetaInfo) {
                    if (usaTarjeta && importeTarjeta > 0 && NUVI_CONFIG_COMISIONES.configuracion_lista) {
                        const terminal = nuvi_terminalActual();
                        const calculo = nuvi_calcularCobroTerminal(importeTarjeta, terminal);
                        if (!calculo) {
                            DOM.comisionTarjetaInfo.style.display = 'none';
                            return;
                        }
                        DOM.comisionTarjetaInfo.style.display = 'block';
                        DOM.comisionMontoProductos.innerText = `$${importeTarjeta.toFixed(2)}`;
                        if (DOM.comisionEtiquetaBase) DOM.comisionEtiquetaBase.innerText = esMixto ? 'Parte con tarjeta:' : 'Total de productos:';
                        DOM.comisionMontoTerminal.innerText = `$${calculo.montoCobrado.toFixed(2)}`;
                        DOM.comisionTasaTexto.innerText = `${terminal.comision_porcentaje.toFixed(2)}%`;
                        if (DOM.comisionTerminalNombre) DOM.comisionTerminalNombre.innerText = terminal.nombre;
                        DOM.comisionMontoComision.innerText = `+$${calculo.recargoCliente.toFixed(2)}`;
                        DOM.comisionMontoNeto.innerText = `$${calculo.montoNeto.toFixed(2)}`;
                    } else {
                        DOM.comisionTarjetaInfo.style.display = 'none';
                    }
                }

                if (esMixto) {
                    const cambioMixto = efectivoRecibidoMixto - efectivoAplicadoMixto;
                    if (DOM.mixtoEfectivoAplicado) DOM.mixtoEfectivoAplicado.innerText = `$${efectivoAplicadoMixto.toFixed(2)}`;
                    if (DOM.mixtoCambio) {
                        DOM.mixtoCambio.innerText = cambioMixto >= 0 ? `$${cambioMixto.toFixed(2)}` : `Faltan $${Math.abs(cambioMixto).toFixed(2)}`;
                        DOM.mixtoCambio.style.setProperty('color', cambioMixto < 0 ? '#fca5a5' : '#86efac', 'important');
                    }
                    if (DOM.btnFinalizar) {
                        DOM.btnFinalizar.innerText = '💰 Finalizar cobro mixto';
                    }
                    return;
                }

                if (!esEfectivo) {
                    if (DOM.btnFinalizar) {
                        const calculo = esTarjeta ? nuvi_calcularCobroTerminal(total) : null;
                        DOM.btnFinalizar.innerText = esTarjeta && total > 0 && NUVI_CONFIG_COMISIONES.configuracion_lista && calculo
                            ? `💳 Cobrar $${calculo.montoCobrado.toFixed(2)} y finalizar venta`
                            : '💰 Finalizar Venta y Cobrar';
                    }
                    return;
                }

                if (DOM.btnFinalizar) DOM.btnFinalizar.innerText = '💰 Finalizar Venta y Cobrar';

                const cambio = pago - total;
                if (cambio > 0) {
                    DOM.labelCambio.innerText = 'Cambio a dar: ';
                    DOM.cambioClienteSpan.innerText = '$' + cambio.toFixed(2);
                    DOM.cambioClienteSpan.style.color = '#10b981'; // verde
                } else if (cambio < 0) {
                    DOM.labelCambio.innerText = 'Faltante: ';
                    DOM.cambioClienteSpan.innerText = '$' + Math.abs(cambio).toFixed(2);
                    DOM.cambioClienteSpan.style.color = '#ef4444'; // rojo
                } else {
                    DOM.labelCambio.innerText = 'Pago exacto: ';
                    DOM.cambioClienteSpan.innerText = '$0.00';
                    DOM.cambioClienteSpan.style.color = '#6b7280'; // gris
                }
            }

            // 1. NUEVA FUNCIÓN MÁGICA: Encuentra palabras en desorden (Ej: "1l Alpura Leche")
            function busquedaInteligente(termino) {
                const palabrasBuscadas = termino.toLowerCase().split(" ").filter(p => p.trim() !== "");

                const scores = _nuvi_getPosScores();

                const matches = PRODUCTOS_LOCALES.filter(p => {
                    const textoCompleto = p._searchText || `${p.nombre || ''} ${p.marca || ''} ${p.categoria || ''} ${p.codigo_barras || ''}`.toLowerCase();

                    // Verificamos que TODAS las palabras escritas existan en el producto
                    return palabrasBuscadas.every(palabra => textoCompleto.includes(palabra));
                });

                // Ordenar por conteo local (desc) y luego por nombre
                matches.sort((a, b) => {
                    const ka = String(a.id || a.codigo_barras || a.nombre || '').trim();
                    const kb = String(b.id || b.codigo_barras || b.nombre || '').trim();
                    const sa = scores[ka] || 0;
                    const sb = scores[kb] || 0;
                    if (sb !== sa) return sb - sa;
                    return (a.nombre || '').localeCompare(b.nombre || '');
                });

                return matches;
            }

            // ============================================
            // ⚖️ MÓDULO: PANEL DE GRANEL
            // ============================================
            function mostrarAccesosGranel() {
                const contenedor = document.getElementById('granel-accesos');
                if (!window.NuviEmergencia || !NuviEmergencia.crearArbolGranel) {
                    contenedor.textContent = 'No se pudieron cargar los accesos. Usa el buscador.';
                    return;
                }
                NuviEmergencia.crearArbolGranel(contenedor, PRODUCTOS_LOCALES, seleccionarProductoGranel);
            }

            DOM.btnAbrirGranel.addEventListener('click', () => {
                const estaAbierto = DOM.panelGranel.style.display !== 'none';
                DOM.panelGranel.style.display = estaAbierto ? 'none' : 'block';
                DOM.btnAbrirGranel.innerText = estaAbierto ? "⚖️ Venta a Granel (Jamón, Queso, etc.)" : "❌ Cerrar Panel de Granel";
                DOM.btnAbrirGranel.style.backgroundColor = estaAbierto ? "#f39c12" : "#f96d5d";
                if (!estaAbierto) {
                    mostrarAccesosGranel();
                    DOM.inputBusquedaGranel.focus();
                }
            });

            DOM.inputBusquedaGranel.addEventListener('keyup', () => {
                clearTimeout(timerBusquedaGranel);
                timerBusquedaGranel = setTimeout(() => {
                    const texto = DOM.inputBusquedaGranel.value.trim();
                    if (texto.length > 2) {
                        fetch(`buscar.php?q=${encodeURIComponent(texto)}&tipo=granel`)
                            .then(response => response.json())
                            .then(data => {
                                DOM.resultadosBusquedaGranel.innerHTML = '';
                                if (data && data.length > 0) {
                                    data.forEach(prod => {
                                        const div = document.createElement('div');
                                        div.style.padding = '10px';
                                        div.style.cursor = 'pointer';
                                        div.style.borderBottom = '1px solid #eee';
                                        div.innerHTML = `${nuvi_escaparHtml(prod.nombre)} - $${Number(prod.precio_venta || 0).toFixed(2)}`;
                                        div.addEventListener('click', () => seleccionarProductoGranel(prod));
                                        DOM.resultadosBusquedaGranel.appendChild(div);
                                    });
                                    DOM.resultadosBusquedaGranel.style.display = 'block';
                                } else {
                                    DOM.resultadosBusquedaGranel.style.display = 'none';
                                }
                            })
                            .catch(err => console.error("Error en búsqueda granel:", err));
                    } else {
                        DOM.resultadosBusquedaGranel.style.display = 'none';
                    }
                }, 180);
            });

            function actualizarFormularioGranel() {
                const esPeso = DOM.tipoCobro.value === 'peso';
                DOM.labelCantidad.innerText = esPeso ? "Cantidad (Kg/g/L):" : "Monto a vender ($):";
                DOM.inputCantidad.placeholder = esPeso ? "0.000" : "0.00";
                DOM.inputCantidad.step = esPeso ? '0.001' : '0.01';
                if (DOM.redondeoGranelGrupo) DOM.redondeoGranelGrupo.style.display = esPeso ? 'block' : 'none';
            }

            DOM.tipoCobro.addEventListener('change', () => {
                actualizarFormularioGranel();
                DOM.inputCantidad.value = '';
            });

            function nuviRedondearImporteGranel(importe, modo) {
                const exacto = Math.round((Number(importe) + Number.EPSILON) * 100) / 100;
                if (modo === 'abajo') return Math.floor(exacto + 0.000001);
                if (modo === 'arriba') return Math.ceil(exacto - 0.000001);
                return exacto;
            }

            function nuviGenerarLineaGranel() {
                return `granel_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 9)}`;
            }

            DOM.btnAgregarGranel.addEventListener('click', () => {
                const producto = window.productoActual;
                const tipo = DOM.tipoCobro.value;
                const valor = parseFloat(DOM.inputCantidad.value);

                if (!producto || !valor || valor <= 0) {
                    mostrarError("Por favor selecciona un producto y cantidad válida");
                    return;
                }

                const precio = parseFloat(producto.precio_venta);
                if (!Number.isFinite(precio) || precio <= 0) {
                    mostrarError('El precio de este producto no es válido');
                    return;
                }

                const cantidad = tipo === 'peso' ? valor : valor / precio;
                const modoRedondeo = tipo === 'peso' && DOM.redondeoGranel
                    ? DOM.redondeoGranel.value
                    : 'exacto';
                const subtotal = nuviRedondearImporteGranel(tipo === 'peso' ? cantidad * precio : valor, modoRedondeo);
                if (subtotal <= 0) {
                    mostrarError('El redondeo no puede dejar una venta en $0');
                    return;
                }

                // Cada pesada queda en su propia línea: conserva su cantidad
                // física, redondeo e importe aun si se vende el mismo producto otra vez.
                procesarProducto(producto, cantidad, subtotal, {
                    origen_granel: true,
                    redondeo_granel: modoRedondeo,
                    linea_granel: nuviGenerarLineaGranel()
                });
                limpiarPanelGranel();
            });

            function seleccionarProductoGranel(producto) {
                DOM.seccionCalculoGranel.style.display = 'block';
                DOM.resultadosBusquedaGranel.style.display = 'none';
                document.getElementById('nombre-prod-sel').innerText = producto.nombre;
                document.getElementById('precio-prod-sel').innerText = producto.precio_venta;
                document.getElementById('unidad-prod-sel').innerText = producto.unidad_medida;
                window.productoActual = producto;
                DOM.inputCantidad.focus();
                DOM.inputBusquedaGranel.value = "";
            }

            function limpiarPanelGranel() {
                DOM.inputCantidad.value = '';
                DOM.seccionCalculoGranel.style.display = 'none';
                DOM.inputBusquedaGranel.focus();
            }

            actualizarFormularioGranel();

            // ============================================
            // 🛒 MÓDULO: CARRITO DE COMPRAS
            // ============================================
            async function buscarProducto(codigo, confirmarLector = false) {
                codigo = codigo.trim();
                if (!codigo) return;
                logLector('Buscando codigo:', codigo);

                const productoLocal = buscarProductoLocalPorCodigo(codigo);
                if (productoLocal) {
                    logLector('Encontrado en PRODUCTOS_LOCALES:', productoLocal.nombre, productoLocal.codigo_barras);
                    procesarProducto(productoLocal, 1, null, { confirmar_lector: confirmarLector });
                    return productoLocal;
                }

                let cache = {};
                try { cache = JSON.parse(localStorage.getItem('cache_nuvi')) || {}; } catch (e) { cache = {}; }
                if (cache[codigo]) {
                    logLector('Encontrado en cache:', cache[codigo].nombre, codigo);
                    procesarProducto(cache[codigo], 1, null, { confirmar_lector: confirmarLector });
                    return cache[codigo];
                }

                const controlador = new AbortController();
                const limiteBusqueda = setTimeout(() => controlador.abort(), 2500);
                try {
                    // buscar_producto.php ya no forma parte del proyecto. Este endpoint
                    // sí existe y mantiene el respaldo para códigos no presentes en caché.
                    const response = await fetch(`buscar.php?q=${encodeURIComponent(codigo)}`, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        signal: controlador.signal
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);

                    const data = await response.json();
                    logLector('Respuesta de buscar.php:', data);
                    if (Array.isArray(data) && data.length > 0) {
                        cache[codigo] = data[0];
                        localStorage.setItem('cache_nuvi', JSON.stringify(cache));
                        procesarProducto(data[0], 1, null, { confirmar_lector: confirmarLector });
                        return data[0];
                    } else {
                        mostrarError("Producto no encontrado");
                        return null;
                    }
                } catch (err) {
                    console.error("Error al buscar producto:", err);
                    mostrarError("Error en la búsqueda");
                    return null;
                } finally {
                    clearTimeout(limiteBusqueda);
                }
            }

            function procesarProducto(producto, cantidadManual = 1, subtotalManual = null, opciones = {}) {
                const esGranel = opciones.origen_granel === true;
                const lineaGranel = esGranel ? String(opciones.linea_granel || nuviGenerarLineaGranel()) : '';
                const idUnico = esGranel ? `${producto.id}-${lineaGranel}` : String(producto.id);
                const codigoVisual = producto.codigo_barras || String(producto.id);
                const filaExistente = document.getElementById(`fila-${idUnico}`);
                const precio = parseFloat(producto.precio_venta);
                const cantidad = parseFloat(cantidadManual);
                const subtotal = subtotalManual !== null ? parseFloat(subtotalManual) : (precio * cantidad);
                if (!Number.isFinite(precio) || !Number.isFinite(cantidad) || cantidad <= 0 || !Number.isFinite(subtotal) || subtotal <= 0) {
                    mostrarError('No se pudo agregar el producto: cantidad o precio inválido');
                    return;
                }

                if (filaExistente) {
                    const celdaCant = filaExistente.querySelector(".cantidad-celda");
                    const celdaSub = filaExistente.querySelector(".subtotal-celda");
                    const cantActual = parseFloat(filaExistente.dataset.cantidadReal || celdaCant.innerText) || 0;
                    const subActual = parseFloat(celdaSub.innerText.replace('$', '')) || 0;
                    const cantidadNueva = Math.round((cantActual + cantidad) * 1000) / 1000;
                    const subtotalNuevo = Math.round((subActual + subtotal) * 100) / 100;

                    filaExistente.dataset.cantidadReal = String(cantidadNueva);
                    celdaCant.innerText = `${cantidadNueva.toFixed(3)}${filaExistente.dataset.unidad ? ' ' + filaExistente.dataset.unidad : ''}`;
                    celdaSub.innerText = `$${subtotalNuevo.toFixed(2)}`;
                } else {
                    const tr = document.createElement("tr");
                    tr.id = `fila-${idUnico}`;
                    tr.dataset.productoId = String(producto.id);
                    // Se muestra con tres decimales, pero se conserva la
                    // cantidad calculada completa. En una venta por monto,
                    // redondearla antes de enviarla alteraba el subtotal:
                    // $30 de jamón a $160/kg terminaba siendo $30.08.
                    tr.dataset.cantidadReal = String(cantidad);
                    tr.dataset.precioUnitario = String(precio);
                    tr.dataset.unidad = String(producto.unidad_medida || '');
                    tr.dataset.origenGranel = esGranel ? '1' : '0';
                    tr.dataset.redondeoGranel = esGranel ? String(opciones.redondeo_granel || 'exacto') : '';
                    tr.dataset.lineaGranel = lineaGranel;
                    const controles = esGranel
                        ? `<button class="btn-eliminar" title="Eliminar" onclick="eliminarProducto('${idUnico}')">❌</button>`
                        : `<button class="btn-opciones" title="Agregar 1" onclick="sumarProducto('${idUnico}')">➕</button>
                           <button class="btn-opciones" title="Restar 1" onclick="restarProducto('${idUnico}')">⛔</button>
                           <button class="btn-eliminar" title="Eliminar" onclick="eliminarProducto('${idUnico}')">❌</button>`;
                    tr.innerHTML = `
                        <td>${nuvi_escaparHtml(codigoVisual)}</td>
                        <td>${nuvi_escaparHtml(producto.nombre)}</td>
                        <td>${nuvi_escaparHtml(producto.categoria || 'N/A')}</td>
                        <td>$${precio.toFixed(2)}</td>
                        <td class="cantidad-celda">${cantidad.toFixed(3)} ${nuvi_escaparHtml(producto.unidad_medida || '')}</td>
                        <td class="subtotal-celda">$${subtotal.toFixed(2)}</td>
                        <td class="opciones-celda">
                            ${controles}
                        </td>
                    `;
                    DOM.tablaCarritoBody.appendChild(tr);
                }
                actualizarGranTotal();
                if (opciones.confirmar_lector === true) confirmarLecturaLector();
                return true;
            }

            function actualizarGranTotal() {
                let total = 0;
                document.querySelectorAll(".subtotal-celda").forEach(td => {
                    total += parseFloat(td.innerText.replace('$', ''));
                });
                DOM.granTotalSpan.innerText = total.toFixed(2);
                actualizarCambio();
            }

            function eliminarProducto(codigo) {
                if (!confirm("¿Estás seguro de quitar este producto?")) return;

                const fila = document.getElementById(`fila-${codigo}`);
                if (fila) {
                    fila.remove();
                    actualizarGranTotal();
                    DOM.inputUniversal.focus();
                }
            }

            function sumarProducto(codigo) {
                const fila = document.getElementById(`fila-${codigo}`);
                if (!fila) return;

                const celdaCant = fila.querySelector(".cantidad-celda");
                const celdaSub = fila.querySelector(".subtotal-celda");
                const cantActual = parseFloat(fila.dataset.cantidadReal || celdaCant.innerText) || 0;
                const precioUnit = parseFloat(fila.dataset.precioUnitario || celdaSub.innerText.replace('$', '')) || 0;
                const cantidadNueva = cantActual + 1;
                const subtotalNuevo = Math.round((cantidadNueva * precioUnit + Number.EPSILON) * 100) / 100;

                fila.dataset.cantidadReal = String(cantidadNueva);
                celdaCant.innerText = `${cantidadNueva.toFixed(3)}${fila.dataset.unidad ? ' ' + fila.dataset.unidad : ''}`;
                celdaSub.innerText = `$${subtotalNuevo.toFixed(2)}`;
                actualizarGranTotal();
            }

            function restarProducto(codigo) {
                const fila = document.getElementById(`fila-${codigo}`);
                if (!fila) return;

                const celdaCant = fila.querySelector(".cantidad-celda");
                const celdaSub = fila.querySelector(".subtotal-celda");
                const cantActual = parseFloat(fila.dataset.cantidadReal || celdaCant.innerText) || 0;

                if (cantActual > 1) {
                    const cantidadNueva = cantActual - 1;
                    const precioUnit = parseFloat(fila.dataset.precioUnitario || celdaSub.innerText.replace('$', '')) || 0;
                    const subtotalNuevo = Math.round((cantidadNueva * precioUnit + Number.EPSILON) * 100) / 100;
                    fila.dataset.cantidadReal = String(cantidadNueva);
                    celdaCant.innerText = `${cantidadNueva.toFixed(3)}${fila.dataset.unidad ? ' ' + fila.dataset.unidad : ''}`;
                    celdaSub.innerText = `$${subtotalNuevo.toFixed(2)}`;
                    actualizarGranTotal();
                } else {
                    eliminarProducto(codigo);
                }
            }

            function limpiarCarrito() {
                nuviCancelarCapturaLectorFisico(true);
                DOM.tablaCarritoBody.innerHTML = "";
                DOM.granTotalSpan.innerText = "0.00";
                if (DOM.notaVentaInput) DOM.notaVentaInput.value = "";
                if (DOM.metodoPagoSelect) DOM.metodoPagoSelect.value = "efectivo";
                DOM.pagoConInput.value = "";
                DOM.pagoMixtoParteInput.value = "";
                DOM.pagoMixtoEfectivoInput.value = "";
                seleccionarCampoTeclado(DOM.pagoConInput);
                if (DOM.inputUniversal) {
                    DOM.inputUniversal.value = '';
                    ocultarSugerencias();
                    // Tras cerrar el aviso de una venta exitosa, varios
                    // navegadores de tablet devuelven el foco al botón que lo
                    // abrió. Se reintenta un instante después para dejar la
                    // siguiente lectura lista sin tocar ni recargar el POS.
                    const enfocarBuscador = () => DOM.inputUniversal.focus({ preventScroll: true });
                    requestAnimationFrame(enfocarBuscador);
                    setTimeout(enfocarBuscador, 120);
                    setTimeout(enfocarBuscador, 420);
                }
                actualizarCambio();
            }

            const NUVI_OFFLINE_QUEUE_KEY = `nuvi_offline_sales_queue_v2_${ID_USUARIO_ACTUAL}`;
            const NUVI_OFFLINE_QUEUE_LEGACY_KEY = 'nuvi_offline_sales_queue_v1';
            const NUVI_OFFLINE_DB_NAME = 'nuvi_pos_offline_v3';
            const NUVI_OFFLINE_DB_STORE = 'ventas_pendientes';
            let nuviOfflineQueueSyncing = false;
            let nuviOfflineQueueTimer = null;
            let nuviOfflineQueueCache = [];
            let nuviOfflineQueueLoaded = false;
            let nuviOfflineDbPromise = null;
            let nuviOfflineQueueInitPromise = null;
            let nuviSesionCaducada = false;
            let nuviKeepAliveActivo = null;

            function nuvi_generarClaveIdempotencia() {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return `pos_${window.crypto.randomUUID()}`;
                }
                if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                    const bytes = new Uint32Array(4);
                    window.crypto.getRandomValues(bytes);
                    return `pos_${Date.now().toString(36)}_${Array.from(bytes).map(n => n.toString(36)).join('_')}`;
                }
                return `pos_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}_${Math.random().toString(36).slice(2)}`;
            }

            function nuvi_leerColaOfflineLocal() {
                try {
                    const raw = localStorage.getItem(NUVI_OFFLINE_QUEUE_KEY);
                    if (raw !== null) {
                        const queue = JSON.parse(raw);
                        return Array.isArray(queue) ? queue : [];
                    }

                    // Conserva ventas de la cola anterior tras actualizar el
                    // sistema y las vincula al usuario que las recuperó.
                    const legacyRaw = localStorage.getItem(NUVI_OFFLINE_QUEUE_LEGACY_KEY);
                    const legacyQueue = legacyRaw ? JSON.parse(legacyRaw) : [];
                    if (!Array.isArray(legacyQueue) || legacyQueue.length === 0) return [];
                    const migrada = legacyQueue.map(item => ({
                        ...item,
                        id_usuario: ID_USUARIO_ACTUAL,
                        migrada_desde_cola_v1: true
                    }));
                    return migrada;
                } catch (e) {
                    console.error('No se pudo leer la cola de ventas pendientes:', e);
                    return [];
                }
            }

            function nuvi_abrirBaseOffline() {
                if (!('indexedDB' in window)) return Promise.reject(new Error('IndexedDB no está disponible'));
                if (nuviOfflineDbPromise) return nuviOfflineDbPromise;

                nuviOfflineDbPromise = new Promise((resolve, reject) => {
                    const solicitud = indexedDB.open(NUVI_OFFLINE_DB_NAME, 1);
                    solicitud.onupgradeneeded = () => {
                        const base = solicitud.result;
                        if (!base.objectStoreNames.contains(NUVI_OFFLINE_DB_STORE)) {
                            const tienda = base.createObjectStore(NUVI_OFFLINE_DB_STORE, { keyPath: 'id' });
                            tienda.createIndex('por_usuario', 'id_usuario', { unique: false });
                        }
                    };
                    solicitud.onsuccess = () => resolve(solicitud.result);
                    solicitud.onerror = () => reject(solicitud.error || new Error('No se pudo abrir IndexedDB'));
                });
                return nuviOfflineDbPromise;
            }

            function nuvi_leerColaOfflineIndexedDb() {
                return nuvi_abrirBaseOffline().then(base => new Promise((resolve, reject) => {
                    const transaccion = base.transaction(NUVI_OFFLINE_DB_STORE, 'readonly');
                    const solicitud = transaccion.objectStore(NUVI_OFFLINE_DB_STORE).getAll();
                    solicitud.onsuccess = () => {
                        const cola = (solicitud.result || []).filter(item => String(item.id_usuario) === String(ID_USUARIO_ACTUAL));
                        resolve(cola);
                    };
                    solicitud.onerror = () => reject(solicitud.error || new Error('No se pudo leer IndexedDB'));
                }));
            }

            function nuvi_guardarColaOfflineIndexedDb(cola) {
                return nuvi_abrirBaseOffline().then(base => new Promise((resolve, reject) => {
                    const transaccion = base.transaction(NUVI_OFFLINE_DB_STORE, 'readwrite');
                    const tienda = transaccion.objectStore(NUVI_OFFLINE_DB_STORE);
                    const cursor = tienda.openCursor();
                    cursor.onsuccess = event => {
                        const actual = event.target.result;
                        if (actual) {
                            if (String(actual.value.id_usuario) === String(ID_USUARIO_ACTUAL)) actual.delete();
                            actual.continue();
                            return;
                        }
                        cola.forEach(item => tienda.put(item));
                    };
                    cursor.onerror = () => reject(cursor.error || new Error('No se pudo actualizar IndexedDB'));
                    transaccion.oncomplete = () => resolve();
                    transaccion.onerror = () => reject(transaccion.error || new Error('No se pudo actualizar IndexedDB'));
                    transaccion.onabort = () => reject(transaccion.error || new Error('La transacción de IndexedDB fue cancelada'));
                }));
            }

            function nuvi_getOfflineQueue() {
                return Array.isArray(nuviOfflineQueueCache) ? [...nuviOfflineQueueCache] : [];
            }

            async function nuvi_inicializarColaOffline() {
                if (nuviOfflineQueueInitPromise) return nuviOfflineQueueInitPromise;
                nuviOfflineQueueInitPromise = (async () => {
                    const local = nuvi_leerColaOfflineLocal();
                    try {
                        const desdeBase = await nuvi_leerColaOfflineIndexedDb();
                        const porId = new Map();
                        [...desdeBase, ...local].forEach(item => {
                            if (item && item.id) porId.set(String(item.id), { ...item, id_usuario: ID_USUARIO_ACTUAL });
                        });
                        nuviOfflineQueueCache = [...porId.values()];
                        if (local.length > 0) {
                            await nuvi_guardarColaOfflineIndexedDb(nuviOfflineQueueCache);
                            localStorage.removeItem(NUVI_OFFLINE_QUEUE_KEY);
                            localStorage.removeItem(NUVI_OFFLINE_QUEUE_LEGACY_KEY);
                        }
                    } catch (e) {
                        // IndexedDB puede estar deshabilitado por políticas del navegador.
                        // En ese caso mantenemos la compatibilidad con localStorage.
                        console.warn('IndexedDB no disponible; se usará la cola local de compatibilidad:', e);
                        nuviOfflineQueueCache = local;
                    } finally {
                        nuviOfflineQueueLoaded = true;
                        nuvi_actualizarEstadoColaOffline();
                    }
                })();
                return nuviOfflineQueueInitPromise;
            }

            async function nuvi_asegurarColaOffline() {
                if (!nuviOfflineQueueLoaded) await nuvi_inicializarColaOffline();
            }

            async function nuvi_setOfflineQueue(queue) {
                const colaNormalizada = Array.isArray(queue) ? queue : [];
                nuviOfflineQueueCache = colaNormalizada;
                try {
                    await nuvi_guardarColaOfflineIndexedDb(colaNormalizada);
                    // La cola ya está durable en IndexedDB; se limpia la copia
                    // antigua para no seguir creciendo en localStorage.
                    localStorage.removeItem(NUVI_OFFLINE_QUEUE_KEY);
                    localStorage.removeItem(NUVI_OFFLINE_QUEUE_LEGACY_KEY);
                } catch (e) {
                    console.warn('No se pudo guardar en IndexedDB; se usa localStorage de compatibilidad:', e);
                    try {
                        localStorage.setItem(NUVI_OFFLINE_QUEUE_KEY, JSON.stringify(colaNormalizada));
                    } catch (fallbackError) {
                        console.error('Error guardando cola offline:', fallbackError);
                        return false;
                    }
                }
                nuvi_actualizarEstadoColaOffline();
                return true;
            }

            function nuvi_obtenerProductosDelCarrito() {
                return Array.from(document.querySelectorAll('#tabla-carrito tbody tr')).map(fila => ({
                    id: fila.dataset.productoId || fila.id.replace('fila-', ''),
                    nombre: fila.cells[1].innerText,
                    precio: parseFloat(fila.dataset.precioUnitario || fila.cells[3].innerText.replace('$', '')),
                    cantidad: parseFloat(fila.dataset.cantidadReal || fila.cells[4].innerText),
                    subtotal: parseFloat(fila.cells[5].innerText.replace('$', '')),
                    origen_granel: fila.dataset.origenGranel === '1',
                    redondeo_granel: fila.dataset.redondeoGranel || undefined,
                    linea_granel: fila.dataset.lineaGranel || undefined
                }));
            }

            function nuvi_actualizarEstadoColaOffline() {
                let badge = document.getElementById('offlineQueueBadge');
                let panel = document.getElementById('offlineQueuePanel');
                let panelBtn = document.getElementById('offlineQueueButton');
                const pendiente = nuvi_getOfflineQueue().length;

                if (!badge) {
                    badge = document.createElement('div');
                    badge.id = 'offlineQueueBadge';
                    badge.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:99999;padding:10px 12px;border-radius:999px;background:#0f172a;color:#fff;font-size:0.85rem;box-shadow:0 8px 24px rgba(15,23,42,.2);display:none;';
                    document.body.appendChild(badge);
                }

                if (!panelBtn) {
                    panelBtn = document.createElement('button');
                    panelBtn.id = 'offlineQueueButton';
                    panelBtn.type = 'button';
                    panelBtn.textContent = '🕓';
                    panelBtn.title = 'Ventas pendientes';
                    panelBtn.style.cssText = 'position:fixed;left:16px;bottom:64px;z-index:99999;width:44px;height:44px;border:none;border-radius:999px;background:#334155;color:#fff;font-size:1rem;box-shadow:0 8px 24px rgba(15,23,42,.2);display:none;cursor:pointer;';
                    panelBtn.addEventListener('click', nuvi_toggleOfflinePanel);
                    document.body.appendChild(panelBtn);
                }

                if (!panel) {
                    panel = document.createElement('div');
                    panel.id = 'offlineQueuePanel';
                    panel.style.cssText = 'position:fixed;left:16px;bottom:116px;z-index:99999;width:340px;max-width:calc(100vw - 32px);background:#ffffff;border:1px solid #cbd5e1;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.18);display:none;overflow:hidden;';
                    panel.innerHTML = `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#0f172a;color:#fff;">
                            <strong style="font-size:0.95rem;">Ventas pendientes</strong>
                            <button type="button" id="offlineQueueClose" style="background:transparent;border:none;color:#fff;font-size:1.1rem;cursor:pointer;">✕</button>
                        </div>
                        <div id="offlineQueueList" style="max-height:260px;overflow:auto;padding:10px 12px;background:#f8fafc;"></div>
                    `;
                    document.body.appendChild(panel);
                    panel.querySelector('#offlineQueueClose').addEventListener('click', () => {
                        panel.style.display = 'none';
                    });
                }

                if (pendiente > 0) {
                    badge.style.display = 'block';
                    badge.textContent = `Ventas pendientes: ${pendiente}`;
                    panelBtn.style.display = 'block';
                } else {
                    badge.style.display = 'none';
                    panelBtn.style.display = 'none';
                    if (panel) panel.style.display = 'none';
                }

                nuvi_renderOfflineQueuePanel();
            }

            function nuvi_toggleOfflinePanel() {
                const panel = document.getElementById('offlineQueuePanel');
                if (!panel) return;
                const visible = panel.style.display !== 'none';
                panel.style.display = visible ? 'none' : 'block';
                if (!visible) nuvi_renderOfflineQueuePanel();
            }

            function nuvi_renderOfflineQueuePanel() {
                const panel = document.getElementById('offlineQueuePanel');
                const list = document.getElementById('offlineQueueList');
                if (!panel || !list) return;

                const queue = nuvi_getOfflineQueue();
                if (queue.length === 0) {
                    list.innerHTML = '<div style="padding:12px;color:#64748b;font-size:0.9rem;">No hay ventas pendientes.</div>';
                    return;
                }

                list.innerHTML = queue.map((item, index) => {
                    const payload = item.payload || {};
                    const total = parseFloat(payload.total || 0).toFixed(2);
                    const tipo = item.endpoint && item.endpoint.includes('registrar_venta_fiada') ? 'Fiada' : 'Normal';
                    const detalle = item.descripcion || tipo;
                    const fecha = item.createdAt ? new Date(item.createdAt).toLocaleString('es-MX') : '';
                    return `
                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:8px;">
                            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                                <div style="min-width:0;">
                                    <div style="font-weight:700;color:#0f172a;font-size:0.92rem;">${detalle}</div>
                                    <div style="font-size:0.82rem;color:#64748b;margin-top:2px;">${fecha}</div>
                                    <div style="font-size:0.82rem;color:#334155;margin-top:4px;">Total: $${total}</div>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                                    <button type="button" data-offline-retry="${index}" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:6px 10px;cursor:pointer;font-size:0.8rem;">Reintentar</button>
                                    <button type="button" data-offline-remove="${index}" style="background:#ef4444;color:#fff;border:none;border-radius:8px;padding:6px 10px;cursor:pointer;font-size:0.8rem;">Quitar</button>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                list.querySelectorAll('[data-offline-retry]').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const idx = parseInt(btn.getAttribute('data-offline-retry'), 10);
                        await nuvi_reintentarItemOffline(idx);
                    });
                });

                list.querySelectorAll('[data-offline-remove]').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const idx = parseInt(btn.getAttribute('data-offline-remove'), 10);
                        await nuvi_eliminarItemOffline(idx);
                    });
                });
            }

            async function nuvi_reintentarItemOffline(index) {
                await nuvi_asegurarColaOffline();
                const queue = nuvi_getOfflineQueue();
                const item = queue[index];
                if (!item) return;

                try {
                    const resultado = await nuvi_enviarVentaConFetch(item.endpoint, item.payload);
                    if (!resultado || resultado.success !== true) {
                        throw new Error((resultado && (resultado.error || resultado.message)) || 'No se pudo sincronizar');
                    }
                    queue.splice(index, 1);
                    if (!await nuvi_setOfflineQueue(queue)) {
                        throw new Error('El servidor confirmó esta venta, pero la tablet no pudo actualizar su cola local. No la reintentes; recarga Nuvi cuando haya memoria disponible.');
                    }
                    mostrarToast('Venta sincronizada correctamente', 'info');
                    nuvi_programarSyncOffline();
                } catch (error) {
                    if (nuvi_esErrorDeConexion(error)) {
                        mostrarError('Sigue sin conexión o el servidor no responde');
                    } else {
                        mostrarError('No se pudo reintentar: ' + error.message);
                    }
                }
            }

            async function nuvi_eliminarItemOffline(index) {
                await nuvi_asegurarColaOffline();
                const queue = nuvi_getOfflineQueue();
                if (!queue[index]) return;
                if (!confirm('¿Eliminar esta venta pendiente?')) return;
                queue.splice(index, 1);
                await nuvi_setOfflineQueue(queue);
            }

            async function nuvi_encolarVentaOffline(endpoint, payload, descripcion) {
                await nuvi_asegurarColaOffline();
                const queue = nuvi_getOfflineQueue();
                queue.push({
                    id: `offline-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                    id_usuario: ID_USUARIO_ACTUAL,
                    endpoint,
                    payload,
                    descripcion: descripcion || 'venta',
                    createdAt: new Date().toISOString()
                });
                if (!await nuvi_setOfflineQueue(queue)) {
                    throw new Error('No se pudo guardar esta venta pendiente en la memoria de la caja. No se limpió el carrito.');
                }
                mostrarToast('Sin conexión: venta guardada para sincronizar después', 'warning');
            }

            async function nuvi_enviarVentaConFetch(endpoint, payload) {
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 5000);
                let response;
                try {
                    response = await fetch(endpoint, {
                        method: 'POST',
                        headers: nuvi_headersMutacion({
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }),
                        body: JSON.stringify({ ...payload, id_usuario_origen: ID_USUARIO_ACTUAL }),
                        signal: controller.signal,
                        credentials: 'same-origin'
                    });
                } finally {
                    clearTimeout(timeout);
                }

                if (response.status === 401) {
                    throw new Error('SESSION_EXPIRED');
                }

                const text = await response.text();
                let data = null;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    throw new Error('Respuesta inválida del servidor');
                }

                if (!response.ok) {
                    if (response.status >= 500) {
                        throw new Error(`SERVER_UNAVAILABLE_${response.status}`);
                    }
                    throw new Error(data.error || data.message || `HTTP ${response.status}`);
                }

                return data;
            }

            function nuvi_esErrorDeConexion(error) {
                const message = String(error && error.message ? error.message : error);
                return (error && error.name === 'AbortError') || /Failed to fetch|NetworkError|Load failed|SERVER_UNAVAILABLE|fetch|aborted/i.test(message);
            }

            function nuvi_esSesionExpirada(error) {
                const message = String(error && error.message ? error.message : error);
                return /SESSION_EXPIRED|sesión venció|sesion vencio/i.test(message);
            }

            function nuvi_hayCarritoActivo() {
                return document.querySelectorAll('#tabla-carrito tbody tr').length > 0;
            }

            function nuvi_avisarSesionCaducada() {
                if (nuviSesionCaducada) return;
                nuviSesionCaducada = true;
                const mensaje = nuvi_hayCarritoActivo()
                    ? 'La sesión venció. La venta se guardará pendiente al cobrar; después inicia sesión de nuevo para sincronizarla.'
                    : 'La sesión venció. Inicia sesión nuevamente antes de continuar.';
                mostrarError(mensaje);
                mostrarToast(mensaje, 'warning');

                // Si no hay nada en proceso, llevar de forma clara al login
                // evita que el cajero descubra la sesión vencida al cobrar.
                if (!nuvi_hayCarritoActivo()) {
                    setTimeout(() => {
                        if (nuviSesionCaducada && !nuvi_hayCarritoActivo()) {
                            window.location.href = 'login.php?motivo=sesion_vencida';
                        }
                    }, 1800);
                }
            }

            async function nuvi_mantenerSesion() {
                if (nuviSesionCaducada) return false;
                if (nuviKeepAliveActivo) return nuviKeepAliveActivo;

                nuviKeepAliveActivo = (async () => {
                    const controller = new AbortController();
                    const timeout = setTimeout(() => controller.abort(), 4000);
                    try {
                        const respuesta = await fetch('sesion_keepalive.php', {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            cache: 'no-store',
                            credentials: 'same-origin',
                            signal: controller.signal
                        });
                        if (respuesta.status === 401) {
                            nuvi_avisarSesionCaducada();
                            return false;
                        }
                        if (respuesta.ok) {
                            const sesion = await respuesta.json();
                            if (Number(sesion.id_usuario) !== ID_USUARIO_ACTUAL) {
                                nuvi_avisarSesionCaducada();
                                return false;
                            }
                            if (typeof sesion.csrf_token === 'string' && sesion.csrf_token) NUVI_CSRF_TOKEN = sesion.csrf_token;
                        }
                        return respuesta.ok;
                    } catch (error) {
                        // Una caída de LAN/servidor no invalida la sesión: la
                        // cola offline se encarga de conservar las ventas.
                        return false;
                    } finally {
                        clearTimeout(timeout);
                        nuviKeepAliveActivo = null;
                    }
                })();
                return nuviKeepAliveActivo;
            }

            async function nuvi_flushOfflineQueue() {
                if (nuviOfflineQueueSyncing) return;

                await nuvi_asegurarColaOffline();

                const queue = nuvi_getOfflineQueue();
                if (queue.length === 0) {
                    nuvi_actualizarEstadoColaOffline();
                    return;
                }

                nuviOfflineQueueSyncing = true;
                let sincronizadas = 0;

                try {
                    while (queue.length > 0) {
                        const item = queue[0];
                        try {
                            const resultado = await nuvi_enviarVentaConFetch(item.endpoint, item.payload);
                            if (!resultado || resultado.success !== true) {
                                throw new Error((resultado && (resultado.error || resultado.message)) || 'No se pudo sincronizar');
                            }
                            queue.shift();
                            // Persistir después de cada confirmación. Si la
                            // tablet se apaga durante el siguiente envío, esta
                            // venta ya no vuelve a quedar en la cola local.
                            if (!await nuvi_setOfflineQueue(queue)) {
                                throw new Error('El servidor confirmó esta venta, pero la tablet no pudo actualizar su cola local. No la reintentes; recarga Nuvi cuando haya memoria disponible.');
                            }
                            sincronizadas++;
                        } catch (error) {
                            console.warn('No se pudo sincronizar una venta offline:', error);
                            if (String(error && error.message || '').includes('servidor confirmó')) {
                                mostrarError(error.message);
                            }
                            break;
                        }
                    }

                    await nuvi_setOfflineQueue(queue);
                    if (sincronizadas > 0) {
                        mostrarToast(`Se sincronizaron ${sincronizadas} venta(s) pendientes`, 'info');
                    }
                } finally {
                    nuviOfflineQueueSyncing = false;
                    nuvi_actualizarEstadoColaOffline();
                }
            }

            function nuvi_programarSyncOffline() {
                clearTimeout(nuviOfflineQueueTimer);
                nuviOfflineQueueTimer = setTimeout(() => {
                    nuvi_flushOfflineQueue();
                }, 1500);
            }

            async function nuvi_intentarGuardarVenta(endpoint, payload, descripcion) {
                try {
                    const resultado = await nuvi_enviarVentaConFetch(endpoint, payload);
                    if (!resultado || resultado.success !== true) {
                        throw new Error((resultado && (resultado.error || resultado.message)) || 'No se pudo guardar');
                    }
                    return { success: true, offline: false, resultado };
                } catch (error) {
                    if (nuvi_esSesionExpirada(error)) {
                        nuvi_avisarSesionCaducada();
                        await nuvi_encolarVentaOffline(endpoint, payload, descripcion);
                        return { success: true, offline: true, queued: true, sesion_expirada: true };
                    }
                    if (nuvi_esErrorDeConexion(error)) {
                        await nuvi_encolarVentaOffline(endpoint, payload, descripcion);
                        return { success: true, offline: true, queued: true };
                    }
                    throw error;
                }
            }

            window.addEventListener('online', nuvi_programarSyncOffline);
            window.addEventListener('focus', () => {
                nuvi_mantenerSesion();
                nuvi_programarSyncOffline();
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    nuvi_mantenerSesion();
                    nuvi_programarSyncOffline();
                }
            });
            setInterval(nuvi_programarSyncOffline, 60000);
            setInterval(nuvi_mantenerSesion, 240000);
            nuvi_inicializarColaOffline().finally(() => {
                nuvi_actualizarEstadoColaOffline();
                setTimeout(nuvi_programarSyncOffline, 2000);
                setTimeout(nuvi_mantenerSesion, 1200);
            });
            NuviEmergencia.iniciarEnCaja(ID_USUARIO_ACTUAL);

            // ============================================
            // 🧾 ÚLTIMO TICKET E IMPRESIÓN DEL NAVEGADOR
            // ============================================
            const NUVI_ULTIMO_TICKET_KEY = `nuvi_ultimo_ticket_v1_${ID_USUARIO_ACTUAL}`;

            function nuvi_leerUltimoTicket() {
                if (ultimoTicketEnMemoria) return ultimoTicketEnMemoria;
                try {
                    const ticket = JSON.parse(sessionStorage.getItem(NUVI_ULTIMO_TICKET_KEY) || 'null');
                    if (ticket && Array.isArray(ticket.productos)) {
                        if (!ticket.claveImpresion) {
                            ticket.claveImpresion = nuvi_generarClaveImpresionTicket();
                            try { sessionStorage.setItem(NUVI_ULTIMO_TICKET_KEY, JSON.stringify(ticket)); } catch (error) {}
                        }
                        ultimoTicketEnMemoria = ticket;
                    }
                } catch (error) {}
                return ultimoTicketEnMemoria;
            }

            function nuvi_actualizarBotonUltimoTicket() {
                const hayTicket = Boolean(nuvi_leerUltimoTicket());
                if (DOM.btnImprimirUltimoTicket) {
                    DOM.btnImprimirUltimoTicket.style.display = hayTicket ? 'inline-flex' : 'none';
                    DOM.btnImprimirUltimoTicket.textContent = NUVI_IMPRESORA_RED.activa ? '🖨 Imprimir ticket' : '🧾 Ver ticket';
                    DOM.btnImprimirUltimoTicket.title = NUVI_IMPRESORA_RED.activa
                        ? `Enviar el comprobante a ${NUVI_IMPRESORA_RED.nombre || 'la impresora de red'}`
                        : 'Abrir diálogo de impresión o guardar PDF';
                }
                if (DOM.btnImprimirCopiaTicket) {
                    DOM.btnImprimirCopiaTicket.style.display = hayTicket ? 'inline-flex' : 'none';
                    DOM.btnImprimirCopiaTicket.textContent = NUVI_IMPRESORA_RED.activa ? '📄 Imprimir copia' : '📄 Ver copia';
                    DOM.btnImprimirCopiaTicket.title = NUVI_IMPRESORA_RED.activa
                        ? 'Enviar una segunda hoja marcada claramente como COPIA'
                        : 'Abrir una copia marcada claramente como COPIA';
                }
            }

            function nuvi_generarClaveImpresionTicket() {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID();
                }
                return `ticket_${Date.now()}_${Math.random().toString(36).slice(2, 14)}`;
            }

            function nuvi_generarClaveCopiaTicket() {
                // El prefijo permite identificar una copia en la bitácora de
                // impresión sin reutilizar jamás la clave del original.
                return `copia_${nuvi_generarClaveImpresionTicket()}`;
            }

            function nuvi_guardarUltimoTicket(ticket) {
                ultimoTicketEnMemoria = ticket;
                try { sessionStorage.setItem(NUVI_ULTIMO_TICKET_KEY, JSON.stringify(ticket)); } catch (error) {}
                nuvi_actualizarBotonUltimoTicket();
            }

            function nuvi_escaparTicketHtml(valor) {
                return String(valor ?? '').replace(/[&<>'"]/g, caracter => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
                }[caracter]));
            }

            function nuvi_nombreMetodoTicket(metodo) {
                return ({ efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transferencia', otro: 'Otro', mixto: 'Pago mixto', fiado: 'Fiado' })[metodo] || String(metodo || 'Venta');
            }

            function nuvi_crearTicketVenta(datos) {
                const metodoPago = String(datos.metodoPago || 'efectivo');
                let pagos = Array.isArray(datos.pagos) ? datos.pagos.map(pago => ({
                    metodo: String(pago?.metodo || ''),
                    monto: Number(pago?.monto) || 0,
                    monto_recibido: Number(pago?.monto_recibido) || 0,
                    cambio: Number(pago?.cambio) || 0,
                    terminal_nombre: String(pago?.terminal_nombre || ''),
                    monto_cobrado_tarjeta: Number(pago?.monto_cobrado_tarjeta) || 0,
                    recargo_cliente_tarjeta: Number(pago?.recargo_cliente_tarjeta) || 0
                })).filter(pago => pago.monto > 0 && pago.metodo) : [];
                if (!pagos.length && metodoPago === 'efectivo') {
                    pagos = [{ metodo: 'efectivo', monto: Number(datos.totalProductos) || 0, monto_recibido: Number(datos.pagoCon) || 0, cambio: Number(datos.cambio) || 0 }];
                } else if (!pagos.length && metodoPago === 'tarjeta') {
                    pagos = [{ metodo: 'tarjeta', monto: Number(datos.totalProductos) || 0, terminal_nombre: String(datos.terminal || ''), monto_cobrado_tarjeta: Number(datos.cobroTerminal) || 0, recargo_cliente_tarjeta: Number(datos.recargoCliente) || 0 }];
                }
                return {
                    idVenta: Number(datos.idVenta) || 0,
                    fecha: new Date().toISOString(),
                    productos: (datos.productos || []).map(producto => ({
                        nombre: String(producto.nombre || 'Producto'),
                        cantidad: Number(producto.cantidad) || 0,
                        precio: Number(producto.precio) || 0,
                        subtotal: Number(producto.subtotal) || 0
                    })),
                    totalProductos: Number(datos.totalProductos) || 0,
                    metodoPago,
                    pagoCon: Number(datos.pagoCon) || 0,
                    cambio: Number(datos.cambio) || 0,
                    cobroTerminal: Number(datos.cobroTerminal) || 0,
                    recargoCliente: Number(datos.recargoCliente) || 0,
                    terminal: String(datos.terminal || ''),
                    cajero: String(datos.cajero || ''),
                    pagos,
                    nota: String(datos.nota || ''),
                    fiado: datos.fiado && typeof datos.fiado === 'object' ? datos.fiado : null,
                    claveImpresion: String(datos.claveImpresion || nuvi_generarClaveImpresionTicket())
                };
            }

            function nuvi_detallePagosTicketHtml(ticket) {
                const pagos = Array.isArray(ticket.pagos) ? ticket.pagos.filter(pago => Number(pago?.monto) > 0) : [];
                if (!pagos.length) return '';
                const esMixto = pagos.length > 1 || ticket.metodoPago === 'mixto';
                const renglones = pagos.map(pago => {
                    const metodo = String(pago.metodo || '');
                    const monto = Number(pago.monto) || 0;
                    if (metodo === 'efectivo') {
                        return `${esMixto ? `<p>Efectivo aplicado: $${monto.toFixed(2)}</p>` : ''}<p>Efectivo recibido: <strong>$${(Number(pago.monto_recibido) || 0).toFixed(2)}</strong></p><p>Cambio: <strong>$${(Number(pago.cambio) || 0).toFixed(2)}</strong></p>`;
                    }
                    const etiqueta = nuvi_nombreMetodoTicket(metodo);
                    const terminal = metodo === 'tarjeta' && pago.terminal_nombre
                        ? `<p>Terminal: ${nuvi_escaparTicketHtml(pago.terminal_nombre)}</p>`
                        : '';
                    const cobrado = metodo === 'tarjeta' && Number(pago.monto_cobrado_tarjeta) > 0
                        ? `<p>Cobrado en terminal: <strong>$${Number(pago.monto_cobrado_tarjeta).toFixed(2)}</strong></p>${Number(pago.recargo_cliente_tarjeta) > 0 ? `<p>Recargo de terminal: $${Number(pago.recargo_cliente_tarjeta).toFixed(2)}</p>` : ''}`
                        : '';
                    return `<p>${nuvi_escaparTicketHtml(etiqueta)}: <strong>$${monto.toFixed(2)}</strong></p>${terminal}${cobrado}`;
                }).join('');
                return `${esMixto ? '<p><strong>DESGLOSE DE PAGO</strong></p>' : ''}${renglones}`;
            }

            function nuvi_abrirDialogoImpresionUltimoTicket(esCopia = false) {
                const ticket = nuvi_leerUltimoTicket();
                if (!ticket) {
                    mostrarError('Aún no hay un ticket de venta confirmado para imprimir.');
                    return;
                }

                const ventana = window.open('', 'nuvi_ticket', 'width=420,height=680');
                if (!ventana) {
                    mostrarError('El navegador bloqueó la impresión. Permite ventanas emergentes para este POS e inténtalo de nuevo.');
                    return;
                }
                try { ventana.opener = null; } catch (error) {}

                const fecha = new Date(ticket.fecha || Date.now()).toLocaleString('es-MX');
                const filas = ticket.productos.map(producto => `
                    <tr>
                        <td>${nuvi_escaparTicketHtml(producto.nombre)}</td>
                        <td class="num">${Number(producto.cantidad || 0).toFixed(3).replace(/\.000$/, '')}</td>
                        <td class="num">$${Number(producto.subtotal || 0).toFixed(2)}</td>
                    </tr>`).join('');
                const detallePago = nuvi_detallePagosTicketHtml(ticket);
                const resumenFiado = ticket.metodoPago === 'fiado' && ticket.fiado && typeof ticket.fiado === 'object'
                    ? ticket.fiado
                    : null;
                const saldoFiado = Number(resumenFiado?.saldo_total || 0);
                const fechaRecargo = (() => {
                    const valor = String(resumenFiado?.fecha_proximo_recargo || '');
                    const partes = valor.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    return partes ? `${partes[3]}/${partes[2]}/${partes[1]}` : '';
                })();
                const detalleFiado = resumenFiado ? `<div class="credito"><strong>CRÉDITO / LIBRETA DIGITAL</strong><p>Cliente: ${nuvi_escaparTicketHtml(resumenFiado.cliente || 'Cliente')}</p><p>Importe fiado hoy: <strong>$${Number(resumenFiado.importe_fiado || ticket.totalProductos).toFixed(2)}</strong></p>${saldoFiado < -0.004 ? `<p>Saldo a favor: <strong>$${Math.abs(saldoFiado).toFixed(2)}</strong></p>` : `<p>${resumenFiado.saldo_es_foto ? 'Saldo tras esta venta' : 'Saldo vigente hoy'}: <strong>$${Math.max(0, saldoFiado).toFixed(2)}</strong></p>`}${saldoFiado > 0.004 && resumenFiado.configuracion_interes && fechaRecargo ? `<p>Próximo recargo: <strong>${fechaRecargo}</strong><br>Pague antes para evitarlo.</p><p>Estimado: +$${Number(resumenFiado.cargo_proximo || 0).toFixed(2)} (${Number(resumenFiado.porcentaje_proximo || 0).toFixed(2).replace(/\.00$/, '')}%)</p><p class="regla">Regla: 5% al cumplir la 1a semana; +10% por cada semana posterior sin abono.</p>` : saldoFiado <= 0.004 ? '<p>Sin recargo mientras no haya adeudo.</p>' : ''}<p class="firma">Firma cliente: __________________________</p></div>` : '';
                const direccion = NUVI_CONFIG_TICKET.direccion ? `<div>${nuvi_escaparTicketHtml(NUVI_CONFIG_TICKET.direccion)}</div>` : '';
                const telefono = NUVI_CONFIG_TICKET.telefono ? `<div>${nuvi_escaparTicketHtml(NUVI_CONFIG_TICKET.telefono)}</div>` : '';
                const cajero = ticket.cajero ? `<div>Atendió: ${nuvi_escaparTicketHtml(ticket.cajero)}</div>` : '';
                const nota = ticket.nota ? `<p class="nota">Nota: ${nuvi_escaparTicketHtml(ticket.nota)}</p>` : '';
                const marcaCopia = esCopia ? '<div class="copia">*** COPIA ***</div>' : '';
                const titulo = `${esCopia ? 'Copia de ticket' : 'Ticket'} ${ticket.idVenta ? '#' + ticket.idVenta : ''}`;

                ventana.document.open();
                ventana.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${titulo}</title><style>
                    @page { size: 80mm auto; margin: 3mm; }
                    * { box-sizing:border-box; } body { width:74mm; margin:0 auto; color:#000; background:#fff; font:12px/1.35 Arial,sans-serif; } h1 { margin:0 0 4px; text-align:center; font-size:16px; } .marca { margin:0; text-align:center; font-size:18px; line-height:1; } .copia { margin:4px 0 2px; text-align:center; font-weight:700; font-size:15px; letter-spacing:1px; } .center { text-align:center; } .linea { border-top:1px dashed #000; margin:8px 0; } table { width:100%; border-collapse:collapse; } td { padding:3px 0; vertical-align:top; } .num { text-align:right; white-space:nowrap; padding-left:6px; } .total { font-size:16px; font-weight:700; text-align:right; } p { margin:3px 0; } .nota { white-space:pre-wrap; } .credito { border:1px dashed #000; margin-top:8px; padding:7px; } .credito > strong { display:block; text-align:center; } .regla { font-size:10px; } .firma { margin-top:12px; } .legal { margin-top:10px; font-size:10px; text-align:center; }
                </style></head><body><div class="marca" aria-label="Mariposa">🦋</div><h1>${nuvi_escaparTicketHtml(NUVI_CONFIG_TICKET.nombre || 'Miscelánea Nuvi')}</h1>${marcaCopia}<div class="center">${direccion}${telefono}</div><div class="linea"></div><div>Venta: #${ticket.idVenta || 'pendiente'}</div><div>${nuvi_escaparTicketHtml(fecha)}</div>${cajero}<div class="linea"></div><table>${filas}</table><div class="linea"></div><div class="total">TOTAL PRODUCTOS: $${ticket.totalProductos.toFixed(2)}</div><p>Método: ${nuvi_escaparTicketHtml(nuvi_nombreMetodoTicket(ticket.metodoPago))}</p>${detallePago}${detalleFiado}${nota}<div class="linea"></div><div class="center">${nuvi_escaparTicketHtml(NUVI_CONFIG_TICKET.leyenda || 'Gracias por su compra')}</div><div class="legal">Comprobante de venta; no sustituye una factura.</div></body></html>`);
                ventana.document.close();
                ventana.focus();
                setTimeout(() => ventana.print(), 150);
            }

            async function nuvi_imprimirUltimoTicketEnRed(esCopia = false) {
                const ticket = nuvi_leerUltimoTicket();
                if (!ticket || !Number(ticket.idVenta)) {
                    mostrarError('Sólo se puede enviar a la térmica una venta ya confirmada por el servidor.');
                    return;
                }
                if (esCopia && !ticket.claveCopiaPendiente) {
                    // Si se pierde la respuesta de red después de que la
                    // térmica imprimió, conservar esta clave evita que el
                    // siguiente toque duplique la copia por accidente.
                    ticket.claveCopiaPendiente = nuvi_generarClaveCopiaTicket();
                    nuvi_guardarUltimoTicket(ticket);
                }
                const claveImpresion = esCopia ? ticket.claveCopiaPendiente : ticket.claveImpresion;
                const boton = esCopia ? DOM.btnImprimirCopiaTicket : DOM.btnImprimirUltimoTicket;
                const textoOriginal = boton ? boton.textContent : '';
                if (boton) {
                    boton.disabled = true;
                    boton.textContent = esCopia ? '📄 Enviando copia…' : '🖨 Enviando ticket…';
                }
                try {
                    const respuesta = await fetch('imprimir_ticket_red.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({'Content-Type': 'application/json', 'Accept': 'application/json'}),
                        body: JSON.stringify({
                            id_venta: ticket.idVenta,
                            clave_impresion: claveImpresion,
                            tipo: esCopia ? 'copia' : 'original'
                        })
                    });
                    const datos = await respuesta.json();
                    if (!respuesta.ok && datos?.puede_reintentar) {
                        const objeto = esCopia ? 'copia' : 'ticket';
                        const confirmarReintento = confirm(
                            `${datos.message}\n\n¿Confirmas que NO salió ${esCopia ? 'la copia' : 'el ticket'}?\n\n` +
                            `Sólo elige Aceptar si revisaste la impresora; se enviará un ${objeto} nuevo.`
                        );
                        if (confirmarReintento) {
                            if (esCopia) {
                                ticket.claveCopiaPendiente = nuvi_generarClaveCopiaTicket();
                            } else {
                                ticket.claveImpresion = nuvi_generarClaveImpresionTicket();
                            }
                            nuvi_guardarUltimoTicket(ticket);
                            return nuvi_imprimirUltimoTicketEnRed(esCopia);
                        }
                        return;
                    }
                    if (!respuesta.ok || !datos.success) throw new Error(datos.message || 'No se pudo enviar el ticket.');
                    if (esCopia) {
                        delete ticket.claveCopiaPendiente;
                        nuvi_guardarUltimoTicket(ticket);
                    }
                    const mensajeRepetido = esCopia
                        ? 'Esta copia ya había sido enviada a la impresora.'
                        : 'Este ticket ya había sido enviado a la impresora.';
                    mostrarToast(datos.ya_impreso ? mensajeRepetido : datos.message, datos.ya_impreso ? 'warning' : 'success');
                } catch (error) {
                    mostrarError(`${error.message} Si la térmica ya emitió ${esCopia ? 'la copia' : 'el ticket'}, no lo reintentes.`);
                } finally {
                    if (boton) {
                        boton.disabled = false;
                        boton.textContent = textoOriginal;
                    }
                }
            }

            async function nuvi_cargarConfigImpresoraRed() {
                try {
                    const respuesta = await fetch('obtener_config_impresora.php', {headers: {'Accept': 'application/json'}});
                    const datos = await respuesta.json();
                    if (!respuesta.ok || !datos.success) return;
                    NUVI_IMPRESORA_RED = {activa: datos.activa === true, nombre: String(datos.nombre || '')};
                    nuvi_actualizarBotonUltimoTicket();
                } catch (error) {
                    // La impresión del navegador continúa disponible aunque el
                    // servidor aún no tenga preparada una térmica de red.
                }
            }

            if (DOM.btnImprimirUltimoTicket) {
                DOM.btnImprimirUltimoTicket.addEventListener('click', () => {
                    if (NUVI_IMPRESORA_RED.activa) {
                        nuvi_imprimirUltimoTicketEnRed();
                    } else {
                        nuvi_abrirDialogoImpresionUltimoTicket();
                    }
                });
            }
            if (DOM.btnImprimirCopiaTicket) {
                DOM.btnImprimirCopiaTicket.addEventListener('click', () => {
                    if (NUVI_IMPRESORA_RED.activa) {
                        nuvi_imprimirUltimoTicketEnRed(true);
                    } else {
                        nuvi_abrirDialogoImpresionUltimoTicket(true);
                    }
                });
            }
            nuvi_actualizarBotonUltimoTicket();
            nuvi_cargarConfigImpresoraRed();



            // ============================================
            // 📷 MÓDULO: ESCÁNER QR
            // ============================================
            function nuvi_obtenerTrackCamara() {
                const video = DOM.readerDiv ? DOM.readerDiv.querySelector('video') : null;
                const stream = video && video.srcObject;
                return stream && typeof stream.getVideoTracks === 'function' ? stream.getVideoTracks()[0] : null;
            }

            function nuvi_capacidadesCamara() {
                const track = nuvi_obtenerTrackCamara();
                try { return track && typeof track.getCapabilities === 'function' ? (track.getCapabilities() || {}) : {}; } catch (error) { return {}; }
            }

            function nuvi_actualizarControlCamara(mensaje) {
                if (!DOM.controlesCamara || !DOM.btnModoPocaLuz || !DOM.estadoCamara) return;
                const capacidades = nuvi_capacidadesCamara();
                const tieneLinterna = capacidades.torch === true;
                const puedeEnfoqueContinuo = Array.isArray(capacidades.focusMode) && capacidades.focusMode.includes('continuous');
                DOM.controlesCamara.style.display = escanerActivo ? 'block' : 'none';
                DOM.btnModoPocaLuz.textContent = modoPocaLuzActivo ? '💡 Luz activa' : '💡 Poca luz';
                if (mensaje) {
                    DOM.estadoCamara.textContent = mensaje;
                } else if (modoPocaLuzActivo && tieneLinterna) {
                    DOM.estadoCamara.textContent = 'Linterna y enfoque continuo activos. Evita acercar demasiado el código para no crear reflejos.';
                } else if (modoPocaLuzActivo) {
                    DOM.estadoCamara.textContent = 'Enfoque mejorado activo. Esta cámara no deja encender su linterna; agrega luz externa si hace falta.';
                } else if (tieneLinterna || puedeEnfoqueContinuo) {
                    DOM.estadoCamara.textContent = tieneLinterna
                        ? 'Enfoque y exposición mejorados. Pulsa poca luz sólo si necesitas encender la linterna.'
                        : 'Enfoque y exposición mejorados. Esta cámara no expone linterna al navegador.';
                } else {
                    DOM.estadoCamara.textContent = 'Esta cámara no expone controles de luz; usa luz externa y evita reflejos en el empaque.';
                }
            }

            async function nuvi_aplicarMejoraAutomaticaCamara() {
                const track = nuvi_obtenerTrackCamara();
                const capacidades = nuvi_capacidadesCamara();
                if (!track) return;

                // Estas mejoras no consumen la linterna: se aplican siempre
                // porque dan lectura más estable en la tablet sin penalizar
                // batería, temperatura ni provocar reflejos en empaques.
                const avanzadas = [];
                if (Array.isArray(capacidades.focusMode) && capacidades.focusMode.includes('continuous')) avanzadas.push({ focusMode: 'continuous' });
                if (Array.isArray(capacidades.exposureMode) && capacidades.exposureMode.includes('continuous')) avanzadas.push({ exposureMode: 'continuous' });
                if (Array.isArray(capacidades.whiteBalanceMode) && capacidades.whiteBalanceMode.includes('continuous')) avanzadas.push({ whiteBalanceMode: 'continuous' });
                if (!avanzadas.length) return;

                for (const ajuste of avanzadas) {
                    try {
                        // Se prueba cada capacidad por separado: algunas
                        // tablets aceptan enfoque pero no balance de blancos.
                        await track.applyConstraints({ advanced: [ajuste] });
                    } catch (error) {
                        // Son mejoras opcionales: una capacidad no admitida
                        // no debe impedir iniciar ni usar el escáner.
                    }
                }
            }

            async function nuvi_aplicarModoPocaLuz(activar) {
                const track = nuvi_obtenerTrackCamara();
                if (!track) {
                    nuvi_actualizarControlCamara('La cámara aún se está preparando. Espera un momento e inténtalo de nuevo.');
                    return;
                }
                const capacidades = nuvi_capacidadesCamara();
                const avanzadas = [];
                if (capacidades.torch === true) avanzadas.push({ torch: !!activar });
                if (activar && Array.isArray(capacidades.focusMode) && capacidades.focusMode.includes('continuous')) avanzadas.push({ focusMode: 'continuous' });
                if (activar && Array.isArray(capacidades.exposureMode) && capacidades.exposureMode.includes('continuous')) avanzadas.push({ exposureMode: 'continuous' });
                if (activar && Array.isArray(capacidades.whiteBalanceMode) && capacidades.whiteBalanceMode.includes('continuous')) avanzadas.push({ whiteBalanceMode: 'continuous' });

                if (!avanzadas.length) {
                    modoPocaLuzActivo = false;
                    nuvi_actualizarControlCamara('Esta cámara no permite controlar linterna o enfoque desde el navegador. Agrega luz externa y alisa el código.');
                    return;
                }
                try {
                    await track.applyConstraints({ advanced: avanzadas });
                    modoPocaLuzActivo = !!activar;
                    nuvi_actualizarControlCamara();
                } catch (error) {
                    // Algunos navegadores no aceptan foco/exposición, pero sí
                    // la linterna. Se intenta el ajuste mínimo antes de avisar.
                    if (capacidades.torch === true) {
                        try {
                            await track.applyConstraints({ advanced: [{ torch: !!activar }] });
                            modoPocaLuzActivo = !!activar;
                            nuvi_actualizarControlCamara();
                            return;
                        } catch (errorLinterna) {}
                    }
                    modoPocaLuzActivo = false;
                    nuvi_actualizarControlCamara('No fue posible ajustar esta cámara. El lector físico y la búsqueda manual siguen disponibles.');
                }
            }

            function cargarLibreriaEscaner() {
                if (window.Html5Qrcode) return Promise.resolve();
                if (cargadorEscaner) return cargadorEscaner;

                // La cámara es opcional. Cargarla sólo al usarla evita que una
                // caída del ISP frene el arranque del POS que vive en la LAN.
                cargadorEscaner = new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    const timeout = setTimeout(() => {
                        script.remove();
                        reject(new Error('La cámara no respondió. El lector físico y la búsqueda local siguen disponibles.'));
                    }, 5000);
                    script.src = 'https://unpkg.com/html5-qrcode';
                    script.async = true;
                    script.onload = () => { clearTimeout(timeout); resolve(); };
                    script.onerror = () => { clearTimeout(timeout); reject(new Error('No se pudo cargar la cámara. El lector físico y la búsqueda local siguen disponibles.')); };
                    document.head.appendChild(script);
                }).catch(error => {
                    cargadorEscaner = null;
                    throw error;
                });
                return cargadorEscaner;
            }

            async function iniciarEscaner() {
                // html5-qrcode necesita que su contenedor ya sea visible al
                // solicitar el video. Si se inicia dentro de display:none,
                // algunas tablets conceden la cámara pero no pintan su vista.
                DOM.readerDiv.style.display = 'block';
                DOM.controlesCamara.style.display = 'none';
                html5QrCode = new Html5Qrcode("reader");
                const config = {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 150
                    },
                    aspectRatio: 1.0
                };

                try {
                    await html5QrCode.start({
                        facingMode: "environment"
                    }, config, (decodedText) => {
                        if (bloqueado) return;
                        bloqueado = true;
                        DOM.inputUniversal.value = decodedText;
                        procesarEntradaUniversal(decodedText, true);
                        setTimeout(() => {
                            bloqueado = false;
                        }, ESPERA_ESCANER_MS);
                    });
                    escanerActivo = true;
                    modoPocaLuzActivo = false;
                    DOM.readerDiv.style.display = 'block';
                    DOM.controlesCamara.style.display = 'block';
                    await nuvi_aplicarMejoraAutomaticaCamara();
                    nuvi_actualizarControlCamara();
                } catch (error) {
                    DOM.readerDiv.style.display = 'none';
                    DOM.controlesCamara.style.display = 'none';
                    html5QrCode = null;
                    throw error;
                }
            }

            function detenerEscaner() {
                if (html5QrCode) {
                    html5QrCode.stop().then(() => {
                        escanerActivo = false;
                        modoPocaLuzActivo = false;
                        DOM.btnEscaner.innerText = "📷 Usar cámara";
                        DOM.btnEscaner.style.backgroundColor = "#475569";
                        DOM.readerDiv.style.display = 'none';
                        DOM.controlesCamara.style.display = 'none';
                        html5QrCode = null;
                    }).catch(err => console.error("Error al detener escáner:", err));
                }
            }

            if (DOM.btnModoPocaLuz) {
                DOM.btnModoPocaLuz.addEventListener('click', () => nuvi_aplicarModoPocaLuz(!modoPocaLuzActivo));
            }

            DOM.btnEscaner.addEventListener('click', async () => {
                if (!escanerActivo) {
                    const textoOriginal = DOM.btnEscaner.innerText;
                    DOM.btnEscaner.disabled = true;
                    DOM.btnEscaner.innerText = 'Cargando cámara…';
                    try {
                        await cargarLibreriaEscaner();
                        await iniciarEscaner();
                        DOM.btnEscaner.innerText = "🛑 Detener Escáner";
                        DOM.btnEscaner.style.backgroundColor = "#dc3545";
                    } catch (error) {
                        mostrarError(error.message || 'No se pudo iniciar la cámara.');
                        DOM.btnEscaner.innerText = textoOriginal;
                    } finally {
                        DOM.btnEscaner.disabled = false;
                    }
                } else {
                    detenerEscaner();
                }
            });

            // ============================================
            // 🔍 MÓDULO: BUSCADOR UNIVERSAL
            // ============================================
            // 2. ACTUALIZAMOS EL PROCESADOR DE BÚSQUEDA
            function procesarEntradaUniversal(valor, confirmarLector = false) {
                const termino = valor.toString().trim();
                if (!termino) return;

                if (esCodigoBarras(termino)) {
                    ocultarSugerencias();
                    const productoLocal = buscarProductoLocalPorCodigo(termino);

                    if (productoLocal) {
                        procesarProducto(productoLocal, 1, null, { confirmar_lector: confirmarLector });
                        DOM.inputUniversal.value = '';
                        DOM.inputUniversal.focus();
                        reiniciarRitmoLector();
                        return;
                    }

                    buscarProducto(termino, confirmarLector);
                    DOM.inputUniversal.value = '';
                    reiniciarRitmoLector();
                    return;
                }

                // Aquí usamos la nueva búsqueda inteligente y mostramos hasta 8 resultados
                const coincidencias = busquedaInteligente(termino).slice(0, 8);

                if (coincidencias.length === 0) {
                    ocultarSugerencias();
                    return;
                }

                renderizarSugerencias(coincidencias);
            }

            DOM.inputUniversal.addEventListener('input', (e) => {
                const valor = e.target.value;
                const lecturaRapida = actualizarRitmoLector(valor);
                clearTimeout(timerBusquedaUniversal);
                if (!valor.trim()) {
                    reiniciarRitmoLector();
                    ocultarSugerencias();
                    return;
                }

                if (esCodigoBarras(valor.trim())) {
                    ocultarSugerencias();
                    if (lecturaRapida) {
                        const codigoLeido = valor.trim();
                        timerBusquedaUniversal = setTimeout(() => {
                            if (DOM.inputUniversal.value.trim() === codigoLeido) {
                                procesarEntradaUniversal(codigoLeido, true);
                            }
                        }, ESPERA_AUTOPROCESAR_LECTOR_MS);
                    }
                    return;
                }

                if (valor.trim().length < 2 && !esTextoCorto(valor.trim())) {
                    ocultarSugerencias();
                    return;
                }

                timerBusquedaUniversal = setTimeout(() => {
                    procesarEntradaUniversal(valor);
                }, 120);
            });

            // 3. ACTUALIZAMOS QUÉ PASA AL DAR "ENTER"
            DOM.inputUniversal.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === 'Tab') {
                    const valor = DOM.inputUniversal.value.trim();
                    // Tab sólo se intercepta cuando viene como sufijo de un
                    // lector; al escribir una búsqueda normal conserva su
                    // navegación habitual entre campos.
                    if (e.key === 'Tab' && !esCodigoBarras(valor)) return;
                    e.preventDefault();
                    clearTimeout(timerBusquedaUniversal);
                    if (!valor) return;

                    if (esCodigoBarras(valor)) {
                        const productoLocal = buscarProductoLocalPorCodigo(valor);

                        if (productoLocal) {
                            procesarProducto(productoLocal, 1, null, { confirmar_lector: true });
                            DOM.inputUniversal.value = '';
                            ocultarSugerencias();
                            DOM.inputUniversal.focus();
                            reiniciarRitmoLector();
                            return;
                        }

                        buscarProducto(valor, true);
                        DOM.inputUniversal.value = '';
                        ocultarSugerencias();
                        DOM.inputUniversal.focus();
                        reiniciarRitmoLector();
                        return;
                    }

                    const coincidencias = busquedaInteligente(valor);

                    if (coincidencias.length > 0) {
                        procesarProducto(coincidencias[0]);
                        DOM.inputUniversal.value = '';
                        ocultarSugerencias();
                        DOM.inputUniversal.focus();
                    } else {
                        mostrarError("Producto no encontrado");
                        DOM.inputUniversal.value = '';
                        ocultarSugerencias();
                        DOM.inputUniversal.focus();
                    }
                }
            });

            function renderizarSugerencias(lista) {
                DOM.sugerenciasBusqueda.innerHTML = '';
                lista.forEach(p => {
                    const fila = document.createElement('div');
                    fila.style.padding = '10px';
                    fila.style.borderBottom = '1px solid #eee';
                    fila.style.cursor = 'pointer';
                    fila.style.transition = 'background 0.2s';

                    fila.innerHTML = `
                        <strong>${nuvi_escaparHtml(p.nombre)}</strong> - $${parseFloat(p.precio_venta).toFixed(2)}
                        <div style="font-size:0.85rem; color:#475569; margin-top:3px;">
                            ${p.marca ? nuvi_escaparHtml(p.marca) + ' · ' : ''}${p.categoria ? nuvi_escaparHtml(p.categoria) : ''}${p.codigo_barras ? ' · ' + nuvi_escaparHtml(p.codigo_barras) : ''}
                        </div>
                    `;

                    fila.addEventListener('mouseenter', () => fila.style.background = '#f0f0f0');
                    fila.addEventListener('mouseleave', () => fila.style.background = 'transparent');
                    fila.addEventListener('click', () => {
                        procesarProducto(p);
                        DOM.inputUniversal.value = '';
                        ocultarSugerencias();
                        DOM.inputUniversal.focus();
                    });

                    DOM.sugerenciasBusqueda.appendChild(fila);
                });
                DOM.sugerenciasBusqueda.style.display = 'block';
            }

            document.addEventListener('click', (e) => {
                if (e.target !== DOM.inputUniversal && !DOM.sugerenciasBusqueda.contains(e.target)) {
                    ocultarSugerencias();
                }
            });

            // ============================================
            // 💰 MÓDULO: FINALIZAR VENTA
            // ============================================
            DOM.btnFinalizar.addEventListener('click', async () => {
                try {
                    if (DOM.btnFinalizar.dataset.procesando === '1') return;

                    const filas = document.querySelectorAll("#tabla-carrito tbody tr");

                    if (filas.length === 0) {
                        mostrarError("El carrito está vacío");
                        return;
                    }

                    const totalVenta = parseFloat(DOM.granTotalSpan.innerText);
                    const notaVenta = DOM.notaVentaInput ? DOM.notaVentaInput.value.trim() : '';
                    const metodoPago = DOM.metodoPagoSelect ? DOM.metodoPagoSelect.value : 'efectivo';
                    const pagoCon = parseFloat(DOM.pagoConInput.value) || 0;
                    const esMixto = metodoPago === 'mixto';
                    const metodoSecundario = DOM.metodoMixtoSecundario ? DOM.metodoMixtoSecundario.value : '';
                    const montoSecundario = Math.round((parseFloat(DOM.montoMixtoSecundarioInput?.value) || 0) * 100) / 100;
                    const efectivoRecibidoMixto = DOM.pagoMixtoEfectivoInput.value.trim() === ''
                        ? Math.round((parseFloat(DOM.pagoMixtoParteInput.value) || 0) * 100) / 100
                        : Math.round((parseFloat(DOM.pagoMixtoEfectivoInput.value) || 0) * 100) / 100;
                    const efectivoAplicadoMixto = Math.round((totalVenta - montoSecundario) * 100) / 100;
                    const usaTarjeta = metodoPago === 'tarjeta' || (esMixto && metodoSecundario === 'tarjeta');
                    const importeTarjeta = metodoPago === 'tarjeta' ? totalVenta : (usaTarjeta ? montoSecundario : 0);
                    const calculoTarjeta = usaTarjeta ? nuvi_calcularCobroTerminal(importeTarjeta) : null;
                    const terminalTarjeta = usaTarjeta ? nuvi_terminalActual() : null;

                    if (!Number.isFinite(totalVenta) || totalVenta <= 0) {
                        mostrarError('El total de la venta no es válido. Revisa el carrito.');
                        return;
                    }

                    if (metodoPago === 'efectivo' && (!importeCobroValido(DOM.pagoConInput.value) || pagoCon < totalVenta)) {
                        mostrarError("El pago es menor al total");
                        return;
                    }

                    if (esMixto) {
                        if (!['tarjeta', 'transferencia', 'otro'].includes(metodoSecundario)) {
                            mostrarError('Selecciona el segundo método del pago mixto.');
                            return;
                        }
                        if (!importeCobroValido(DOM.pagoMixtoParteInput.value) || montoSecundario <= 0 || montoSecundario >= totalVenta) {
                            mostrarError('Escribe una parte en efectivo mayor a cero y menor al total.');
                            return;
                        }
                        if ((DOM.pagoMixtoEfectivoInput.value.trim() !== '' && !importeCobroValido(DOM.pagoMixtoEfectivoInput.value)) || efectivoRecibidoMixto < efectivoAplicadoMixto) {
                            mostrarError(`Falta efectivo: deben recibirse al menos $${efectivoAplicadoMixto.toFixed(2)}.`);
                            return;
                        }
                    }

                    if (usaTarjeta && (!NUVI_CONFIG_COMISIONES.configuracion_lista || !calculoTarjeta || !terminalTarjeta)) {
                        mostrarError('Selecciona una terminal configurada antes de cobrar con tarjeta.');
                        return;
                    }

                    const productosVenta = nuvi_obtenerProductosDelCarrito();
                    const pagosSolicitados = esMixto
                        ? [
                            {
                                metodo: 'efectivo',
                                monto: efectivoAplicadoMixto,
                                monto_recibido: efectivoRecibidoMixto,
                                cambio: Math.max(0, Math.round((efectivoRecibidoMixto - efectivoAplicadoMixto) * 100) / 100)
                            },
                            {
                                metodo: metodoSecundario,
                                monto: montoSecundario,
                                id_terminal_pago: usaTarjeta ? terminalTarjeta?.id : null,
                                terminal_nombre: usaTarjeta ? terminalTarjeta?.nombre : '',
                                monto_cobrado_tarjeta: usaTarjeta ? calculoTarjeta?.montoCobrado : 0,
                                recargo_cliente_tarjeta: usaTarjeta ? calculoTarjeta?.recargoCliente : 0
                            }
                        ]
                        : metodoPago === 'efectivo'
                            ? [{ metodo: 'efectivo', monto: totalVenta, monto_recibido: pagoCon, cambio: Math.max(0, Math.round((pagoCon - totalVenta) * 100) / 100) }]
                            : [{
                                metodo: metodoPago,
                                monto: totalVenta,
                                id_terminal_pago: usaTarjeta ? terminalTarjeta?.id : null,
                                terminal_nombre: usaTarjeta ? terminalTarjeta?.nombre : '',
                                monto_cobrado_tarjeta: usaTarjeta ? calculoTarjeta?.montoCobrado : 0,
                                recargo_cliente_tarjeta: usaTarjeta ? calculoTarjeta?.recargoCliente : 0
                            }];
                    const payloadVenta = {
                        total: totalVenta,
                        nota_venta: notaVenta,
                        metodo_pago: metodoPago,
                        id_terminal_pago: terminalTarjeta ? terminalTarjeta.id : null,
                        pago_con_efectivo: esMixto ? efectivoRecibidoMixto : pagoCon,
                        metodo_secundario: esMixto ? metodoSecundario : null,
                        monto_secundario: esMixto ? montoSecundario : null,
                        idempotency_key: nuvi_generarClaveIdempotencia(),
                        productos: productosVenta
                    };
                    const textoBoton = DOM.btnFinalizar.innerText;
                    let ventaFinalizada = false;
                    DOM.btnFinalizar.dataset.procesando = '1';
                    DOM.btnFinalizar.disabled = true;
                    DOM.btnFinalizar.innerText = '⏳ Guardando venta…';

                    try {
                        const respuesta = await nuvi_intentarGuardarVenta('finalizar_venta.php', payloadVenta, 'venta normal');

                        if (respuesta.success) {
                            ventaFinalizada = true;
                            const cambioDado = metodoPago === 'efectivo'
                                ? (pagoCon - totalVenta).toFixed(2)
                                : esMixto
                                    ? (efectivoRecibidoMixto - efectivoAplicadoMixto).toFixed(2)
                                    : '0.00';
                            const resultadoVenta = respuesta.resultado || {};
                            const cobroTerminal = parseFloat(resultadoVenta.monto_cobrado_tarjeta ?? calculoTarjeta?.montoCobrado ?? 0);
                            const recargoCliente = parseFloat(resultadoVenta.recargo_cliente_tarjeta ?? calculoTarjeta?.recargoCliente ?? 0);
                            const detalleTarjeta = usaTarjeta
                                ? `\nTerminal: ${resultadoVenta.terminal_nombre || terminalTarjeta?.nombre || 'seleccionada'}\nCobro en terminal: $${cobroTerminal.toFixed(2)}\nComisión pagada por cliente: $${recargoCliente.toFixed(2)}`
                                : '';
                            const mensajeVentaPendiente = respuesta.sesion_expirada
                                ? `⚠️ La sesión venció, pero la venta quedó guardada en esta caja.\nInicia sesión de nuevo; se sincronizará automáticamente.\nMétodo: ${metodoPago}${detalleTarjeta}\nCambio: $${cambioDado}`
                                : `⚠️ Venta guardada localmente.\nSe sincronizará cuando vuelva la conexión.\nMétodo: ${metodoPago}${detalleTarjeta}\nCambio: $${cambioDado}`;
                            // Sólo se ofrece ticket cuando el servidor ya
                            // confirmó la venta. Una venta pendiente no debe
                            // imprimirse como comprobante definitivo.
                            if (!respuesta.offline) {
                                nuvi_guardarUltimoTicket(nuvi_crearTicketVenta({
                                    idVenta: resultadoVenta.id_venta,
                                    productos: productosVenta,
                                    totalProductos: totalVenta,
                                    metodoPago,
                                    pagoCon,
                                    cambio: Number(cambioDado),
                                    cobroTerminal,
                                    recargoCliente,
                                    terminal: resultadoVenta.terminal_nombre || terminalTarjeta?.nombre || '',
                                    cajero: resultadoVenta.cajero || '',
                                    nota: notaVenta,
                                    pagos: Array.isArray(resultadoVenta.pagos) && resultadoVenta.pagos.length ? resultadoVenta.pagos : pagosSolicitados
                                }));
                            }
                            alert(respuesta.offline
                                ? mensajeVentaPendiente
                                : `✅ Venta Guardada!\nMétodo: ${metodoPago}${detalleTarjeta}\nCambio: $${cambioDado}`);
                            // Actualizar puntuación local por productos vendidos (mejora el orden del buscador en POS)
                            try { nuvi_incrementScores(productosVenta); } catch (e) { console.error('nuvi_incrementScores error', e); }
                            limpiarCarrito();
                            precargarProductos();
                            if (respuesta.sesion_expirada) {
                                window.location.href = 'login.php?motivo=sesion_vencida';
                            }
                        }
                    } catch (error) {
                        console.error("Error:", error);
                        mostrarError("Error al guardar: " + error.message);
                    } finally {
                        delete DOM.btnFinalizar.dataset.procesando;
                        DOM.btnFinalizar.disabled = false;
                        if (!ventaFinalizada) {
                            DOM.btnFinalizar.innerText = textoBoton;
                        }
                    }
                } catch (error) {
                    console.error('No se pudo iniciar el cobro:', error);
                    mostrarError('No se pudo iniciar el cobro: ' + (error.message || 'revisa los datos de la venta.'));
                }
            });

            function importeCobroValido(valor) {
                return /^\d+(?:\.\d{0,2})?$/.test(String(valor).trim());
            }

            function seleccionarCampoTeclado(campo) {
                campoTecladoCobro = campo;
                [DOM.pagoConInput, DOM.pagoMixtoParteInput, DOM.pagoMixtoEfectivoInput].forEach(input => {
                    input.classList.toggle('campo-teclado-activo', input === campo);
                });
                const texto = campo === DOM.pagoMixtoParteInput ? 'parte de la venta en efectivo'
                    : campo === DOM.pagoMixtoEfectivoInput ? 'efectivo entregado' : 'efectivo recibido';
                document.getElementById('teclado-cobro-destino').textContent = `Escribir: ${texto}`;
            }

            [DOM.pagoConInput, DOM.pagoMixtoParteInput, DOM.pagoMixtoEfectivoInput].forEach(campo => {
                campo.addEventListener('focus', () => seleccionarCampoTeclado(campo));
                campo.addEventListener('click', () => seleccionarCampoTeclado(campo));
            });
            document.querySelectorAll('[data-tecla-cobro]').forEach(boton => {
                boton.addEventListener('click', () => {
                    const tecla = boton.dataset.teclaCobro;
                    let valor = campoTecladoCobro.value;
                    if (tecla === 'limpiar') valor = '';
                    else if (tecla === 'borrar') valor = valor.slice(0, -1);
                    else if (tecla === '.' && !valor.includes('.')) valor = (valor || '0') + '.';
                    else if (/^\d$/.test(tecla) && valor.length < 12 && (!valor.includes('.') || valor.split('.')[1].length < 2)) valor += tecla;
                    campoTecladoCobro.value = valor;
                    campoTecladoCobro.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
            seleccionarCampoTeclado(DOM.pagoConInput);
            DOM.pagoConInput.addEventListener('input', actualizarCambio);
            DOM.pagoMixtoParteInput.addEventListener('input', actualizarCambio);
            if (DOM.pagoMixtoEfectivoInput) DOM.pagoMixtoEfectivoInput.addEventListener('input', actualizarCambio);
            if (DOM.metodoMixtoSecundario) DOM.metodoMixtoSecundario.addEventListener('change', () => {
                actualizarCambio();
                if (DOM.metodoMixtoSecundario.value === 'tarjeta'
                    && (!NUVI_CONFIG_COMISIONES.configuracion_lista || NUVI_CONFIG_COMISIONES.terminales.length === 0)) {
                    nuvi_cargarConfigComisiones(true);
                }
            });
            DOM.metodoPagoSelect.addEventListener('change', () => {
                const esEfectivo = DOM.metodoPagoSelect.value === 'efectivo';
                const esMixto = DOM.metodoPagoSelect.value === 'mixto';
                const esTarjeta = DOM.metodoPagoSelect.value === 'tarjeta'
                    || (esMixto && DOM.metodoMixtoSecundario?.value === 'tarjeta');
                if (!esEfectivo) {
                    DOM.pagoConInput.value = "";
                } else if (!DOM.pagoConInput.value || DOM.pagoConInput.value === '0.00') {
                    DOM.pagoConInput.value = "";
                }
                if (esEfectivo) seleccionarCampoTeclado(DOM.pagoConInput);
                if (esMixto) seleccionarCampoTeclado(DOM.pagoMixtoParteInput);
                actualizarCambio();
                // Reintenta sin recargar el POS si éste se abrió antes de que se
                // configurara la terminal o si la red tardó en responder.
                if (esTarjeta && (!NUVI_CONFIG_COMISIONES.configuracion_lista || NUVI_CONFIG_COMISIONES.terminales.length === 0)) {
                    nuvi_cargarConfigComisiones(true);
                }
            });
            if (DOM.terminalPagoSelect) DOM.terminalPagoSelect.addEventListener('change', actualizarCambio);

            // ============================================
            // 📚 MÓDULO: LIBRETA DIGITAL
            // ============================================
            function abrirLibreta() {
                DOM.modalLibreta.style.display = 'block';
                if (estadoClientesLibreta !== 'activos') {
                    estadoClientesLibreta = 'activos';
                    clientesLibreta = [];
                }
                actualizarEstadoClientesLibreta();
                actualizarListaClientes();
                DOM.buscarClienteLibreta.focus();
            }

            function cerrarLibreta() {
                DOM.modalLibreta.style.display = 'none';
                cerrarDetalle();
                cancelarNuevoCliente();
            }

            function cerrarDetalle() {
                DOM.detalleCliente.style.display = 'none';
            }

            function mostrarFormNuevoCliente() {
                clienteEditandoLibreta = null;
                DOM.nomCliente.value = '';
                DOM.telCliente.value = '';
                DOM.limiteCliente.value = '';
                if (DOM.tituloFormCliente) DOM.tituloFormCliente.textContent = 'Registrar Nuevo Cliente';
                if (DOM.btnGuardarCliente) DOM.btnGuardarCliente.textContent = 'Guardar';
                DOM.formNuevoCliente.style.display = 'block';
                DOM.nomCliente.focus();
            }

            function cancelarNuevoCliente() {
                DOM.formNuevoCliente.style.display = 'none';
                clienteEditandoLibreta = null;
                DOM.nomCliente.value = '';
                DOM.telCliente.value = '';
                DOM.limiteCliente.value = '';
                if (DOM.tituloFormCliente) DOM.tituloFormCliente.textContent = 'Registrar Nuevo Cliente';
                if (DOM.btnGuardarCliente) DOM.btnGuardarCliente.textContent = 'Guardar';
            }

            DOM.nomCliente.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') guardarCliente();
            });

            DOM.buscarClienteLibreta.addEventListener('input', () => {
                renderizarListaClientes(DOM.buscarClienteLibreta.value.trim());
            });

            if (DOM.btnVerClientesArchivados) {
                DOM.btnVerClientesArchivados.addEventListener('click', () => {
                    estadoClientesLibreta = estadoClientesLibreta === 'activos' ? 'archivados' : 'activos';
                    clientesLibreta = [];
                    cerrarDetalle();
                    actualizarEstadoClientesLibreta();
                    DOM.listaClientesLibreta.innerHTML = '<p style="color:#64748b;">Cargando clientes...</p>';
                    actualizarListaClientes(DOM.buscarClienteLibreta.value.trim(), true);
                });
            }

            function actualizarEstadoClientesLibreta() {
                const viendoArchivados = estadoClientesLibreta === 'archivados';
                if (DOM.btnVerClientesArchivados) {
                    DOM.btnVerClientesArchivados.textContent = viendoArchivados ? '← Activos' : '🗃️ Archivados';
                    DOM.btnVerClientesArchivados.style.background = viendoArchivados ? '#0f766e' : '#64748b';
                    DOM.btnVerClientesArchivados.setAttribute('aria-pressed', viendoArchivados ? 'true' : 'false');
                }
                if (DOM.estadoClientesLibreta) {
                    DOM.estadoClientesLibreta.textContent = viendoArchivados
                        ? 'Mostrando clientes archivados. Puedes buscarlos, editarlos o restaurarlos.'
                        : 'Mostrando clientes activos.';
                }
                if (DOM.buscarClienteLibreta) {
                    DOM.buscarClienteLibreta.placeholder = viendoArchivados
                        ? 'Buscar cliente archivado por nombre o teléfono...'
                        : 'Buscar cliente por nombre o teléfono...';
                }
            }

            function guardarCliente() {
                const datos = {
                    nombre: DOM.nomCliente.value.trim(),
                    telefono: DOM.telCliente.value.trim(),
                    limite: DOM.limiteCliente.value.trim()
                };

                if (!datos.nombre) {
                    alert("El nombre es obligatorio");
                    return;
                }

                const editando = Number.isInteger(clienteEditandoLibreta) && clienteEditandoLibreta > 0;
                if (editando) datos.id_cliente = clienteEditandoLibreta;

                fetch(editando ? 'actualizar_cliente.php' : 'guardar_cliente.php', {
                        method: 'POST',
                        body: JSON.stringify(datos),
                        headers: nuvi_headersMutacion({
                            'Content-Type': 'application/json'
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(editando ? '✅ Cliente actualizado con éxito' : '✅ Cliente registrado con éxito');
                            cancelarNuevoCliente();
                            actualizarListaClientes(DOM.buscarClienteLibreta.value.trim(), true);
                        } else {
                            alert("❌ Error: " + data.message);
                        }
                    })
                    .catch(err => {
                        console.error('Error al guardar cliente:', err);
                        alert('❌ No se pudo guardar el cliente. Revisa la conexión con el servidor local.');
                    });
            }

            function editarClienteLibreta(idCliente) {
                if (!PUEDE_GESTIONAR_CLIENTES) {
                    alert('Sólo administración puede editar datos de clientes.');
                    return;
                }
                const cliente = clientesLibreta.find(c => Number(c.id_cliente) === Number(idCliente));
                if (!cliente) {
                    alert('No se encontró el cliente. Actualiza la libreta e inténtalo otra vez.');
                    return;
                }

                clienteEditandoLibreta = Number(idCliente);
                DOM.nomCliente.value = String(cliente.nombre_completo || '');
                DOM.telCliente.value = String(cliente.telefono || '');
                DOM.limiteCliente.value = Number(cliente.limite_credito || 0).toFixed(2);
                if (DOM.tituloFormCliente) DOM.tituloFormCliente.textContent = `Editar cliente: ${cliente.nombre_completo || ''}`;
                if (DOM.btnGuardarCliente) DOM.btnGuardarCliente.textContent = 'Guardar cambios';
                DOM.formNuevoCliente.style.display = 'block';
                DOM.formNuevoCliente.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                DOM.nomCliente.focus();
            }

            function leerCacheLibreta(estado = estadoClientesLibreta) {
                try {
                    const guardado = JSON.parse(localStorage.getItem(LIBRETA_CLIENTES_CACHE_KEY) || '{}');
                    return Array.isArray(guardado[estado]) ? guardado[estado] : [];
                } catch (error) {
                    return [];
                }
            }

            function guardarCacheLibreta(clientes, estado = estadoClientesLibreta) {
                try {
                    const guardado = JSON.parse(localStorage.getItem(LIBRETA_CLIENTES_CACHE_KEY) || '{}');
                    const cache = {
                        activos: Array.isArray(guardado.activos) ? guardado.activos : [],
                        archivados: Array.isArray(guardado.archivados) ? guardado.archivados : [],
                        actualizadoEn: Date.now()
                    };
                    cache[estado] = Array.isArray(clientes) ? clientes : [];
                    localStorage.setItem(LIBRETA_CLIENTES_CACHE_KEY, JSON.stringify(cache));
                } catch (error) {
                    console.warn('No se pudo guardar la libreta local:', error);
                }
            }

            function normalizarBusquedaCliente(valor) {
                const texto = String(valor || '');
                const sinAcentos = typeof texto.normalize === 'function'
                    ? texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    : texto;
                return sinAcentos.toLocaleLowerCase('es-MX');
            }

            function escaparHtmlCliente(valor) {
                return String(valor ?? '').replace(/[&<>'"]/g, caracter => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
                })[caracter]);
            }

            function obtenerClienteLibreta(idCliente) {
                return clientesLibreta.find(cliente => Number(cliente.id_cliente) === Number(idCliente)) || null;
            }

            function seleccionarClienteParaAbono(idCliente) {
                const cliente = obtenerClienteLibreta(idCliente);
                if (cliente) seleccionarParaAbono(idCliente, String(cliente.nombre_completo || ''), Number(cliente.saldo_actual || 0));
            }

            function seleccionarClienteParaVentaFiada(idCliente) {
                const cliente = obtenerClienteLibreta(idCliente);
                if (cliente) seleccionarParaVentaFiada(idCliente, String(cliente.nombre_completo || ''));
            }

            function verHistorialClienteLibreta(idCliente, boton) {
                const cliente = obtenerClienteLibreta(idCliente);
                if (cliente) {
                    verHistorial(
                        idCliente,
                        String(cliente.nombre_completo || ''),
                        Number(cliente.saldo_actual || 0),
                        Number(cliente.limite_credito || 0),
                        boton
                    );
                }
            }

            function ajustarSaldoClienteDesdeLibreta(idCliente) {
                const cliente = obtenerClienteLibreta(idCliente);
                if (cliente) ajustarSaldoCliente(idCliente, String(cliente.nombre_completo || ''), Number(cliente.saldo_actual || 0));
            }

            function archivarClienteDesdeLibreta(idCliente) {
                const cliente = obtenerClienteLibreta(idCliente);
                if (cliente) eliminarClienteLibreta(idCliente, String(cliente.nombre_completo || 'Cliente'));
            }

            function renderizarListaClientes(filtro = '') {
                const filtroNormalizado = normalizarBusquedaCliente(filtro);
                const viendoArchivados = estadoClientesLibreta === 'archivados';
                const clientesFiltrados = clientesLibreta.filter(c => {
                    if (!filtroNormalizado) return true;
                    return normalizarBusquedaCliente(c.nombre_completo).includes(filtroNormalizado) ||
                        normalizarBusquedaCliente(c.telefono).includes(filtroNormalizado);
                });

                if (clientesFiltrados.length === 0) {
                    DOM.listaClientesLibreta.innerHTML = clientesLibreta.length
                        ? '<p style="color:#64748b;">No se encontraron clientes con esa búsqueda.</p>'
                        : (viendoArchivados
                            ? '<p style="color:#64748b;">No hay clientes archivados.</p>'
                            : '<p style="color:#64748b;">Aún no hay clientes activos registrados.</p>');
                    return;
                }

                DOM.listaClientesLibreta.innerHTML = clientesFiltrados.map(c => {
                    const idCliente = Number(c.id_cliente);
                    const saldo = Number(c.saldo_actual || 0);
                    const limite = Number(c.limite_credito || 0);
                    const tieneSaldoFavor = saldo < -0.004;
                    const etiquetaSaldo = saldo > 0.004 ? 'Debe' : (tieneSaldoFavor ? 'Saldo a favor' : 'Sin adeudo');
                    const montoSaldo = Math.abs(saldo);
                    const colorSaldo = saldo > 0.004 ? '#ef4444' : (tieneSaldoFavor ? '#059669' : '#64748b');
                    const interesPendiente = Number(c.interes_pendiente || 0);
                    const semanasMora = Math.max(0, parseInt(c.semanas_mora || 0, 10));
                    const porcentajeInteres = Number(c.porcentaje_interes_pendiente || 0);
                    const nombre = escaparHtmlCliente(c.nombre_completo || 'Cliente');
                    const telefono = escaparHtmlCliente(c.telefono || '');
                    const avisoInteres = interesPendiente > 0
                        ? `<div style="font-size:.76em;color:#b45309;margin-top:3px;">Incluye $${interesPendiente.toFixed(2)} de interés simple pendiente (${porcentajeInteres.toFixed(0)}% · ${semanasMora} semana${semanasMora === 1 ? '' : 's'} sin abono)</div>`
                        : '';
                    const accionesActivos = `
                        <button type="button" onclick="seleccionarClienteParaAbono(${idCliente})" style="background:#3b82f6; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">${tieneSaldoFavor ? 'Agregar saldo' : 'Abonar'}</button>
                        <button type="button" onclick="seleccionarClienteParaVentaFiada(${idCliente})" style="background:#f59e0b; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">Fiar</button>`;
                    const accionesGestion = PUEDE_GESTIONAR_CLIENTES ? `
                        <button type="button" onclick="editarClienteLibreta(${idCliente})" style="background:#475569; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">Editar</button>` : '';
                    const accionEstado = viendoArchivados
                        ? (PUEDE_GESTIONAR_CLIENTES
                            ? `<button type="button" onclick="restaurarClienteLibreta(${idCliente})" style="background:#059669; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">Desarchivar</button>`
                            : '')
                        : (PUEDE_GESTIONAR_CLIENTES
                            ? `<button type="button" onclick="archivarClienteDesdeLibreta(${idCliente})" style="background:#dc2626; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">Archivar</button>`
                            : '');
                    return `
                        <div class="cliente-card" data-id="${idCliente}" style="border:1px solid ${viendoArchivados ? '#cbd5e1' : '#e2e8f0'}; padding:12px; border-radius:8px; margin-bottom:10px; background:white; display:flex; justify-content:space-between; align-items:center; gap:10px; ${viendoArchivados ? 'opacity:.88;' : ''}">
                            <div style="min-width:0;">
                                <strong style="color:#1e293b;">${nombre}</strong>${viendoArchivados ? ' <span style="font-size:.72em; color:#475569; font-weight:700;">ARCHIVADO</span>' : ''}
                                ${telefono ? `<div style="font-size:.82em; color:#64748b; margin-top:3px;">Tel.: ${telefono}</div>` : ''}
                                <div style="font-size:.85em; color:#666; margin-top:4px;">
                                    ${etiquetaSaldo}: <span style="color:${colorSaldo}; font-weight:bold;">$${montoSaldo.toFixed(2)}</span> /
                                    Límite: $${limite.toFixed(2)}
                                </div>
                                ${avisoInteres}
                            </div>
                            <div style="display:flex; flex-wrap:wrap; justify-content:flex-end; gap:5px;">
                                ${viendoArchivados ? '' : accionesActivos}
                                <button type="button" onclick="verHistorialClienteLibreta(${idCliente}, this)" style="background:#9333ea; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">Ver</button>
                                ${viendoArchivados ? '' : (PUEDE_AJUSTAR_SALDO ? `<button type="button" onclick="ajustarSaldoClienteDesdeLibreta(${idCliente})" style="background:#0ea5e9; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:.85em;">Ajustar</button>` : '')}
                                ${accionesGestion}
                                ${accionEstado}
                            </div>
                        </div>`;
                }).join('');
            }

            async function actualizarListaClientes(filtro = '', forzarRecarga = false) {
                const estadoSolicitado = estadoClientesLibreta;
                const solicitud = ++secuenciaCargaLibreta;
                const cache = leerCacheLibreta(estadoSolicitado);
                if (cache.length > 0 && clientesLibreta.length === 0 && !forzarRecarga) {
                    clientesLibreta = cache;
                    renderizarListaClientes(filtro);
                }

                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 4500);
                try {
                    const respuesta = await fetch(`obtener_clientes.php?estado=${encodeURIComponent(estadoSolicitado)}`, {
                        signal: controller.signal,
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await respuesta.json();
                    if (!respuesta.ok || data.error || !Array.isArray(data)) {
                        throw new Error(data.error || 'No se pudo cargar la libreta');
                    }
                    if (solicitud !== secuenciaCargaLibreta || estadoSolicitado !== estadoClientesLibreta) return;
                    clientesLibreta = data;
                    guardarCacheLibreta(data, estadoSolicitado);
                    renderizarListaClientes(filtro);
                } catch (error) {
                    if (solicitud !== secuenciaCargaLibreta || estadoSolicitado !== estadoClientesLibreta) return;
                    console.warn('Libreta usando datos locales:', error);
                    if (clientesLibreta.length === 0 && cache.length > 0) {
                        clientesLibreta = cache;
                        renderizarListaClientes(filtro);
                    }
                    if (clientesLibreta.length === 0) {
                        DOM.listaClientesLibreta.innerHTML = '<p style="color:#dc2626;">No se pudo cargar la libreta. Revisa la conexión con el servidor local.</p>';
                    }
                } finally {
                    clearTimeout(timeout);
                }
            }

            function seleccionarParaAbono(id, nombre, saldoActual = 0) {
                const saldo = Number(saldoActual || 0);
                const tieneSaldoFavor = saldo < -0.004;
                const mensajeMonto = tieneSaldoFavor
                    ? `${nombre} ya tiene $${Math.abs(saldo).toFixed(2)} de saldo a favor.\n\n¿Cuánto dinero adicional dejará a cuenta?`
                    : `Saldo actual de ${nombre}: $${Math.max(saldo, 0).toFixed(2)}\n\n¿Cuánto va a abonar?`;
                const monto = prompt(mensajeMonto);
                const montoNumerico = Number(String(monto ?? '').trim().replace(',', '.'));
                if (monto === null || String(monto).trim() === '' || !Number.isFinite(montoNumerico) || montoNumerico <= 0) {
                    alert("Por favor ingresa un monto válido");
                    return;
                }

                const nota = prompt("Nota del movimiento:", tieneSaldoFavor ? "Saldo a favor adicional en efectivo" : "Pago en efectivo");

                fetch('abonar_pago.php', {
                        method: 'POST',
                        body: JSON.stringify({
                            id_cliente: id,
                            monto: montoNumerico,
                            nota: nota || "Sin nota"
                        }),
                        headers: nuvi_headersMutacion({
                            'Content-Type': 'application/json'
                        })
                    })
                    .then(async res => {
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            throw new Error(data.message || 'El servidor no pudo registrar el abono.');
                        }
                        return data;
                    })
                    .then(data => {
                        if (data.success) {
                            const saldoNuevo = Number(data.saldo_nuevo || 0);
                            const saldoAFavor = Number(data.saldo_a_favor || 0);
                            clientesLibreta = clientesLibreta.map(cliente => String(cliente.id_cliente) === String(id)
                                ? { ...cliente, saldo_actual: saldoNuevo, saldo_registrado: saldoNuevo, interes_pendiente: 0, semanas_mora: 0, porcentaje_interes_pendiente: 0 }
                                : cliente
                            );
                            guardarCacheLibreta(clientesLibreta);
                            const filtroActual = DOM.buscarClienteLibreta.value.trim();
                            renderizarListaClientes(filtroActual);
                            const resumenSaldo = saldoAFavor > 0
                                ? `Saldo a favor: $${saldoAFavor.toFixed(2)}`
                                : `Saldo pendiente: $${Math.max(saldoNuevo, 0).toFixed(2)}`;
                            alert(`✅ Movimiento registrado.\n\nAntes: $${Number(data.saldo_anterior || 0).toFixed(2)}\nRecibido: $${Number(data.monto_abonado || montoNumerico).toFixed(2)}\n${resumenSaldo}`);
                            actualizarListaClientes(filtroActual, true);
                            if (DOM.detalleCliente.style.display === 'block') {
                                const clienteActual = clientesLibreta.find(cliente => String(cliente.id_cliente) === String(id));
                                verHistorial(id, nombre, saldoNuevo, parseFloat(clienteActual?.limite_credito || 0));
                            }
                        } else {
                            alert("❌ Error: " + data.message);
                        }
                    })
                    .catch(err => {
                        console.error("Error al abonar:", err);
                        alert("❌ No se registró el movimiento: " + (err.message || 'revisa la conexión al servidor local.'));
                    });
            }

            function eliminarClienteLibreta(id, nombre) {
                if (!confirm(`¿Archivar a ${nombre}?\n\nDejará de aparecer en la libreta activa, pero su historial se conservará.`)) {
                    return;
                }

                fetch('eliminar_cliente.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({
                            'Content-Type': 'application/json'
                        }),
                        body: JSON.stringify({
                            id_cliente: id
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert("✅ Cliente archivado; su historial se conserva.");
                            cerrarDetalle();
                            clientesLibreta = clientesLibreta.filter(cliente => Number(cliente.id_cliente) !== Number(id));
                            guardarCacheLibreta(clientesLibreta);
                            renderizarListaClientes(DOM.buscarClienteLibreta.value.trim());
                            actualizarListaClientes(DOM.buscarClienteLibreta.value.trim(), true);
                        } else {
                            alert("❌ Error: " + data.message);
                        }
                    })
                    .catch(err => {
                        console.error("Error al eliminar cliente:", err);
                        alert("❌ Error al eliminar cliente");
                    });
            }

            function restaurarClienteLibreta(id) {
                const cliente = obtenerClienteLibreta(id);
                const nombre = String(cliente?.nombre_completo || 'este cliente');
                if (!confirm(`¿Desarchivar a ${nombre}?\n\nVolverá a aparecer en la libreta activa y se conservará todo su historial.`)) {
                    return;
                }

                fetch('restaurar_cliente.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
                        body: JSON.stringify({ id_cliente: id })
                    })
                    .then(async res => {
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) throw new Error(data.message || 'No se pudo desarchivar el cliente.');
                        return data;
                    })
                    .then(data => {
                        if (!data.success) throw new Error(data.message || 'No se pudo desarchivar el cliente.');
                        alert('✅ Cliente restaurado a la libreta activa.');
                        cerrarDetalle();
                        clientesLibreta = clientesLibreta.filter(clienteActual => Number(clienteActual.id_cliente) !== Number(id));
                        guardarCacheLibreta(clientesLibreta);
                        renderizarListaClientes(DOM.buscarClienteLibreta.value.trim());
                        actualizarListaClientes(DOM.buscarClienteLibreta.value.trim(), true);
                    })
                    .catch(err => {
                        console.error('Error al desarchivar cliente:', err);
                        alert('❌ ' + (err.message || 'No se pudo desarchivar el cliente.'));
                    });
            }

            function ajustarSaldoCliente(id, nombre, saldoActual) {
                const saldoActualNum = parseFloat(saldoActual || 0);
                const nuevoSaldoRaw = prompt(
                    `Saldo actual de ${nombre}: $${saldoActualNum.toFixed(2)}\n\nIngresa el NUEVO saldo correcto:`,
                    saldoActualNum.toFixed(2)
                );

                if (nuevoSaldoRaw === null) return;

                const nuevoSaldo = parseFloat(String(nuevoSaldoRaw).replace(',', '.'));
                if (isNaN(nuevoSaldo)) {
                    alert('Ingresa un saldo válido. Puede ser negativo si el cliente tiene saldo a favor.');
                    return;
                }

                const contexto = prompt(
                    'Contexto del ajuste (ej: se devolvió efectivo, corrección de captura, etc.):',
                    'Corrección manual de saldo'
                );
                if (contexto === null) return;

                const justificacion = prompt('Justificación del ajuste (obligatoria):', '');
                if (justificacion === null) return;
                if (!justificacion.trim() || justificacion.trim().length < 6) {
                    alert('La justificación es obligatoria y debe tener al menos 6 caracteres.');
                    return;
                }

                fetch('ajustar_saldo_cliente.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({
                            'Content-Type': 'application/json'
                        }),
                        body: JSON.stringify({
                            id_cliente: id,
                            nuevo_saldo: nuevoSaldo,
                            contexto: contexto.trim() || 'Corrección manual de saldo',
                            justificacion: justificacion.trim()
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const dif = parseFloat(data.diferencia || 0);
                            alert(`✅ Saldo actualizado correctamente.\n\nAnterior: $${parseFloat(data.saldo_anterior || 0).toFixed(2)}\nNuevo: $${parseFloat(data.saldo_nuevo || 0).toFixed(2)}\nDiferencia: $${dif.toFixed(2)}`);
                            actualizarListaClientes(DOM.buscarClienteLibreta.value.trim(), true);
                            if (DOM.detalleCliente.style.display === 'block') {
                                verHistorial(id, nombre);
                            }
                        } else {
                            alert('❌ Error: ' + (data.message || 'No se pudo ajustar el saldo'));
                        }
                    })
                    .catch(err => {
                        console.error('Error ajustando saldo:', err);
                        alert('❌ Error al ajustar saldo');
                    });
            }

            function verHistorial(id, nombre, saldoActual = null, limiteCredito = null, triggerBtn = null) {
                // Si se proporcionó el botón desencadenante, insertar el panel justo debajo del cliente en la lista
                if (triggerBtn && triggerBtn.closest) {
                    const clienteCard = triggerBtn.closest('.cliente-card');
                    if (clienteCard && clienteCard.parentNode) {
                        clienteCard.insertAdjacentElement('afterend', DOM.detalleCliente);
                    }
                }

                const nombreDetalleEl = document.getElementById('nombreDetalle');
                const tablaHistorialEl = document.getElementById('tablaHistorial');
                if (!DOM.detalleCliente || !nombreDetalleEl || !tablaHistorialEl) {
                    console.warn('No se encontraron nodos del detalle de libreta para refrescar historial');
                    return;
                }

                DOM.detalleCliente.style.display = 'block';
                nombreDetalleEl.innerText = `Historial de ${nombre}`;
                const saldoTexto = saldoActual !== null ? `$${parseFloat(saldoActual || 0).toFixed(2)}` : '...';
                const limiteTexto = limiteCredito !== null ? `$${parseFloat(limiteCredito || 0).toFixed(2)}` : '...';
                tablaHistorialEl.innerHTML = `
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:14px;">
                        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px;">
                            <div style="font-size:0.78rem; color:#9a3412;">Saldo actual</div>
                            <div style="font-size:1.05rem; font-weight:800; color:#c2410c;">${saldoTexto}</div>
                        </div>
                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px;">
                            <div style="font-size:0.78rem; color:#1d4ed8;">Límite crédito</div>
                            <div style="font-size:1.05rem; font-weight:800; color:#1e40af;">${limiteTexto}</div>
                        </div>
                    </div>
                    <p style="margin: 0; color: #64748b;">Cargando historial...</p>
                `;

                fetch(`obtener_historial.php?id=${encodeURIComponent(id)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.error) {
                            document.getElementById('tablaHistorial').innerHTML = `<p style="color: #dc2626;">❌ ${nuvi_escaparHtml(data.error)}</p>`;
                            return;
                        }

                        if (!Array.isArray(data) || data.length === 0) {
                            document.getElementById('tablaHistorial').innerHTML = `
                                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:14px;">
                                    <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px;">
                                        <div style="font-size:0.78rem; color:#9a3412;">Saldo actual</div>
                                        <div style="font-size:1.05rem; font-weight:800; color:#c2410c;">${saldoTexto}</div>
                                    </div>
                                    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px;">
                                        <div style="font-size:0.78rem; color:#1d4ed8;">Límite crédito</div>
                                        <div style="font-size:1.05rem; font-weight:800; color:#1e40af;">${limiteTexto}</div>
                                    </div>
                                </div>
                                <p style="margin: 0; color: #64748b;">Sin movimientos todavía.</p>
                            `;
                            return;
                        }

                        document.getElementById('tablaHistorial').innerHTML = `
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:14px;">
                                <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px;">
                                    <div style="font-size:0.78rem; color:#9a3412;">Saldo actual</div>
                                    <div style="font-size:1.05rem; font-weight:800; color:#c2410c;">${saldoTexto}</div>
                                </div>
                                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px;">
                                    <div style="font-size:0.78rem; color:#1d4ed8;">Límite crédito</div>
                                    <div style="font-size:1.05rem; font-weight:800; color:#1e40af;">${limiteTexto}</div>
                                </div>
                            </div>
                            ${data.map(mov => {
                            const ajuste = mov.detalle_ajuste || null;
                            const esAjusteQueReduce = ajuste &&
                                ['AJUSTE_SALDO', 'CORRECCION_RECARGO_SEMANAL_V1'].includes(ajuste.tipo) &&
                                Number(ajuste.nuevo) < Number(ajuste.anterior);
                            const esAbono = mov.tipo_movimiento === 'abono' || esAjusteQueReduce;
                            const color = esAbono ? '#059669' : '#c2410c';
                            const fondo = esAbono ? '#ecfdf5' : '#fff7ed';
                            const borde = esAbono ? '#a7f3d0' : '#fed7aa';
                            const signo = esAbono ? '-' : '+';
                            const ventaTexto = mov.id_venta ? ` · Venta #${mov.id_venta}` : '';
                            const fecha = mov.fecha_movimiento ? new Date(mov.fecha_movimiento).toLocaleString('es-MX') : '';
                            let descripcionMovimiento = mov.notas || 'Sin nota';
                            let tituloMovimiento = mov.tipo_movimiento || 'movimiento';

                            if (ajuste && ajuste.tipo) {
                                if (ajuste.tipo === 'AJUSTE_SALDO') {
                                    descripcionMovimiento = ajuste.contexto || 'Ajuste manual de saldo';
                                    tituloMovimiento = 'ajuste de saldo';
                                } else if (ajuste.tipo === 'RECARGO_DIARIO') {
                                    descripcionMovimiento = 'Recargo diario histórico';
                                    tituloMovimiento = 'recargo diario';
                                } else if (ajuste.tipo === 'INTERES_SIMPLE_V2') {
                                    const semanasDesde = Number(ajuste.semanas_desde || 0);
                                    const semanasHasta = Number(ajuste.semanas_hasta || 0);
                                    const porcentaje = Number(ajuste.porcentaje || 0);
                                    descripcionMovimiento = `Interés simple: ${porcentaje.toFixed(0)}% sobre $${Number(ajuste.base || 0).toFixed(2)}${semanasHasta ? ` · semanas ${semanasDesde + 1} a ${semanasHasta}` : ''}`;
                                    tituloMovimiento = 'interés por atraso';
                                } else if (ajuste.tipo === 'ABONO_INTERES_V2') {
                                    descripcionMovimiento = ajuste.nota || 'Abono registrado; el plazo de interés se reinició sobre el saldo restante.';
                                    tituloMovimiento = 'abono';
                                } else if (ajuste.tipo === 'DEUDA_INTERES_V2') {
                                    descripcionMovimiento = ajuste.nota || 'Venta fiada registrada.';
                                    tituloMovimiento = 'venta fiada';
                                } else if (ajuste.tipo === 'RECARGO_SEMANAL') {
                                    descripcionMovimiento = `Recargo semanal histórico${ajuste.semanas ? ` (${ajuste.semanas} semana${Number(ajuste.semanas) === 1 ? '' : 's'})` : ''}`;
                                    tituloMovimiento = 'recargo histórico';
                                } else if (ajuste.tipo === 'CORRECCION_RECARGO_SEMANAL_V1') {
                                    descripcionMovimiento = `Crédito por corregir recargos diarios a semanales${ajuste.devolucion ? `: $${Number(ajuste.devolucion).toFixed(2)}` : ''}`;
                                    tituloMovimiento = 'corrección de recargos';
                                } else {
                                    descripcionMovimiento = ajuste.texto || descripcionMovimiento;
                                }
                            }

                            return `
                                <div style="padding: 12px; border: 1px solid ${borde}; background:${fondo}; border-radius:10px; margin-bottom:10px;">
                                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                                        <div>
                                            <div style="font-weight: 700; color: ${color}; text-transform: capitalize;">
                                                ${nuvi_escaparHtml(tituloMovimiento)}${ventaTexto}
                                            </div>
                                            <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">${fecha}</div>
                                            <div style="font-size: 0.85rem; color: #334155; margin-top: 4px;">
                                                ${nuvi_escaparHtml(descripcionMovimiento)}${mov.nombre_usuario ? ' - ' + nuvi_escaparHtml(mov.nombre_usuario) : ''}
                                            </div>
                                        </div>
                                        <div style="font-weight:bold; color:${color}; white-space:nowrap;">
                                            ${signo}$${parseFloat(mov.monto || 0).toFixed(2)}
                                        </div>
                                    </div>
                                    ${Array.isArray(mov.productos) && mov.productos.length ? `
                                        <div style="margin-top:12px; padding:10px; border-radius:10px; background:#f8fafc; border:1px solid #cbd5e1;">
                                            <div style="font-size:0.85rem; font-weight:700; color:#334155; margin-bottom:8px;">Productos prestados / vendidos</div>
                                                            ${mov.productos.map(prod => `
                                                                <div style="display:grid; grid-template-columns:1fr auto auto; gap:8px; align-items:center; font-size:0.84rem; margin-bottom:6px;">
                                                                    <div>${nuvi_escaparHtml(prod.producto_nombre || prod.codigo_barras || `Producto #${prod.id_producto}`)}</div>
                                                                    <div style="text-align:right; color:#0f172a;">x${parseFloat(prod.cantidad || 0)}</div>
                                                                    <div style="text-align:right; color:#0f172a;">$${parseFloat(prod.precio_unitario || 0).toFixed(2)}</div>
                                                                </div>
                                                            `).join('')}
                                        </div>
                                    ` : ''}
                                    ${ajuste && ajuste.tipo ? `
                                        <div style="margin-top:12px; padding:10px; border-radius:10px; background:#eff6ff; border:1px solid #bfdbfe;">
                                            <div style="font-size:0.85rem; font-weight:800; color:#1d4ed8; margin-bottom:6px;">Detalle del ajuste</div>
                                            <div style="font-size:0.84rem; color:#334155; line-height:1.5;">
                                                ${ajuste.anterior !== undefined ? `<div><strong>Anterior:</strong> $${parseFloat(ajuste.anterior || 0).toFixed(2)}</div>` : ''}
                                                ${ajuste.nuevo !== undefined ? `<div><strong>Nuevo:</strong> $${parseFloat(ajuste.nuevo || 0).toFixed(2)}</div>` : ''}
                                                ${ajuste.contexto ? `<div><strong>Contexto:</strong> ${nuvi_escaparHtml(ajuste.contexto)}</div>` : ''}
                                                ${ajuste.justificacion ? `<div><strong>Justificación:</strong> ${nuvi_escaparHtml(ajuste.justificacion)}</div>` : ''}
                                                ${ajuste.dias ? `<div><strong>Días acumulados:</strong> ${ajuste.dias}</div>` : ''}
                                                ${ajuste.cargo ? `<div><strong>Cargo aplicado:</strong> $${parseFloat(ajuste.cargo || 0).toFixed(2)}</div>` : ''}
                                            </div>
                                        </div>
                                    ` : ''}
                                </div>
                            `;
                        }).join('')}
                        `;
                    })
                    .catch(err => {
                        console.error("Error al cargar historial:", err);
                        document.getElementById('tablaHistorial').innerHTML = `<p style="color: #dc2626;">❌ Error al cargar historial</p>`;
                    });
            }

            function seleccionarParaVentaFiada(id, nombre) {
                const filas = document.querySelectorAll("#tabla-carrito tbody tr");
                if (filas.length === 0) {
                    alert("El carrito está vacío");
                    return;
                }

                const total = parseFloat(DOM.granTotalSpan.innerText);
                const notaVenta = DOM.notaVentaInput ? DOM.notaVentaInput.value.trim() : '';

                if (!confirm(`¿Cargar $${total.toFixed(2)} a la cuenta de ${nombre}?`)) return;

                const productosVenta = nuvi_obtenerProductosDelCarrito();
                const payloadFiada = {
                    id_cliente: id,
                    total: total,
                    nota_venta: notaVenta,
                    idempotency_key: nuvi_generarClaveIdempotencia(),
                    productos: productosVenta
                };

                nuvi_intentarGuardarVenta('registrar_venta_fiada.php', payloadFiada, 'venta fiada')
                    .then(respuesta => {
                        if (respuesta.success) {
                            const resultadoFiado = respuesta.resultado || {};
                            // Igual que una venta normal, el comprobante sólo
                            // se habilita cuando el servidor ya confirmó la
                            // deuda. Así nunca se imprime como definitivo algo
                            // que aún está pendiente en la cola offline.
                            if (!respuesta.offline) {
                                nuvi_guardarUltimoTicket(nuvi_crearTicketVenta({
                                    idVenta: resultadoFiado.id_venta,
                                    productos: productosVenta,
                                    totalProductos: total,
                                    metodoPago: 'fiado',
                                    cajero: resultadoFiado.cajero || '',
                                    nota: notaVenta,
                                    fiado: resultadoFiado.resumen_fiado || {
                                        cliente: nombre,
                                        importe_fiado: total,
                                        saldo_total: 0,
                                        saldo_es_foto: false,
                                        configuracion_interes: false
                                    }
                                }));
                            }
                            alert(respuesta.offline
                                ? (respuesta.sesion_expirada
                                    ? "⚠️ La sesión venció, pero la venta fiada quedó guardada en esta caja. Inicia sesión de nuevo; se sincronizará automáticamente."
                                    : "⚠️ Venta fiada guardada localmente. Se sincronizará cuando vuelva la conexión.")
                                : "✅ Venta fiada registrada correctamente.\nEl comprobante está listo en ‘Imprimir último ticket’.");
                            try { nuvi_incrementScores(productosVenta); } catch (e) { console.error('nuvi_incrementScores error', e); }
                            try {
                                limpiarCarrito();
                                cerrarLibreta();
                                precargarProductos();
                                actualizarListaClientes('', true);
                                verHistorial(id, nombre);
                            } catch (uiErr) {
                                console.error('Venta fiada guardada, pero falló el refresco visual:', uiErr);
                            }
                            if (respuesta.sesion_expirada) {
                                window.location.href = 'login.php?motivo=sesion_vencida';
                            }
                        }
                    })
                    .catch(err => {
                        console.error("Error al registrar venta fiada:", err);
                        alert("❌ Error al registrar venta fiada: " + err.message);
                    });
            }

            function cerrarVentasRecientes() {
                DOM.modalVentasRecientes.style.display = 'none';
            }

            function abrirVentasRecientes() {
                DOM.modalVentasRecientes.style.display = 'block';
                cargarVentasRecientes();
            }

            function cargarVentasRecientes() {
                fetch('obtener_ventas_recientes.php')
                    .then(res => {
                        if (!res.ok) {
                            throw new Error(`HTTP ${res.status}`);
                        }
                        return res.text(); // Primero obtener como texto para debug
                    })
                    .then(text => {
                        try {
                            const data = JSON.parse(text);
                            const contenedor = document.getElementById('listaVentasRecientes');

                            // Verificar si hay error
                            if (data.error) {
                                contenedor.innerHTML = `<p style="color: red;">❌ Error del servidor: ${nuvi_escaparHtml(data.error)}</p>`;
                                console.error('Error desde servidor:', data.error);
                                return;
                            }

                            if (!Array.isArray(data) || data.length === 0) {
                                contenedor.innerHTML = '<p>No hay ventas recientes.</p>';
                                return;
                            }

                            let html = '<div style="max-height: 400px; overflow-y: auto;">';
                            data.forEach(venta => {
                                const metodoPago = venta.metodo_pago && venta.metodo_pago.trim() ? venta.metodo_pago : 'N/A';
                                const notaVenta = venta.nota_venta && venta.nota_venta.trim() ? venta.nota_venta : '';
                                const montoTerminal = parseFloat(venta.monto_cobrado_tarjeta);
                                const recargoCliente = parseFloat(venta.recargo_cliente_tarjeta);
                                const nombreTerminal = venta.terminal_nombre && venta.terminal_nombre.trim() ? venta.terminal_nombre.trim() : 'Tarjeta';
                                const detalleTarjeta = metodoPago === 'tarjeta' && Number.isFinite(montoTerminal)
                                    ? `<br><small style="color:#1d4ed8; font-weight:700;">${nuvi_escaparHtml(nombreTerminal)}: $${montoTerminal.toFixed(2)}${Number.isFinite(recargoCliente) ? ` · Comisión cliente: $${recargoCliente.toFixed(2)}` : ''}</small>`
                                    : '';
                                const badgeNota = notaVenta
                                    ? `<span style="display:inline-flex; align-items:center; gap:4px; margin-left:8px; padding:3px 8px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:0.75rem; font-weight:700; border:1px solid #fdba74;">📝 Con nota</span>`
                                    : '';
                                html += `
                                    <div style="border: 1px solid #e2e8f0; padding: 15px; margin-bottom: 10px; border-radius: 8px; background: #f8fafc;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <strong>Venta #${venta.id_venta}</strong>${badgeNota} - ${new Date(venta.fecha_venta).toLocaleString('es-MX')}
                                                <br>Total: $${parseFloat(venta.total).toFixed(2)}
                                                <br><small style="color: #0f766e;">Método: ${nuvi_escaparHtml(metodoPago)}</small>
                                                ${detalleTarjeta}
                                                ${notaVenta ? `<br><small style="color: #7c2d12;">Nota: ${nuvi_escaparHtml(notaVenta)}</small>` : ''}
                                                <br><small style="color: #666;">Categorías: ${nuvi_escaparHtml(venta.categorias || 'N/A')}</small>
                                            </div>
                                            <button onclick="editarVenta(${venta.id_venta})" style="background: #2563eb; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer;">Editar</button>
                                        </div>
                                    </div>
                                `;
                            });
                            html += '</div>';
                            contenedor.innerHTML = html;
                        } catch (parseErr) {
                            console.error('Error parsing JSON:', parseErr, 'Respuesta:', text);
                            document.getElementById('listaVentasRecientes').innerHTML = `<p style="color: red;">❌ Error al procesar respuesta del servidor</p>`;
                        }
                    })
                    .catch(err => {
                        console.error('Error cargando ventas:', err);
                        document.getElementById('listaVentasRecientes').innerHTML = `<p style="color: red;">❌ Error de conexión: ${nuvi_escaparHtml(err.message)}</p>`;
                    });
            }

            let NUVI_EDICION_VENTA_ACTUAL = null;
            const NUVI_METODOS_CORREGIBLES_A_TARJETA = new Set(['efectivo', 'transferencia', 'otro']);

            function nuvi_escaparHtml(valor) {
                return String(valor ?? '').replace(/[&<>'"]/g, caracter => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
                }[caracter]));
            }

            function editarVenta(idVenta) {
                console.log('Abriendo venta para editar, ID:', idVenta);
                if (!idVenta) {
                    alert('❌ Error: ID de venta no válido');
                    return;
                }
                document.getElementById('idVentaEditar').innerText = idVenta;
                DOM.modalEditarVenta.style.display = 'block';

                // Mostrar mensaje de carga
                document.getElementById('contenidoEditarVenta').innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">⏳ Cargando detalles de la venta...</div>';

                // El editor puede abrirse justo después de configurar una
                // terminal. Cargamos la lista antes de presentar la corrección.
                if (!NUVI_CONFIG_COMISIONES.configuracion_lista || NUVI_CONFIG_COMISIONES.terminales.length === 0) {
                    nuvi_cargarConfigComisiones(true).then(() => cargarDetalleVenta(idVenta));
                } else {
                    cargarDetalleVenta(idVenta);
                }
            }

            function cerrarEditarVenta() {
                DOM.modalEditarVenta.style.display = 'none';
                NUVI_EDICION_VENTA_ACTUAL = null;
            }

            function nuvi_productosEdicionSinCambios(productos) {
                const originales = NUVI_EDICION_VENTA_ACTUAL?.productos || [];
                const normalizar = lista => {
                    const acumulados = new Map();
                    lista.forEach(producto => {
                        const id = String(producto.id ?? '');
                        const cantidad = Number(producto.cantidad) || 0;
                        acumulados.set(id, (acumulados.get(id) || 0) + cantidad);
                    });
                    return [...acumulados.entries()]
                        .map(([id, cantidad]) => ({ id, cantidad: Math.round(cantidad * 1000) / 1000 }))
                        .sort((a, b) => a.id.localeCompare(b.id));
                };
                const antes = normalizar(originales);
                const despues = normalizar(productos);
                return antes.length === despues.length && antes.every((producto, indice) =>
                    producto.id === despues[indice].id && Math.abs(producto.cantidad - despues[indice].cantidad) < 0.000001
                );
            }

            function nuvi_actualizarCorreccionTarjetaEditar() {
                const grupo = document.getElementById('correccionTarjetaEditar');
                const metodo = document.getElementById('metodoPagoEditar');
                if (!grupo || !metodo) return;

                const esCorreccionTarjeta = metodo.value === 'tarjeta'
                    && NUVI_METODOS_CORREGIBLES_A_TARJETA.has(NUVI_EDICION_VENTA_ACTUAL?.metodoOriginal);
                grupo.style.display = esCorreccionTarjeta ? 'block' : 'none';

                const bloquearArticulos = metodo.value === 'tarjeta';
                const buscador = document.getElementById('buscarProductoEditar');
                if (buscador) buscador.disabled = bloquearArticulos;
                document.querySelectorAll('#tbodyEditarVenta input, #tbodyEditarVenta button').forEach(elemento => {
                    elemento.disabled = bloquearArticulos;
                    elemento.style.cursor = bloquearArticulos ? 'not-allowed' : '';
                });

                if (!esCorreccionTarjeta) return;

                const selectorTerminal = document.getElementById('terminalPagoEditar');
                const montoInput = document.getElementById('montoCobradoTarjetaEditar');
                const resumen = document.getElementById('resumenCorreccionTarjetaEditar');
                const terminal = NUVI_CONFIG_COMISIONES.terminales.find(item => Number(item.id) === Number(selectorTerminal?.value));
                const total = Number(NUVI_EDICION_VENTA_ACTUAL?.total || 0);
                const montoCobrado = Number(montoInput?.value || 0);

                if (!terminal) {
                    resumen.textContent = 'No hay una terminal activa disponible. Cierra el editor, verifica la configuración de terminales y vuelve a abrir la venta.';
                    resumen.style.color = '#b91c1c';
                    return;
                }
                if (!Number.isFinite(montoCobrado) || montoCobrado <= 0) {
                    resumen.textContent = 'Indica el importe que realmente marcó la terminal.';
                    resumen.style.color = '#b91c1c';
                    return;
                }
                if (montoCobrado + 0.009 < total) {
                    resumen.textContent = `El cobro real no puede ser menor al total de productos ($${total.toFixed(2)}).`;
                    resumen.style.color = '#b91c1c';
                    return;
                }

                const sugerido = nuvi_calcularCobroTerminal(total, terminal);
                const tasaEfectiva = (Number(terminal.comision_porcentaje) / 100) * (1 + (Number(terminal.iva_porcentaje) / 100));
                const comisionReal = (montoCobrado * tasaEfectiva) + Number(terminal.cargo_fijo || 0);
                const recargoCliente = montoCobrado - total;
                const neto = montoCobrado - comisionReal;
                const absorbido = Math.max(0, total - neto);
                const detalleAbsorbido = absorbido > 0.009
                    ? ` En esta excepción el negocio absorbió aprox. $${absorbido.toFixed(2)} de comisión.`
                    : '';
                resumen.textContent = `Cobro sugerido con ${terminal.nombre}: $${sugerido.montoCobrado.toFixed(2)}. Cobro real: $${montoCobrado.toFixed(2)}; recargo al cliente: $${recargoCliente.toFixed(2)}.${detalleAbsorbido}`;
                resumen.style.color = '#1e3a8a';
            }

            function cargarDetalleVenta(idVenta) {
                fetch(`obtener_detalle_venta.php?id_venta=${idVenta}`)
                    .then(res => res.json())
                    .then(data => {
                        console.log('Datos cargados:', data);
                        const contenedor = document.getElementById('contenidoEditarVenta');

                        // Verificar si hay error
                        if (data.error) {
                            contenedor.innerHTML = `<p style="color: red;">❌ Error: ${nuvi_escaparHtml(data.error)}</p>`;
                            return;
                        }

                        const venta = data.venta || {};
                        const detalles = Array.isArray(data.detalles) ? data.detalles : null;

                        if (!detalles) {
                            console.error('Formato inesperado en detalle:', data);
                            contenedor.innerHTML = `<p style="color: red;">❌ Error: formato de datos inválido</p>`;
                            return;
                        }

                        // Verificar si hay productos
                        if (detalles.length === 0) {
                            contenedor.innerHTML = `<p style="color: orange;">⚠️ No se encontraron productos en esta venta</p>`;
                            return;
                        }

                        const metodoPagoActual = venta.metodo_pago && String(venta.metodo_pago).trim() ? venta.metodo_pago : 'efectivo';
                        const avisoTarjeta = metodoPagoActual === 'tarjeta'
                            ? '<div style="margin:0 0 14px;padding:10px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:.9rem;"><strong>Venta con tarjeta:</strong> puedes convertirla en devolución total para restaurar inventario. El reembolso en la terminal se realiza por separado.</div>'
                            : '';
                        const terminalesEditar = NUVI_CONFIG_COMISIONES.terminales || [];
                        const opcionesTerminalesEditar = terminalesEditar.map(terminal =>
                            `<option value="${Number(terminal.id)}">${nuvi_escaparHtml(terminal.nombre)}${Number(terminal.es_predeterminada) === 1 ? ' (predeterminada)' : ''}</option>`
                        ).join('');
                        const tarjetaPermitida = NUVI_METODOS_CORREGIBLES_A_TARJETA.has(metodoPagoActual) || metodoPagoActual === 'tarjeta';
                        const nombreMetodoOriginal = metodoPagoActual.charAt(0).toUpperCase() + metodoPagoActual.slice(1);
                        const totalVentaOriginal = Number(venta.total) || 0;
                        NUVI_EDICION_VENTA_ACTUAL = {
                            idVenta: Number(idVenta),
                            metodoOriginal: metodoPagoActual,
                            total: totalVentaOriginal,
                            productos: detalles.map(detalle => ({
                                id: String(detalle.producto_id_real),
                                cantidad: Number(detalle.cantidad) || 0
                            }))
                        };

                        let html = `
                            <div style="margin-bottom: 20px;">
                                ${avisoTarjeta}
                                <div style="display: grid; gap: 12px; margin-bottom: 15px;">
                                    <div>
                                        <label style="display:block; font-weight: 600; margin-bottom: 6px;">Método de pago</label>
                                        <select id="metodoPagoEditar" style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc; font-size: 0.95rem;">
                                            <option value="efectivo" ${metodoPagoActual === 'efectivo' ? 'selected' : ''}>Efectivo</option>
                                            <option value="tarjeta" ${metodoPagoActual === 'tarjeta' ? 'selected' : ''} ${tarjetaPermitida ? '' : 'disabled'}>Tarjeta${tarjetaPermitida ? '' : ' (requiere una corrección especial)'}</option>
                                            <option value="transferencia" ${metodoPagoActual === 'transferencia' ? 'selected' : ''}>Transferencia</option>
                                            <option value="otro" ${metodoPagoActual === 'otro' ? 'selected' : ''}>Otro</option>
                                            <option value="fiado" ${metodoPagoActual === 'fiado' ? 'selected' : ''}>Fiado</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="correccionTarjetaEditar" style="display:none; margin:-2px 0 15px; padding:12px; border-radius:8px; background:#eff6ff; border:1px solid #93c5fd;">
                                    <strong style="display:block; color:#1e3a8a; margin-bottom:6px;">Corrección de “${nuvi_escaparHtml(nombreMetodoOriginal)}” a “Tarjeta”</strong>
                                    <p style="margin:0 0 12px; font-size:.88rem; color:#475569;">No cambia artículos ni existencias. Registra el importe que ya procesó físicamente la terminal.</p>
                                    <label style="display:block; font-weight:600; margin-bottom:5px;">Terminal utilizada</label>
                                    <select id="terminalPagoEditar" style="width:100%; padding:9px; border-radius:5px; border:1px solid #93c5fd; margin-bottom:10px;" ${terminalesEditar.length ? '' : 'disabled'}>
                                        ${opcionesTerminalesEditar || '<option value="">No hay terminal activa</option>'}
                                    </select>
                                    <label style="display:block; font-weight:600; margin-bottom:5px;">Cobro real ya realizado en terminal ($)</label>
                                    <input id="montoCobradoTarjetaEditar" type="number" min="0" step="0.01" value="${totalVentaOriginal.toFixed(2)}" style="width:100%; padding:9px; border-radius:5px; border:1px solid #93c5fd; box-sizing:border-box;">
                                    <div id="resumenCorreccionTarjetaEditar" style="margin-top:9px; font-size:.84rem; line-height:1.4;"></div>
                                </div>
                                <input type="text" id="buscarProductoEditar" placeholder="Buscar producto para agregar..." style="width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc; font-size: 0.95rem;">
                                <div id="sugerenciasEditar" style="display: none; background: white; border: 1px solid #ddd; max-height: 150px; overflow-y: auto; margin-top: 5px; border-radius: 5px;"></div>
                            </div>
                            <div style="background: #f0f4f8; padding: 10px; margin-bottom: 15px; border-radius: 5px; border-left: 4px solid #2563eb;">
                                <strong>${detalles.length} producto(s) en esta venta</strong>
                            </div>
                            <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #2563eb; color: white;">
                                        <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Producto</th>
                                        <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Categoría</th>
                                        <th style="padding: 12px; border: 1px solid #ddd; text-align: center; width: 80px;">Cant.</th>
                                        <th style="padding: 12px; border: 1px solid #ddd; text-align: right; width: 80px;">Precio</th>
                                        <th style="padding: 12px; border: 1px solid #ddd; text-align: right; width: 80px;">Subtotal</th>
                                        <th style="padding: 12px; border: 1px solid #ddd; text-align: center; width: 70px;">Quitar</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyEditarVenta">
                        `;

                        detalles.forEach(detalle => {
                            const categoriaNombre = detalle.categoria && detalle.categoria.trim() ? detalle.categoria : '(Sin categoría)';
                            const colorCategoria = detalle.categoria && detalle.categoria.trim() ? '#f0f4ff' : '#fff3cd';
                            const colorTextoCategoria = detalle.categoria && detalle.categoria.trim() ? '#1e40af' : '#856404';
                            const idProducto = detalle.producto_id_real;
                            const subtotalRegistrado = Number(detalle.subtotal);
                            const subtotalInicial = Number.isFinite(subtotalRegistrado) && subtotalRegistrado > 0
                                ? subtotalRegistrado
                                : Number(detalle.cantidad || 0) * Number(detalle.precio_unitario || 0);

                            html += `
                                <tr data-id="${Number(idProducto)}" data-precio="${parseFloat(detalle.precio_unitario).toFixed(2)}" data-subtotal-original="${subtotalInicial.toFixed(2)}" data-nombre="${nuvi_escaparHtml(detalle.nombre)}">
                                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: 500;">${nuvi_escaparHtml(detalle.nombre)}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd; background: ${colorCategoria}; font-weight: 500; color: ${colorTextoCategoria};">${nuvi_escaparHtml(categoriaNombre)}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><input type="number" step="0.001" value="${detalle.cantidad}" min="0" style="width: 100%; text-align: center; padding: 5px; border: 1px solid #ddd; border-radius: 3px;"></td>
                                    <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">$${parseFloat(detalle.precio_unitario).toFixed(2)}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd; text-align: right; font-weight: bold; color: #059669;" class="subtotal-editar">$${subtotalInicial.toFixed(2)}</td>
                                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><button onclick="eliminarProductoEditar(this)" style="background: #dc2626; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-weight: 600;">✕</button></td>
                                </tr>
                            `;
                        });

                        html += `
                                </tbody>
                            </table>
                            </div>
                            <div style="margin-top: 20px; text-align: right; background: #f0f4f8; padding: 15px; border-radius: 8px; border-left: 4px solid #2563eb;">
                                <strong style="font-size: 1.2rem; color: #1e293b;">Total: $<span id="totalEditarVenta">0.00</span></strong>
                            </div>
                            <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                                <button onclick="guardarEdicionVenta(${idVenta})" style="background: #28a745; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; flex: 1; min-width: 150px;">✓ Guardar Cambios</button>
                                <button onclick="cerrarEditarVenta()" style="background: #6c757d; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; flex: 1; min-width: 150px;">✕ Cancelar</button>
                            </div>
                        `;

                        contenedor.innerHTML = html;
                        // Al abrir una venta se respeta el subtotal que se
                        // registró. Es esencial para granel por monto, donde
                        // una cantidad mostrada a 3 decimales no siempre se
                        // multiplica exactamente al importe cobrado.
                        calcularTotalEditar(true);

                        const metodoPagoEditar = document.getElementById('metodoPagoEditar');
                        const terminalPagoEditar = document.getElementById('terminalPagoEditar');
                        const montoCobradoTarjetaEditar = document.getElementById('montoCobradoTarjetaEditar');
                        if (metodoPagoEditar) metodoPagoEditar.addEventListener('change', nuvi_actualizarCorreccionTarjetaEditar);
                        if (terminalPagoEditar) terminalPagoEditar.addEventListener('change', nuvi_actualizarCorreccionTarjetaEditar);
                        if (montoCobradoTarjetaEditar) montoCobradoTarjetaEditar.addEventListener('input', nuvi_actualizarCorreccionTarjetaEditar);
                        nuvi_actualizarCorreccionTarjetaEditar();

                        // Agregar event listeners a los inputs existentes
                        document.querySelectorAll('#tbodyEditarVenta input').forEach(input => {
                            input.addEventListener('input', calcularTotalEditar);
                        });

                        // Event listener para buscar productos
                        document.getElementById('buscarProductoEditar').addEventListener('input', function() {
                            const termino = this.value.trim();
                            if (termino.length < 2) {
                                document.getElementById('sugerenciasEditar').style.display = 'none';
                                return;
                            }

                            const coincidencias = busquedaInteligente(termino, PRODUCTOS_LOCALES).slice(0, 8);
                            const sugerenciasDiv = document.getElementById('sugerenciasEditar');
                            sugerenciasDiv.innerHTML = '';

                            if (coincidencias.length === 0) {
                                sugerenciasDiv.innerHTML = '<div style="padding: 10px; color: #666;">No se encontraron productos...</div>';
                            } else {
                                coincidencias.forEach(p => {
                                    const div = document.createElement('div');
                                    div.style.padding = '12px';
                                    div.style.cursor = 'pointer';
                                    div.style.borderBottom = '1px solid #eee';
                                    div.style.display = 'flex';
                                    div.style.justifyContent = 'space-between';
                                    div.style.alignItems = 'center';
                                    div.style.transition = 'background 0.2s';
                                    div.innerHTML = `
                                        <div>
                                        <div style="font-weight: 600; color: #1e293b;">${nuvi_escaparHtml(p.nombre)}</div>
                                        <div style="font-size: 0.85rem; color: #64748b;">${nuvi_escaparHtml(p.categoria || 'Sin categoría')}</div>
                                        </div>
                                        <div style="text-align: right; font-weight: 600; color: #2563eb;">$${parseFloat(p.precio_venta).toFixed(2)}</div>
                                    `;
                                    div.addEventListener('mouseenter', () => {
                                        div.style.background = document.documentElement.classList.contains('nuvi-tema-oscuro')
                                            ? '#253247'
                                            : '#f0f4f8';
                                    });
                                    div.addEventListener('mouseleave', () => {
                                        div.style.background = document.documentElement.classList.contains('nuvi-tema-oscuro')
                                            ? '#172033'
                                            : 'white';
                                    });
                                    div.addEventListener('click', () => agregarProductoEditar(p));
                                    sugerenciasDiv.appendChild(div);
                                });
                            }

                            sugerenciasDiv.style.display = 'block';
                        });
                    })
                    .catch(err => {
                        console.error('Error cargando detalle:', err);
                        const contenedor = document.getElementById('contenidoEditarVenta');
                        contenedor.innerHTML = `<p style="color: red;">❌ Error al cargar detalles de la venta: ${nuvi_escaparHtml(err.message)}</p>`;
                    });
            }

            function agregarProductoEditar(producto) {
                const tbody = document.getElementById('tbodyEditarVenta');
                const tr = document.createElement('tr');
                tr.setAttribute('data-id', producto.id);
                tr.setAttribute('data-precio', parseFloat(producto.precio_venta).toFixed(2));
                tr.innerHTML = `
                    <td style="padding: 10px; border: 1px solid #ddd;">${nuvi_escaparHtml(producto.nombre)}</td>
                    <td style="padding: 10px; border: 1px solid #ddd; background: #f0f4ff; font-weight: 500; color: #1e40af;">${nuvi_escaparHtml(producto.categoria || 'N/A')}</td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><input type="number" step="0.001" value="1" style="width: 100%; text-align: center; padding: 5px;"></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: right;">$${parseFloat(producto.precio_venta).toFixed(2)}</td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: right; font-weight: bold; color: #059669;" class="subtotal-editar">$${parseFloat(producto.precio_venta).toFixed(2)}</td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><button onclick="eliminarProductoEditar(this)" style="background: #dc2626; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-weight: 600;">✕</button></td>
                `;
                tbody.appendChild(tr);
                calcularTotalEditar();
                document.getElementById('buscarProductoEditar').value = '';
                document.getElementById('sugerenciasEditar').style.display = 'none';

                // Event listener para recalcular subtotal cuando cambia la cantidad
                tr.querySelector('input').addEventListener('input', () => calcularTotalEditar());
            }

            function eliminarProductoEditar(btn) {
                btn.closest('tr').remove();
                calcularTotalEditar();
            }

            function calcularTotalEditar(conservarSubtotalRegistrado = false) {
                let total = 0;
                document.querySelectorAll('#tbodyEditarVenta tr').forEach(tr => {
                    const cantidad = parseFloat(tr.querySelector('input').value) || 0;

                    // Si la cantidad es 0, mostrar visualmente que será eliminado
                    if (cantidad === 0) {
                        tr.style.opacity = '0.5';
                        tr.style.textDecoration = 'line-through';
                    } else {
                        tr.style.opacity = '1';
                        tr.style.textDecoration = 'none';
                    }

                    // Ahora el precio está en td[3] porque agregamos la columna de categoría
                    const precio = parseFloat(tr.cells[3].innerText.replace('$', ''));
                    const subtotalGuardado = parseFloat(tr.dataset.subtotalOriginal || '');
                    const subtotal = conservarSubtotalRegistrado && Number.isFinite(subtotalGuardado)
                        ? subtotalGuardado
                        : cantidad * precio;
                    tr.querySelector('.subtotal-editar').innerText = `$${subtotal.toFixed(2)}`;
                    total += subtotal;
                });
                document.getElementById('totalEditarVenta').innerText = total.toFixed(2);
            }

            function guardarEdicionVenta(idVenta) {
                const productos = [];
                document.querySelectorAll('#tbodyEditarVenta tr').forEach(tr => {
                    const id = tr.getAttribute('data-id');
                    const cantidad = parseFloat(tr.querySelector('input').value) || 0;
                    // Ahora el precio está en td[3] porque incluimos la columna de categoría
                    const precio = parseFloat(tr.cells[3].innerText.replace('$', ''));

                    // Solo incluir productos con cantidad > 0
                    if (cantidad > 0) {
                        productos.push({
                            id: id,
                            cantidad: cantidad,
                            precio: precio
                        });
                    }
                });

                const metodoPagoEditar = document.getElementById('metodoPagoEditar')?.value || 'efectivo';
                if (metodoPagoEditar === 'tarjeta') {
                    const estadoEdicion = NUVI_EDICION_VENTA_ACTUAL;
                    if (!estadoEdicion || estadoEdicion.idVenta !== Number(idVenta) || !NUVI_METODOS_CORREGIBLES_A_TARJETA.has(estadoEdicion.metodoOriginal)) {
                        alert('Sólo es posible corregir a tarjeta una venta capturada como efectivo, transferencia u “Otro”.');
                        return;
                    }
                    if (!productos.length || !nuvi_productosEdicionSinCambios(productos)) {
                        alert('Para corregir el método a tarjeta, conserva los mismos productos y cantidades. Esta acción no modifica inventario; si necesitas corregir artículos, deja el método original y edítalos por separado.');
                        return;
                    }

                    const selectorTerminal = document.getElementById('terminalPagoEditar');
                    const montoInput = document.getElementById('montoCobradoTarjetaEditar');
                    const idTerminal = Number(selectorTerminal?.value || 0);
                    const montoCobrado = Number(montoInput?.value || 0);
                    const totalProductos = Number(estadoEdicion.total || 0);
                    const nombreTerminal = selectorTerminal?.selectedOptions?.[0]?.textContent || 'la terminal seleccionada';
                    if (!idTerminal || !Number.isFinite(montoCobrado) || montoCobrado + 0.009 < totalProductos) {
                        alert('Selecciona una terminal e indica el importe real, igual o mayor al total de productos.');
                        return;
                    }

                    const recargo = montoCobrado - totalProductos;
                    const confirmar = confirm(
                        `¿Registrar esta venta como tarjeta?\n\nTerminal: ${nombreTerminal}\nTotal de productos: $${totalProductos.toFixed(2)}\nCobro real ya realizado: $${montoCobrado.toFixed(2)}\nRecargo al cliente: $${recargo.toFixed(2)}\n\nNo se modificarán productos ni existencias.`
                    );
                    if (!confirmar) return;

                    fetch('corregir_metodo_tarjeta.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify({
                            id_venta: idVenta,
                            id_terminal_pago: idTerminal,
                            monto_cobrado_tarjeta: montoCobrado
                        })
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (!data.success) {
                                alert('❌ Error: ' + (data.message || 'No se pudo corregir la venta.'));
                                return;
                            }
                            const absorbido = Number(data.comision_absorbida_negocio || 0);
                            const notaAbsorbida = absorbido > 0.009
                                ? `\nComisión absorbida por el negocio en esta excepción: $${absorbido.toFixed(2)}`
                                : '';
                            alert(`✓ Venta corregida a tarjeta.\n\nTerminal: ${data.terminal_nombre}\nCobro registrado: $${Number(data.monto_cobrado_tarjeta).toFixed(2)}\nComisión cubierta por cliente: $${Number(data.recargo_cliente_tarjeta).toFixed(2)}${notaAbsorbida}`);
                            cerrarEditarVenta();
                            cargarVentasRecientes();
                        })
                        .catch(err => {
                            console.error('Error corrigiendo método a tarjeta:', err);
                            alert('❌ Error al corregir el método de pago: ' + err.message);
                        });
                    return;
                }

                if (productos.length === 0) {
                    const confirmarEliminacion = confirm(
                        'Esta venta quedará sin productos.\n\n¿Deseas convertir esto en una devolución total?\n\nEl inventario se restaurará automáticamente y la venta quedará registrada como devuelta.'
                    );

                    if (!confirmarEliminacion) {
                        return;
                    }

                    const motivoDevolucion = prompt(
                        'Motivo de la devolución total:\n\nPuedes dejarlo vacío si no quieres escribir nada.',
                        ''
                    );

                    if (motivoDevolucion === null) {
                        return;
                    }

                    fetch('editar_venta.php', {
                            method: 'POST',
                            headers: nuvi_headersMutacion({
                                'Content-Type': 'application/json'
                            }),
                            body: JSON.stringify({
                                id_venta: idVenta,
                                eliminar_venta: true,
                                motivo_devolucion: motivoDevolucion.trim(),
                                productos: []
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                alert(`✓ Venta eliminada correctamente.\n\nSe tomó como devolución total por $${data.total_original.toFixed(2)}.`);
                                cerrarEditarVenta();
                                cargarVentasRecientes();
                                precargarProductos();
                            } else {
                                alert('❌ Error: ' + data.message);
                            }
                        })
                        .catch(err => {
                            console.error('Error eliminando venta:', err);
                            alert('❌ Error al eliminar la venta: ' + err.message);
                        });
                    return;
                }

                fetch('editar_venta.php', {
                        method: 'POST',
                        headers: nuvi_headersMutacion({
                            'Content-Type': 'application/json'
                        }),
                        body: JSON.stringify({
                            id_venta: idVenta,
                            metodo_pago: metodoPagoEditar,
                            productos: productos
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(`✓ Venta editada exitosamente.\n\nTotal original: $${data.total_original.toFixed(2)}\nTotal nuevo: $${data.total_nuevo.toFixed(2)}\nDiferencia: $${data.diferencia.toFixed(2)}`);
                            cerrarEditarVenta();
                            cargarVentasRecientes(); // Recargar la lista
                            precargarProductos(); // Actualizar productos si cambió stock
                        } else {
                            alert('❌ Error: ' + data.message);
                        }
                    })
                    .catch(err => {
                        console.error('Error guardando edición:', err);
                        alert('❌ Error al guardar cambios: ' + err.message);
                    });
            }

            // Ejemplo de lógica para resaltar stock bajo
            function verificarStock(producto) {
                const stockMinimo = 1;
                if (producto.stock <= stockMinimo) {
                    return `<span class="badge-alerta" style="color: red; font-weight: bold;">¡Reabastecer! (${producto.stock})</span>`;
                }
                return `<span>Stock: ${producto.stock}</span>`;
            }
        </script>

    </body>

    </html>
