<?php



require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';

AuthMiddleware::requireRole(['Docente', 'Administrador'], '../auth/login.php');



if (empty($_SESSION['csrf_gestion'])) {

    $_SESSION['csrf_gestion'] = bin2hex(random_bytes(32));

}

require_once __DIR__ . '/../../database/database.php';

require_once __DIR__ . '/../../src/Controllers/PreguntaController.php';



try {

    $database = new Database();

    $db = $database->connect();

} catch (RuntimeException $e) {

    die("No se pudo conectar a la base de datos. Verifica que MySQL esté encendido y que la base beatcell esté importada.");

}



$controller = new PreguntaController($db);



$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_gestion'], $token)) {

        $mensaje = ['tipo' => 'danger', 'texto' => 'La sesión del formulario expiró. Recarga la página.'];

    } else {

        $mensaje = $controller->procesarFormulario();

    }

}

$actividades = $controller->obtenerActividades();



$idActividadSeleccionada = isset($_GET['id_actividad']) ? (int) $_GET['id_actividad'] : ($actividades[0]['id_actividad'] ?? null);

$preguntas = $controller->listarPorActividad($idActividadSeleccionada);

// Calcular los bloques de la actividad seleccionada.
$actividadBloques = null;

if (!empty($idActividadSeleccionada)) {
    $stmtBloques = $db->prepare("
        SELECT modo_tiempo, tiempo_limite, preguntas_por_bloque,
            (SELECT COUNT(*)
             FROM preguntas p
             WHERE p.id_actividad = a.id_actividad
               AND p.estado = 'Activo') AS preguntas_activas
        FROM actividades a
        WHERE id_actividad = ?
    ");

    $stmtBloques->execute([(int) $idActividadSeleccionada]);
    $actividadBloques = $stmtBloques->fetch(PDO::FETCH_ASSOC) ?: null;
}

$preguntasActivas = (int) ($actividadBloques['preguntas_activas'] ?? 0);
$preguntasPorBloque = (int) ($actividadBloques['preguntas_por_bloque'] ?? 0);
$minutosPorBloque = (int) ($actividadBloques['tiempo_limite'] ?? 0);

$totalBloques = (
    ($actividadBloques['modo_tiempo'] ?? '') === 'por_bloque'
    && $preguntasPorBloque > 0
    && $preguntasActivas > 0
) ? (int) ceil($preguntasActivas / $preguntasPorBloque) : 0;



$preguntaEditar = null;

if (isset($_GET['editar'])) {

    $preguntaEditar = $controller->verDetalle((int)$_GET['editar']);

}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gestión de Preguntas - BeatCell</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

</head>

<body class="bg-light">



    <div class="container py-4">

        <header class="pb-3 mb-4 border-bottom d-flex justify-content-between align-items-center">

            <h1><i class="bi bi-question-circle-fill text-primary"></i> Gestión de Preguntas - BeatCell</h1>

            <a href="panel.php" class="btn btn-outline-secondary">

                <i class="bi bi-arrow-left"></i> Volver al Panel

            </a>

        </header>



        <?php if ($mensaje): ?>

            <div class="alert alert-<?= htmlspecialchars($mensaje['tipo'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show" role="alert">

                <?= nl2br(htmlspecialchars($mensaje['texto'], ENT_QUOTES, 'UTF-8')) ?>

                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>

            </div>

        <?php endif; ?>



        <!-- Seleccionar actividad -->

        <div class="card shadow-sm mb-4">

            <div class="card-body">

                <form method="GET" class="row g-3 align-items-center">

                    <div class="col-auto">

                        <label for="id_actividad" class="col-form-label fw-bold">Actividad:</label>

                    </div>

                    <div class="col-md-6">

                        <select name="id_actividad" id="id_actividad" class="form-select" onchange="this.form.submit()">

                            <option value="">-- Seleccione una Actividad --</option>

                            <?php foreach ($actividades as $act): ?>

                                <option value="<?= $act['id_actividad'] ?>" <?= $idActividadSeleccionada == $act['id_actividad'] ? 'selected' : '' ?>>

                                    [Actividad #<?= $act['id_actividad'] ?>] <?= htmlspecialchars($act['titulo']) ?> (Tema: <?= htmlspecialchars($act['tema_nombre']) ?>)

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </form>

            </div>

        </div>



        <div class="row">

            <!-- Crear / Editar Formulario -->

            <div class="col-lg-5 mb-4">

                <div class="card shadow-sm">

                    <div class="card-header bg-primary text-white">

                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> <?= $preguntaEditar ? 'Editar Pregunta #' . $preguntaEditar['id_pregunta'] : 'Nueva Pregunta' ?></h5>

                    </div>

                    <div class="card-body">

                        <form method="POST" action="preguntas.php?id_actividad=<?= $idActividadSeleccionada ?>" enctype="multipart/form-data">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_gestion'], ENT_QUOTES, 'UTF-8') ?>">

                            <input type="hidden" name="accion" value="<?= $preguntaEditar ? 'actualizar' : 'crear' ?>">

                            <?php if ($preguntaEditar): ?>

                                <input type="hidden" name="id_pregunta" value="<?= $preguntaEditar['id_pregunta'] ?>">

                            <?php endif; ?>



                            <div class="mb-3">

                                <label for="form_id_actividad" class="form-label fw-bold">Asociar a Actividad</label>

                                <select name="id_actividad" id="form_id_actividad" class="form-select" required>

                                    <?php foreach ($actividades as $act): ?>

                                        <option value="<?= $act['id_actividad'] ?>" <?= ($preguntaEditar ? $preguntaEditar['id_actividad'] : $idActividadSeleccionada) == $act['id_actividad'] ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($act['titulo']) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>



                            <div class="row">

                                <div class="col-md-6 mb-3">

                                    <label for="tipo" class="form-label fw-bold">Tipo</label>

                                    <select name="tipo" id="tipo" class="form-select" onchange="cambiarTipoPregunta()">

                                        <option value="verdadero_falso" <?= ($preguntaEditar['tipo'] ?? '') === 'verdadero_falso' ? 'selected' : '' ?>>Verdadero / Falso</option>

                                        <option value="completar" <?= ($preguntaEditar['tipo'] ?? '') === 'completar' ? 'selected' : '' ?>>Completar</option>

                                        <option value="imagen" <?= ($preguntaEditar['tipo'] ?? '') === 'imagen' ? 'selected' : '' ?>>Imagen</option>

                                        <option value="audio" <?= ($preguntaEditar['tipo'] ?? '') === 'audio' ? 'selected' : '' ?>>Audio</option>
                                          <option value="opcion_multiple" <?= ($preguntaEditar['tipo'] ?? '') === 'opcion_multiple' ? 'selected' : '' ?>>Opción múltiple</option>
                                          <option value="video" <?= ($preguntaEditar['tipo'] ?? '') === 'video' ? 'selected' : '' ?>>Video</option>

                                    </select>

                                </div>

                                <div class="col-md-6 mb-3">

                                    <label for="puntos" class="form-label fw-bold">Puntos</label>

                                    <input type="number" name="puntos" id="puntos" class="form-control" value="<?= (int) ($preguntaEditar['puntos'] ?? 1) ?>" min="1" required>

                                </div>

                            </div>



                            <div class="mb-3">

                                <label for="pregunta" class="form-label fw-bold">Enunciado</label>

                                <textarea name="pregunta" id="pregunta" rows="3" class="form-control" required><?= htmlspecialchars($preguntaEditar['pregunta'] ?? '') ?></textarea>

                            </div>



                            <div class="mb-3">

                                <label for="imagen" class="form-label fw-bold">Imagen del enunciado (opcional)</label>

                                <?php if (!empty($preguntaEditar['imagen'])): ?>

                                    <div class="mb-2">

                                        <img src="../../public/assets/img/preguntas/<?= htmlspecialchars(basename($preguntaEditar['imagen']), ENT_QUOTES, 'UTF-8') ?>" alt="Imagen actual del enunciado" class="img-thumbnail" style="max-height: 140px;">

                                        <input type="hidden" name="imagen_actual_pregunta" value="<?= htmlspecialchars(basename($preguntaEditar['imagen']), ENT_QUOTES, 'UTF-8') ?>">

                                    </div>

                                <?php endif; ?>

                                <input type="file" name="imagen" id="imagen" class="form-control" accept="image/*">

                                <div class="form-text small text-muted">Puedes adjuntar una imagen que acompañe el enunciado.</div>

                            </div>



                            <!-- Campo de Audio para la Pregunta (Visible cuando tipo === 'audio') -->

                            <div class="mb-3" id="contenedor-audio-pregunta" style="display: none;">

                                <label for="audio_pregunta" class="form-label fw-bold"><i class="bi bi-volume-up-fill text-primary"></i> Archivo de Audio de la Pregunta</label>

                                <?php if (!empty($preguntaEditar['audio'])): ?>

                                    <div class="mb-2 p-2 border rounded bg-white">

                                        <audio controls style="max-width: 100%; height: 35px;">

                                            <source src="../../public/assets/audio/preguntas/<?= htmlspecialchars($preguntaEditar['audio'], ENT_QUOTES, 'UTF-8') ?>">

                                            Tu navegador no soporta el reproductor de audio.

                                        </audio>

                                        <input type="hidden" name="audio_actual_pregunta" value="<?= htmlspecialchars($preguntaEditar['audio'], ENT_QUOTES, 'UTF-8') ?>">

                                    </div>

                                <?php endif; ?>

                                <input type="file" name="audio_pregunta" id="audio_pregunta" class="form-control" accept="audio/*">

                                <div class="form-text small text-muted">Sube el archivo de audio que escuchará el estudiante como enunciado.</div>

                            </div>                              <div class="mb-3" id="contenedor-video-pregunta" style="display:none;">
                                  <label class="form-label fw-bold" for="video_url">Video de la pregunta</label>
                                  <?php if (!empty($preguntaEditar['video_url'])): ?>
                                      <p class="small">Video actual: <?= htmlspecialchars($preguntaEditar['video_url'], ENT_QUOTES, 'UTF-8') ?></p>
                                  <?php endif; ?>
                                  <input type="url" name="video_url" id="video_url" class="form-control mb-2" placeholder="https://www.youtube.com/watch?v=..." value="<?= htmlspecialchars((isset($preguntaEditar['video_url']) && preg_match('~^https://~i', $preguntaEditar['video_url'])) ? $preguntaEditar['video_url'] : '', ENT_QUOTES, 'UTF-8') ?>">
                                  <label for="video_pregunta" class="form-label">O subir MP4</label>
                                  <input type="file" name="video_pregunta" id="video_pregunta" class="form-control" accept="video/mp4,.mp4">
                                  <div class="form-text">Usa un enlace HTTPS o un archivo MP4. Para reemplazar un video local, selecciona otro archivo.</div>
                              </div>
                              <div class="mb-3" id="contenedor-tipo-respuesta-video" style="display:none;">
                                  <label for="tipo_respuesta" class="form-label fw-bold">Tipo de respuesta del video</label>
                                  <select name="tipo_respuesta" id="tipo_respuesta" class="form-select" onchange="cambiarTipoPregunta(true)">
                                      <option value="opcion_multiple" <?= ($preguntaEditar['tipo_respuesta'] ?? 'opcion_multiple') === 'opcion_multiple' ? 'selected' : '' ?>>Opciones</option>
                                      <option value="verdadero_falso" <?= ($preguntaEditar['tipo_respuesta'] ?? '') === 'verdadero_falso' ? 'selected' : '' ?>>Verdadero / Falso</option>
                                      <option value="completar" <?= ($preguntaEditar['tipo_respuesta'] ?? '') === 'completar' ? 'selected' : '' ?>>Completar</option>
                                  </select>
                              </div>
                              <div class="mb-3" id="contenedor-seleccion-multiple" style="display:none;">
                                  <input type="hidden" name="seleccion_multiple" value="0">
                                  <div class="form-check">
                                      <input class="form-check-input" type="checkbox" name="seleccion_multiple" id="seleccion_multiple" value="1" <?= !empty($preguntaEditar['seleccion_multiple']) ? 'checked' : '' ?> onchange="actualizarModoSeleccion()">
                                      <label class="form-check-label" for="seleccion_multiple">Permitir varias respuestas correctas</label>
                                  </div>
                              </div>





                            <div class="mb-3">

                                <label for="estado" class="form-label fw-bold">Estado</label>

                                <select name="estado" id="estado" class="form-select">

                                    <option value="Activo" <?= ($preguntaEditar['estado'] ?? 'Activo') === 'Activo' ? 'selected' : '' ?>>Activo</option>

                                    <option value="Inactivo" <?= ($preguntaEditar['estado'] ?? 'Activo') === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>

                                </select>

                            </div>



                            <div class="mb-3">

                                <label for="explicacion" class="form-label fw-bold">Explicación / Retroalimentación</label>

                                <textarea name="explicacion" id="explicacion" rows="2" class="form-control"><?= htmlspecialchars($preguntaEditar['explicacion'] ?? '') ?></textarea>

                            </div>



                            <hr>

                            <div class="d-flex justify-content-between align-items-center mb-2">

                                <label class="form-label fw-bold mb-0" id="label-opciones">Opciones y Respuesta Correcta</label>

                                <button type="button" id="btn-agregar-opcion" class="btn btn-sm btn-outline-success" onclick="agregarOpcion()"><i class="bi bi-plus"></i> Añadir Opción</button>

                            </div>



                            <div id="contenedor-opciones" class="mb-3">

                                <!-- Se renderiza dinámicamente mediante JS -->

                            </div>



                            <div class="d-grid gap-2">

                                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> <?= $preguntaEditar ? 'Actualizar Pregunta' : 'Guardar Pregunta' ?></button>

                                <?php if ($preguntaEditar): ?>

                                    <a href="preguntas.php?id_actividad=<?= $idActividadSeleccionada ?>" class="btn btn-secondary">Cancelar</a>

                                <?php endif; ?>

                            </div>

                        </form>

                    </div>

                </div>

            </div>



            <!-- Listado -->

            <div class="col-lg-7">

                <div class="card shadow-sm">

                    <div class="card-header bg-secondary text-white">

                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Preguntas Registradas</h5>

                    </div>

                    <div class="card-body">
                                    <?php if ($totalBloques > 0): ?>
                    <div class="alert alert-primary mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><i class="bi bi-layers"></i> Bloques de la actividad</strong>
                            <span class="badge bg-primary fs-6">
                                <?= $totalBloques ?> bloques
                            </span>
                        </div>

                        <p class="small mt-2 mb-3">
                            <?= $preguntasActivas ?> preguntas activas ·
                            <?= $preguntasPorBloque ?> preguntas por bloque ·
                            <?= $minutosPorBloque ?> minutos por bloque
                        </p>

                        <div class="row g-2">
                            <?php for ($i = 1; $i <= $totalBloques; $i++):
                                $desde = (($i - 1) * $preguntasPorBloque) + 1;
                                $hasta = min($i * $preguntasPorBloque, $preguntasActivas);
                                $cantidad = $hasta - $desde + 1;
                            ?>
                                <div class="col-sm-6 col-xl-4">
                                    <div class="border rounded bg-white p-2 h-100">
                                        <strong>Bloque <?= $i ?></strong>
                                        <div class="small">Preguntas <?= $desde ?>–<?= $hasta ?></div>
                                        <span class="badge bg-secondary">
                                            <?= $cantidad ?> preguntas
                                        </span>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <p class="small mt-3 mb-0">
                            Tiempo máximo total:
                            <strong><?= $totalBloques * $minutosPorBloque ?> minutos</strong>
                        </p>
                    </div>
                <?php endif; ?>                    
                        <?php if (empty($preguntas)): ?>

                            <p class="text-muted text-center py-4">No hay preguntas para esta actividad.</p>

                        <?php else: ?>

                            <div class="accordion" id="accordionPreguntas">

                                <?php foreach ($preguntas as $preg): $conOpciones = $controller->verDetalle($preg['id_pregunta']); ?>

                                    <div class="accordion-item mb-2">

                                        <h2 class="accordion-header" id="heading<?= $preg['id_pregunta'] ?>">

                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $preg['id_pregunta'] ?>">

                                                <span class="badge bg-primary me-2">#<?= $preg['id_pregunta'] ?></span>

                                                <span class="badge bg-info text-dark me-2"><?= htmlspecialchars($preg['tipo']) ?></span>

                                                <strong class="me-auto"><?= htmlspecialchars(mb_substr($preg['pregunta'], 0, 50)) ?>...</strong>

                                                <span class="badge bg-secondary ms-2"><?= (int) $preg['puntos'] ?> pts</span>

                                                <?php if ($preg['estado'] === 'Inactivo'): ?><span class="badge bg-dark ms-1">Inactiva</span><?php endif; ?>

                                            </button>

                                        </h2>

                                        <div id="collapse<?= $preg['id_pregunta'] ?>" class="accordion-collapse collapse" data-bs-parent="#accordionPreguntas">

                                            <div class="accordion-body">

                                                <p><strong>Enunciado:</strong> <?= htmlspecialchars($preg['pregunta']) ?></p>



                                                <?php 

                                                $audioPregunta = $conOpciones['audio'] ?? $preg['audio'] ?? null;

                                                if (!empty($audioPregunta)): 

                                                ?>

                                                    <div class="mb-3 p-2 bg-light border rounded">

                                                        <span class="small fw-bold text-muted d-block mb-1"><i class="bi bi-volume-up-fill"></i> Audio del Enunciado:</span>

                                                        <audio controls style="max-width: 100%; height: 35px;">

                                                            <source src="../../public/assets/audio/preguntas/<?= htmlspecialchars($audioPregunta) ?>">

                                                            Tu navegador no soporta el reproductor de audio.

                                                        </audio>

                                                    </div>

                                                <?php endif; ?>



                                                <?php if (!empty($preg['explicacion'])): ?>

                                                    <p><strong>Explicación:</strong> <?= htmlspecialchars($preg['explicacion']) ?></p>

                                                <?php endif; ?>



                                                <h6 class="fw-bold mt-3">Opciones / Respuesta:</h6>

                                                <ul class="list-group mb-3">

                                                    <?php foreach ($conOpciones['opciones'] as $opcion): ?>

                                                        <li class="list-group-item d-flex justify-content-between align-items-center <?= $opcion['correcta'] ? 'list-group-item-success' : '' ?>">

                                                            <div>

                                                                <?php if (!empty($opcion['texto'])): ?>

                                                                    <span><?= htmlspecialchars($opcion['texto']) ?></span><br>

                                                                <?php endif; ?>

                                                                <?php if (!empty($opcion['imagen'])): ?>

                                                                    <img src="../../public/assets/img/preguntas/<?= htmlspecialchars($opcion['imagen']) ?>" alt="Imagen opción" class="img-thumbnail mt-1" style="max-height: 80px;">

                                                                <?php endif; ?>

                                                            </div>

                                                            <?php if ($opcion['correcta']): ?>

                                                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Correcta</span>

                                                            <?php endif; ?>

                                                        </li>

                                                    <?php endforeach; ?>

                                                </ul>



                                                <div class="d-flex justify-content-end gap-2">

                                                    <a href="preguntas.php?id_actividad=<?= $idActividadSeleccionada ?>&editar=<?= $preg['id_pregunta'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i> Editar</a>



                                                    <form method="POST" action="preguntas.php?id_actividad=<?= $idActividadSeleccionada ?>" onsubmit="return confirm('¿Eliminar pregunta?');" style="display:inline;">

                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_gestion'], ENT_QUOTES, 'UTF-8') ?>">

                                                        <input type="hidden" name="accion" value="eliminar">

                                                        <input type="hidden" name="id_pregunta" value="<?= $preg['id_pregunta'] ?>">

                                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Eliminar</button>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>

     <?php

    // Normalización del tipo para garantizar coincidencia con el <select>

    $tipoRaw = strtolower(trim($preguntaEditar['tipo'] ?? 'verdadero_falso'));

    if (strpos($tipoRaw, 'verdadero') !== false) {$tipoNormalizado = 'verdadero_falso';

    } elseif (strpos($tipoRaw, 'completar') !== false) {$tipoNormalizado = 'completar';

    } elseif (strpos($tipoRaw, 'imagen') !== false) {$tipoNormalizado = 'imagen';

    } elseif (strpos($tipoRaw, 'audio') !== false) {$tipoNormalizado = 'audio';
    } elseif (strpos($tipoRaw, 'video') !== false) {$tipoNormalizado = 'video';
    } elseif (strpos($tipoRaw, 'opcion_multiple') !== false) {$tipoNormalizado = 'opcion_multiple';

    } else {

        $tipoNormalizado = 'verdadero_falso';

    }

    ?>



    // array_values() garantiza que json_encode devuelva un Array [] y no un Objeto {}

    const opcionesRaw = <?= json_encode(array_values($preguntaEditar['opciones'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const opcionesExistentes = Array.isArray(opcionesRaw) ? opcionesRaw : Object.values(opcionesRaw || {});

    const tipoActual = <?= json_encode($tipoNormalizado) ?>;



    function esc(valor) {

        return String(valor ?? '')

            .replace(/&/g, '&')

            .replace(/"/g, '"')

            .replace(/</g, '<')

            .replace(/>/g, '>');

    }



    document.addEventListener("DOMContentLoaded", function() {

        const selectTipo = document.getElementById('tipo');

        if (selectTipo) {

            selectTipo.value = tipoActual;

        }

        cambiarTipoPregunta(true);

    });



    function cambiarTipoPregunta(cargarDatos = false) {

        const tipo = document.getElementById('tipo').value;
        const esVideo = tipo === 'video';
        const tipoEfectivo = esVideo ? (document.getElementById('tipo_respuesta')?.value || 'opcion_multiple') : tipo;
        const selectorVideo = document.getElementById('contenedor-tipo-respuesta-video');
        if (selectorVideo) selectorVideo.style.display = esVideo ? 'block' : 'none';

        const contenedor = document.getElementById('contenedor-opciones');

        const btnAgregar = document.getElementById('btn-agregar-opcion');

        const labelOpciones = document.getElementById('label-opciones');

        const contenedorAudioPregunta = document.getElementById('contenedor-audio-pregunta');
        const contenedorVideo = document.getElementById('contenedor-video-pregunta');
        const contenedorMultiple = document.getElementById('contenedor-seleccion-multiple');
        if (contenedorVideo) contenedorVideo.style.display = tipo === 'video' ? 'block' : 'none';
        if (contenedorMultiple) contenedorMultiple.style.display = (esVideo ? tipoEfectivo === 'opcion_multiple' : ['audio','imagen','opcion_multiple'].includes(tipo)) ? 'block' : 'none';



        if (!contenedor) return;



        // Mostrar u ocultar campo de audio del enunciado

        if (contenedorAudioPregunta) {

            contenedorAudioPregunta.style.display = (tipo === 'audio') ? 'block' : 'none';

        }



        contenedor.innerHTML = '';



        if (tipoEfectivo === 'verdadero_falso') {

            if (btnAgregar) btnAgregar.style.display = 'none';

            if (labelOpciones) labelOpciones.textContent = 'Indique la Respuesta Correcta';



            let esVerdaderoCorrecta = true;

            let esFalsoCorrecta = false;



            if (cargarDatos && opcionesExistentes.length >= 2) {

                esVerdaderoCorrecta = opcionesExistentes.find(o => String(o.texto).trim().toLowerCase() === 'verdadero')?.correcta == 1;

                esFalsoCorrecta = opcionesExistentes.find(o => String(o.texto).trim().toLowerCase() === 'falso')?.correcta == 1;

            }



            const idVerdadero = opcionesExistentes.find(o => String(o.texto).trim().toLowerCase() === 'verdadero')?.id_opcion ?? '';

            const idFalso = opcionesExistentes.find(o => String(o.texto).trim().toLowerCase() === 'falso')?.id_opcion ?? '';



            contenedor.innerHTML = `

                <div class="form-check mb-2 p-2 border rounded bg-white">

                    <input class="form-check-input ms-1 me-2" type="radio" name="opciones[correcta_vf]" id="vf_verdadero" value="Verdadero" ${esVerdaderoCorrecta ? 'checked' : ''} required>

                    <label class="form-check-label fw-bold" for="vf_verdadero">Verdadero</label>

                    <input type="hidden" name="opciones[texto][0]" value="Verdadero">

                    <input type="hidden" name="opciones[correcta][0]" value="${esVerdaderoCorrecta ? '1' : '0'}">

                    <input type="hidden" name="opciones[id_opcion][0]" value="${idVerdadero}">

                </div>

                <div class="form-check mb-2 p-2 border rounded bg-white">

                    <input class="form-check-input ms-1 me-2" type="radio" name="opciones[correcta_vf]" id="vf_falso" value="Falso" ${esFalsoCorrecta ? 'checked' : ''} required>

                    <label class="form-check-label fw-bold" for="vf_falso">Falso</label>

                    <input type="hidden" name="opciones[texto][1]" value="Falso">

                    <input type="hidden" name="opciones[correcta][1]" value="${esFalsoCorrecta ? '1' : '0'}">

                    <input type="hidden" name="opciones[id_opcion][1]" value="${idFalso}">

                </div>

            `;



            document.querySelectorAll('input[name="opciones[correcta_vf]"]').forEach((radio, idx) => {

                radio.addEventListener('change', function() {
            if (document.getElementById('seleccion_multiple')?.checked) { sincronizarCorrectas(); return; }

                    document.querySelectorAll('input[name^="opciones[correcta]"]').forEach(inp => inp.value = "0");

                    const targetInput = document.querySelector(`input[name="opciones[correcta][${idx}]"]`);

                    if (targetInput) targetInput.value = "1";

                });

            });



        } else if (tipoEfectivo === 'completar') {

            if (btnAgregar) btnAgregar.style.display = 'none';

            if (labelOpciones) labelOpciones.textContent = 'Respuesta Correcta (Palabra o frase esperada)';



            let textoRespuesta = '';

            if (cargarDatos && opcionesExistentes.length > 0) {

                textoRespuesta = opcionesExistentes[0].texto || '';

            }

            const idRespuesta = opcionesExistentes[0]?.id_opcion ?? '';



            contenedor.innerHTML = `

                <div class="input-group mb-2">

                    <input type="text" name="opciones[texto][0]" class="form-control" placeholder="Escribe la respuesta correcta..." value="${esc(textoRespuesta)}" required>

                    <input type="hidden" name="opciones[correcta][0]" value="1">

                    <input type="hidden" name="opciones[id_opcion][0]" value="${idRespuesta}">

                </div>

            `;



        } else if (tipo === 'audio' || tipo === 'video' || tipo === 'opcion_multiple') {

            if (btnAgregar) btnAgregar.style.display = 'inline-block';

            if (labelOpciones) labelOpciones.textContent = 'Opciones y Respuesta Correcta';



            if (cargarDatos && opcionesExistentes.length > 0) {

                opcionesExistentes.forEach((op, idx) => {

                    crearFilaOpcion(idx, op.texto, op.correcta == 1, op.imagen || '', false, op.id_opcion);

                });

            } else {

                crearFilaOpcion(0, '', false, '', false, 0);

                crearFilaOpcion(1, '', false, '', false, 0);

            }



        } else if (tipo === 'imagen') {

            if (btnAgregar) btnAgregar.style.display = 'inline-block';

            if (labelOpciones) labelOpciones.textContent = 'Opciones con Imágenes y Respuesta Correcta';



            if (cargarDatos && opcionesExistentes.length > 0) {

                opcionesExistentes.forEach((op, idx) => {

                    crearFilaOpcion(idx, op.texto, op.correcta == 1, op.imagen || '', true, op.id_opcion);

                });

            } else {

                crearFilaOpcion(0, '', false, '', true, 0);

                crearFilaOpcion(1, '', false, '', true, 0);

            }

        }

    }



    function actualizarModoSeleccion() {
        const multiple = document.getElementById('seleccion_multiple')?.checked;
        const checks = document.querySelectorAll('.opcion-item input[name="opciones[correcta_radio]"]');
        checks.forEach((input) => { input.type = multiple ? 'checkbox' : 'radio'; });
        if (!multiple) {
            let elegido = false;
            checks.forEach((input) => {
                if (input.checked && !elegido) elegido = true;
                else input.checked = false;
            });
        }
        sincronizarCorrectas();
    }

    function sincronizarCorrectas() {
        document.querySelectorAll('.opcion-item').forEach(item => {
            const input = item.querySelector('input[name="opciones[correcta_radio]"]');
            const hidden = item.querySelector('input[name^="opciones[correcta]"]');
            if (input && hidden) hidden.value = input.checked ? '1' : '0';
        });
    }

    // Verificar la respuesta correcta antes de enviar el formulario.
    document.querySelector('form[enctype="multipart/form-data"]')?.addEventListener('submit', function (evento) {
        const tipo = document.getElementById('tipo').value;
        if (tipo === 'completar' || tipo === 'verdadero_falso' || (tipo === 'video' && ['completar', 'verdadero_falso'].includes(document.getElementById('tipo_respuesta')?.value))) return;
        sincronizarCorrectas();
        const opciones = [...document.querySelectorAll('.opcion-item input[name="opciones[correcta_radio]"]')];
        if (opciones.length < 2 || !opciones.some(op => op.checked)) {
            evento.preventDefault();
            alert('Agrega al menos dos opciones y marca una respuesta correcta.');
        }
    });

    function agregarOpcion() {

        const contadorOpciones = document.querySelectorAll('.opcion-item').length;

        const tipo = document.getElementById('tipo').value;

        crearFilaOpcion(

            contadorOpciones, 

            '', 

            false, 

            '', 

            tipo === 'imagen', 

            0

        );

    }



    function crearFilaOpcion(index, texto = '', correcta = false, imagen = '', mostrarImagen = false, idOpcion = 0) {

        const contenedor = document.getElementById('contenedor-opciones');

        const div = document.createElement('div');

        div.className = 'mb-3 border p-3 rounded bg-white opcion-item';



        let imagenActualHtml = '';

        if (imagen) {

            imagenActualHtml = `

                <div class="mb-2">

                    <img src="../../public/assets/img/preguntas/${esc(imagen)}" alt="Vista previa" class="img-thumbnail" style="max-height: 60px;">

                    <input type="hidden" name="opciones[imagen_actual][${index}]" value="${esc(imagen)}">

                </div>

            `;

        }



        let inputImagenHtml = '';

        if (mostrarImagen) {

            inputImagenHtml = `

                ${imagenActualHtml}

                <div class="mb-1">

                    <label class="form-label small text-muted">Subir imagen para la opción:</label>

                    <input type="file" name="opciones[imagen][${index}]" class="form-control form-control-sm" accept="image/*">

                </div>

            `;

        }



        div.innerHTML = `

            <div class="d-flex justify-content-between align-items-center mb-2">

                <div class="form-check">

                    <input class="form-check-input" type="${document.getElementById('seleccion_multiple')?.checked ? 'checkbox' : 'radio'}" name="opciones[correcta_radio]" value="${index}" ${correcta ? 'checked' : ''} id="chk_${index}">

                    <label class="form-check-label fw-bold" for="chk_${index}">Marcar como Correcta</label>

                    <input type="hidden" name="opciones[correcta][${index}]" value="${correcta ? '1' : '0'}">

                    <input type="hidden" name="opciones[id_opcion][${index}]" value="${idOpcion}">

                </div>

                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.opcion-item').remove(); actualizarIndices();"><i class="bi bi-trash"></i> Eliminar</button>

            </div>

            <div class="mb-2">

                <input type="text" name="opciones[texto][${index}]" class="form-control" placeholder="${mostrarImagen ? 'Texto de la opción (Opcional si se usa imagen)' : 'Texto de la opción'}" value="${esc(texto)}" ${mostrarImagen ? '' : 'required'}>

            </div>

            ${inputImagenHtml}

        `;

        contenedor.appendChild(div);



        const radio = div.querySelector('input[name="opciones[correcta_radio]"]');

        radio.addEventListener('change', function() {
            if (document.getElementById('seleccion_multiple')?.checked) { sincronizarCorrectas(); return; }

            document.querySelectorAll('.opcion-item').forEach((item) => {

                const hid = item.querySelector('input[name^="opciones[correcta]"]');

                const rad = item.querySelector('input[name="opciones[correcta_radio]"]');

                if (hid && rad) {

                    hid.value = rad.checked ? '1' : '0';

                }

            });

        });

    }



    function actualizarIndices() {

        document.querySelectorAll('.opcion-item').forEach((item, newIndex) => {

            const radio = item.querySelector('input[name="opciones[correcta_radio]"]');

            const hiddenCorrecta = item.querySelector('input[name^="opciones[correcta]"]');

            const hiddenIdOpcion = item.querySelector('input[name^="opciones[id_opcion]"]');

            const inputTexto = item.querySelector('input[name^="opciones[texto]"]');

            const hiddenImagenActual = item.querySelector('input[name^="opciones[imagen_actual]"]');

            const inputFileImagen = item.querySelector('input[type="file"][name*="[imagen]"]');

            const label = item.querySelector('.form-check-label');



            if (radio) {

                radio.id = `chk_${newIndex}`;

                radio.value = newIndex;

            }

            if (label) {

                label.setAttribute('for', `chk_${newIndex}`);

            }

            if (hiddenCorrecta) {

                hiddenCorrecta.name = `opciones[correcta][${newIndex}]`;

            }

            if (hiddenIdOpcion) {

                hiddenIdOpcion.name = `opciones[id_opcion][${newIndex}]`;

            }

            if (inputTexto) {

                inputTexto.name = `opciones[texto][${newIndex}]`;

            }

            if (hiddenImagenActual) {

                hiddenImagenActual.name = `opciones[imagen_actual][${newIndex}]`;

            }

            if (inputFileImagen) {

                inputFileImagen.name = `opciones[imagen][${newIndex}]`;

            }

        });

    }

</script>

</body>

</html>
