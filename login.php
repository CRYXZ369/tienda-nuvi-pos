<?php require_once('app_header.php'); ?>
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
    </style>
</head>

<body>

    <div class="login-box">
        <h2>LOGIN TIENDA NUVI</h2>
        <p style="color: #888;">Tienda Nuvi - Acceso al Sistema</p>

        <?php if (isset($_GET['error'])): ?>
            <p class="error">⚠️ Usuario o contraseña incorrectos</p>
        <?php endif; ?>

        <form action="validar_login.php" method="POST">
            <div class="input-group">
                <input type="text" name="usuario" placeholder="Usuario" required autocomplete="off">
            </div>
            <div class="input-group">
                <input type="password" name="password" placeholder="Contraseña" required>
            </div>
            <button type="submit">Iniciar - Entrar</button>
        </form>
    </div>

</body>

</html>
