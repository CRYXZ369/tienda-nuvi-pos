<?php
/**
 * Utilidades para la impresora térmica de tickets en la LAN.
 *
 * La impresora recibe ESC/POS sólo desde PHP y únicamente por una IP privada
 * configurada por administración. Las tablets nunca necesitan conocer el
 * puerto de la impresora ni tener una aplicación adicional instalada.
 */

require_once(__DIR__ . '/libreta_utils.php');
require_once(__DIR__ . '/pagos_venta_utils.php');

function nuviImpresionConfigPredeterminada(): array
{
    return [
        'activa' => false,
        'host' => '',
        'puerto' => 9100,
        'nombre' => 'Impresora de tickets',
    ];
}

function nuviImpresionTextoCorto(string $texto, int $limite): string
{
    $texto = trim($texto);
    return function_exists('mb_substr') ? mb_substr($texto, 0, $limite) : substr($texto, 0, $limite);
}

function nuviImpresionEsIpPrivada(string $host): bool
{
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $partes = array_map('intval', explode('.', $host));
    return $partes[0] === 10
        || ($partes[0] === 172 && $partes[1] >= 16 && $partes[1] <= 31)
        || ($partes[0] === 192 && $partes[1] === 168);
}

function nuviImpresionNormalizarConfig(array $datos): array
{
    $config = nuviImpresionConfigPredeterminada();
    $config['activa'] = !empty($datos['activa']);
    $config['host'] = trim((string) ($datos['host'] ?? ''));
    $config['puerto'] = (int) ($datos['puerto'] ?? 9100);
    $config['nombre'] = nuviImpresionTextoCorto((string) ($datos['nombre'] ?? $config['nombre']), 80);

    if ($config['nombre'] === '') {
        $config['nombre'] = 'Impresora de tickets';
    }
    if ($config['puerto'] !== 9100) {
        throw new InvalidArgumentException('Esta integración usa el puerto RAW 9100 de la impresora.');
    }
    if ($config['activa'] && !nuviImpresionEsIpPrivada($config['host'])) {
        throw new InvalidArgumentException('Indica una IP privada válida de la impresora, por ejemplo 192.168.1.210.');
    }
    if (!$config['activa'] && $config['host'] !== '' && !nuviImpresionEsIpPrivada($config['host'])) {
        throw new InvalidArgumentException('La IP guardada no pertenece a una red local privada.');
    }
    return $config;
}

function nuviImpresionAsegurarTablaConfiguracion(mysqli $conexion): bool
{
    static $resultado = null;
    if ($resultado !== null) {
        return $resultado;
    }
    $sql = "CREATE TABLE IF NOT EXISTS configuracion_impresora_ticket (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        activa TINYINT(1) NOT NULL DEFAULT 0,
        host VARCHAR(45) NOT NULL DEFAULT '',
        puerto SMALLINT UNSIGNED NOT NULL DEFAULT 9100,
        nombre VARCHAR(80) NOT NULL DEFAULT 'Impresora de tickets',
        id_usuario_actualizacion INT UNSIGNED DEFAULT NULL,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $resultado = mysqli_query($conexion, $sql) === true;
    if (!$resultado) {
        error_log('Nuvi impresión: no se pudo preparar configuración: ' . mysqli_error($conexion));
    }
    return $resultado;
}

function nuviImpresionTieneConfiguracion(mysqli $conexion): bool
{
    $res = mysqli_query($conexion, "SHOW TABLES LIKE 'configuracion_impresora_ticket'");
    return $res instanceof mysqli_result && mysqli_num_rows($res) > 0;
}

function nuviObtenerConfigImpresora(mysqli $conexion): array
{
    $predeterminada = nuviImpresionConfigPredeterminada();
    if (!nuviImpresionTieneConfiguracion($conexion)) {
        return $predeterminada;
    }
    $res = mysqli_query($conexion, 'SELECT activa, host, puerto, nombre FROM configuracion_impresora_ticket WHERE id = 1 LIMIT 1');
    $fila = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    if (!$fila) {
        return $predeterminada;
    }
    try {
        return nuviImpresionNormalizarConfig($fila);
    } catch (Throwable $error) {
        error_log('Nuvi impresión: configuración inválida: ' . $error->getMessage());
        return $predeterminada;
    }
}

