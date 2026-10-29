<?php
require_once __DIR__ . '/../../src/Support/ViewHelpers.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
AuthMiddleware::requireRole(['Docente', 'Administrador'], '../auth/login.php');
require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/../../src/Controllers/ActividadController.php';
require_once __DIR__ . '/../../src/Models/TiempoActividad.php';

if (empty($_SESSION['csrf_gestion'])) {
    $_SESSION['csrf_gestion'] = bin2hex(random_bytes(32));
}

$datosFormulario = [
    'titulo' => '',
    'descripcion' => '',
    'tipo' => 'Cuestionario',
    'modo_tiempo' => 'sin_limite',
    'tiempo_minutos' => '10',
    'tiempo_por_pregunta' => '20',
    'preguntas_por_bloque' => '4',
    'tiempo_por_bloque_minutos' => '5',
    'puntaje_minimo' => '70',
    'fecha_limite' => '',
];

$actividades = [];
$temas = [];
$error = null;
$mensaje = '';
$database = null;
$actividadController = null;

try {
    $database = (new Database())->connect();
    $actividadController = new ActividadController(
        new ActividadModel($database)
    );

    $stmtTemas = $database->query(
        "SELECT t.id_tema, t.nombre AS tema, m.nombre AS modulo, c.nombre AS curso
         FROM temas t
         INNER JOIN modulos m ON m.id_modulo = t.id_modulo
         INNER JOIN cursos c ON c.id_curso = m.id_curso
         WHERE t.estado = 'Activo'
           AND m.estado = 'Activo'
           AND c.estado = 'Activo'
         ORDER BY c.nombre, m.orden, t.orden"
    );

    $temas = $stmtTemas->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $datosFormulario = array_merge($datosFormulario, $_POST);

        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token)
            || !hash_equals($_SESSION['csrf_gestion'], $token)
        ) {
            throw new InvalidArgumentException(
                'La sesión del formulario expiró. Recarga la página.'
            );
        }

        if (($_POST['accion'] ?? '') !== 'crear') {
            throw new InvalidArgumentException(
                'La operación solicitada no es válida.'
            );
        }

        $actividadController->crear($_POST);

        header('Location: actividades.php?ok=creada');
        exit;
    }

    $actividades = $actividadController->listar();

    if (($_GET['ok'] ?? '') === 'creada') {
        $mensaje = 'Actividad creada correctamente. Ahora puedes gestionar sus preguntas.';
    }
} catch (InvalidArgumentException $ex) {
    $error = $ex->getMessage();

    if ($actividadController !== null) {
        $actividades = $actividadController->listar();
    }
} catch (Throwable $ex) {
    error_log('BeatCell - gestión de actividades: ' . $ex->getMessage());

    $error = 'No se pudo completar la operación. Verifica la base de datos y vuelve a intentarlo.';
}

$modoSeleccionado = in_array(
    $datosFormulario['modo_tiempo'] ?? '',
    TiempoActividad::MODOS,
    true
)
    ? $datosFormulario['modo_tiempo']
    : TiempoActividad::SIN_LIMITE;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de actividades | BeatCell</title>

    <link
        rel="stylesheet"
        href="../../public/assets/css/actividades.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/actividades.css') ?>"
    >
