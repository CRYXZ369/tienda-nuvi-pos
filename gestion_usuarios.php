<?php
include("auth.php");
tienePermiso(['admin']);
include("conexion.php");
require_once("app_header.php");

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die('Sin conexión a base de datos');
}

$sql = "SELECT id, nombre, usuario, rol, estado FROM usuarios WHERE estado = 1 ORDER BY nombre ASC";
$resultado = mysqli_query($conexion, $sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios | Nuvi</title>
    <?php renderAppHeaderStyles(); ?>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; background: #f4f6f8; color: #0f172a; }
        .contenedor { max-width: 900px; margin: 32px auto; padding: 0 20px 40px; }
        .panel { background: white; padding: 20px; border-radius: 14px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); margin-bottom: 18px; }
        .card-usuario { background: white; padding: 18px; border-radius: 12px; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.06); margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .rol-tag { padding: 5px 10px; border-radius: 999px; font-size: 0.82rem; font-weight: bold; }
        .admin { background: #fee2e2; color: #b91c1c; }
        .cajero { background: #dcfce7; color: #15803d; }
        .btn-accion { padding: 8px 12px; border: none; border-radius: 8px; cursor: pointer; margin-left: 5px; font-weight: 650; transition: background .18s ease, box-shadow .18s ease, transform .18s ease; }
        .btn-accion:hover { transform: translateY(-1px); box-shadow: 0 3px 9px rgba(15, 23, 42, .18); }
        .btn-neutro { background: #e2e8f0; color: #1e293b; border: 1px solid #cbd5e1; }
        .btn-neutro:hover { background: #cbd5e1; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
        input, select, button { padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; }
        button[type="submit"] { background: #2563eb; color: white; border: none; cursor: pointer; }

        /* Los botones grises usaban el color claro original incluso en modo
           oscuro, por eso Nombre y Contraseña parecían deshabilitados. */
        html.nuvi-tema-oscuro .btn-neutro {
            background: #334155 !important;
            color: #f8fafc !important;
            border-color: #64748b !important;
        }
        html.nuvi-tema-oscuro .btn-neutro:hover,
        html.nuvi-tema-oscuro .btn-neutro:focus-visible {
            background: #475569 !important;
            color: #ffffff !important;
            border-color: #93c5fd !important;
        }

        @media (max-width: 640px) {
            .card-usuario { align-items: stretch; flex-direction: column; }
            .card-usuario > div:last-child { display: flex; flex-wrap: wrap; gap: 7px; }
            .btn-accion { margin-left: 0; flex: 1 1 130px; }
        }
    </style>
</head>
<body>
    <?php renderAppHeader('gestion_usuarios'); ?>
    <div class="contenedor">
        <h2>👥 Gestión de Personal</h2>
        <p>Control de acceso, permisos y credenciales para la Tienda Nuvi.</p>

        <div class="panel">
            <h3>➕ Agregar usuario</h3>
            <form id="formNuevoUsuario">
                <div class="form-grid">
                    <input type="text" name="nombre" placeholder="Nombre" required>
                    <input type="text" name="usuario" placeholder="Usuario" required>
                    <input type="password" name="password" placeholder="Contraseña" required>
                    <select name="rol">
                        <option value="cajero">Cajero</option>
                        <option value="admin">Administrador</option>
                    </select>
                    <button type="submit">Agregar usuario</button>
                </div>
            </form>
        </div>

        <div id="listaUsuarios">
            <?php while($u = mysqli_fetch_assoc($resultado)): ?>
                <div class="card-usuario">
                    <div>
                        <strong><?php echo htmlspecialchars($u['nombre']); ?></strong>
                        <span style="color: #64748b;">(@<?php echo htmlspecialchars($u['usuario']); ?>)</span><br>
                        <span class="rol-tag <?php echo $u['rol']; ?>"><?php echo strtoupper($u['rol']); ?></span>
                    </div>
                    <div>
                        <button class="btn-accion btn-neutro" onclick="editarNombre(<?php echo $u['id']; ?>, '<?php echo addslashes($u['nombre']); ?>')">✏️ Nombre</button>
                        <button class="btn-accion btn-neutro" onclick="abrirModalPassword(<?php echo $u['id']; ?>, '<?php echo addslashes($u['nombre']); ?>')">🔑 Contraseña</button>
                        <button class="btn-accion" style="background: #3b82f6; color: white;" onclick="cambiarRol(<?php echo $u['id']; ?>, '<?php echo $u['rol']; ?>')">🔄 Rol</button>
                        <button class="btn-accion" style="background: #ef4444; color: white;" onclick="eliminarUsuario(<?php echo $u['id']; ?>, '<?php echo addslashes($u['nombre']); ?>')">🗑️ Eliminar</button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <script>
        function cambiarRol(id, rolActual) {
            const nuevoRol = rolActual === 'admin' ? 'cajero' : 'admin';
            if (!confirm(`¿Cambiar el rol del usuario #${id} a ${nuevoRol}?`)) return;
            actualizarUsuario({ id, accion: 'cambiar_rol', rol: nuevoRol });
        }

        function editarNombre(id, nombreActual) {
            const nuevoNombre = prompt('Nuevo nombre para el usuario:', nombreActual);
            if (nuevoNombre && nuevoNombre.trim()) {
                actualizarUsuario({ id, accion: 'editar_nombre', nombre: nuevoNombre.trim() });
            }
        }

        function abrirModalPassword(id, nombre) {
            const nuevaPass = prompt(`Nueva contraseña para ${nombre}:`);
            if (nuevaPass && nuevaPass.length >= 4) {
                actualizarUsuario({ id, accion: 'cambiar_pass', password: nuevaPass });
            } else if (nuevaPass) {
                alert('La contraseña debe tener al menos 4 caracteres.');
            }
        }

        function eliminarUsuario(id, nombre) {
            if (!confirm(`¿Deseas desactivar a ${nombre}?`)) return;
            actualizarUsuario({ id, accion: 'eliminar_usuario' });
        }

        function actualizarUsuario(datos) {
            fetch('api/actualizar_usuario.php', {
                method: 'POST',
                body: JSON.stringify(datos),
                headers: { 'Content-Type': 'application/json' }
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'ok') {
                    location.reload();
                } else {
                    alert('Error: ' + (res.message || 'No se pudo completar la acción'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error de conexión al actualizar usuario');
            });
        }

        document.getElementById('formNuevoUsuario').addEventListener('submit', function(e) {
            e.preventDefault();
            const datos = Object.fromEntries(new FormData(this).entries());
            actualizarUsuario({ accion: 'agregar_usuario', ...datos });
        });
    </script>
</body>
</html>
