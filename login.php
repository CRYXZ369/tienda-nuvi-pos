<?php
require_once __DIR__ . '/session_utils.php';
require_once __DIR__ . '/csrf_utils.php';
require_once __DIR__ . '/dispositivos_confianza_utils.php';
require_once __DIR__ . '/app_header.php';
header('Cache-Control: no-store');
$perfiles = [];
$errorPerfiles = false;
if (nuviConfianzaHttps() && nuviConfianzaCookies()) {
    require_once __DIR__ . '/conexion.php';
    try {
        if (!$conexion instanceof mysqli) throw new RuntimeException('Base no disponible');
        $perfiles = nuviConfianzaPerfiles($conexion);
    } catch (Throwable $e) {
        error_log('No se pudieron consultar perfiles de confianza: ' . $e->getMessage());
        $errorPerfiles = true;
    }
}
$csrf = htmlspecialchars(nuviTokenCsrf(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Login - Tienda Nuvi</title>
    <?php renderNuviPwaHead(); ?>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f172a;
            /* Azul muy oscuro, más elegante */
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }

        .login-card {
            background: #ffffff;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            width: 350px;
            text-align: center;
        }

        h2 {
            color: #ffffff;
            font-weight: 700;
            margin-bottom: 5px;
        }

        p {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 25px;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            outline: none;
        }

        input:focus {
            border-color: #2563eb;
        }

        button {
            align-items: center;
            width: 110%;
            padding: 12px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }
        .perfil { margin: 8px 0; background: #075985; }
        .olvidar { width: auto; margin: 4px; background: #334155; }
        .aviso { color: #e2e8f0; line-height: 1.4; }
        label { display: block; color: #e2e8f0; margin: 12px 0; text-align: left; }
        input[type=checkbox] { width: auto; margin: 0 6px 0 0; }
    </style>
</head>

<body>

    <div class="login-box">
        <h2>LOGIN TIENDA NUVI</h2>
        <p style="color: #888;">Tienda Nuvi - Acceso al Sistema</p>

        <?php if (isset($_GET['error'])): ?>
            <p class="error">⚠️ Usuario o contraseña incorrectos</p>
        <?php endif; ?>
        <?php if (isset($_GET['perfil_error']) || $errorPerfiles): ?>
            <p class="aviso" role="alert">No se pudo usar el perfil guardado. Entra con usuario y contraseña.</p>
        <?php endif; ?>

        <?php if ($perfiles): ?>
            <p class="aviso">Perfiles de este dispositivo</p>
            <?php foreach ($perfiles as $selector => $perfil): ?>
                <form action="acceso_confianza.php" method="post">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="selector" value="<?= htmlspecialchars($selector, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="perfil" name="accion" value="entrar" type="submit"><?= htmlspecialchars($perfil['nombre'], ENT_QUOTES, 'UTF-8') ?></button>
                    <button class="olvidar" name="accion" value="olvidar" type="submit">Olvidar perfil</button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (nuviConfianzaHttps() && nuviConfianzaCookies()): ?>
            <form action="acceso_confianza.php" method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button class="olvidar" name="accion" value="olvidar_todos" type="submit">Olvidar todos los perfiles de esta tablet</button>
            </form>
        <?php endif; ?>

        <form action="validar_login.php" method="POST">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="input-group">
                <input type="text" name="usuario" placeholder="Usuario" required autocomplete="off">
            </div>
            <div class="input-group">
                <input type="password" name="password" placeholder="Contraseña" required>
            </div>
            <?php if (nuviConfianzaHttps()): ?>
                <label><input type="checkbox" name="confiar" value="1"> Recordar mi perfil en este dispositivo durante 30 días. Cualquiera con acceso a él podrá entrar con mis permisos.</label>
            <?php endif; ?>
            <button type="submit">Iniciar - Entrar</button>
        </form>
    </div>

</body>

</html>
