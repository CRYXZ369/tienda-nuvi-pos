<?php
require_once('auth.php');
require_once("app_header.php");
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Registro de Productos - Tienda Nuvi</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            margin: 0;
            padding: 14px;
            background-color: #f4f4f4;
        }

        .contenedor {
            background: white;
            padding: 20px;
            border-radius: 14px;
            max-width: 500px;
            margin: auto;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
        }

        input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 10px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background-color: #218838;
        }

        .sugerencia-precio {
            margin-top: -4px;
            margin-bottom: 12px;
            padding: 10px;
            border: 1px solid #cfe8d2;
            background: #f0fff4;
            border-radius: 8px;
            font-size: 0.92rem;
            color: #166534;
        }

        .sugerencia-precio strong {
            color: #14532d;
        }

        .sugerencia-botones {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .btn-margen {
            width: auto;
            padding: 8px 10px;
            border-radius: 8px;
            background: #0ea5e9;
            font-size: 0.84rem;
            font-weight: 700;
        }

        .btn-margen:hover {
            background: #0284c7;
        }

        /* Este módulo usa tarjetas propias; el tema global no alcanza este
           aviso y antes dejaba texto verde sobre fondo claro en modo oscuro. */
        html.nuvi-tema-oscuro .sugerencia-precio {
            background: #12332b;
            border-color: #248567;
            color: #d1fae5;
        }

        html.nuvi-tema-oscuro .sugerencia-precio strong {
            color: #ffffff;
        }

        html.nuvi-tema-oscuro .btn-margen {
            background: #0369a1;
            color: #f8fafc;
        }

        html.nuvi-tema-oscuro .btn-margen:hover {
            background: #0284c7;
        }

        .ayuda-unidad {
            margin: -2px 0 10px;
            padding: 9px 10px;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e3a8a;
            font-size: .86rem;
            line-height: 1.4;
        }

        html.nuvi-tema-oscuro .ayuda-unidad {
            background: #1d3557;
            border-color: #3b82f6;
            color: #dbeafe;
        }
    </style>
    <?php renderAppHeaderStyles(); ?>
</head>

<body>
    <?php renderAppHeader('registro', true); ?>

    <div class="contenedor">
        <h2>📦 Registro de Nuevo Producto</h2>

        <button id="btn-escaner" type="button" style="width: 100%; padding: 10px; margin-bottom: 10px; background-color: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer;">
            📷 Escanear Código de Barras
        </button>

        <div id="reader" style="width: 100%; max-width: 500px; margin: auto; display: none; border: 2px solid #007bff; border-radius: 8px; overflow: hidden; margin-bottom: 20px;"></div>

        <form action="guardar_producto.php" method="POST">
            <label>Código de Barras:</label>
            <input type="text" name="codigo_barras" id="codigo_barras" placeholder="Escanea o escribe el código de barras" autofocus required>

            <label>Nombre del Producto:</label>
            <input type="text" name="nombre" id="nombre_producto" placeholder="Ej. Coca Cola 3L, Jamón de Pavo 5k,  " required>

            <label>Unidad de Medida:</label>
            <select name="unidad_medida" id="unidad_medida" style="width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px;">
                <option value="pza">Pieza (pza)</option>
                <option value="kg">Kilogramo (kg)</option>
                <option value="g">Gramo (g)</option>
                <option value="L">Litro (L)</option>
                <option value="ml">Mililitro (ml)</option>
            </select>
            <p class="ayuda-unidad" id="ayuda-unidad">Piezas: registra el stock y los precios por pieza.</p>

            <label>Marca:</label>
            <input type="text" name="marca" placeholder="De preferencia Provedor Ej. Bimbo, Modelez, etc." required>

            <label id="label-precio-compra">Precio de Compra (por pieza):</label>
            <input type="number" step="0.0001" name="precio_compra" id="precio_compra" placeholder="0.00" required>

            <label id="label-precio-venta">Precio de Venta (por pieza):</label>
            <input type="number" step="0.0001" name="precio_venta" id="precio_venta" placeholder="0.00" required>
            <div class="sugerencia-precio" id="sugerencia-precio-box">
                Precio automático (25%): <strong id="precio-sugerido-base">$0</strong>. Puedes ajustarlo con los botones si deseas otro margen.
                <div class="sugerencia-botones">
                    <button type="button" class="btn-margen" data-margen="25">Aplicar 25%</button>
                    <button type="button" class="btn-margen" data-margen="30">Aplicar 30%</button>
                    <button type="button" class="btn-margen" data-margen="35">Aplicar 35%</button>
                    <button type="button" class="btn-margen" data-margen="50">Aplicar 50%</button>
                </div>
            </div>

            <label id="label-stock">Stock Inicial (pza):</label>
            <input type="number" step="0.001" name="stock" id="stock" placeholder="Cantidad disponible en piezas" required>



            <label>Categoría:</label>
            <input type="text" name="categoria" placeholder="Ej. Pan, Pan Dulce, Bebidas, Bebidas Alcoholicas, Carnes Frías, etc." required>

            <button type="submit">Guardar Producto</button>
        </form>
    </div>

    <script>
        let html5QrCode;
        let escanerActivo = false;
        let bloqueado = false; // 🚦 3. Definimos la bandera del semáforo aquí
        let cargadorEscaner = null;

        const btnEscaner = document.getElementById('btn-escaner');
        const readerDiv = document.getElementById('reader');
        const inputRegistro = document.getElementById('codigo_barras');
        const precioCompraInput = document.getElementById('precio_compra');
        const precioVentaInput = document.getElementById('precio_venta');
        const unidadMedidaInput = document.getElementById('unidad_medida');
        const ayudaUnidad = document.getElementById('ayuda-unidad');
        const labelPrecioCompra = document.getElementById('label-precio-compra');
        const labelPrecioVenta = document.getElementById('label-precio-venta');
        const labelStock = document.getElementById('label-stock');
        const stockInput = document.getElementById('stock');
        const sugeridoBaseText = document.getElementById('precio-sugerido-base');
        const botonesMargen = document.querySelectorAll('.btn-margen');

        function esUnidadDePrecioFraccionario(unidad) {
            return ['g', 'ml'].includes(String(unidad || '').toLowerCase());
        }

        function calcularPrecioSugerido(precioCompra, margenPorciento) {
            const costo = parseFloat(precioCompra) || 0;
            if (costo <= 0) return 0;
            const bruto = costo * (1 + (margenPorciento / 100));
            // Por pieza/kg/L se redondea al peso superior. Por gramo/ml se
            // conservan cuatro decimales, porque redondear a $1 alteraría por
            // completo el precio real por kilo/litro.
            return esUnidadDePrecioFraccionario(unidadMedidaInput.value)
                ? Math.ceil(bruto * 10000) / 10000
                : Math.ceil(bruto);
        }

        function formatearMonto(valor) {
            const decimales = esUnidadDePrecioFraccionario(unidadMedidaInput.value) ? 4 : 0;
            return `$${(parseFloat(valor) || 0).toFixed(decimales)}`;
        }

        function aplicarSugerencia(margenPorciento) {
            const sugerido = calcularPrecioSugerido(precioCompraInput.value, margenPorciento);
            if (sugerido > 0) {
                precioVentaInput.value = sugerido;
            }
        }

        function actualizarSugerenciasDesdeCompra() {
            const sugerido25 = calcularPrecioSugerido(precioCompraInput.value, 25);
            sugeridoBaseText.textContent = formatearMonto(sugerido25);
            // Al registrar un producto nuevo, el margen base de 25 % debe
            // seguir llenando el precio de venta automáticamente. En el
            // inventario este comportamiento permanece desactivado: ahí sólo
            // cambian los precios al pulsar uno de sus botones.
            if (sugerido25 > 0) {
                precioVentaInput.value = sugerido25;
            }
        }

        function actualizarAyudaUnidad() {
            const unidad = unidadMedidaInput.value;
            const ayuda = {
                pza: 'Piezas: registra el stock y los precios por pieza.',
                kg: 'Granel por kilo: registra stock en kg y precio por kg. Ejemplo: 5.000 kg disponibles y $22.00 por kg; una venta de 0.500 kg descuenta medio kilo.',
                g: 'Granel por gramo: registra stock en g y precio por g. Ejemplo: 5000 g disponibles y $0.022 por g; una venta de 500 g descuenta 500 g.',
                L: 'Granel por litro: registra stock en L y precio por L. La báscula/medidor puede capturarse también en ml.',
                ml: 'Granel por mililitro: registra stock en ml y precio por ml. La báscula/medidor puede capturarse también en L.'
            }[unidad] || 'Registra stock y precios siempre en la misma unidad.';
            ayudaUnidad.textContent = ayuda;
            labelPrecioCompra.textContent = `Precio de Compra (por ${unidad}):`;
            labelPrecioVenta.textContent = `Precio de Venta (por ${unidad}):`;
            labelStock.textContent = `Stock Inicial (${unidad}):`;
            stockInput.placeholder = `Cantidad disponible en ${unidad}`;
            actualizarSugerenciasDesdeCompra();
        }

        precioCompraInput.addEventListener('input', actualizarSugerenciasDesdeCompra);
        unidadMedidaInput.addEventListener('change', actualizarAyudaUnidad);

        botonesMargen.forEach(btn => {
            btn.addEventListener('click', () => {
                const margen = parseInt(btn.getAttribute('data-margen'), 10);
                if (!margen) return;
                aplicarSugerencia(margen);
            });
        });

        btnEscaner.addEventListener('click', async () => {
            if (!escanerActivo) {
                const textoOriginal = btnEscaner.innerText;
                btnEscaner.disabled = true;
                btnEscaner.innerText = 'Cargando cámara…';
                try {
                    await cargarLibreriaEscaner();
                    iniciarEscaner();
                } catch (error) {
                    alert(error.message || 'No se pudo iniciar la cámara.');
                    btnEscaner.innerText = textoOriginal;
                } finally {
                    btnEscaner.disabled = false;
                }
            } else {
                detenerEscaner();
            }
        });

        actualizarAyudaUnidad();
        actualizarSugerenciasDesdeCompra();

        function cargarLibreriaEscaner() {
            if (window.Html5Qrcode) return Promise.resolve();
            if (cargadorEscaner) return cargadorEscaner;
            cargadorEscaner = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                const timeout = setTimeout(() => {
                    script.remove();
                    reject(new Error('La cámara no respondió. Puedes capturar el código con el lector físico.'));
                }, 5000);
                script.src = 'https://unpkg.com/html5-qrcode';
                script.async = true;
                script.onload = () => { clearTimeout(timeout); resolve(); };
                script.onerror = () => { clearTimeout(timeout); reject(new Error('No se pudo cargar la cámara. Puedes capturar el código con el lector físico.')); };
                document.head.appendChild(script);
            }).catch(error => {
                cargadorEscaner = null;
                throw error;
            });
            return cargadorEscaner;
        }

        function iniciarEscaner() {
            html5QrCode = new Html5Qrcode("reader");
            readerDiv.style.display = 'block';

            const config = {
                fps: 15,
                qrbox: {
                    width: 300,
                    height: 150
                },
                aspectRatio: 1.0,
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.EAN_13,
                    Html5QrcodeSupportedFormats.EAN_8,
                    Html5QrcodeSupportedFormats.UPC_A,
                    Html5QrcodeSupportedFormats.CODE_128
                ]
            };

            html5QrCode.start({
                    facingMode: "environment"
                }, config,
                (decodedText) => {
                    if (bloqueado) return;
                    bloqueado = true;

                    console.log("¡Detectado!", decodedText);
                    inputRegistro.value = decodedText;

                    setTimeout(() => {
                        detenerEscaner();
                        bloqueado = false;
                        // 4. Asegúrate de que el input de nombre tenga id="nombre_producto"
                        document.getElementById('nombre_producto').focus();
                    }, 500);
                }
            ).then(() => {
                escanerActivo = true;
                btnEscaner.innerText = "🛑 Detener Escáner";
                btnEscaner.style.backgroundColor = "#dc3545";
            }).catch(err => {
                console.error(err);
            });
        }

        function detenerEscaner() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => {
                    escanerActivo = false;
                    btnEscaner.innerText = "📷 Escanear Código de Barras";
                    btnEscaner.style.backgroundColor = "#6c757d";
                    readerDiv.style.display = 'none';
                });
            }
        }
    </script>
</body>

</html>
