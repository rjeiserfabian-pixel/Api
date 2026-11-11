<?php
/**
 * Interfaz Premium de Subida de Certificados
 * Permite subir certificados PEM o PFX de forma segura para entornos de Prueba o Producción.
 */

session_start();

// ==========================================
// CONFIGURACIÓN DE SEGURIDAD
// ==========================================
// ¡IMPORTANTE! Cambiar esta contraseña para producción.
define('ADMIN_USER', 'admin');
// Hash de 'admin123' usando password_hash('admin123', PASSWORD_DEFAULT)
// Se usa un hash para no tener la contraseña en texto plano.
define('ADMIN_PASS_HASH', '$2y$10$..pIIPT1SuBpFzCKXMrSUeqUyryyu6B0Ch1o1BX2XFjtLLqdz/Eqy'); 

// ==========================================
// RUTAS DE DESTINO
// ==========================================
$base_dir = __DIR__ . '/libraries/certificado_digital/';
$paths = [
    'prueba' => $base_dir . 'prueba/',
    'produccion' => $base_dir . 'produccion/'
];

// ==========================================
// MANEJO DE PETICIONES AJAX (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    // 1. Lógica de Login
    if ($action === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($username === ADMIN_USER && password_verify($password, ADMIN_PASS_HASH)) {
            $_SESSION['auth_user'] = true;
            echo json_encode(['success' => true, 'message' => 'Login exitoso']);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Credenciales incorrectas']);
        }
        exit;
    }

    // 2. Lógica de Logout
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // Proteger todas las acciones posteriores requiriendo autenticación
    if (!isset($_SESSION['auth_user']) || $_SESSION['auth_user'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }

    // 3. Lógica de Subida de Certificados
    if ($action === 'upload') {
        $env = $_POST['environment'] ?? '';
        $type = $_POST['upload_type'] ?? '';

        if (!array_key_exists($env, $paths)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Entorno inválido seleccionado.']);
            exit;
        }

        $target_dir = $paths[$env];
        // Crear directorio si no existe (por seguridad)
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        try {
            if ($type === 'pem') {
                // Subida tipo PEM (2 archivos)
                if (!isset($_FILES['server_key_file']) || !isset($_FILES['server_cert_file'])) {
                    throw new Exception("Faltan archivos requeridos para la subida PEM.");
                }

                $key_file = $_FILES['server_key_file'];
                $cert_file = $_FILES['server_cert_file'];

                if ($key_file['error'] !== UPLOAD_ERR_OK || $cert_file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Error al subir los archivos al servidor.");
                }

                // Mover y renombrar
                if (!move_uploaded_file($key_file['tmp_name'], $target_dir . 'server_key.pem')) {
                     throw new Exception("No se pudo guardar server_key.pem");
                }
                if (!move_uploaded_file($cert_file['tmp_name'], $target_dir . 'server.pem')) {
                     throw new Exception("No se pudo guardar server.pem");
                }

                echo json_encode(['success' => true, 'message' => 'Certificados PEM actualizados correctamente en ' . ucfirst($env)]);

            } elseif ($type === 'pfx') {
                // Subida tipo PFX (1 archivo + password)
                if (!isset($_FILES['pfx_file'])) {
                    throw new Exception("Falta el archivo PFX.");
                }

                $pfx_file = $_FILES['pfx_file'];
                $pfx_pass = $_POST['pfx_password'] ?? '';

                if ($pfx_file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Error al subir el archivo PFX al servidor.");
                }

                $pfx_content = file_get_contents($pfx_file['tmp_name']);
                
                $certs = array();
                // Extraer el PFX
                if (openssl_pkcs12_read($pfx_content, $certs, $pfx_pass)) {
                    // Extraer Llave Privada
                    if (!isset($certs['pkey'])) {
                        throw new Exception("El PFX no contiene una llave privada válida.");
                    }
                    file_put_contents($target_dir . 'server_key.pem', $certs['pkey']);

                    // Extraer Certificado (Llave Pública)
                    if (!isset($certs['cert'])) {
                        throw new Exception("El PFX no contiene un certificado válido.");
                    }
                    file_put_contents($target_dir . 'server.pem', $certs['cert']);

                    echo json_encode(['success' => true, 'message' => 'Certificado PFX extraído y guardado correctamente en ' . ucfirst($env)]);
                } else {
                    throw new Exception("No se pudo extraer el PFX. ¿Contraseña incorrecta?");
                }
            } else {
                throw new Exception("Tipo de subida inválido.");
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// ==========================================
// VISTA HTML
// ==========================================
$is_authenticated = isset($_SESSION['auth_user']) && $_SESSION['auth_user'] === true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor Premium de Certificados - API SUNAT</title>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/premium_upload.css">
</head>
<body>

    <div class="background-elements">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
    </div>

    <main class="container">
        
        <?php if (!$is_authenticated): ?>
        <!-- ======================= -->
        <!-- FORMULARIO DE LOGIN -->
        <!-- ======================= -->
        <div class="glass-panel login-panel" id="loginPanel">
            <div class="panel-header">
                <div class="logo-icon futuristic-logo-container">
                    <img src="factuexpress.jpeg" alt="FactuExpress" class="futuristic-logo">
                </div>
                <h1>Acceso Seguro</h1>
                <p>Ingresa tus credenciales para gestionar certificados</p>
            </div>
            
            <form id="loginForm" class="styled-form">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" id="username" name="username" placeholder="Ej: admin" required>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <div class="input-with-icon">
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                        <button type="button" class="toggle-pass-login" tabindex="-1">
                            <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-primary" id="btnLogin">
                    <span>Iniciar Sesión</span>
                    <div class="loader hidden"></div>
                </button>
                <div id="loginError" class="error-message hidden"></div>
            </form>
        </div>

        <?php else: ?>
        <!-- ======================= -->
        <!-- PANEL DE SUBIDA -->
        <!-- ======================= -->
        <div class="glass-panel dashboard-panel" id="dashboardPanel">
            <div class="panel-header dashboard-header">
                <div>
                    <h1>Gestor de Certificados</h1>
                    <p>API de Facturación Electrónica SUNAT</p>
                </div>
                <button id="btnLogout" class="btn-icon" title="Cerrar sesión">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                </button>
            </div>

            <form id="uploadForm" class="styled-form">
                
                <!-- SELECTOR DE ENTORNO -->
                <div class="form-section">
                    <h3 class="section-title">1. Selecciona el Entorno</h3>
                    <div class="environment-selector">
                        <label class="env-card">
                            <input type="radio" name="environment" value="prueba" checked>
                            <div class="env-content">
                                <div class="env-icon">
                                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                </div>
                                <h4>Entorno de Prueba</h4>
                                <p>Certificados para fase de homologación</p>
                            </div>
                        </label>
                        <label class="env-card">
                            <input type="radio" name="environment" value="produccion">
                            <div class="env-content">
                                <div class="env-icon warning">
                                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                </div>
                                <h4>Entorno de Producción</h4>
                                <p>Certificados para emisión real</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- TABS PARA TIPO DE SUBIDA -->
                <div class="form-section">
                    <h3 class="section-title">2. Método de Subida</h3>
                    <div class="tabs">
                        <button type="button" class="tab-btn active" data-target="tab-pem">Archivos PEM (Recomendado)</button>
                        <button type="button" class="tab-btn" data-target="tab-pfx">Extraer de PFX / P12</button>
                    </div>

                    <div class="tab-content">
                        <!-- TAB: PEM -->
                        <div id="tab-pem" class="tab-pane active">
                            <div class="drop-zones-grid">
                                <div class="drop-zone" id="dz-key">
                                    <input type="file" id="server_key_file" accept=".pem" class="file-input">
                                    <div class="dz-content">
                                        <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="1.5" fill="none"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                                        <h5>server_key.pem</h5>
                                        <p>Arrastra o haz click</p>
                                    </div>
                                </div>
                                <div class="drop-zone" id="dz-cert">
                                    <input type="file" id="server_cert_file" accept=".pem" class="file-input">
                                    <div class="dz-content">
                                        <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="1.5" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        <h5>server.pem</h5>
                                        <p>Arrastra o haz click</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: PFX -->
                        <div id="tab-pfx" class="tab-pane">
                            <div class="drop-zone single-drop-zone" id="dz-pfx">
                                <input type="file" id="pfx_file" accept=".pfx,.p12" class="file-input">
                                <div class="dz-content">
                                    <svg viewBox="0 0 24 24" width="32" height="32" stroke="currentColor" stroke-width="1.5" fill="none"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                    <h5>Certificado .PFX o .P12</h5>
                                    <p>Arrastra tu archivo unificado aquí</p>
                                </div>
                            </div>
                            <div class="form-group mt-4 pfx-pass-group hidden" id="pfxPassGroup">
                                <label for="pfx_password">Contraseña del PFX</label>
                                <div class="input-with-icon">
                                    <input type="password" id="pfx_password" placeholder="Ingresa la contraseña para extraer las llaves">
                                    <button type="button" class="toggle-pass" tabindex="-1">
                                        <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-primary btn-large" id="btnUpload" disabled>
                        <span>Actualizar Certificados</span>
                        <div class="loader hidden"></div>
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </main>

    <!-- TOAST NOTIFICATIONS -->
    <div id="toastContainer" class="toast-container"></div>

    <script src="assets/js/premium_upload.js"></script>
</body>
</html>
