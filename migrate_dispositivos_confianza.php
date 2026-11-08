<?php
require_once __DIR__ . '/auth.php';
tienePermiso(['admin']);
require_once __DIR__ . '/conexion.php';

try {
    if (!$conexion instanceof mysqli) {
        throw new RuntimeException('No hay conexión con la base de datos.');
    }
    if (!mysqli_query($conexion, "CREATE TABLE IF NOT EXISTS dispositivos_confianza (
        selector CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        usuario_id INT NOT NULL,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        password_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        vence DATETIME NOT NULL,
        INDEX (usuario_id),
        INDEX (vence)
    ) ENGINE=InnoDB")) {
        throw new RuntimeException('No se pudo crear la tabla de dispositivos.');
    }
    echo json_encode(['success' => true, 'message' => 'Acceso de confianza preparado.']);
} catch (Throwable $e) {
    error_log('Migración dispositivos de confianza: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'No se pudo preparar el acceso de confianza.']);
}
