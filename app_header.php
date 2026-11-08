<?php
function renderNuviPwaHead() {
    echo '<meta name="theme-color" content="#0f766e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Nuvi">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/nuvi-pwa-192.png">
    <link rel="apple-touch-icon" href="assets/nuvi-pwa-192.png">
    <script defer src="js/nuvi-pwa.js?v=21"></script>';
}

function renderAppHeaderStyles() {
    renderNuviPwaHead();
    echo '<style>
        .app-header-shell {
            max-width: 1280px;
            margin: 0 auto 22px auto;
            padding: 0 10px;
        }

        .app-header {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .app-header.compact {
            padding: 10px 14px;
            border-radius: 12px;
        }

        .app-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .app-brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #2563eb, #0f766e);
            color: white;
            font-size: 1.1rem;
            font-weight: bold;
            flex-shrink: 0;
        }

        .app-brand-text {
            min-width: 0;
        }

        .app-brand-title {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }

        .app-brand-subtitle {
            font-size: 0.82rem;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.1;
        }

        .app-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .app-nav-link {
            text-decoration: none;
            color: #475569;
            font-weight: 700;
            padding: 9px 14px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .app-nav-link:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.18);
        }

        .app-nav-link.active {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .app-nav-link.danger {
            color: #b91c1c;
            background: #fef2f2;
            border-color: #fecaca;
        }

        .app-nav-link.danger:hover {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }

        .app-theme-toggle {
            color: #475569;
            font: inherit;
            font-weight: 700;
            padding: 9px 12px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            white-space: nowrap;
        }

        .app-theme-toggle:hover { background: #e2e8f0; }

        .app-install-button { display: none; font: inherit; cursor: pointer; }
        .app-install-button.available { display: inline-flex; }
        .app-install-button[hidden] { display: none !important; }

        /* Tema compartido. !important es intencional porque las páginas
           históricas tienen algunos colores escritos en línea. */
        html.nuvi-tema-oscuro { color-scheme: dark; }
        html.nuvi-tema-oscuro body { background: #0f172a !important; color: #e2e8f0 !important; }
        html.nuvi-tema-oscuro .app-header,
        html.nuvi-tema-oscuro .contenedor,
        html.nuvi-tema-oscuro .panel,
        html.nuvi-tema-oscuro .card,
        html.nuvi-tema-oscuro .chart-card,
        html.nuvi-tema-oscuro .metodo-card,
        html.nuvi-tema-oscuro .corte-bar,
        html.nuvi-tema-oscuro .card-usuario,
        html.nuvi-tema-oscuro .modal-box,
        html.nuvi-tema-oscuro .modal-content,
        html.nuvi-tema-oscuro .cliente-card { background: #172033 !important; color: #e2e8f0 !important; border-color: #334155 !important; }
        html.nuvi-tema-oscuro h1,
        html.nuvi-tema-oscuro h2,
        html.nuvi-tema-oscuro h3,
        html.nuvi-tema-oscuro h4,
        html.nuvi-tema-oscuro strong,
        html.nuvi-tema-oscuro .app-brand-title,
        html.nuvi-tema-oscuro .metodo-card strong,
        html.nuvi-tema-oscuro .chart-title { color: #f8fafc !important; }
        html.nuvi-tema-oscuro p,
        html.nuvi-tema-oscuro small,
        html.nuvi-tema-oscuro label,
        html.nuvi-tema-oscuro .app-brand-subtitle,
        html.nuvi-tema-oscuro .corte-bar-head { color: #cbd5e1 !important; }
        html.nuvi-tema-oscuro input,
        html.nuvi-tema-oscuro select,
        html.nuvi-tema-oscuro textarea,
        html.nuvi-tema-oscuro [contenteditable="true"] { background: #0f172a !important; color: #f8fafc !important; border-color: #475569 !important; }
        html.nuvi-tema-oscuro table { background: #172033 !important; color: #e2e8f0 !important; }
        html.nuvi-tema-oscuro th { background: #1e293b !important; color: #cbd5e1 !important; border-color: #334155 !important; }
        html.nuvi-tema-oscuro td { border-color: #334155 !important; }
        html.nuvi-tema-oscuro .app-nav-link,
        html.nuvi-tema-oscuro .app-theme-toggle { background: #1e293b; color: #e2e8f0; border-color: #475569; }
        html.nuvi-tema-oscuro .app-nav-link.active { background: #1e3a5f; color: #bfdbfe; border-color: #3b82f6; }
        html.nuvi-tema-oscuro .app-nav-link:hover,
        html.nuvi-tema-oscuro .app-nav-link:focus-visible {
            background: #2563eb;
            color: #ffffff;
            border-color: #60a5fa;
            box-shadow: 0 4px 12px rgba(96, 165, 250, 0.22);
        }
        html.nuvi-tema-oscuro .app-theme-toggle:hover,
        html.nuvi-tema-oscuro .app-theme-toggle:focus-visible { background: #334155; color: #f8fafc; }
        html.nuvi-tema-oscuro .badge {
            background: #334155 !important;
            color: #f8fafc !important;
            border: 1px solid #475569;
        }
        html.nuvi-tema-oscuro .btn-edit {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #475569 !important;
        }
        html.nuvi-tema-oscuro .btn-edit:hover,
        html.nuvi-tema-oscuro .btn-edit:focus-visible {
            background: #334155 !important;
            color: #ffffff !important;
            border-color: #60a5fa !important;
        }
        html.nuvi-tema-oscuro .editable { background: #222c3e !important; color: #f8fafc !important; }
        html.nuvi-tema-oscuro table tbody tr:hover > td { background: #253247 !important; color: #f8fafc !important; }
        html.nuvi-tema-oscuro :focus-visible { outline: 2px solid #60a5fa; outline-offset: 2px; }
        html.nuvi-tema-oscuro .corte-bar-track { background: #334155 !important; }
        html.nuvi-tema-oscuro [style*="background:white"],
        html.nuvi-tema-oscuro [style*="background: white"],
        html.nuvi-tema-oscuro [style*="background-color: white"],
        html.nuvi-tema-oscuro [style*="background-color: #ffffff"] { background: #172033 !important; }

        @media (max-width: 720px) {
            .app-header {
                align-items: stretch;
            }

            .app-nav {
                width: 100%;
                justify-content: stretch;
            }

            .app-nav-link {
                flex: 1 1 150px;
                justify-content: center;
            }

            .app-theme-toggle { flex: 1 1 150px; }
        }
    </style>';

    $temaSesion = ($_SESSION['tema_ui'] ?? 'claro') === 'oscuro' ? 'oscuro' : 'claro';
    $idUsuarioTema = (int) ($_SESSION['id_usuario'] ?? 0);
    echo '<script>(function(){
        const clave = "nuvi_tema_ui_' . $idUsuarioTema . '";
        const desdeSesion = ' . json_encode($temaSesion) . ';
        let tema = desdeSesion;
        try { const local = localStorage.getItem(clave); if (local === "claro" || local === "oscuro") tema = local; } catch (e) {}
        const aplicar = valor => {
            document.documentElement.classList.toggle("nuvi-tema-oscuro", valor === "oscuro");
            const boton = document.getElementById("nuvi-theme-toggle");
            if (boton) { boton.textContent = valor === "oscuro" ? "☀️" : "🌙"; boton.setAttribute("aria-pressed", valor === "oscuro" ? "true" : "false"); }
        };
        aplicar(tema);
        document.addEventListener("DOMContentLoaded", () => {
            aplicar(tema);
            const boton = document.getElementById("nuvi-theme-toggle");
            if (!boton) return;
            boton.addEventListener("click", async () => {
                tema = tema === "oscuro" ? "claro" : "oscuro";
                aplicar(tema);
                try { localStorage.setItem(clave, tema); } catch (e) {}
                try {
                    await fetch("guardar_preferencia_tema.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({tema}) });
                } catch (e) { console.warn("No se pudo sincronizar el tema; se conservará en este equipo.", e); }
            });
        });
    })();</script>';
}

function renderAppHeader($current = '', $compact = false) {
    $links = [
        ['key' => 'pos', 'href' => 'punto_de_venta.php', 'label' => 'Punto de Venta', 'icon' => '🛒'],
        ['key' => 'inventario', 'href' => 'consultar_productos.php', 'label' => 'Inventario', 'icon' => '📦'],
        ['key' => 'registro', 'href' => 'registro_productos.php', 'label' => 'Registrar', 'icon' => '➕'],
        ['key' => 'corte', 'href' => 'corte_caja.php', 'label' => 'Corte', 'icon' => '📊'],
        ['key' => 'historial_cortes', 'href' => 'consultar_cortes.php', 'label' => 'Historial y estadísticas 📊', 'icon' => '📋'],
        ['key' => 'bitacora', 'href' => 'bitacora.php', 'label' => 'Bitácora', 'icon' => '🧾', 'roles' => ['admin']],
        ['key' => 'gestion_usuarios', 'href' => 'gestion_usuarios.php', 'label' => 'Admin Usuarios', 'icon' => '⚙️', 'roles' => ['admin']]
    ];

    echo '<div class="app-header-shell">';
    echo '<div class="app-header' . ($compact ? ' compact' : '') . '">';
    echo '<div class="app-brand">';
    echo '<div class="app-brand-mark">N</div>';
    echo '<div class="app-brand-text">';
    echo '<div class="app-brand-title">Tienda Nuvi 1.2</div>';
    echo '<div class="app-brand-subtitle">Navegación rápida del sistema</div>';
    echo '</div>';
    echo '</div>';
    echo '<nav class="app-nav">';

    foreach ($links as $link) {
        if (!empty($link['roles']) && (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], $link['roles'], true))) {
            continue;
        }

        $classes = 'app-nav-link';
        if ($current === $link['key']) {
            $classes .= ' active';
        }

        echo '<a class="' . $classes . '" href="' . htmlspecialchars($link['href']) . '">';
        echo '<span>' . $link['icon'] . '</span>';
        echo '<span>' . htmlspecialchars($link['label']) . '</span>';
        echo '</a>';
    }

    echo '<button class="app-nav-link app-install-button" id="nuvi-install-app" type="button" hidden><span>📲</span><span>Instalar</span></button>';
    echo '<button class="app-theme-toggle" id="nuvi-theme-toggle" type="button" aria-label="Cambiar modo visual"></button>';
    echo '<a class="app-nav-link danger" href="cerrar_sesion.php"><span>🚪</span><span>Salir</span></a>';
    echo '</nav>';
    echo '</div>';
    echo '</div>';
}
?>