function nuviGuardarConfigImpresora(mysqli $conexion, array $config, int $idUsuario): array
{
    if (!nuviImpresionAsegurarTablaConfiguracion($conexion)) {
        throw new RuntimeException('No se pudo preparar la configuración de impresión.');
    }
    $config = nuviImpresionNormalizarConfig($config);
    $activa = $config['activa'] ? 1 : 0;
    $host = $config['host'];
    $puerto = $config['puerto'];
    $nombre = $config['nombre'];
    $sql = 'INSERT INTO configuracion_impresora_ticket (id, activa, host, puerto, nombre, id_usuario_actualizacion) VALUES (1, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE activa = VALUES(activa), host = VALUES(host), puerto = VALUES(puerto), nombre = VALUES(nombre), id_usuario_actualizacion = VALUES(id_usuario_actualizacion)';
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el guardado de la impresora.');
    }
    mysqli_stmt_bind_param($stmt, 'isisi', $activa, $host, $puerto, $nombre, $idUsuario);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('No se pudo guardar la impresora: ' . $error);
    }
    mysqli_stmt_close($stmt);
    return $config;
}

function nuviImpresionAsegurarTablaSolicitudes(mysqli $conexion): bool
{
    static $resultado = null;
    if ($resultado !== null) {
        return $resultado;
    }
    $sql = "CREATE TABLE IF NOT EXISTS impresiones_ticket (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_venta INT UNSIGNED NOT NULL,
        clave_impresion VARCHAR(80) NOT NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'enviando',
        id_usuario INT UNSIGNED DEFAULT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        impreso_en DATETIME DEFAULT NULL,
        UNIQUE KEY uq_impresiones_ticket_venta_clave (id_venta, clave_impresion),
        KEY idx_impresiones_ticket_venta (id_venta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $resultado = mysqli_query($conexion, $sql) === true;
    if (!$resultado) {
        error_log('Nuvi impresión: no se pudo preparar solicitudes: ' . mysqli_error($conexion));
    }
    return $resultado;
}

function nuviImpresionRegistrarSolicitud(mysqli $conexion, int $idVenta, string $clave, int $idUsuario): array
{
    if (!nuviImpresionAsegurarTablaSolicitudes($conexion)) {
        throw new RuntimeException('No se pudo preparar el control de tickets.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $clave)) {
        throw new InvalidArgumentException('La clave de impresión no es válida.');
    }
    $stmt = mysqli_prepare($conexion, "INSERT IGNORE INTO impresiones_ticket (id_venta, clave_impresion, estado, id_usuario) VALUES (?, ?, 'enviando', ?)");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la solicitud de impresión.');
    }
    mysqli_stmt_bind_param($stmt, 'isi', $idVenta, $clave, $idUsuario);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException('No se pudo registrar el ticket: ' . $error);
    }
    $nueva = mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    if ($nueva) {
        return ['nueva' => true, 'estado' => 'enviando'];
    }

    $consulta = mysqli_prepare($conexion, 'SELECT estado FROM impresiones_ticket WHERE id_venta = ? AND clave_impresion = ? LIMIT 1');
    if (!$consulta) {
        throw new RuntimeException('No se pudo consultar el estado del ticket.');
    }
    mysqli_stmt_bind_param($consulta, 'is', $idVenta, $clave);
    mysqli_stmt_execute($consulta);
    $fila = mysqli_fetch_assoc(mysqli_stmt_get_result($consulta)) ?: [];
    mysqli_stmt_close($consulta);
    return ['nueva' => false, 'estado' => (string) ($fila['estado'] ?? 'enviando')];
}

function nuviImpresionMarcarSolicitud(mysqli $conexion, int $idVenta, string $clave, string $estado): void
{
    $campoFecha = $estado === 'impreso' ? ', impreso_en = NOW()' : '';
    $stmt = mysqli_prepare($conexion, "UPDATE impresiones_ticket SET estado = ?$campoFecha WHERE id_venta = ? AND clave_impresion = ?");
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'sis', $estado, $idVenta, $clave);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function nuviImpresionColumnaExiste(mysqli $conexion, string $tabla, string $columna): bool
{
    static $cache = [];
    $clave = $tabla . '.' . $columna;
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }
    $tablaEscapada = mysqli_real_escape_string($conexion, $tabla);
    $columnaEscapada = mysqli_real_escape_string($conexion, $columna);
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM `$tablaEscapada` LIKE '$columnaEscapada'");
    return $cache[$clave] = $res instanceof mysqli_result && mysqli_num_rows($res) > 0;
}

function nuviImpresionColumnaNota(mysqli $conexion): ?string
{
    foreach (['notas', 'nota_venta', 'observaciones', 'nota', 'comentario'] as $columna) {
        if (nuviImpresionColumnaExiste($conexion, 'ventas', $columna)) {
            return $columna;
        }
    }
    return null;
}