</head>
<body>
    <main class="contenedor-actividades">
        <a href="panel.php" class="btn-volver">← Volver al panel</a>

        <section class="encabezado-actividades">
            <h1>Actividades</h1>
            <p>Gestiona las actividades y sus evaluaciones.</p>
        </section>

        <?php if ($error): ?>
            <div class="mensaje-error" role="alert">
                <?= e($error) ?>
            </div>
        <?php elseif ($mensaje !== ''): ?>
            <div class="mensaje-exito" role="status">
                <?= e($mensaje) ?>
            </div>
        <?php endif; ?>

        <section
            class="formulario-actividad"
            aria-labelledby="titulo-nueva-actividad"
        >
            <h2 id="titulo-nueva-actividad">Crear actividad</h2>

            <?php if ($temas === []): ?>
                <p class="mensaje-info">
                    Primero crea un curso, un módulo y un tema activo
                    para poder asociar una actividad.
                </p>
            <?php else: ?>

                <form method="post" action="actividades.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_gestion']) ?>"
                    >

                    <input
                        type="hidden"
                        name="accion"
                        value="crear"
                    >

                    <div class="formulario-actividad-grid">

                        <label>Curso, módulo y tema
                            <select name="id_tema" required>
                                <option value="">Selecciona un tema</option>

                                <?php foreach ($temas as $tema): ?>
                                    <option
                                        value="<?= (int) $tema['id_tema'] ?>"
                                        <?= (string) ($datosFormulario['id_tema'] ?? '') === (string) $tema['id_tema'] ? 'selected' : '' ?>
                                    >
                                        <?= e(
                                            $tema['curso']
                                            . ' / '
                                            . $tema['modulo']
                                            . ' / '
                                            . $tema['tema']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>Título
                            <input
                                type="text"
                                name="titulo"
                                maxlength="150"
                                value="<?= e($datosFormulario['titulo']) ?>"
                                required
                            >
                        </label>

                        <label>Tipo
                            <select name="tipo" required>
                                <?php foreach (['Cuestionario', 'Práctica', 'Evaluación'] as $tipo): ?>
                                    <option
                                        value="<?= e($tipo) ?>"
                                        <?= ($datosFormulario['tipo'] ?? '') === $tipo ? 'selected' : '' ?>
                                    >
                                        <?= e($tipo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <fieldset class="grupo-tiempo" id="grupo-tiempo">
                            <legend>Tiempo de la actividad</legend>

                            <div
                                class="modos-tiempo"
                                role="radiogroup"
                                aria-label="Cómo se mide el tiempo"
                            >
                                <label class="modo-tiempo">
                                    <input
                                        type="radio"
                                        name="modo_tiempo"
                                        value="sin_limite"
                                        <?= $modoSeleccionado === 'sin_limite' ? 'checked' : '' ?>
                                    >
                                    <span class="modo-contenido">
                                        <strong>Sin límite</strong>
                                        <small>Los estudiantes no tienen reloj.</small>
                                    </span>
                                </label>

                                <label class="modo-tiempo">
                                    <input
                                        type="radio"
                                        name="modo_tiempo"
                                        value="total"
                                        <?= $modoSeleccionado === 'total' ? 'checked' : '' ?>
                                    >
                                    <span class="modo-contenido">
                                        <strong>Tiempo total</strong>
                                        <small>Un solo reloj para toda la actividad.</small>
                                    </span>
                                </label>

                                <label class="modo-tiempo">
                                    <input
                                        type="radio"
                                        name="modo_tiempo"
                                        value="por_pregunta"
                                        <?= $modoSeleccionado === 'por_pregunta' ? 'checked' : '' ?>
                                    >
                                    <span class="modo-contenido">
                                        <strong>Por pregunta</strong>
                                        <small>Cada pregunta tiene su propio reloj.</small>
                                    </span>
                                </label>

                                <label class="modo-tiempo">
                                    <input
                                        type="radio"
                                        name="modo_tiempo"
                                        value="por_bloque"
                                        <?= $modoSeleccionado === 'por_bloque' ? 'checked' : '' ?>
                                    >
                                    <span class="modo-contenido">
                                        <strong>Por bloque de preguntas</strong>
                                        <small>
                                            Define cuántas preguntas contiene cada bloque
                                            y cuánto tiempo tiene el estudiante para resolverlo.
                                        </small>
                                    </span>
                                </label>
                            </div>

                            <!-- Tiempo total de la actividad -->
                            <div
                                class="panel-tiempo"
                                data-modo="total"
                                <?= $modoSeleccionado === 'total' ? '' : 'hidden' ?>
                            >
                                <span class="etiqueta-panel">
                                    ¿Cuántos minutos dura toda la actividad?
                                </span>

                                <div class="control-tiempo">
                                    <div class="selector-numero">
                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="-1"
                                            aria-label="Restar un minuto"
                                        >−</button>

                                        <input
                                            type="number"
                                            name="tiempo_minutos"
                                            min="<?= TiempoActividad::MIN_TOTAL_MINUTOS ?>"
                                            max="<?= TiempoActividad::MAX_TOTAL_MINUTOS ?>"
                                            step="1"
                                            inputmode="numeric"
                                            value="<?= e($datosFormulario['tiempo_minutos']) ?>"
                                            aria-label="Minutos totales"
                                        >

                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="1"
                                            aria-label="Sumar un minuto"
                                        >+</button>

                                        <span class="unidad-tiempo">minutos</span>
                                    </div>

                                    <div class="atajos-tiempo">
                                        <?php foreach ([5, 10, 15, 20, 30, 45, 60] as $minutos): ?>
                                            <button
                                                type="button"
                                                class="chip-tiempo"
                                                data-valor="<?= $minutos ?>"
                                            ><?= $minutos ?> min</button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Tiempo individual por pregunta -->
                            <div
                                class="panel-tiempo"
                                data-modo="por_pregunta"
                                <?= $modoSeleccionado === 'por_pregunta' ? '' : 'hidden' ?>
                            >
                                <span class="etiqueta-panel">
                                    ¿Cuántos segundos tiene cada pregunta?
                                </span>

                                <div class="control-tiempo">
                                    <div class="selector-numero">
                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="-5"
                                            aria-label="Restar 5 segundos"
                                        >−</button>

                                        <input
                                            type="number"
                                            name="tiempo_por_pregunta"
                                            min="<?= TiempoActividad::MIN_PREGUNTA_SEGUNDOS ?>"
                                            max="<?= TiempoActividad::MAX_PREGUNTA_SEGUNDOS ?>"
                                            step="1"
                                            inputmode="numeric"
                                            value="<?= e($datosFormulario['tiempo_por_pregunta']) ?>"
                                            aria-label="Segundos por pregunta"
                                        >

                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="5"
                                            aria-label="Sumar 5 segundos"
                                        >+</button>

                                        <span class="unidad-tiempo">segundos</span>
                                    </div>

                                    <div class="atajos-tiempo">
                                        <?php foreach ([10, 15, 20, 30, 45, 60, 90] as $segundos): ?>
                                            <button
                                                type="button"
                                                class="chip-tiempo"
                                                data-valor="<?= $segundos ?>"
                                            ><?= $segundos ?> s</button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Nuevo modo: tiempo por bloque -->
                            <div
                                class="panel-tiempo"
                                data-modo="por_bloque"
                                <?= $modoSeleccionado === 'por_bloque' ? '' : 'hidden' ?>
                            >
                                <span class="etiqueta-panel">
                                    Configura las preguntas y el tiempo de cada bloque.
                                </span>

                                <div class="control-tiempo">
                                    <label class="etiqueta-panel" for="preguntas_por_bloque">
                                        ¿Cuántas preguntas tendrá cada bloque?
                                    </label>

                                    <div class="selector-numero">
                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="-1"
                                            aria-label="Restar una pregunta por bloque"
                                        >−</button>

                                        <input
                                            type="number"
                                            id="preguntas_por_bloque"
                                            name="preguntas_por_bloque"
                                            min="<?= TiempoActividad::MIN_PREGUNTAS_BLOQUE ?>"
                                            max="<?= TiempoActividad::MAX_PREGUNTAS_BLOQUE ?>"
                                            step="1"
                                            inputmode="numeric"
                                            value="<?= e($datosFormulario['preguntas_por_bloque']) ?>"
                                            aria-label="Cantidad de preguntas por bloque"
                                        >

                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="1"
                                            aria-label="Añadir una pregunta por bloque"
                                        >+</button>

                                        <span class="unidad-tiempo">preguntas</span>
                                    </div>

                                    <div class="atajos-tiempo">
                                        <?php foreach ([2, 3, 4, 5, 10] as $cantidad): ?>
                                            <button
                                                type="button"
                                                class="chip-tiempo"
                                                data-valor="<?= $cantidad ?>"
                                            ><?= $cantidad ?> preguntas</button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="control-tiempo">
                                    <label class="etiqueta-panel" for="tiempo_por_bloque_minutos">
                                        ¿Cuántos minutos durará cada bloque?
                                    </label>

                                    <div class="selector-numero">
                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="-1"
                                            aria-label="Restar un minuto por bloque"
                                        >−</button>

                                        <input
                                            type="number"
                                            id="tiempo_por_bloque_minutos"
                                            name="tiempo_por_bloque_minutos"
                                            min="<?= TiempoActividad::MIN_BLOQUE_MINUTOS ?>"
                                            max="<?= TiempoActividad::MAX_BLOQUE_MINUTOS ?>"
                                            step="1"
                                            inputmode="numeric"
                                            value="<?= e($datosFormulario['tiempo_por_bloque_minutos']) ?>"
                                            aria-label="Minutos por bloque"
                                        >

                                        <button
                                            type="button"
                                            class="paso-tiempo"
                                            data-paso="1"
                                            aria-label="Añadir un minuto por bloque"
                                        >+</button>

                                        <span class="unidad-tiempo">minutos</span>
                                    </div>

                                    <div class="atajos-tiempo">
                                        <?php foreach ([1, 2, 3, 5, 10, 15] as $minutos): ?>
                                            <button
                                                type="button"
                                                class="chip-tiempo"
                                                data-valor="<?= $minutos ?>"
                                            ><?= $minutos ?> min</button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <p class="mensaje-info">
                                    Ejemplo: si una actividad tiene 10 preguntas,
                                    con 4 preguntas por bloque se formarán 3 bloques.
                                    Si asignas 5 minutos por bloque, el tiempo máximo
                                    será de 15 minutos.
                                </p>
                            </div>

                            <p
                                class="resumen-tiempo"
                                id="resumen-tiempo"
                                aria-live="polite"
                            ></p>
                        </fieldset>

                        <label>Puntaje mínimo para aprobar (%)
                            <input
                                type="number"
                                name="puntaje_minimo"
                                min="0"
                                max="100"
                                step="0.01"
                                value="<?= e($datosFormulario['puntaje_minimo']) ?>"
                            >
                        </label>

                        <label>Fecha límite (opcional)
                            <input
                                type="datetime-local"
                                name="fecha_limite"
                                value="<?= e($datosFormulario['fecha_limite']) ?>"
                            >
                            <small>
                                Déjala vacía si la actividad no tiene fecha de entrega.
                                Los estudiantes la verán y el asistente se la recordará.
                            </small>
                        </label>

                        <label>Descripción (opcional)
                            <textarea name="descripcion" rows="3"><?= e($datosFormulario['descripcion']) ?></textarea>
                        </label>
                    </div>

                    <button type="submit" class="btn-crear-actividad">
                        Crear actividad
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <section class="lista-actividades">
            <?php if (empty($actividades)): ?>
                <div class="sin-actividades">
                    <h2>No hay actividades registradas</h2>
                    <p>Aún no existen actividades creadas en el sistema.</p>
                </div>
            <?php else: ?>
                <?php foreach ($actividades as $actividad): ?>
                    <article class="tarjeta-actividad">
                        <span class="actividad-tipo">
                            <?= e($actividad['tipo'] ?? 'Actividad') ?>
                        </span>

                        <h2><?= e($actividad['titulo'] ?? 'Sin título') ?></h2>

                        <?php if (!empty($actividad['descripcion'])): ?>
                            <p class="actividad-descripcion">
                                <?= e($actividad['descripcion']) ?>
                            </p>
                        <?php endif; ?>

                        <div class="actividad-informacion">
                            <div class="dato-actividad">
                                <strong>Estado:</strong>
                                <span><?= e($actividad['estado'] ?? 'Sin estado') ?></span>
                            </div>

                            <div class="dato-actividad">
                                <strong>Preguntas:</strong>
                                <span>
                                    <?= (int) ($actividad['preguntas_registradas'] ?? 0) ?>
                                </span>
                            </div>

                            <div class="dato-actividad">
                                <strong>Tiempo:</strong>
                                <span>
                                    <?= e(
                                        TiempoActividad::describir(
                                            $actividad['tiempo_limite'] ?? null,
                                            $actividad['modo_tiempo'] ?? null,
                                            (int) ($actividad['preguntas_registradas'] ?? 0),
                                            isset($actividad['preguntas_por_bloque'])
                                                ? (int) $actividad['preguntas_por_bloque']
                                                : null
                                        )
                                    ) ?>
                                </span>
                            </div>

                            <div class="dato-actividad">
                                <strong>Fecha límite:</strong>
                                <span>
                                    <?= e(
                                        TiempoActividad::describirFecha(
                                            $actividad['fecha_limite'] ?? null
                                        )
                                    ) ?>
                                </span>
                            </div>

                            <div class="dato-actividad">
                                <strong>Puntaje mínimo:</strong>
                                <span>
                                    <?= isset($actividad['puntaje_minimo'])
                                        && $actividad['puntaje_minimo'] !== null
                                            ? e($actividad['puntaje_minimo'])
                                            : 'No definido' ?>
                                </span>
                            </div>
                        </div>

                        <div class="actividad-acciones">
                            <a
                                href="preguntas.php?id_actividad=<?= (int) ($actividad['id_actividad'] ?? 0) ?>"
                                class="btn-resolver"
                            >Gestionar preguntas</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <script>
    (function () {
        const grupo = document.getElementById('grupo-tiempo');

        if (!grupo) {
            return;
        }

        const radios = Array.from(
            grupo.querySelectorAll('input[name="modo_tiempo"]')
        );

        const paneles = Array.from(
            grupo.querySelectorAll('.panel-tiempo')
        );

        const resumen = document.getElementById('resumen-tiempo');

        function formatear(segundos) {
            const minutos = Math.floor(segundos / 60);
            const resto = segundos % 60;

            if (minutos === 0) {
                return resto + ' s';
            }

            return resto === 0
                ? minutos + ' min'
                : minutos + ' min ' + resto + ' s';
        }

        function modoActual() {
            const marcado = radios.find((radio) => radio.checked);

            return marcado ? marcado.value : 'sin_limite';
        }

        function valorValido(input) {
            const valor = Number(input.value);

            const dentro = input.value !== ''
                && Number.isInteger(valor)
                && valor >= Number(input.min)
                && valor <= Number(input.max);

            return dentro ? valor : null;
        }

        function actualizar() {
            const modo = modoActual();

            let texto = 'Los estudiantes podrán tomarse el tiempo que necesiten.';

            paneles.forEach((panel) => {
                const activo = panel.dataset.modo === modo;

                panel.hidden = !activo;

                const inputs = Array.from(
                    panel.querySelectorAll('input[type="number"]')
                );

                // Activa y exige solamente los campos del modo seleccionado.
                inputs.forEach((input) => {
                    input.disabled = !activo;
                    input.required = activo;
                });

                // Actualiza los chips y botones de cada campo numérico.
                panel.querySelectorAll('.control-tiempo').forEach((control) => {
                    const input = control.querySelector('input[type="number"]');

                    if (!input) {
                        return;
                    }

                    control.querySelectorAll('.chip-tiempo').forEach((chip) => {
                        const seleccionado = input.value !== ''
                            && chip.dataset.valor === String(Number(input.value));

                        chip.classList.toggle('activo', seleccionado);
                    });
                });

                if (!activo) {
                    return;
                }

                if (modo === 'total') {
                    const input = panel.querySelector(
                        'input[name="tiempo_minutos"]'
                    );

                    const minutos = valorValido(input);

                    if (minutos === null) {
                        texto = 'Ingresa una duración total entre '
                            + input.min + ' y ' + input.max + ' minutos.';
                    } else {
                        texto = 'Los estudiantes tendrán '
                            + minutos
                            + (minutos === 1 ? ' minuto' : ' minutos')
                            + ' para completar toda la actividad.';
                    }

                    return;
                }

                if (modo === 'por_pregunta') {
                    const input = panel.querySelector(
                        'input[name="tiempo_por_pregunta"]'
                    );

                    const segundos = valorValido(input);

                    if (segundos === null) {
                        texto = 'Ingresa un tiempo por pregunta entre '
                            + input.min + ' y ' + input.max + ' segundos.';
                    } else {
                        texto = 'Cada pregunta durará '
                            + formatear(segundos)
                            + '. Al terminar su tiempo, el estudiante pasará '
                            + 'a la siguiente pregunta.';
                    }

                    return;
                }

                if (modo === 'por_bloque') {
                    const inputPreguntas = panel.querySelector(
                        'input[name="preguntas_por_bloque"]'
                    );

                    const inputMinutos = panel.querySelector(
                        'input[name="tiempo_por_bloque_minutos"]'
                    );

                    const preguntas = valorValido(inputPreguntas);
                    const minutos = valorValido(inputMinutos);

                    if (preguntas === null) {
                        texto = 'Ingresa una cantidad de preguntas por bloque entre '
                            + inputPreguntas.min + ' y '
                            + inputPreguntas.max + '.';
                        return;
                    }

                    if (minutos === null) {
                        texto = 'Ingresa una duración por bloque entre '
                            + inputMinutos.min + ' y '
                            + inputMinutos.max + ' minutos.';
                        return;
                    }

                    texto = 'Cada bloque tendrá '
                        + preguntas
                        + (preguntas === 1 ? ' pregunta' : ' preguntas')
                        + ' y durará '
                        + minutos
                        + (minutos === 1 ? ' minuto' : ' minutos')
                        + '. Al agotarse el tiempo, el bloque deberá cerrarse '
                        + 'y se continuará con el siguiente.';

                    return;
                }
            });

            resumen.textContent = texto;
        }

        radios.forEach((radio) => {
            radio.addEventListener('change', actualizar);
        });

        // Cada grupo de controles maneja su propio campo numérico.
        grupo.querySelectorAll('.control-tiempo').forEach((control) => {
            const input = control.querySelector('input[type="number"]');

            if (!input) {
                return;
            }

            input.addEventListener('input', actualizar);

            control.querySelectorAll('.chip-tiempo').forEach((chip) => {
                chip.addEventListener('click', () => {
                    input.value = chip.dataset.valor;
                    actualizar();
                });
            });

            control.querySelectorAll('.paso-tiempo').forEach((boton) => {
                boton.addEventListener('click', () => {
                    const minimo = Number(input.min);
                    const maximo = Number(input.max);

                    const base = input.value !== ''
                        && Number.isFinite(Number(input.value))
                            ? Number(input.value)
                            : minimo;

                    input.value = Math.min(
                        maximo,
                        Math.max(
                            minimo,
                            base + Number(boton.dataset.paso)
                        )
                    );

                    actualizar();
                });
            });
        });

        actualizar();
    })();
    </script>
</body>
</html>
