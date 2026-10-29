
<?php
require_once __DIR__ . '/../../src/Support/ViewHelpers.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';

$sesionUsuario = AuthMiddleware::requireRole(
    ['Estudiante'],
    '../auth/login.php'
);

require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/../../src/Models/TiempoActividad.php';

$actividades = [];
$errorCarga = null;

try {
    $db = (new Database())->connect();

    $stmt = $db->prepare("
        SELECT
            a.id_actividad,
            a.titulo,
            a.descripcion,
            a.tipo,
            a.modo_tiempo,
            a.tiempo_limite,
            a.preguntas_por_bloque,
            a.puntaje_minimo,
            a.fecha_limite,

            (
                SELECT COUNT(*)
                FROM preguntas p
                WHERE p.id_actividad = a.id_actividad
                  AND p.estado = 'Activo'
            ) AS preguntas_registradas,

            EXISTS (
                SELECT 1
                FROM resultados r
                WHERE r.id_actividad = a.id_actividad
                  AND r.id_usuario = ?
            ) AS realizada

        FROM actividades a

        WHERE a.estado = 'Activo'
          AND EXISTS (
              SELECT 1
              FROM preguntas p
              WHERE p.id_actividad = a.id_actividad
                AND p.estado = 'Activo'
          )

        ORDER BY a.id_actividad DESC
    ");

    $stmt->execute([
        (int) $sesionUsuario['id_usuario']
    ]);

    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log(
        'BeatCell - error al cargar actividades del estudiante: '
        . $e->getMessage()
    );

    $errorCarga = 'No se pudieron cargar las actividades. Inténtalo de nuevo más tarde.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Actividades | BeatCell</title>

    <link rel="stylesheet" href="../../public/assets/css/actividades.css">

    <link
        rel="stylesheet"
        href="../../public/assets/css/estudiante.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/estudiante.css') ?>"
    >
</head>

<body>

<?php require __DIR__ . '/_navegacion.php'; ?>

<main class="contenedor-actividades">

    <section class="encabezado-actividades">
        <h1>Actividades</h1>
        <p>Selecciona una actividad para comenzar.</p>
    </section>

    <?php if ($errorCarga): ?>
        <p class="mensaje-error" role="alert">
            <?= e($errorCarga) ?>
        </p>
    <?php endif; ?>

    <section class="lista-actividades">

        <?php if (!$errorCarga && !$actividades): ?>

            <p>No hay actividades activas disponibles.</p>

        <?php elseif ($actividades): ?>

            <?php foreach ($actividades as $actividad): ?>

                <article class="tarjeta-actividad<?= (int) $actividad['realizada'] === 1 ? ' actividad-realizada' : '' ?>">

                    <?php if ((int) $actividad['realizada'] === 1): ?>
                        <span
                            class="estado-actividad realizada"
                            aria-label="Actividad realizada"
                        >
                            <span aria-hidden="true">✓</span>
                            Realizada
                        </span>
                    <?php endif; ?>

                    <span class="actividad-tipo">
                        <?= e($actividad['tipo']) ?>
                    </span>

                    <h2><?= e($actividad['titulo']) ?></h2>

                    <?php if (!empty($actividad['descripcion'])): ?>
                        <p><?= e($actividad['descripcion']) ?></p>
                    <?php endif; ?>

                    <p>
                        Preguntas:
                        <?= (int) $actividad['preguntas_registradas'] ?>
                    </p>

                    <p>
                        Tiempo:
                        <?= e(
                            TiempoActividad::describir(
                                $actividad['tiempo_limite'],
                                $actividad['modo_tiempo'] ?? null,
                                (int) $actividad['preguntas_registradas'],
                                $actividad['preguntas_por_bloque'] ?? null
                            )
                        ) ?>
                    </p>

                    <?php
                    // Una actividad realizada no necesita alerta de vencimiento.
                    $estadoFecha = TiempoActividad::estadoFecha(
                        $actividad['fecha_limite'] ?? null
                    );

                    if (
                        (int) $actividad['realizada'] === 1
                        && in_array(
                            $estadoFecha,
                            [
                                TiempoActividad::PRONTO,
                                TiempoActividad::VENCIDA
                            ],
                            true
                        )
                    ) {
                        $estadoFecha = TiempoActividad::VIGENTE;
                    }

                    $avisoFecha = TiempoActividad::avisoFecha($estadoFecha);
                    ?>

                    <p class="fecha-limite fecha-limite--<?= e($estadoFecha) ?>">

                        <?= e(
                            TiempoActividad::describirFecha(
                                $actividad['fecha_limite'] ?? null
                            )
                        ) ?>

                        <?php if ($avisoFecha !== ''): ?>
                            <strong class="fecha-limite-aviso">
                                <?= e($avisoFecha) ?>
                            </strong>
                        <?php endif; ?>

                    </p>

                    <a
                        class="btn-resolver"
                        href="actividad.php?id=<?= (int) $actividad['id_actividad'] ?>"
                    >
                        <?= (int) $actividad['realizada'] === 1
                            ? 'Ver estado'
                            : 'Resolver actividad' ?>
                    </a>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