/**
 * Recupera el acuerdo de crédito guardado junto con la venta fiada. Las ventas
 * nuevas conservan una fotografía del saldo y de la siguiente fecha; para
 * comprobantes antiguos se muestra el estado vigente, claramente marcado.
 */
function nuviImpresionResumenFiadoVenta(mysqli $conexion, int $idVenta): ?array
{
    $requeridas = [
        ['libreta_digital', 'id_cliente'],
        ['libreta_digital', 'id_venta'],
        ['libreta_digital', 'monto'],
        ['clientes', 'id_cliente'],
        ['clientes', 'saldo_actual'],
    ];
    foreach ($requeridas as [$tabla, $columna]) {
        if (!nuviImpresionColumnaExiste($conexion, $tabla, $columna)) {
            return null;
        }
    }

    $nombreCliente = nuviImpresionColumnaExiste($conexion, 'clientes', 'nombre_completo')
        ? 'c.nombre_completo'
        : (nuviImpresionColumnaExiste($conexion, 'clientes', 'nombre') ? 'c.nombre' : "'Cliente'");
    $notaMovimiento = nuviImpresionColumnaExiste($conexion, 'libreta_digital', 'notas')
        ? 'ld.notas'
        : (nuviImpresionColumnaExiste($conexion, 'libreta_digital', 'nota') ? 'ld.nota' : "''");
    $campos = [
        "$nombreCliente AS nombre_cliente",
        'c.saldo_actual',
        'ld.monto AS importe_fiado',
        "$notaMovimiento AS nota_movimiento",
    ];
    foreach (['fecha_inicio_interes', 'saldo_base_interes', 'semanas_interes_aplicadas'] as $columna) {
        $campos[] = nuviImpresionColumnaExiste($conexion, 'clientes', $columna)
            ? "c.$columna"
            : "NULL AS $columna";
    }

    $sql = 'SELECT ' . implode(', ', $campos)
        . ' FROM libreta_digital ld INNER JOIN clientes c ON c.id_cliente = ld.id_cliente'
        . ' WHERE ld.id_venta = ? LIMIT 1';
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $idVenta);
    mysqli_stmt_execute($stmt);
    $fila = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$fila) {
        return null;
    }

    $meta = nuvi_parse_libreta_nota((string) ($fila['nota_movimiento'] ?? '')) ?: [];
    $tieneFoto = array_key_exists('ticket_saldo', $meta) && is_numeric($meta['ticket_saldo']);
    $saldo = $tieneFoto ? (float) $meta['ticket_saldo'] : (float) ($fila['saldo_actual'] ?? 0);
    $base = isset($meta['ticket_base']) && is_numeric($meta['ticket_base'])
        ? (float) $meta['ticket_base']
        : (float) ($fila['saldo_base_interes'] ?? 0);
    $semanas = isset($meta['ticket_semanas']) && is_numeric($meta['ticket_semanas'])
        ? (int) $meta['ticket_semanas']
        : (int) ($fila['semanas_interes_aplicadas'] ?? 0);
    $inicio = (string) ($meta['inicio'] ?? $fila['fecha_inicio_interes'] ?? '');
    $proximo = (string) ($meta['ticket_proximo'] ?? '');
    $cargo = isset($meta['ticket_cargo']) && is_numeric($meta['ticket_cargo']) ? (float) $meta['ticket_cargo'] : null;
    $porcentaje = isset($meta['ticket_porcentaje']) && is_numeric($meta['ticket_porcentaje']) ? (float) $meta['ticket_porcentaje'] : null;

    if ($proximo === '' || $cargo === null || $porcentaje === null) {
        $programacion = nuvi_resumen_proximo_interes_simple_libreta($saldo, $base, $inicio, $semanas);
        $proximo = (string) ($programacion['fecha_proximo'] ?? '');
        $cargo = (float) ($programacion['cargo_proximo'] ?? 0);
        $porcentaje = (float) ($programacion['porcentaje_proximo'] ?? 0);
    }

    return [
        'cliente' => trim((string) ($fila['nombre_cliente'] ?? '')) ?: 'Cliente',
        'importe_fiado' => round((float) ($fila['importe_fiado'] ?? 0), 2),
        'saldo_total' => round($saldo, 2),
        'saldo_es_foto' => $tieneFoto,
        'interes_configurado' => $base > 0 && $inicio !== '',
        'saldo_base_interes' => round($base, 2),
        'fecha_proximo_recargo' => $proximo !== '' ? $proximo : null,
        'cargo_proximo' => round((float) $cargo, 2),
        'porcentaje_proximo' => round((float) $porcentaje, 2),
    ];
}

