<?php
require_once __DIR__ . '/session_utils.php';
$_SESSION = [];
session_destroy(); // Borra todos los datos de la sesión
header("Location: login.php");
exit();
?>
