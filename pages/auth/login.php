
<?php
require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/../../src/Models/UsuarioModel.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_logout'])) {
    $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';
$segundosBloqueo = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_login'], $csrfToken)
    ) {
        $error = 'La sesión del formulario expiró. Recarga la página.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Ingresa tu correo y contraseña.';
        } else {
            try {
                $db = (new Database())->connect();
                $modeloUsuario = new UsuarioModel($db);

                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $usuario = $modeloUsuario->obtenerPorCorreo($email);

                $intentosCuenta =
                    $modeloUsuario->contarIntentosFallidos($email);

                // Bloquear la cuenta si alcanzó cinco intentos fallidos.
                if ($intentosCuenta >= 5) {
                    $segundosBloqueo =
                        $modeloUsuario->obtenerSegundosBloqueo($email);

                    $error = 'Demasiados intentos fallidos. '
                        . 'Espera a que termine el contador.';
                } elseif (
                    $usuario === null ||
                    !$modeloUsuario->verificarPassword(
                        $password,
                        (string) $usuario['password']
                    )
                ) {
                    // Registrar el intento fallido en el servidor.
                    $modeloUsuario->registrarIntentoFallido(
                        $email,
                        $ip
                    );

                    $intentosCuenta =
                        $modeloUsuario->contarIntentosFallidos($email);

                    if ($intentosCuenta >= 5) {
                        $segundosBloqueo =
                            $modeloUsuario->obtenerSegundosBloqueo($email);

                        $error = 'Has alcanzado los cinco intentos '
                            . 'fallidos. Tu acceso está bloqueado '
                            . 'temporalmente.';
                    } else {
                        $restantes = 5 - $intentosCuenta;

                        $error = 'El correo o la contraseña no son '
                            . 'correctos. Intentos restantes: '
                            . $restantes . '.';
                    }
                } elseif (($usuario['estado'] ?? '') !== 'Activo') {
                    $error = 'Tu cuenta está inactiva. '
                        . 'Comunícate con un administrador.';
                } else {
                    // Limpiar intentos tras autenticar correctamente.
                    $modeloUsuario->limpiarIntentosFallidos($email);

                    // Evitar la fijación de sesión.
                    session_regenerate_id(true);

                    $_SESSION['id_usuario'] =
                        (int) $usuario['id_usuario'];

                    $_SESSION['rol'] = (string) $usuario['rol'];

                    $_SESSION['usuario'] = [
                        'id_usuario' => (int) $usuario['id_usuario'],
                        'codigo' => (string) $usuario['codigo'],
                        'nombres' => (string) $usuario['nombres'],
                        'apellidos' => (string) $usuario['apellidos'],
                        'correo' => (string) $usuario['correo'],
                        'rol' => (string) $usuario['rol'],
                    ];

                    if ($usuario['rol'] === 'Estudiante') {
                        header('Location: ../estudiante/principal.php');
                    } else {
                        header('Location: ../../public/index.php');
                    }

                    exit;
                }
            } catch (Throwable $e) {
                error_log(
                    'BeatCell - error al iniciar sesión: '
                    . $e->getMessage()
                );

                $error = 'No se pudo iniciar sesión. '
                    . 'Verifica la conexión e inténtalo de nuevo.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BeatCell - Acceso al Sistema</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"
    >
    <link rel="stylesheet" href="../../public/assets/css/login.css">
</head>
<body>
<div class="bg-image">
    <div class="auth-card">
        <div class="brand-logo">
            <h2>BeatCell</h2>
            <p>Academia de Tecnología Celular</p>
        </div>

        <?php if (($_GET['registro'] ?? '') === 'exitoso'): ?>
            <div class="alert alert-success" role="status">
                Tu cuenta se creó correctamente. Ya puedes iniciar sesión.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

                <?php if ($segundosBloqueo > 0): ?>
                    <p class="mt-2 mb-0">
                        Tiempo restante:
                        <strong id="contadorBloqueo">
                            <?= sprintf(
                                '%02d:%02d',
                                intdiv($segundosBloqueo, 60),
                                $segundosBloqueo % 60
                            ) ?>
                        </strong>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_login'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <div class="mb-3">
                <label class="form-label">Correo Electrónico</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-envelope"></i>
                    </span>
                    <input
                        type="email"
                        name="email"
                        class="form-control"
                        placeholder="correo@ejemplo.com"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        required
                    >
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-lock"></i>
                    </span>
                    <input
                        type="password"
                        name="password"
                        class="form-control"
                        placeholder="********"
                        required
                    >
                </div>
            </div>

            <button
                type="submit"
                class="btn btn-primary"
                <?= $segundosBloqueo > 0 ? 'disabled' : '' ?>
            >
                <?= $segundosBloqueo > 0
                    ? 'Acceso bloqueado'
                    : 'Ingresar' ?>
            </button>
        </form>

        <div class="auth-footer">
            <a href="recover.php">¿Olvidaste tu contraseña?</a>
            <p>
                ¿No tienes cuenta?
                <a href="register.php">Regístrate aquí</a>
            </p>
        </div>
    </div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
</script>
<script
    src="../../public/assets/js/login.js?v=<?= (int) @filemtime(
        __DIR__ . '/../../public/assets/js/login.js'
    ) ?>"
    defer>
</script>

<?php if ($segundosBloqueo > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const contador = document.getElementById('contadorBloqueo');

    if (!contador) {
        return;
    }

    const finBloqueo = Date.now()
        + (<?= (int) $segundosBloqueo ?> * 1000);

    function actualizarContador() {
        const segundos = Math.max(
            0,
            Math.ceil((finBloqueo - Date.now()) / 1000)
        );

        const minutos = Math.floor(segundos / 60);
        const resto = segundos % 60;

        contador.textContent =
            String(minutos).padStart(2, '0')
            + ':'
            + String(resto).padStart(2, '0');

        if (segundos === 0) {
            clearInterval(intervalo);
            window.location.reload();
        }
    }

    const intervalo = setInterval(actualizarContador, 1000);
    actualizarContador();
});
</script>
<?php endif; ?>

</body>
</html>