function nuviObtenerVentaParaTicket(mysqli $conexion, int $idVenta): array
{
    $campos = ['v.id_venta', 'v.total', 'v.fecha_venta'];
    $campos[] = nuviImpresionColumnaExiste($conexion, 'ventas', 'metodo_pago') ? 'v.metodo_pago' : "'efectivo' AS metodo_pago";
    $nota = nuviImpresionColumnaNota($conexion);
    $campos[] = $nota ? "v.`$nota` AS nota_venta" : "'' AS nota_venta";
    $campos[] = nuviImpresionColumnaExiste($conexion, 'ventas', 'monto_cobrado_tarjeta') ? 'v.monto_cobrado_tarjeta' : 'NULL AS monto_cobrado_tarjeta';
    $campos[] = nuviImpresionColumnaExiste($conexion, 'ventas', 'recargo_cliente_tarjeta') ? 'v.recargo_cliente_tarjeta' : 'NULL AS recargo_cliente_tarjeta';
    $campos[] = nuviImpresionColumnaExiste($conexion, 'ventas', 'terminal_nombre') ? 'v.terminal_nombre' : "'' AS terminal_nombre";
    $tieneUsuarioVenta = nuviImpresionColumnaExiste($conexion, 'ventas', 'id_usuario');
    $campos[] = $tieneUsuarioVenta ? "COALESCE(NULLIF(u.nombre, ''), '') AS cajero" : "'' AS cajero";
    $sql = 'SELECT ' . implode(', ', $campos) . ' FROM ventas v'
        . ($tieneUsuarioVenta ? ' LEFT JOIN usuarios u ON u.id = v.id_usuario' : '')
        . ' WHERE v.id_venta = ? LIMIT 1';
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la venta para imprimir.');
    }
    mysqli_stmt_bind_param($stmt, 'i', $idVenta);
    mysqli_stmt_execute($stmt);
    $venta = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$venta) {
        throw new RuntimeException('La venta ya no existe.');
    }
    if ((float) $venta['total'] < 0 || ($venta['metodo_pago'] ?? '') === 'devuelto') {
        throw new RuntimeException('No se imprime un ticket normal de una devolución.');
    }

    $sqlDetalle = "SELECT
            dv.cantidad,
            dv.precio_unitario,
            COALESCE(por_id.nombre, por_codigo.nombre, CONCAT('Producto #', dv.id_producto)) AS nombre
        FROM detalle_ventas dv
        LEFT JOIN productos por_id ON CAST(dv.id_producto AS CHAR) = CAST(por_id.id AS CHAR)
        LEFT JOIN productos por_codigo ON por_id.id IS NULL AND CAST(dv.id_producto AS CHAR) = CAST(por_codigo.codigo_barras AS CHAR)
        WHERE dv.id_venta = ? ORDER BY dv.id ASC";
    $stmtDetalle = mysqli_prepare($conexion, $sqlDetalle);
    if (!$stmtDetalle) {
        throw new RuntimeException('No se pudo consultar los productos del ticket.');
    }
    mysqli_stmt_bind_param($stmtDetalle, 'i', $idVenta);
    mysqli_stmt_execute($stmtDetalle);
    $resultado = mysqli_stmt_get_result($stmtDetalle);
    $detalles = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $fila['subtotal'] = round((float) $fila['cantidad'] * (float) $fila['precio_unitario'], 2);
        $detalles[] = $fila;
    }
    mysqli_stmt_close($stmtDetalle);
    if (!$detalles) {
        throw new RuntimeException('La venta no tiene productos para imprimir.');
    }
    $fiado = ($venta['metodo_pago'] ?? '') === 'fiado'
        ? nuviImpresionResumenFiadoVenta($conexion, $idVenta)
        : null;
    return [
        'venta' => $venta,
        'detalles' => $detalles,
        'fiado' => $fiado,
        'pagos' => nuviObtenerPagosVenta($conexion, $idVenta),
    ];
}

function nuviImpresionConfigEncabezado(): array
{
    $config = ['nombre' => 'Miscelánea Nuvi', 'direccion' => '', 'telefono' => '', 'leyenda' => 'Gracias por su compra'];
    $archivo = __DIR__ . '/config/ticket.local.php';
    if (is_file($archivo)) {
        $local = require $archivo;
        if (is_array($local)) {
            foreach ($config as $clave => $predeterminado) {
                if (isset($local[$clave]) && is_scalar($local[$clave])) {
                    $config[$clave] = nuviImpresionTextoCorto((string) $local[$clave], 180);
                }
            }
        }
    }
    return $config;
}

