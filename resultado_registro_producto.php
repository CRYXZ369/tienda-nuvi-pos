<?php
require_once('auth.php');
require_once('app_header.php');

$estado = trim((string) ($_GET['estado'] ?? 'error_guardando'));
$productoId = max(0, (int) ($_GET['producto_id'] ?? 0));
$consulta = trim((string) ($_GET['q'] ?? ''));

$resultados = [
    'guardado' => [
        'icono' => '✓',
        'titulo' => 'Producto guardado',
        'mensaje' => 'El producto ya quedó registrado y está disponible para venderse.',
        'clase' => 'exito',
    ],
    'duplicado' => [
        'icono' => '!',
        'titulo' => 'Este producto ya existe',
        'mensaje' => 'No se creó un duplicado ni se modificó el inventario existente.',
        'clase' => 'advertencia',
    ],
    'datos_invalidos' => [
        'icono' => '!',
        'titulo' => 'Faltan datos por revisar',
        'mensaje' => 'Completa todos los campos obligatorios y usa cantidades y precios válidos.',
        'clase' => 'error',
    ],
    'error_conexion' => [
        'icono' => '!',
        'titulo' => 'No se pudo conectar para guardar',
        'mensaje' => 'Revisa la conexión con el servidor y vuelve a intentarlo. El producto no se registró.',
        'clase' => 'error',
    ],
    'error_consulta' => [
        'icono' => '!',
        'titulo' => 'No se pudo preparar el registro',
        'mensaje' => 'El producto no se registró. Intenta nuevamente o avisa a un administrador si continúa.',
        'clase' => 'error',
    ],
    'error_guardando' => [
        'icono' => '!',
        'titulo' => 'No se pudo guardar el producto',
        'mensaje' => 'El producto no se registró. Intenta nuevamente o avisa a un administrador si continúa.',
        'clase' => 'error',
    ],
];

$resultado = $resultados[$estado] ?? $resultados['error_guardando'];
$destinoInventario = 'consultar_productos.php';
if ($consulta !== '') {
    $destinoInventario .= '?q=' . rawurlencode($consulta);
} elseif ($productoId > 0) {
    $destinoInventario .= '?q=' . rawurlencode((string) $productoId);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($resultado['titulo'], ENT_QUOTES, 'UTF-8'); ?> · Tienda Nuvi</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { margin: 0; padding: 20px; background: #f4f6f8; color: #1e293b; }
        .resultado-contenedor {
            width: min(100%, 620px);
            margin: 8px auto 0;
            padding: 34px;
            text-align: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 16px 36px -20px rgba(15, 23, 42, .35);
        }
        .resultado-icono {
            width: 66px; height: 66px; margin: 0 auto 16px;
            display: grid; place-items: center; border-radius: 50%;
            font-size: 2.1rem; font-weight: 900;
        }
        .exito .resultado-icono { color: #166534; background: #dcfce7; }
        .advertencia .resultado-icono { color: #92400e; background: #fef3c7; }
        .error .resultado-icono { color: #b91c1c; background: #fee2e2; }
        h1 { margin: 0; font-size: clamp(1.45rem, 5vw, 2rem); }
        .mensaje { max-width: 470px; margin: 12px auto 8px; color: #475569; line-height: 1.55; }
        .codigo {
            display: inline-block; margin: 8px 0 2px; padding: 7px 10px;
            border-radius: 8px; background: #f1f5f9; color: #334155;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700;
        }
        .acciones { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 28px; }
        .boton {
            display: inline-flex; justify-content: center; align-items: center; min-height: 46px;
            padding: 10px 14px; border: 1px solid transparent; border-radius: 10px;
            color: #fff; text-decoration: none; font-weight: 800; transition: transform .15s ease, box-shadow .15s ease;
        }
        .boton:hover, .boton:focus-visible { transform: translateY(-1px); box-shadow: 0 8px 14px -9px rgba(15, 23, 42, .9); }
        .boton-inventario { background: #2563eb; }
        .boton-registro { background: #0f766e; }
        html.nuvi-tema-oscuro .resultado-contenedor { background: #172033; border-color: #334155; }
        html.nuvi-tema-oscuro .mensaje { color: #cbd5e1; }
        html.nuvi-tema-oscuro .codigo { background: #0f172a; color: #e2e8f0; }
        html.nuvi-tema-oscuro .exito .resultado-icono { color: #86efac; background: #14532d; }
        html.nuvi-tema-oscuro .advertencia .resultado-icono { color: #fde68a; background: #78350f; }
        html.nuvi-tema-oscuro .error .resultado-icono { color: #fca5a5; background: #7f1d1d; }
        @media (max-width: 560px) {
            body { padding: 12px; }
            .resultado-contenedor { padding: 26px 20px; }
            .acciones { grid-template-columns: 1fr; }
        }
    </style>
    <?php renderAppHeaderStyles(); ?>
</head>
<body>
    <?php renderAppHeader('registro', true); ?>
    <main class="resultado-contenedor <?php echo htmlspecialchars($resultado['clase'], ENT_QUOTES, 'UTF-8'); ?>" aria-live="polite">
        <div class="resultado-icono" aria-hidden="true"><?php echo htmlspecialchars($resultado['icono'], ENT_QUOTES, 'UTF-8'); ?></div>
        <h1><?php echo htmlspecialchars($resultado['titulo'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="mensaje"><?php echo htmlspecialchars($resultado['mensaje'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($consulta !== ''): ?>
            <div class="codigo">Código: <?php echo htmlspecialchars($consulta, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="acciones">
            <a class="boton boton-inventario" href="<?php echo htmlspecialchars($destinoInventario, ENT_QUOTES, 'UTF-8'); ?>">📦 Ver en inventario</a>
            <a class="boton boton-registro" href="registro_productos.php">➕ Registrar otro</a>
        </div>
    </main>
</body>
</html>
