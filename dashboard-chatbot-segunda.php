<?php
/**
 * ============================================================================
 * DASHBOARD ANALYTICS UAN - CHATBOT
 * ============================================================================
 * Este script genera un panel de control interactivo inyectable directamente
 * dentro de un módulo o artículo de Joomla.
 * 
 * Funcionalidades principales:
 * 1. Autenticación básica por sesión (independiente del login de Joomla).
 * 2. Conexión automática a la base de datos leyendo el configuration.php de Joomla.
 * 3. Renderizado de métricas mediante Chart.js.
 * 4. Exportación de datos a Excel mediante DataTables.
 * ============================================================================
 */
session_start();

// Guardamos la URL base limpia (sin parámetros) para que los botones y formularios 
// no rompan el enrutamiento SEF (URLs amigables) de Joomla al hacer POST o GET.
$current_uri = htmlspecialchars(strtok($_SERVER["REQUEST_URI"], '?'));

// ==========================================
// CONFIGURACIÓN DE SEGURIDAD
// ==========================================
$PASSWORD_SECRETA = "uan2026"; // Contraseña para entrar al dashboard

if (isset($_POST['password'])) {
    if ($_POST['password'] === $PASSWORD_SECRETA) {
        $_SESSION['dashboard_auth'] = true;
    } else {
        $error_msg = "Contraseña incorrecta.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $current_uri);
    exit;
}

if (!isset($_SESSION['dashboard_auth']) || $_SESSION['dashboard_auth'] !== true) {
    // Pantalla de Login Institucional (Adaptada para Joomla)
    ?>
    <style>
        /* Ocultar el título automático de Joomla ("panel-chatbot") */
        h1.mainHeading { display: none !important; }
        
        .uan-login-container {
            display: flex; align-items: center; justify-content: center; 
            padding: 40px 15px; font-family: 'Verdana', sans-serif;
        }
        .login-card { 
            background: white; padding: 3rem 2.5rem; border-radius: 20px; 
            box-shadow: 0 15px 50px rgba(0,40,85,0.15); width: 100%; max-width: 420px; text-align: center; 
            position: relative; overflow: hidden; border: 1px solid #e2e8f0;
        }
        .login-card::before {
            content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 6px;
            background: #e59719; /* Dorado UAN */
        }
        .login-card .btn-primary { 
            background-color: #002855; border: none; padding: 12px; font-family: 'Amaranth', sans-serif; 
            border-radius: 50px; font-size: 1.1rem; letter-spacing: 0.5px; transition: all 0.3s;
        }
        .login-card .btn-primary:hover { background-color: #e59719; color: white; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(229, 151, 25, 0.4); }
        .login-card h3 { font-family: 'Amaranth', sans-serif; color: #002855; font-weight: bold; font-size: 2rem; margin-bottom: 5px; }
        .login-card p { margin-bottom: 30px !important; }
        .login-card .form-control { border-radius: 50px; padding: 12px 20px; border: 2px solid #e2e8f0; font-size: 1rem; transition: all 0.3s; margin-bottom: 25px !important;}
        .login-card .form-control:focus { border-color: #002855; box-shadow: 0 0 0 0.25rem rgba(0, 40, 85, 0.1); }
        .login-card .icon-lock { font-size: 3rem; margin-bottom: 15px; }
    </style>

    <div class="uan-login-container">
        <div class="login-card">
            <div class="icon-lock">🔐</div>
            <h3> Analisis chatbot UAN</h3>
            <p class="text-muted" style="font-size: 0.95rem;">Panel de Control Administrativo</p>
            
            <?php if(isset($error_msg)) echo "<div class='alert alert-danger' style='border-radius: 12px; font-weight: bold; margin-bottom: 20px;'>$error_msg</div>"; ?>
            
            <form method="POST" action="">
                <input type="password" name="password" class="form-control text-center fw-bold" placeholder="Ingresa la contraseña maestra" required>
                <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm">Ingresar al Dashboard</button>
            </form>
            
            <div class="mt-4 text-muted" style="font-size: 0.75rem;">
                Acceso restringido • Uso exclusivo UAN
            </div>
        </div>
    </div>
    <?php
    exit;
}

// ==========================================
// CONEXIÓN A BASE DE DATOS (NATIVA DE JOOMLA)
// ==========================================
/**
 * Para no tener credenciales de base de datos quemadas (hardcoded) en este script, 
 * leemos directamente el archivo configuration.php de la raíz de Joomla.
 * Esto asegura que si Joomla cambia de base de datos o contraseña, el dashboard 
 * seguirá funcionando automáticamente sin necesidad de ajustes.
 */
$ruta_config = JPATH_CONFIGURATION . '/configuration.php';
if (file_exists($ruta_config)) {
    require_once $ruta_config;
    $jconfig = new JConfig();
    $db_host = $jconfig->host;
    $db_user = $jconfig->user;
    $db_pass = $jconfig->password;
    $db_name = $jconfig->db;
} else if (file_exists('configuration.php')) {
    require_once 'configuration.php';
    $jconfig = new JConfig();
    $db_host = $jconfig->host;
    $db_user = $jconfig->user;
    $db_pass = $jconfig->password;
    $db_name = $jconfig->db;
} else {
    die("No se pudo cargar la configuración de Joomla. Por seguridad, no hay credenciales por defecto.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset("utf8mb4"); 
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// ==========================================
// TRADUCTOR DE CATEGORÍAS TÉCNICAS A HUMANAS
// ==========================================
/**
 * mapear_origen()
 * 
 * El chatbot guarda internamente los orígenes de las respuestas en formatos 
 * técnicos o variados (ej. 'NLP_SHEETS', 'IA', 'CLIC DE MENU').
 * Esta función normaliza esos textos eliminando tildes y agrupándolos en 
 * 4 grandes categorías oficiales para mostrar estadísticas limpias en la gráfica.
 *
 * @param string $origen El origen técnico guardado en la base de datos
 * @return string La categoría humanizada (ej. 'Inteligencia Artificial (Gemini)')
 */




// ==========================================
// ENDPOINTS AJAX (MODO WHATSAPP) - SAFE FOR JOOMLA
// ==========================================
if (isset($_GET['ajax'])) {
    $f_inicio = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
    $f_fin = $_GET['end'] ?? date('Y-m-d');
    $f_inicio_full = $f_inicio . ' 00:00:00';
    $f_fin_full = $f_fin . ' 23:59:59';
    
    if ($_GET['ajax'] == 'get_sessions') {
        $query = "SELECT s.id_sesion, s.codigo_navegador, s.tipo_usuario, MAX(m.fecha_hora) as ultima_actividad,
                  COUNT(m.id_mensaje) as total_mensajes
                  FROM chatbot_sesiones s
                  JOIN chatbot_mensajes m ON s.id_sesion = m.id_sesion
                  WHERE m.fecha_hora >= '$f_inicio_full' AND m.fecha_hora <= '$f_fin_full'
                  GROUP BY s.id_sesion, s.codigo_navegador, s.tipo_usuario
                  ORDER BY ultima_actividad DESC
                  LIMIT 500";
        $res = $conn->query($query);
        $sesiones = [];
        if($res) {
            while ($row = $res->fetch_assoc()) { $sesiones[] = $row; }
        }
        echo "<!--AJAX_START-->" . json_encode($sesiones) . "<!--AJAX_END-->";
        exit;
    }
    
    if ($_GET['ajax'] == 'get_chat' && isset($_GET['id_sesion'])) {
        $id_sesion = (int)$_GET['id_sesion'];
        $query = "SELECT m.quien_escribio, m.mensaje, m.origen_respuesta, m.fecha_hora, m.calificacion
                  FROM chatbot_mensajes m
                  WHERE m.id_sesion = $id_sesion
                  ORDER BY m.fecha_hora ASC";
        $res = $conn->query($query);
        $mensajes = [];
        if($res) {
            while ($row = $res->fetch_assoc()) { $mensajes[] = $row; }
        }
        echo "<!--AJAX_START-->" . json_encode($mensajes) . "<!--AJAX_END-->";
        exit;
    }
}

function mapear_origen($origen) {
    if (empty($origen)) return '-';
    // Quitamos tildes y pasamos a mayúsculas para evitar errores de agrupación
    $unwanted_array = array('Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                            'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                            'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
    
    $o = strtr($origen, $unwanted_array);
    $o = strtoupper(trim($o));
    
    // Respuestas generadas por el Bot:
    if (in_array($o, ['IA', 'INTELIGENCIA ARTIFICIAL', 'IA (MENU)', 'NLP_SHEETS', 'EXCEL', 'JSON_LOCAL', 'RAG_MARKDOWN'])) return 'IA RAG (Gemini + Base Markdown)';
    if (in_array($o, ['MENUS', 'MENU', 'BASE LOCAL', 'SERVICES', 'JPROGS', 'JOOMLA'])) return 'Menú Interactivo (Botones)';
    if (in_array($o, ['MENSAJE DE BIENVENIDA', 'MENSAJE POR DEFECTO', 'SISTEMA'])) return 'Mensajes del Sistema';
    
    // Mensajes enviados por el Usuario:
    if (in_array($o, ['TEXTO LIBRE', 'USUARIO'])) return 'Usuario (Escribió en teclado)';
    if (in_array($o, ['CLIC DE MENU', 'CLIC DE USUARIO'])) return 'Usuario (Hizo clic en botón)';
    if ($o === 'AUTO-PERFIL') return 'Memoria del Chatbot (Auto-Perfil)';
    
    return 'Otro (' . htmlspecialchars($origen) . ')';
}

// ==========================================
// CONSULTAS DE ESTADÍSTICAS Y FILTROS
// ==========================================
try {
    /**
     * Lógica del Filtro de Fechas:
     * Toma las fechas enviadas por la URL (GET) desde el calendario Flatpickr.
     * Si no se envían fechas, por defecto calcula los últimos 7 días.
     */
    $f_inicio = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
    $f_fin = $_GET['end'] ?? date('Y-m-d');
    
    // Validación básica de formato de fecha
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_inicio)) $f_inicio = date('Y-m-d', strtotime('-7 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_fin)) $f_fin = date('Y-m-d');
    
    $f_inicio_full = $f_inicio . ' 00:00:00';
    $f_fin_full = $f_fin . ' 23:59:59';

    // --- 0. KPI SCORECARDS ---
    // A) Total de Usuarios Únicos (Sesiones)
    $res_usuarios = $conn->query("SELECT COUNT(DISTINCT id_sesion) as total FROM chatbot_mensajes WHERE fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full'");
    $kpi_usuarios = $res_usuarios ? $res_usuarios->fetch_assoc()['total'] : 0;

    // B) Hora Pico
    $res_hora = $conn->query("SELECT HOUR(fecha_hora) as hora, COUNT(*) as total FROM chatbot_mensajes WHERE fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full' GROUP BY HOUR(fecha_hora) ORDER BY total DESC LIMIT 1");
    if ($res_hora && $res_hora->num_rows > 0) {
        $row_hora = $res_hora->fetch_assoc();
        $hora_num = (int)$row_hora['hora'];
        $am_pm = $hora_num >= 12 ? 'PM' : 'AM';
        $hora_12 = $hora_num % 12;
        if ($hora_12 == 0) $hora_12 = 12;
        $kpi_hora = $hora_12 . ':00 ' . $am_pm;
    } else {
        $kpi_hora = "N/A";
    }

    // C) Porcentaje de Aprobación IA (Feedback)
    $res_feedback = $conn->query("SELECT calificacion, COUNT(*) as total FROM chatbot_mensajes WHERE calificacion IS NOT NULL AND fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full' GROUP BY calificacion");
    $total_votos = 0;
    $votos_positivos = 0;
    $votos_negativos = 0;
    if ($res_feedback) {
        while ($row_feedback = $res_feedback->fetch_assoc()) {
            $total_votos += $row_feedback['total'];
            if ((int)$row_feedback['calificacion'] === 1) {
                $votos_positivos += $row_feedback['total'];
            } elseif ((int)$row_feedback['calificacion'] === -1) {
                $votos_negativos += $row_feedback['total'];
            }
        }
    }
    $kpi_feedback_porcentaje = ($total_votos > 0) ? round(($votos_positivos / $total_votos) * 100) : 0;
    $kpi_feedback_porcentaje = ($total_votos > 0) ? round(($votos_positivos / $total_votos) * 100) : 0;

    // D) Top 10 Preguntas (Para el Modal)
    $res_top = $conn->query("SELECT mensaje, COUNT(*) as total FROM chatbot_mensajes WHERE quien_escribio = 'Usuario' AND TRIM(mensaje) != '' AND LOWER(TRIM(mensaje)) NOT IN ('hola', 'hola!', 'buenos dias', 'buenas tardes', 'gracias', 'gracias!', 'adios', 'asesor', 'ninguna de las anteriores') AND fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full' GROUP BY mensaje HAVING total >= 2 ORDER BY total DESC LIMIT 10");
    $kpi_top_list = [];
    if ($res_top) {
        while ($row_top = $res_top->fetch_assoc()) {
            $texto_limpio = strip_tags($row_top['mensaje']);
            if (mb_strlen($texto_limpio) > 70) $texto_limpio = mb_substr($texto_limpio, 0, 70) . '...';
            $kpi_top_list[] = ['texto' => $texto_limpio, 'cantidad' => $row_top['total']];
        }
    }

    // E) Últimos Votos Negativos (Para el Modal)
    $res_bad = $conn->query("SELECT id_sesion, mensaje, fecha_hora FROM chatbot_mensajes WHERE calificacion = -1 AND fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full' ORDER BY fecha_hora DESC LIMIT 10");
    $kpi_bad_list = [];
    if ($res_bad) {
        while ($row_bad = $res_bad->fetch_assoc()) {
            $texto_limpio = strip_tags($row_bad['mensaje']);
            if (mb_strlen($texto_limpio) > 100) $texto_limpio = mb_substr($texto_limpio, 0, 100) . '...';
            $kpi_bad_list[] = [
                'texto' => $texto_limpio, 
                'fecha' => date('d M, h:i a', strtotime($row_bad['fecha_hora'])),
                'id_sesion' => $row_bad['id_sesion']
            ];
        }
    }

    // 1. Orígenes de respuesta (Doughnut chart agrupado)
    $origenes_data = [];
    $res_origenes = $conn->query("SELECT origen_respuesta, COUNT(*) as total FROM chatbot_mensajes WHERE origen_respuesta IS NOT NULL AND quien_escribio = 'Bot' AND fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full' GROUP BY origen_respuesta");
    while ($row = $res_origenes->fetch_assoc()) {
        if ($row['origen_respuesta'] === 'Auto-Perfil') continue;
        $cat = mapear_origen($row['origen_respuesta']);
        if (!isset($origenes_data[$cat])) $origenes_data[$cat] = 0;
        $origenes_data[$cat] += $row['total'];
    }

    // 2. Mensajes por día (Bar chart)
    $dias_data = [];
    $res_dias = $conn->query("
        SELECT DATE(fecha_hora) as dia, COUNT(*) as total 
          FROM chatbot_mensajes 
          WHERE quien_escribio = 'Usuario' AND fecha_hora >= '$f_inicio_full' AND fecha_hora <= '$f_fin_full'
          GROUP BY DATE(fecha_hora) 
        ORDER BY DATE(fecha_hora) ASC
    ");
    if($res_dias) {
        while ($row = $res_dias->fetch_assoc()) {
            $dias_data[$row['dia']] = $row['total'];
        }
    }

    // 3. Tabla de mensajes con JOIN a sesiones
    $mensajes_query = "
        SELECT 
            m.id_mensaje, 
            s.id_sesion,
            s.codigo_navegador, 
            s.tipo_usuario, 
            m.quien_escribio, 
            m.mensaje, 
            m.origen_respuesta,
            m.fecha_hora as fecha,
            m.calificacion
        FROM chatbot_mensajes m
        JOIN chatbot_sesiones s ON m.id_sesion = s.id_sesion
        WHERE m.fecha_hora >= '$f_inicio_full' AND m.fecha_hora <= '$f_fin_full'
        ORDER BY m.id_mensaje DESC
        LIMIT 2000
    "; // Límite de 2000 para mantener el navegador fluido

    $res_mensajes = $conn->query($mensajes_query);
} catch (Exception $e) {
    die("<h1>Error de Base de Datos</h1><p>El error fue: " . $e->getMessage() . "</p>");
}

?>
<!-- ==========================================
     INICIO DE LA INTERFAZ DE USUARIO (DASHBOARD)
     ========================================== -->
    <!-- Fuentes Corporativas UAN -->
    <link href="https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS (Sistema de grillas y componentes) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- CSS DataTables + Botones (Para la tabla interactiva y exportación a Excel) -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <!-- Flatpickr (Selector de rango de fechas estilo Píldora) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    /* Ocultar el título automático de Joomla en todo el módulo */
    h1.mainHeading { display: none !important; }
    
    :root {
        /* Paleta Institucional UAN */
        --uan-dark-blue: #002855;
            --uan-mid-blue: #173f73;
            --uan-gold: #e59719;
            --bg-color: #f8fafc;
            --card-border: #e2e8f0;
            --text-main: #333333;
        }
        body { 
            background-color: var(--bg-color); 
            font-family: 'Verdana', sans-serif; 
            color: var(--text-main);
            font-size: 0.95rem;
        }
        h1, h2, h3, h4, h5, h6, .navbar-brand, .card-header, th {
            font-family: 'Amaranth', sans-serif;
        }
        
        
        /* BREAKOUT DEL CONTENEDOR DE JOOMLA PARA PANTALLA COMPLETA RELATIVA */
        .uan-dashboard-breakout {
            width: 100vw;
            position: relative;
            left: 50%;
            right: 50%;
            margin-left: -50vw;
            margin-right: -50vw;
            background: var(--bg-color);
            padding-bottom: 2rem;
            min-height: calc(100vh - 100px);
            z-index: 100;
        }
        
        /* Estilo de Botones Píldora (Pills) */
        .custom-pills .nav-link {
            border: 2px solid var(--uan-dark-blue) !important;
            border-radius: 50px !important;
            color: var(--uan-dark-blue) !important;
            background-color: white !important;
            font-family: 'Amaranth', sans-serif !important;
            font-weight: bold !important;
            padding: 10px 30px !important;
            transition: all 0.3s ease !important;
            min-width: 200px;
            text-align: center;
        }
        .custom-pills .nav-link.active {
            background-color: var(--uan-dark-blue) !important;
            color: white !important;
            box-shadow: 0 4px 10px rgba(0, 40, 85, 0.3) !important;
        }
        .custom-pills .nav-link:hover:not(.active) {
            background-color: #f8fafc !important;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1) !important;
        }
        /* Navbar Corporativo */
        .navbar-uan { 
            background-color: var(--uan-dark-blue); 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-bottom: 3px solid var(--uan-gold);
            margin-top: -30px; /* Sube la barra para cerrar el hueco de Joomla */
        }
        .navbar-brand { font-size: 1.4rem; letter-spacing: 0.5px; font-weight: bold; }
        
        /* Cards Profesionales */
        .card { 
            background: white;
            border: 1px solid var(--card-border); 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); 
            margin-bottom: 24px; 
        }
        .card-header { 
            background-color: transparent; 
            border-bottom: 1px solid var(--card-border); 
            color: var(--uan-dark-blue); 
            padding: 1rem 1.5rem;
            font-size: 1.15rem;
        }
        .chart-container { position: relative; height: 320px; width: 100%; padding: 10px;}
        
        /* Badges Sobrios */
        .badge-user { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px 8px; font-weight: normal; font-size:0.85em;}
        .badge-bot { background-color: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 4px; padding: 4px 8px; font-weight: normal; font-size:0.85em;}
        .badge-tag { background-color: #f8fafc; color: var(--uan-mid-blue); border: 1px solid var(--card-border); border-radius: 4px; padding: 4px 8px; font-weight: normal; font-size:0.85em;}
        
        /* Tabla Corporativa */
        table.dataTable { border-collapse: collapse !important; width: 100%; margin-top: 15px !important;}
        table.dataTable thead th { border-bottom: 2px solid var(--uan-dark-blue); color: var(--uan-dark-blue); font-size: 0.95rem; font-weight: 700;}
        table.dataTable tbody tr { background-color: white; border-bottom: 1px solid var(--card-border); transition: background-color 0.2s;}
        table.dataTable tbody tr:hover { background-color: #f8fafc; }
        table.dataTable td { font-size: 0.9rem; vertical-align: top; padding: 12px 10px;}
        
        /* Formulario Filtros */
        .filter-bar {
            background: white;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid var(--card-border);
            margin-bottom: 25px;
        }
        .form-control { border-radius: 4px; border: 1px solid #cbd5e1; font-size: 0.9rem;}
        .form-control:focus { border-color: var(--uan-mid-blue); box-shadow: 0 0 0 0.2rem rgba(23, 63, 115, 0.25);}
        .btn-primary { background-color: var(--uan-dark-blue); border: none; border-radius: 4px; font-family: 'Amaranth', sans-serif; letter-spacing: 0.5px;}
        .btn-primary:hover { background-color: var(--uan-mid-blue); }
        .btn-outline-secondary { border-color: #cbd5e1; color: #475569; border-radius: 4px;}
        
        /* Botón Excel Flotante */
        .dt-buttons .btn-success { background-color: #10b981 !important; border: none !important; border-radius: 8px !important; font-family: 'Amaranth', sans-serif !important; padding: 8px 20px !important; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2) !important;}
        
        /* Barra de Búsqueda Mejorada */
        .custom-search label { font-weight: bold; color: var(--uan-dark-blue); font-size: 0.95rem; display: flex; align-items: center; gap: 10px; margin-bottom: 0; }
        .custom-search input[type="search"] { border-radius: 50px !important; border: 2px solid #e2e8f0 !important; padding: 8px 20px !important; min-width: 280px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); transition: all 0.3s; }
        .custom-search input[type="search"]:focus { border-color: var(--uan-dark-blue) !important; outline: none; box-shadow: 0 4px 15px rgba(0,40,85,0.1); }

        
        /* Personalización Premium Flatpickr (Tema UAN) */
        .flatpickr-calendar { font-family: 'Verdana', sans-serif !important; border: none !important; box-shadow: 0 10px 40px rgba(0,40,85,0.2) !important; border-radius: 12px !important; padding: 5px !important;}
        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus, .flatpickr-day.selected:hover, .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover, .flatpickr-day.selected.prevMonthDay, .flatpickr-day.startRange.prevMonthDay, .flatpickr-day.endRange.prevMonthDay, .flatpickr-day.selected.nextMonthDay, .flatpickr-day.startRange.nextMonthDay, .flatpickr-day.endRange.nextMonthDay {
            background: var(--uan-dark-blue) !important; border-color: var(--uan-dark-blue) !important;
        }
        .flatpickr-day.inRange, .flatpickr-day.prevMonthDay.inRange, .flatpickr-day.nextMonthDay.inRange, .flatpickr-day.today.inRange, .flatpickr-day.prevMonthDay.today.inRange, .flatpickr-day.nextMonthDay.today.inRange, .flatpickr-day:hover, .flatpickr-day.prevMonthDay:hover, .flatpickr-day.nextMonthDay:hover, .flatpickr-day:focus, .flatpickr-day.prevMonthDay:focus, .flatpickr-day.nextMonthDay:focus {
            background: #f1f5f9 !important; border-color: #f1f5f9 !important;
        }
        .flatpickr-day.today { border-color: var(--uan-gold) !important; color: var(--uan-dark-blue) !important; font-weight: bold; border-width: 2px !important;}
        
        /* Forzar visibilidad del Año que Joomla estaba ocultando */
        .flatpickr-current-month { display: flex !important; justify-content: center !important; align-items: center !important; }
        .flatpickr-current-month .flatpickr-monthDropdown-months { font-family: 'Amaranth', sans-serif !important; color: var(--uan-dark-blue) !important; font-weight: bold !important; appearance: auto !important; margin-right: 5px !important; }
        .flatpickr-current-month .numInputWrapper { display: inline-block !important; width: 6ch !important; visibility: visible !important; opacity: 1 !important; }
        .flatpickr-current-month .numInputWrapper input.cur-year { display: inline-block !important; color: var(--uan-dark-blue) !important; font-weight: bold !important; font-family: 'Amaranth', sans-serif !important; background: transparent !important; padding: 0 !important; border: none !important; }
        .flatpickr-months .flatpickr-prev-month, .flatpickr-months .flatpickr-next-month { fill: var(--uan-dark-blue) !important; }
        
        /* Píldora de Rango Oscura */
        #date_range::placeholder { color: rgba(255,255,255,0.7) !important; }
        #date_range:focus { box-shadow: none !important; }
    </style>

<div class="uan-dashboard-breakout">
<nav class="navbar navbar-expand-lg navbar-uan mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand text-white" href="#">📊 Panel Chatbot - UAN</a>
        <div class="d-flex">
            <a href="<?= $current_uri ?>?logout=1" class="btn btn-outline-light btn-sm" style="font-family:'Amaranth', sans-serif;">Cerrar Sesión</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">
    
    
    <!-- Nav Tabs para cambiar de vista -->
    <ul class="nav nav-pills custom-pills mb-4 d-flex gap-3 justify-content-center" id="dashboardTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics" type="button" role="tab" >Analíticas Generales</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="chats-tab" data-bs-toggle="tab" data-bs-target="#chats" type="button" role="tab" >Historial de Sesiones (Chat)</button>
        </li>
    </ul>

    <div class="tab-content" id="dashboardTabsContent">
        <!-- PESTAÑA 1: ANALÍTICAS (CONTENIDO ORIGINAL) -->
        <div class="tab-pane fade show active" id="analytics" role="tabpanel">
<!-- Barra de Filtros Minimalista -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0 text-uan-dark" style="font-family:'Amaranth', sans-serif; color: var(--uan-dark-blue);">Resumen Estadístico</h4>
        
        <form method="GET" action="<?= $current_uri ?>" id="filterForm" class="d-flex align-items-center gap-2 m-0">
            <!-- Rango de Fechas (Píldora interactiva estilo Móvil Premium) -->
            <div class="input-group" style="width: 310px; box-shadow: 0 6px 15px rgba(0,40,85,0.15); border-radius: 50px; background: var(--uan-dark-blue); transition: transform 0.2s;">
                <span class="input-group-text bg-transparent border-0 ps-4 pe-2" style="color: white; font-size: 1.1rem;">
                    📅
                </span>
                <input type="text" id="date_range" class="form-control border-0 fw-bold bg-transparent text-white" style="cursor: pointer; text-align: center; font-size: 0.95rem; padding-left: 0; padding-right: 20px; letter-spacing: 0.5px;" placeholder="Selecciona un rango..." readonly>
            </div>
            
            <!-- Campos ocultos para mantener compatibilidad PHP -->
            <input type="hidden" name="start" id="hidden_start" value="<?= htmlspecialchars($f_inicio) ?>">
            <input type="hidden" name="end" id="hidden_end" value="<?= htmlspecialchars($f_fin) ?>">
            
            <!-- Botón Limpiar Minimalista -->
            <a href="<?= $current_uri ?>" class="btn btn-light rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; border: 1px solid #cbd5e1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); color: #475569; text-decoration: none; transition: background 0.2s;" title="Limpiar Filtros" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'">
                <span style="font-size: 1.5rem; line-height: 1; margin-top: -2px;">&times;</span>
            </a>
        </form>
    </div>

    <!-- Fila de Tarjetas de Resumen (KPIs) -->
    
<style>
.kpi-card-premium .card {
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.kpi-card-premium .card:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.06) !important;
}
.kpi-watermark {
    position: absolute;
    right: -10px;
    bottom: -15px;
    font-size: 8rem;
    opacity: 0.06;
    z-index: 0;
    transform: rotate(-10deg);
}
.kpi-content {
    position: relative;
    z-index: 1;
}
</style>

    <div class="d-flex flex-wrap justify-content-between mb-4 w-100 gap-3">
        
        <!-- 1. Usuarios Únicos -->
        <div class="kpi-card-premium flex-fill" style="min-width: 200px; max-width: 24%;">
            <div class="card h-100 border-0" style="border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 60%); padding: 1.5rem;">
                <i class="fa fa-users kpi-watermark" style="color:#0284c7;"></i>
                <div class="kpi-content">
                    <div class="d-flex align-items-center mb-4">
                        <div style="width: 50px; height: 50px; border-radius: 12px; background: #e0f2fe; color: #0284c7; font-size: 1.5rem; display: flex; align-items: center; justify-content: center;">
                            <i class="fa fa-users"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <span style="font-size: 0.6rem; color: #1e293b;">▲</span>
                        <span style="font-size: 0.8rem; font-weight: 800; color: #334155; letter-spacing: 0.5px; text-transform: uppercase;">Sesiones</span>
                    </div>
                    <div style="font-size: 0.65rem; font-weight: 700; color: #94a3b8; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 5px;">
                        TOTAL DE USUARIOS
                    </div>
                    <div style="font-size: 2.2rem; font-weight: 900; color: #0f172a; font-family: 'Arial', sans-serif; line-height: 1.1; letter-spacing: -1px;">
                        <?= $kpi_usuarios ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Hora Pico -->
        <div class="kpi-card-premium flex-fill" style="min-width: 200px; max-width: 24%;">
            <div class="card h-100 border-0" style="border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 60%); padding: 1.5rem;">
               <i class="fa fa-clock-o kpi-watermark" style="color:#16a34a;"></i>
                <div class="kpi-content">
                    <div class="d-flex align-items-center mb-4">
                        <div style="width: 50px; height: 50px; border-radius: 12px; background: #dcfce7; color: #16a34a; font-size: 1.5rem; display: flex; align-items: center; justify-content: center;">
                            <i class="fa fa-clock"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <span style="font-size: 0.6rem; color: #1e293b;">▲</span>
                        <span style="font-size: 0.8rem; font-weight: 800; color: #334155; letter-spacing: 0.5px; text-transform: uppercase;">Tráfico</span>
                    </div>
                    <div style="font-size: 0.65rem; font-weight: 700; color: #94a3b8; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 5px;">
                        HORA DE MAYOR USO
                    </div>
                    <div style="font-size: 2.2rem; font-weight: 900; color: #0f172a; font-family: 'Arial', sans-serif; line-height: 1.1; letter-spacing: -1px;">
                        <?= $kpi_hora ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Feedback IA -->
        <div class="kpi-card-premium flex-fill" style="min-width: 200px; max-width: 24%; cursor: pointer; transition: transform 0.2s ease;" data-bs-toggle="modal" data-bs-target="#topFeedbackModal" onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
            <div class="card h-100 border-0" style="border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); background: linear-gradient(135deg, #ecfdf5 0%, #ffffff 60%); padding: 1.5rem;">
                <i class="fa fa-thumbs-up kpi-watermark" style="color:#10b981;"></i>
                <div class="kpi-content">
                    <div class="d-flex align-items-center mb-4">
                        <div style="width: 50px; height: 50px; border-radius: 12px; background: #d1fae5; color: #10b981; font-size: 1.5rem; display: flex; align-items: center; justify-content: center;">
                            <i class="fa fa-thumbs-up"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <span style="font-size: 0.6rem; color: #10b981;">▲</span>
                        <span style="font-size: 0.8rem; font-weight: 800; color: #334155; letter-spacing: 0.5px; text-transform: uppercase;">Aprobación IA</span>
                    </div>
                    <div style="font-size: 0.65rem; font-weight: 700; color: #94a3b8; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 5px;">
                        POSITIVOS VS NEGATIVOS (<?= $total_votos ?> TOTALES)
                    </div>
                    <div style="font-size: 1.8rem; font-weight: 900; color: #0f172a; font-family: 'Arial', sans-serif; line-height: 1.1; letter-spacing: -1px; display: flex; align-items: center; gap: 15px;">
                        <span style="color: #10b981;">👍 <?= $votos_positivos ?></span>
                        <span style="color: #ef4444; font-size: 1.5rem;">👎 <?= $votos_negativos ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Top Consultas -->
        <div class="kpi-card-premium flex-fill" style="min-width: 200px; max-width: 24%; cursor: pointer; transition: transform 0.2s ease;" data-bs-toggle="modal" data-bs-target="#topConsultasModal" onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
            <div class="card h-100 border-0" style="border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); background: linear-gradient(135deg, #faf5ff 0%, #ffffff 60%); padding: 1.5rem;">
                <i class="fa fa-trophy kpi-watermark" style="color:#9333ea;"></i>
                <div class="kpi-content">
                    <div class="d-flex align-items-center mb-4">
                        <div style="width: 50px; height: 50px; border-radius: 12px; background: #f3e8ff; color: #9333ea; font-size: 1.5rem; display: flex; align-items: center; justify-content: center;">
                            <i class="fa fa-trophy"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <span style="font-size: 0.6rem; color: #1e293b;">▲</span>
                        <span style="font-size: 0.8rem; font-weight: 800; color: #334155; letter-spacing: 0.5px; text-transform: uppercase;">Consultas</span>
                    </div>
                    <div style="font-size: 0.65rem; font-weight: 700; color: #94a3b8; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 5px;">
                        RANKING DE TEMAS
                    </div>
                    <div style="font-size: 1.1rem; font-weight: 800; color: #9333ea; font-family: 'Arial', sans-serif; line-height: 1.1; margin-top: 8px;">
                        Ver Top 10 🔍 <i class="fa fa-arrow-right" style="font-size: 0.8rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fila de Gráficas -->
    <div class="row">
        <!-- Gráfica de Orígenes -->
        <div class="col-lg-5 col-md-12">
            <div class="card h-100">
                <div class="card-header">
                    Métodos de Interacción y Resolución
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <div class="chart-container">
                        <canvas id="origenesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráfica de Volúmen -->
        <div class="col-lg-7 col-md-12">
            <div class="card h-100">
                <div class="card-header">
                    Volumen de Interacciones Diarias
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="diasChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fila de Tabla -->
    <div class="row mt-2">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    Historial de Conversaciones
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="overflow-x: hidden;">
                        <table id="mensajesTable" class="table w-100">
                            <thead>
                                <tr>
                                    <th style="min-width: 140px;">Fecha y Hora</th>
                                    <th style="width: 100px; text-align: center;">Enviado Por</th>
                                    <th style="width: 50%;">Mensaje</th>
                                    <th>Origen de Respuesta</th>
                                    <th style="text-align: center; min-width: 90px;">Feed.</th>
                                    <th style="text-align: center; min-width: 110px;">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($m = $res_mensajes->fetch_assoc()): 
                                    $emisor = ($m['quien_escribio'] == 'Usuario') ? 'Usuario' : 'Bot UAN';
                                    $categoria_origen = mapear_origen($m['origen_respuesta']);
                                    $id_ses = (int)($m['id_sesion'] ?? 0);
                                    $cod_nav = htmlspecialchars(substr($m['codigo_navegador'] ?? 'Anon', 0, 20), ENT_QUOTES, 'UTF-8');
                                    $tipo_u = htmlspecialchars($m['tipo_usuario'] ?? 'General', ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td class="text-muted" style="vertical-align: middle;"><?= htmlspecialchars($m['fecha']) ?></td>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <span class="<?= ($emisor == 'Usuario') ? 'badge-user' : 'badge-bot' ?>">
                                            <?= htmlspecialchars($emisor) ?>
                                        </span>
                                    </td>
                                    <td style="white-space: normal; word-wrap: break-word; line-height: 1.5; font-size: 0.95rem; vertical-align: middle; padding: 15px 10px;">
                                        <?= htmlspecialchars(strip_tags($m['mensaje'])) ?>
                                    </td>
                                    <td style="vertical-align: middle;">
                                        <?php if($categoria_origen != '-'): ?>
                                            <span class="badge-tag"><?= htmlspecialchars($categoria_origen) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <?php if ((string)$m['calificacion'] === '1'): ?>
                                            <span style="color: #10b981; font-size: 1.2rem;" title="Me sirvió">👍</span>
                                        <?php elseif ((string)$m['calificacion'] === '-1'): ?>
                                            <span style="color: #ef4444; font-size: 1.2rem;" title="No me sirvió">👎</span>
                                        <?php else: ?>
                                            <span class="text-muted" title="Sin calificación">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold" onclick="abrirChatSesion(<?= $id_ses ?>, '<?= $cod_nav ?>', '<?= $tipo_u ?>')" title="Ver la conversación completa de esta sesión" style="font-size: 0.8rem; border-color: var(--uan-dark-blue); color: var(--uan-dark-blue); transition: all 0.2s;">
                                            💬 Ver Chat
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>


        </div> <!-- Fin Pestaña 1 -->

        <!-- PESTAÑA 2: CONVERSACIONES ESTILO WHATSAPP -->
        <div class="tab-pane fade" id="chats" role="tabpanel">
            <div class="row g-0" style="height: calc(100vh - 200px); min-height: 500px; overflow: hidden; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); background: #fff;">
                
                <!-- Panel Izquierdo: Lista de Sesiones -->
                <div class="col-md-4 p-0 border-end" style="height: 100%; display: flex; flex-direction: column; background: #fff;">
                    <div class="p-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, var(--uan-dark-blue), var(--uan-mid-blue)); border-radius: 20px 0 0 0;">
    <h6 class="mb-0 text-white" style="font-family:'Amaranth', sans-serif;"><i class="fa fa-users"></i> Sesiones Activas</h6>
    <button class="btn btn-sm" onclick="loadSessions()" title="Recargar" style="background: rgba(255,255,255,0.15); color: white; border: none; border-radius: 50%; width: 32px; height: 32px; display:flex; align-items:center; justify-content:center;"><i class="fa fa-sync"></i></button>
</div>
                    <!-- Barra de búsqueda -->
                    <div class="p-3 bg-white border-bottom position-relative">
    <i class="fa fa-search" style="position:absolute; left:28px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.8rem;"></i>
    <input type="text" id="search-sessions" class="form-control form-control-sm ps-4" style="border-radius: 50px; border: 1px solid #e2e8f0; padding-left: 35px !important;" placeholder="Buscar por ID de usuario..." onkeyup="filterSessions()">
</div>
                    <div id="sessions-list" style="overflow-y: auto; flex: 1; min-height: 0;">
                        <div class="p-4 text-center text-muted">Cargando sesiones...</div>
                    </div>
                </div>

                <!-- Panel Derecho: Ventana de Chat -->
                <div class="col-md-8 p-0" style="height: 100%; display: flex; flex-direction: column; background: #f0f2f5;">
                    <div class="p-3 bg-white border-bottom" id="chat-header-container" style="border-radius: 0 20px 0 0;">
    <h5 class="mb-0" id="chat-header-title" style="font-family:'Amaranth', sans-serif; color: var(--uan-dark-blue);">Selecciona una conversación</h5>
    <small class="text-muted" id="chat-header-subtitle">Historial completo del usuario</small>
</div>

<div id="chat-window" class="p-4" style="flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 15px; background-image: radial-gradient(#d2d6db 1px, transparent 0); background-size: 20px 20px;">
    <div class="text-center" style="opacity: 0.85;">
        <div style="width: 90px; height: 90px; border-radius: 50%; background: #eef4ff; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <span style="font-size: 2.2rem;">💬</span>
        </div>
        <h6 style="font-family:'Amaranth', sans-serif; color: var(--uan-dark-blue); margin-bottom: 6px;">Ninguna conversación seleccionada</h6>
        <p class="text-muted" style="font-size: 0.85rem;">Elige una sesión de la lista para ver el historial completo</p>
    </div>
</div>
                </div>

            </div>
        </div> <!-- Fin Pestaña 2 -->
    </div> <!-- Fin Tab Content -->
</div>
</div> <!-- Fin Breakout -->


<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables & Botones -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Flatpickr (Calendario) -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/es.js"></script>

<script>
    $(document).ready(function() {
        // Inicializar Calendario de Rango Moderno
        flatpickr("#date_range", { 
            mode: "range",
            locale: "es", 
            dateFormat: "Y-m-d",
            defaultDate: ["<?= $f_inicio ?>", "<?= $f_fin ?>"],
            altInput: true,
            altFormat: "d M, Y", // Ej: 17 Jul, 2026
            onClose: function(selectedDates, dateStr, instance) {
                // Autoguardar cuando seleccionen 2 fechas (inicio y fin)
                if (selectedDates.length === 2) {
                    const start = instance.formatDate(selectedDates[0], "Y-m-d");
                    const end = instance.formatDate(selectedDates[1], "Y-m-d");
                    document.getElementById('hidden_start').value = start;
                    document.getElementById('hidden_end').value = end;
                    document.getElementById('filterForm').submit();
                }
            }
        });

        // Inicializar Tabla
        var table = $('#mensajesTable').DataTable({
            language: { 
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                search: "🔍 Buscar:"
            },
            order: [[0, 'desc']],
            dom: '<"d-flex flex-wrap align-items-center gap-4 mb-4"B<"custom-search"f>>rt<"d-flex justify-content-between align-items-center mt-4"ip>',
            buttons: [
                {
                    extend: 'excelHtml5',
                    text: 'Exportar a Excel',
                    className: 'btn btn-success',
                    title: 'Historial_Chatbot_UAN'
                }
            ],
            pageLength: 20
        });

        // Configuración Global Chart.js
        Chart.defaults.font.family = "'Verdana', sans-serif";
        Chart.defaults.color = '#333333';

        // Paleta de la gráfica Doughnut (Alto contraste)
        // Usamos colores muy distintos para que sea fácil diferenciarlos
        const uanPalette = [
            '#002855', // Azul muy oscuro (UAN)
            '#e59719', // Dorado (UAN)
            '#00B4D8', // Celeste brillante
            '#64748b', // Gris pizarra
            '#10b981'  // Verde esmeralda (por si acaso sale una 5ta categoría)
        ];

        // Gráfica de Orígenes
        const origenesData = <?= json_encode($origenes_data) ?>;
        if(Object.keys(origenesData).length > 0) {
            const ctxOrigenes = document.getElementById('origenesChart').getContext('2d');
            new Chart(ctxOrigenes, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(origenesData),
                    datasets: [{
                        data: Object.values(origenesData),
                        backgroundColor: uanPalette,
                        borderWidth: 1,
                        borderColor: '#ffffff',
                        hoverOffset: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: { 
                        legend: { position: 'bottom', labels: { padding: 15, usePointStyle: true, font: { family: 'Verdana' } } } 
                    }
                }
            });
        }

        // Gráfica de Días
        const diasData = <?= json_encode($dias_data) ?>;
        if(Object.keys(diasData).length > 0) {
            const ctxDias = document.getElementById('diasChart').getContext('2d');
            
            new Chart(ctxDias, {
                type: 'bar',
                data: {
                    labels: Object.keys(diasData),
                    datasets: [{
                        label: 'Interacciones',
                        data: Object.values(diasData),
                        backgroundColor: '#173f73',
                        hoverBackgroundColor: '#002855',
                        borderRadius: 4,
                        barThickness: 35
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        y: { beginAtZero: true, grid: { borderDash: [2, 2], color: '#e2e8f0' } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }
    });

    const spMenu = document.getElementById('sp-menu');
if (spMenu) {
    spMenu.style.marginLeft = 'auto';
    spMenu.style.position = 'static';
}
</script>
</script>

<style>
/* Estilos para la vista de WhatsApp */
.session-item {
    padding: 14px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background 0.2s;
}
.session-item:hover { background: #f8fafc; }
.session-item.active { background: #e0f2fe; border-left: 4px solid var(--uan-dark-blue); }
.session-icon {
    width: 38px; height: 38px; min-width: 38px; background: var(--uan-dark-blue); color: white; border-radius: 50%; 
    display: flex; align-items: center; justify-content: center; font-weight: bold; font-family: 'Amaranth', sans-serif; font-size: 0.9rem;
}
.chat-bubble {
    max-width: 82%; padding: 14px 18px; border-radius: 16px; font-size: 0.95rem; line-height: 1.5;
    position: relative; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 12px;
    word-wrap: break-word; word-break: break-word;
}
.chat-bubble.user {
    background: var(--uan-dark-blue); color: white; align-self: flex-end; border-bottom-right-radius: 4px; margin-left: auto;
}
.chat-bubble.user .chat-meta { color: rgba(255,255,255,0.75); }
.chat-bubble.bot {
    background: white; color: #1e293b; align-self: flex-start; border-bottom-left-radius: 4px; border: 1px solid #e2e8f0; margin-right: auto;
}
.chat-bubble.bot a {
    color: var(--uan-dark-blue); text-decoration: underline; font-weight: bold;
}
.chat-bubble.bot a.chat-link-btn {
    background-color: var(--uan-dark-blue); color: white !important; text-decoration: none !important;
    padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; display: inline-block; margin: 4px 4px 4px 0;
}
.chat-meta {
    font-size: 0.72rem; margin-top: 6px; text-align: right; opacity: 0.85;
}
.chat-bubble.bot .chat-meta { color: #64748b; }
.chat-origen {
    font-size: 0.72rem; background: rgba(0,0,0,0.06); padding: 3px 8px; border-radius: 4px; margin-top: 6px; display: inline-block; font-weight: 600; color: #475569;
}
</style>

<script>
// Lógica AJAX para el modo WhatsApp y navegación desde la tabla
let currentSessionId = null;

function abrirChatSesion(idSesion, navShort, tipoUsuario) {
    if (!idSesion) return;
    
    // 1. Activar la pestaña de Historial de Sesiones (Chat)
    const chatsTabEl = document.getElementById('chats-tab');
    if (chatsTabEl) {
        const tabTrigger = new bootstrap.Tab(chatsTabEl);
        tabTrigger.show();
    }
    
    // 2. Cargar sesiones
    loadSessions();
    
    // 3. Abrir de inmediato la conversación de esa sesión
    setTimeout(() => {
        loadChat(idSesion, navShort, tipoUsuario);
        const chatContainer = document.getElementById('chats');
        if (chatContainer) {
            chatContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 250);
}

function formatTime(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}

function loadSessions() {
    const start = document.getElementById('hidden_start').value;
    const end = document.getElementById('hidden_end').value;
    
    // Mantener la URL base actual (ej. para Joomla) y agregar los parámetros
    const currentUrl = window.location.href.split('#')[0];
    const separator = currentUrl.includes('?') ? '&' : '?';
    
    document.getElementById('sessions-list').innerHTML = '<div class="p-4 text-center text-muted">Cargando...</div>';
    
    fetch(currentUrl + separator + 'ajax=get_sessions&start=' + start + '&end=' + end)
        .then(res => res.text())
        .then(html => {
            let match = html.match(/<!--AJAX_START-->(.*?)<!--AJAX_END-->/s);
            if (!match) throw new Error("Invalid response format");
            let data = JSON.parse(match[1]);
            const list = document.getElementById('sessions-list');
            list.innerHTML = '';
            if(data.length === 0) {
                list.innerHTML = '<div class="p-4 text-center text-muted">No hay sesiones en este rango.</div>';
                return;
            }
            
            data.forEach(s => {
                const navShort = (s.codigo_navegador || 'Anon').substring(0, 20).toUpperCase();
                
                const div = document.createElement('div');
                div.className = 'session-item d-flex gap-3 align-items-center';
                div.id = 'session-' + s.id_sesion;
                div.onclick = () => loadChat(s.id_sesion, navShort, s.tipo_usuario);
                
                div.innerHTML = `
                    <div class="session-icon">${s.id_sesion}</div>
                    <div style="flex: 1; min-width: 0;">
                        <div class="d-flex justify-content-between align-items-baseline mb-1">
                            <strong class="text-truncate d-block" style="max-width: 150px;" title="${navShort}">ID BD: ${s.id_sesion}</strong>
                            <small class="text-muted" style="font-size: 0.7rem;">${formatTime(s.ultima_actividad)}</small>
                        </div>
                        <div class="text-muted text-truncate" style="font-size: 0.8rem;">
                            ${navShort}
                        </div>
                        <div class="text-muted text-truncate" style="font-size: 0.75rem; color: var(--uan-gold) !important;">
                            ${s.tipo_usuario || 'Sin perfil'} • ${s.total_mensajes} msjs
                        </div>
                    </div>
                `;
                list.appendChild(div);
            });
        })
        .catch(err => {
            document.getElementById('sessions-list').innerHTML = '<div class="p-4 text-center text-danger">Error cargando sesiones.</div>';
            console.error(err);
        });
}

function loadChat(idSesion, navShort, tipoUsuario) {
    // Marcar activo
    document.querySelectorAll('.session-item').forEach(el => el.classList.remove('active'));
    const sesEl = document.getElementById('session-' + idSesion);
    if (sesEl) sesEl.classList.add('active');
    
    document.getElementById('chat-header-title').textContent = "Sesión #" + idSesion + " (" + navShort + ")";
    document.getElementById('chat-header-subtitle').textContent = "Perfil: " + (tipoUsuario || 'General / Desconocido');
    
    const win = document.getElementById('chat-window');
    win.style.justifyContent = 'flex-start';
    win.style.alignItems = 'stretch';
    win.innerHTML = '<div class="text-center text-muted mt-4">Cargando conversación...</div>';
    
    const currentUrl = window.location.href.split('#')[0];
    const separator = currentUrl.includes('?') ? '&' : '?';

    fetch(currentUrl + separator + 'ajax=get_chat&id_sesion=' + idSesion)
        .then(res => res.text())
        .then(html => {
            let match = html.match(/<!--AJAX_START-->(.*?)<!--AJAX_END-->/s);
            if (!match) throw new Error("Invalid response format");
            let data = JSON.parse(match[1]);
            win.innerHTML = '';
            
            if(data.length === 0) {
                win.innerHTML = '<div class="text-center text-muted mt-4">No hay mensajes registrados para esta sesión.</div>';
                return;
            }
            
            let lastDate = '';
            
            data.forEach(m => {
                // Separador de fecha si cambia de día
                const msgDate = new Date(m.fecha_hora).toLocaleDateString();
                if(msgDate !== lastDate) {
                    const sep = document.createElement('div');
                    sep.className = 'text-center my-3';
                    sep.innerHTML = `<span class="badge bg-white text-muted border shadow-sm px-3 py-1">${msgDate}</span>`;
                    win.appendChild(sep);
                    lastDate = msgDate;
                }
                
                const isUser = m.quien_escribio && m.quien_escribio.toLowerCase().includes('usuario');
                const bubble = document.createElement('div');
                bubble.className = 'chat-bubble ' + (isUser ? 'user' : 'bot');
                
                let text = (m.mensaje || '').trim();
                
                // Fallback para mensajes vacíos (como el saludo con botones)
                if (!text || text === '') {
                    if (m.origen_respuesta && m.origen_respuesta.toLowerCase().includes('bienvenida')) {
                        text = '👋 <em>[El usuario abrió el chat y el asistente presentó el saludo y menú de opciones inicial]</em>';
                    } else {
                        text = '<em>[Interacción del sistema / Selección de menú]</em>';
                    }
                } else if (!text.includes('<br') && !text.includes('<p') && !text.includes('<div')) {
                    text = text.replace(/\n/g, '<br>');
                }
                
                let origenHtml = '';
                if(!isUser && m.origen_respuesta) {
                    const origenLimpio = mapearOrigenTexto(m.origen_respuesta);
                    origenHtml = `<div class="chat-origen">Origen: ${origenLimpio}</div>`;
                }
                
                let feedbackHtml = '';
                if (!isUser && m.calificacion !== null && m.calificacion !== undefined) {
                    if (String(m.calificacion) === '1') {
                        feedbackHtml = `<div style="text-align: right; margin-top: 5px;"><span style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">👍 Me sirvió</span></div>`;
                    } else if (String(m.calificacion) === '-1') {
                        feedbackHtml = `<div style="text-align: right; margin-top: 5px;"><span style="background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">👎 No me sirvió</span></div>`;
                    }
                }
                
                bubble.innerHTML = `
                    <div style="font-size: 0.95rem;">${text}</div>
                    ${feedbackHtml}
                    ${origenHtml}
                    <div class="chat-meta">${formatTime(m.fecha_hora)}</div>
                `;
                
                win.appendChild(bubble);
            });
            
            // Scroll down
            setTimeout(() => { win.scrollTop = win.scrollHeight; }, 100);
        })
        .catch(err => {
            win.innerHTML = '<div class="text-center text-danger mt-4">Error al cargar los mensajes.</div>';
            console.error(err);
        });
}

function mapearOrigenTexto(orig) {
    if (!orig) return '';
    const o = orig.toUpperCase();
    if (o.includes('IA') || o.includes('GEMINI') || o.includes('RAG')) return 'IA RAG (Gemini + Markdown)';
    if (o.includes('MENU')) return 'Menú Interactivo (Botones)';
    if (o.includes('BIENVENIDA') || o.includes('SISTEMA') || o.includes('DEFECTO')) return 'Mensaje del Sistema';
    return orig;
}

function filterSessions() {
    const input = document.getElementById('search-sessions').value.toLowerCase();
    const items = document.querySelectorAll('.session-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(input)) {
            item.classList.remove('d-none');
            item.classList.add('d-flex');
        } else {
            item.classList.remove('d-flex');
            item.classList.add('d-none');
        }
    });
}

// Cargar sesiones automáticamente al abrir la pestaña de chats
document.getElementById('chats-tab').addEventListener('shown.bs.tab', function (e) {
    loadSessions();
});

// Cargar sesiones inmediatamente al cargar la página (ya que ahora es la pestaña por defecto)
// Ya no cargamos sesiones de inmediato a menos que estemos en la pestaña
setTimeout(() => {
    if (document.getElementById('chats').classList.contains('active')) {
        loadSessions();
    }
}, 500);
</script>


<!-- Modal Top Consultas -->
<div class="modal fade" id="topConsultasModal" tabindex="-1" aria-labelledby="topConsultasModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 50px rgba(0,0,0,0.1);">
      <div class="modal-header" style="background: linear-gradient(135deg, var(--uan-dark-blue), var(--uan-mid-blue)); color: white; border-radius: 20px 20px 0 0; padding: 1.5rem;">
        <h5 class="modal-title" id="topConsultasModalLabel" style="font-family:'Amaranth', sans-serif; font-weight: bold;"><i class="fa fa-trophy text-warning me-2"></i> Top 10 Preguntas Frecuentes</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <table class="table table-hover mb-0">
          <thead style="background: #f8fafc;">
            <tr>
              <th class="ps-4 py-3 border-0 text-muted" style="width: 10%;">#</th>
              <th class="py-3 border-0 text-muted" style="width: 70%;">Consulta del Usuario</th>
              <th class="text-center pe-4 py-3 border-0 text-muted" style="width: 20%;">Veces Solicitado</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($kpi_top_list)): ?>
                <tr><td colspan="3" class="text-center py-5 text-muted"><i class="fa fa-inbox fa-3x mb-3 opacity-25"></i><br>No hay consultas registradas en este periodo.</td></tr>
            <?php else: ?>
                <?php foreach ($kpi_top_list as $index => $item): ?>
                <tr>
                  <td class="ps-4 py-3 align-middle font-weight-bold" style="color: #94a3b8; font-size: 1.1rem;"><?= $index + 1 ?></td>
                  <td class="py-3 align-middle"><span class="badge" style="background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; margin-right: 8px;"><i class="fa fa-user"></i></span> <span style="font-weight: 500; color: #334155;"><?= htmlspecialchars($item['texto']) ?></span></td>
                  <td class="text-center pe-4 py-3 align-middle"><span class="badge rounded-pill" style="background: var(--uan-gold); color: #fff; font-size: 0.9rem; padding: 6px 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);"><?= $item['cantidad'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="modal-footer bg-light" style="border-radius: 0 0 20px 20px; border-top: 1px solid #e2e8f0;">
        <button type="button" class="btn text-white px-4" style="background: var(--uan-dark-blue); border-radius: 8px;" data-bs-dismiss="modal">Entendido</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Feedback Negativo -->
<div class="modal fade" id="topFeedbackModal" tabindex="-1" aria-labelledby="topFeedbackModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 50px rgba(0,0,0,0.1);">
      <div class="modal-header" style="background: linear-gradient(135deg, #ef4444, #b91c1c); color: white; border-radius: 20px 20px 0 0; padding: 1.5rem;">
        <h5 class="modal-title" id="topFeedbackModalLabel" style="font-family:'Amaranth', sans-serif; font-weight: bold;"><i class="fa fa-thumbs-down text-white me-2"></i> Últimas Respuestas Negativas</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <table class="table table-hover mb-0">
          <thead style="background: #f8fafc;">
            <tr>
              <th class="ps-4 py-3 border-0 text-muted" style="width: 20%;">Fecha</th>
              <th class="py-3 border-0 text-muted" style="width: 60%;">Respuesta de la IA (Extracto)</th>
              <th class="text-center pe-4 py-3 border-0 text-muted" style="width: 20%;">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($kpi_bad_list)): ?>
                <tr><td colspan="3" class="text-center py-5 text-muted"><i class="fa fa-check-circle fa-3x mb-3 text-success opacity-50"></i><br>¡Excelente! No hay respuestas calificadas negativamente.</td></tr>
            <?php else: ?>
                <?php foreach ($kpi_bad_list as $item): ?>
                <tr>
                  <td class="ps-4 py-3 align-middle" style="color: #64748b; font-size: 0.9rem;"><?= htmlspecialchars($item['fecha']) ?></td>
                  <td class="py-3 align-middle"><span style="font-size: 0.95rem; color: #334155; font-style: italic;">"<?= htmlspecialchars($item['texto']) ?>"</span></td>
                  <td class="text-center pe-4 py-3 align-middle">
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold" onclick="abrirChatSesion(<?= (int)$item['id_sesion'] ?>, '', '')" data-bs-dismiss="modal" title="Revisar conversación" style="font-size: 0.8rem;">
                        💬 Ver Contexto
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="modal-footer bg-light" style="border-radius: 0 0 20px 20px; border-top: 1px solid #e2e8f0;">
        <button type="button" class="btn text-white px-4" style="background: #ef4444; border-radius: 8px;" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