function nuviImpresionCodificar(string $texto): string
{
    $texto = preg_replace('/[\r\n\t]+/u', ' ', $texto) ?? '';
    $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? '';
    if (function_exists('iconv')) {
        // La hoja de autoprueba de la 80-VIII-VL identifica la página 16
        // como WPC1252. Mantener texto y página de códigos alineados evita
        // acentos extraños en tickets reales.
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        if ($convertido !== false) {
            return $convertido;
        }
    }
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
}

function nuviImpresionLineas(string $texto, int $ancho = 42): array
{
    $codificado = nuviImpresionCodificar($texto);
    if ($codificado === '') {
        return [];
    }
    return explode("\n", wordwrap($codificado, $ancho, "\n", true));
}

function nuviImpresionLineaCentrada(string $texto, int $ancho = 42): string
{
    $lineas = nuviImpresionLineas($texto, $ancho);
    $salida = '';
    foreach ($lineas as $linea) {
        // ESC a 1 se encarga del centrado físico. Agregar espacios aquí
        // provocaba que el controlador centrara dos veces el texto y el
        // encabezado terminara desplazado hacia la derecha.
        $salida .= $linea . "\n";
    }
    return $salida;
}

/**
 * Mariposa real convertida a bitmap de un bit para la térmica.
 *
 * El diseño fuente se conserva en assets/mariposa-ticket.png. Los bytes se
 * guardan aquí ya compactados para que el servidor no necesite GD/ImageMagick
 * al imprimir. ESC * es el modo de gráfico clásico de Epson que acepta esta
 * Kinwodon, a diferencia del raster GS v 0 que su firmware ignora.
 */
function nuviImpresionMariposaEscPos(bool $espacioAntes = false, bool $espacioDespues = false): string
{
    static $datos = null;
    if ($datos === null) {
        // 36 columnas x 24 puntos; se convirtió desde la imagen aprobada y
        // se invirtió verticalmente para la orientación real de esta Kinwodon.
        $datos = base64_decode('YAAA2AAA94AAv2AAh/DAcPNwQJXoKB58PhgMGIiaDI4uBEzDIiwOISkMAIgIAE4gAB8AAH/wAH/wAB8AAE4gAIgIISkMIiwOBEzDDI4uGYiaPhgMKB58QJXodPNwh/DAv2AA94AA2AAAYAAA', true);
    }
    if ($datos === false || strlen($datos) !== 108) {
        return '';
    }
    // Cuatro columnas transparentes equivalen a un pequeño margen real en
    // papel. Se agregan del lado interno de cada mariposa para que no roce la
    // M ni la última letra, sin cambiar el centrado del encabezado.
    $margen = str_repeat("\x00", 12);
    $contenido = ($espacioAntes ? $margen : '') . $datos . ($espacioDespues ? $margen : '');
    $columnas = 36 + ($espacioAntes ? 4 : 0) + ($espacioDespues ? 4 : 0);
    // ESC * m nL nH: 24-dot single-density y tres bytes por columna. No
    // lleva salto: permite colocar el nombre entre dos alas.
    return "\x1B* " . chr($columnas) . "\x00" . $contenido;
}

function nuviImpresionEncabezadoEscPos(array $encabezado): string
{
    $esc = "\x1B";
    $ancho = 42;
    $anchoDoble = intdiv($ancho, 2);
    $salida = $esc . "a\x01";
    $lineasNombre = nuviImpresionLineas((string) $encabezado['nombre'], $anchoDoble);
    if (count($lineasNombre) === 1 && nuviImpresionMariposaEscPos() !== '') {
        // Una mariposa a cada lado de Miscelánea Nuvi. El centrado por ESC a
        // toma el conjunto completo (imagen + texto + imagen) como una línea.
        $salida .= nuviImpresionMariposaEscPos(false, true);
        $salida .= $esc . "!\x20" . $lineasNombre[0] . $esc . "!\x00";
        $salida .= nuviImpresionMariposaEscPos(true, false) . "\n";
    } else {
        // Una personalización extraordinariamente larga no debe cortar el
        // encabezado; conserva el nombre y muestra el emblema arriba.
        $salida .= nuviImpresionMariposaEscPos() . "\n";
        $salida .= $esc . "!\x20" . nuviImpresionLineaCentrada((string) $encabezado['nombre'], $anchoDoble) . $esc . "!\x00";
    }
    foreach (['direccion', 'telefono'] as $campo) {
        if (!empty($encabezado[$campo])) {
            $salida .= nuviImpresionLineaCentrada((string) $encabezado[$campo], $ancho);
        }
    }
    return $salida;
}

