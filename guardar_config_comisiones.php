<?php
header('Content-Type: application/json');
require_once("auth.php");
tienePermiso(['admin']);
require_once("conexion.php");
require_once("comisiones_terminal.php");

if (!isset($conexion) || !$conexion) {
    echo json_encode(["success" => false, "message" => "Sin conexión a base de datos"]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$comision = isset($data['comision_porcentaje']) ? floatval($data['comision_porcentaje']) : null;
$iva = isset($data['iva_porcentaje']) ? floatval($data['iva_porcentaje']) : null;
$cargoFijo = isset($data['cargo_fijo']) ? floatval($data['cargo_fijo']) : null;
$tiempo = trim($data['tiempo_acreditacion'] ?? '');

if ($comision === null || $comision < 0 || $iva === null || $iva < 0 || $cargoFijo === null || $cargoFijo < 0) {
    echo json_encode(["success" => false, "message" => "Datos inválidos"]);
    exit;
}

if (($comision / 100) * (1 + ($iva / 100)) >= 1) {
    echo json_encode(["success" => false, "message" => "La comisión total debe ser menor a 100%"]);
    exit;
}

if ($tiempo === '') {
    $tiempo = 'Al día siguiente hábil';
}

$tablaExiste = nuviTablaExiste($conexion, 'config_comisiones');
if (!$tablaExiste) {
    echo json_encode(["success" => false, "message" => "Falta ejecutar la migración de comisiones (migrate_comisiones_tarjeta.php)"]);
    exit;
}

$stmt = mysqli_prepare(
    $conexion,
    "INSERT INTO config_comisiones (id, comision_porcentaje, iva_porcentaje, cargo_fijo, tiempo_acreditacion)
     VALUES (1, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        comision_porcentaje = VALUES(comision_porcentaje),
        iva_porcentaje = VALUES(iva_porcentaje),
        cargo_fijo = VALUES(cargo_fijo),
        tiempo_acreditacion = VALUES(tiempo_acreditacion)"
);

if (!$stmt) {
    error_log('guardar_config_comisiones.php: ' . mysqli_error($conexion));
    echo json_encode(["success" => false, "message" => "No se pudo guardar la configuración"]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ddds", $comision, $iva, $cargoFijo, $tiempo);
mysqli_stmt_execute($stmt);

// Compatibilidad con la pantalla anterior: si ya existe el catálogo nuevo,
// actualizar también la terminal predeterminada.
if (nuviTablaExiste($conexion, 'terminales_pago')) {
    $terminal = nuviObtenerTerminalPago($conexion);
    if ($terminal) {
        $idTerminal = (int) $terminal['id'];
        $actualizar = mysqli_prepare($conexion, 'UPDATE terminales_pago SET comision_porcentaje = ?, iva_porcentaje = ?, cargo_fijo = ?, tiempo_acreditacion = ? WHERE id = ?');
        if ($actualizar) {
            mysqli_stmt_bind_param($actualizar, 'dddsi', $comision, $iva, $cargoFijo, $tiempo, $idTerminal);
            mysqli_stmt_execute($actualizar);
        }
    }
}

echo json_encode(["success" => true, "message" => "Configuración de comisión actualizada"]);
?>