function nuviImpresionFechaCorta($fecha): string
{
    $marca = strtotime((string) $fecha);
    return $marca ? date('d/m/Y', $marca) : '';
}

function nuviConstruirTicketEscPos(array $datos, array $opciones = []): string
{
    $venta = $datos['venta'];
    $detalles = $datos['detalles'];
    $fiado = is_array($datos['fiado'] ?? null) ? $datos['fiado'] : null;
    $pagos = is_array($datos['pagos'] ?? null) ? $datos['pagos'] : [];
    $esCopia = !empty($opciones['copia']);
    $encabezado = nuviImpresionConfigEncabezado();
    $esc = "\x1B";
    $gs = "\x1D";
    $ancho = 42;
    $salida = $esc . "@" . $esc . "t\x10";
    $salida .= nuviImpresionEncabezadoEscPos($encabezado);
    if ($esCopia) {
        // Debe ser inequívoco en papel: esta orden reutiliza una venta ya
        // confirmada y no representa un nuevo cobro ni una segunda salida de
        // inventario.
        $salida .= $esc . "a\x01" . $esc . "!\x08" . "*** COPIA ***\n" . $esc . "!\x00";
    }
    $salida .= $esc . "a\x00" . str_repeat('-', $ancho) . "\n";
    $fecha = strtotime((string) ($venta['fecha_venta'] ?? ''));
    $salida .= 'Venta #' . (int) $venta['id_venta'] . '  ' . ($fecha ? date('d/m/Y H:i', $fecha) : '') . "\n";
    if (!empty($venta['cajero'])) {
        foreach (nuviImpresionLineas('Atendio: ' . (string) $venta['cajero'], $ancho) as $linea) {
            $salida .= $linea . "\n";
        }
    }
    $salida .= str_repeat('-', $ancho) . "\n";
    foreach ($detalles as $detalle) {
        foreach (nuviImpresionLineas((string) $detalle['nombre'], $ancho) as $linea) {
            $salida .= $linea . "\n";
        }
        $cantidad = rtrim(rtrim(number_format((float) $detalle['cantidad'], 3, '.', ''), '0'), '.');
        $renglon = '  ' . $cantidad . ' x $' . number_format((float) $detalle['precio_unitario'], 2, '.', '') . ' = $' . number_format((float) $detalle['subtotal'], 2, '.', '');
        $salida .= $renglon . "\n";
    }
    $salida .= str_repeat('-', $ancho) . "\n";
    $salida .= $esc . "!\x08" . 'TOTAL PRODUCTOS: $' . number_format((float) $venta['total'], 2, '.', '') . $esc . "!\x00\n";
    $metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'otro' => 'Otro', 'mixto' => 'Pago mixto', 'fiado' => 'Fiado'];
    $metodo = (string) ($venta['metodo_pago'] ?? 'efectivo');
    $esMixto = count($pagos) > 1;
    $salida .= 'Metodo: ' . ($esMixto ? 'Pago mixto' : ($metodos[$metodo] ?? $metodo)) . "\n";
    if ($pagos) {
        $salida .= str_repeat('-', $ancho) . "\n";
        $salida .= $esMixto ? 'DESGLOSE DE PAGO' . "\n" : 'DETALLE DE PAGO' . "\n";
        foreach ($pagos as $pago) {
            $metodoPago = (string) ($pago['metodo'] ?? '');
            $monto = (float) ($pago['monto'] ?? 0);
            if ($metodoPago === 'efectivo') {
                if ($esMixto) {
                    $salida .= 'Efectivo aplicado: $' . number_format($monto, 2, '.', '') . "\n";
                }
                $recibido = (float) ($pago['monto_recibido'] ?? 0);
                $salida .= 'Efectivo recibido: $' . number_format($recibido, 2, '.', '') . "\n";
                $salida .= 'Cambio: $' . number_format((float) ($pago['cambio'] ?? 0), 2, '.', '') . "\n";
                continue;
            }

            $etiqueta = $metodos[$metodoPago] ?? ucfirst($metodoPago);
            $salida .= $etiqueta . ': $' . number_format($monto, 2, '.', '') . "\n";
            if ($metodoPago === 'tarjeta') {
                if (!empty($pago['terminal_nombre'])) {
                    foreach (nuviImpresionLineas('Terminal: ' . (string) $pago['terminal_nombre'], $ancho) as $linea) {
                        $salida .= $linea . "\n";
                    }
                }
                $cobrado = (float) ($pago['monto_cobrado_tarjeta'] ?? 0);
                if ($cobrado > 0) {
                    $salida .= 'Cobrado terminal: $' . number_format($cobrado, 2, '.', '') . "\n";
                }
                $recargo = (float) ($pago['recargo_cliente_tarjeta'] ?? 0);
                if ($recargo > 0) {
                    $salida .= 'Recargo terminal: $' . number_format($recargo, 2, '.', '') . "\n";
                }
            }
        }
    } elseif ($metodo === 'tarjeta') {
        // Compatibilidad con tickets históricos, creados antes de guardar
        // el desglose individual de cada cobro.
        if (!empty($venta['terminal_nombre'])) {
            foreach (nuviImpresionLineas('Terminal: ' . (string) $venta['terminal_nombre'], $ancho) as $linea) {
                $salida .= $linea . "\n";
            }
        }
        if ($venta['monto_cobrado_tarjeta'] !== null) {
            $salida .= 'Cobrado terminal: $' . number_format((float) $venta['monto_cobrado_tarjeta'], 2, '.', '') . "\n";
        }
        if ((float) ($venta['recargo_cliente_tarjeta'] ?? 0) > 0) {
            $salida .= 'Recargo terminal: $' . number_format((float) $venta['recargo_cliente_tarjeta'], 2, '.', '') . "\n";
        }
    }
    if ($metodo === 'fiado' && $fiado) {
        $saldo = round((float) ($fiado['saldo_total'] ?? 0), 2);
        $salida .= str_repeat('-', $ancho) . "\n";
        $salida .= 'CREDITO / LIBRETA DIGITAL' . "\n";
        foreach (nuviImpresionLineas('Cliente: ' . (string) ($fiado['cliente'] ?? 'Cliente'), $ancho) as $linea) {
            $salida .= $linea . "\n";
        }
        $salida .= 'Importe fiado hoy: $' . number_format((float) ($fiado['importe_fiado'] ?? $venta['total']), 2, '.', '') . "\n";
        if ($saldo < -0.004) {
            $salida .= 'Saldo a favor: $' . number_format(abs($saldo), 2, '.', '') . "\n";
        } else {
            $etiquetaSaldo = !empty($fiado['saldo_es_foto']) ? 'Saldo tras esta venta' : 'Saldo vigente hoy';
            $salida .= $etiquetaSaldo . ': $' . number_format(max(0, $saldo), 2, '.', '') . "\n";
        }

        $fechaProximo = nuviImpresionFechaCorta($fiado['fecha_proximo_recargo'] ?? '');
        $cargoProximo = (float) ($fiado['cargo_proximo'] ?? 0);
        $porcentajeProximo = (float) ($fiado['porcentaje_proximo'] ?? 0);
        if ($saldo > 0.004 && !empty($fiado['interes_configurado']) && $fechaProximo !== '') {
            $salida .= 'Proximo recargo: ' . $fechaProximo . "\n";
            $salida .= 'Pague antes para evitarlo.' . "\n";
            $salida .= 'Estimado: +$' . number_format($cargoProximo, 2, '.', '')
                . ' (' . rtrim(rtrim(number_format($porcentajeProximo, 2, '.', ''), '0'), '.') . '%)' . "\n";
            foreach (nuviImpresionLineas('Regla: 5% al cumplir la 1a semana; +10% por cada semana posterior sin abono.', $ancho) as $linea) {
                $salida .= $linea . "\n";
            }
        } elseif ($saldo <= 0.004) {
            $salida .= 'Sin recargo mientras no haya adeudo.' . "\n";
        }
        $salida .= 'Firma cliente:' . "\n";
        $salida .= '__________________________________________' . "\n";
    }
    if (!empty($venta['nota_venta'])) {
        foreach (nuviImpresionLineas('Nota: ' . (string) $venta['nota_venta'], $ancho) as $linea) {
            $salida .= $linea . "\n";
        }
    }
    $salida .= str_repeat('-', $ancho) . "\n" . $esc . "a\x01";
    $salida .= nuviImpresionLineaCentrada($encabezado['leyenda'], $ancho);
    $salida .= nuviImpresionLineaCentrada('Comprobante de venta; no sustituye factura.', $ancho);
    // El cortador de esta térmica queda demasiado cerca del último renglón si
    // sólo recibe saltos de línea. ESC d avanza el papel de forma explícita y
    // deja un margen legible antes del corte, incluso en tickets largos.
    $salida .= $esc . "a\x00" . $esc . "d\x06";
    return $salida . $gs . "V\x00";
}

/** Construye una lista breve de surtido para papel térmico de 80 mm. */
function nuviConstruirListaSurtidoEscPos(array $lineas, array $opciones = []): string
{
    $esc = "\x1B";
    $gs = "\x1D";
    $ancho = 42;
    $encabezado = nuviImpresionConfigEncabezado();
    $diasEntrega = max(0, min(60, (int) ($opciones['dias_entrega'] ?? 0)));
    $diasSeguridad = max(0, min(60, (int) ($opciones['dias_seguridad'] ?? 0)));
    $diasObjetivo = $diasEntrega + $diasSeguridad;

    $salida = $esc . "@" . $esc . "t\x10";
    $salida .= nuviImpresionEncabezadoEscPos($encabezado);
    $salida .= $esc . "a\x00" . str_repeat('-', $ancho) . "\n";
    $salida .= $esc . "a\x01" . $esc . "!\x08" . "LISTA DE SURTIDO\n" . $esc . "!\x00";
    if ($diasObjetivo > 0) {
        $salida .= 'Cobertura objetivo: ' . $diasObjetivo . " dias\n";
        if ($diasEntrega > 0 || $diasSeguridad > 0) {
            $salida .= $diasEntrega . ' entrega + ' . $diasSeguridad . " colchon\n";
        }
    }
    $salida .= 'Generada: ' . date('d/m/Y H:i') . "\n";
    $salida .= $esc . "a\x00" . str_repeat('-', $ancho) . "\n";

    foreach ($lineas as $indice => $linea) {
        $nombre = nuviImpresionTextoCorto((string) ($linea['nombre'] ?? 'Producto sin nombre'), 120);
        $marca = nuviImpresionTextoCorto((string) ($linea['marca'] ?? ''), 80);
        $codigo = nuviImpresionTextoCorto((string) ($linea['codigo'] ?? ''), 40);
        $unidad = nuviImpresionTextoCorto((string) ($linea['unidad'] ?? 'unid.'), 20);
        $cantidad = (float) ($linea['cantidad'] ?? 0);
        if ($cantidad <= 0 || !is_finite($cantidad)) {
            continue;
        }
        foreach (nuviImpresionLineas(($indice + 1) . '. ' . $nombre, $ancho) as $texto) {
            $salida .= $texto . "\n";
        }
        if ($marca !== '' || $codigo !== '') {
            $referencia = trim(($marca !== '' ? $marca : '') . ($marca !== '' && $codigo !== '' ? ' · ' : '') . ($codigo !== '' ? 'Cod: ' . $codigo : ''));
            foreach (nuviImpresionLineas('   ' . $referencia, $ancho) as $texto) {
                $salida .= $texto . "\n";
            }
        }
        $cantidadTexto = rtrim(rtrim(number_format($cantidad, 3, '.', ''), '0'), '.');
        $salida .= $esc . "!\x08" . '   PEDIR: ' . $cantidadTexto . ' ' . $unidad . $esc . "!\x00\n";
        $salida .= "   [  ] Conseguido\n";
        $salida .= str_repeat('-', $ancho) . "\n";
    }

    $salida .= $esc . "a\x01";
    $salida .= nuviImpresionLineaCentrada('Revisar existencias antes de comprar.', $ancho);
    $salida .= $esc . "a\x00" . $esc . "d\x06";
    return $salida . $gs . "V\x00";
}

function nuviEnviarTicketImpresora(array $config, string $contenido): void
{
    if (!$config['activa'] || !nuviImpresionEsIpPrivada((string) $config['host'])) {
        throw new RuntimeException('La impresora de red no está configurada.');
    }
    $errorNumero = 0;
    $errorTexto = '';
    $socket = @fsockopen($config['host'], (int) $config['puerto'], $errorNumero, $errorTexto, 2.5);
    if (!$socket) {
        throw new RuntimeException('No se pudo conectar con ' . $config['nombre'] . '. Revisa su IP, cable LAN y energía.');
    }
    stream_set_timeout($socket, 3);
    $offset = 0;
    $longitud = strlen($contenido);
    try {
        while ($offset < $longitud) {
            $escrito = fwrite($socket, substr($contenido, $offset));
            if ($escrito === false || $escrito === 0) {
                throw new RuntimeException('La impresora no aceptó el ticket completo.');
            }
            $offset += $escrito;
        }
    } finally {
        fclose($socket);
    }
}
