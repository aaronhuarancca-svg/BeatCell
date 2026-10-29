
<?php
require_once __DIR__ . '/../../src/Support/ViewHelpers.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';

$sesionUsuario = AuthMiddleware::requireRole(
    ['Estudiante'],
    '../auth/login.php'
);

require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/../../src/Controllers/ResultadoController.php';
require_once __DIR__ . '/../../src/Models/TiempoActividad.php';

$db = (new Database())->connect();

$idUsuario = (int) $sesionUsuario['id_usuario'];
$idActividad = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

$error = '';
$resultado = null;
$yaRespondida = false;
$tiempoAgotado = false;

if ($idActividad === false || $idActividad === null || $idActividad < 1) {
    header('Location: actividades.php');
    exit;
}

$idActividad = (int) $idActividad;

$stmt = $db->prepare(
    "SELECT *
     FROM actividades
     WHERE id_actividad = ?
       AND estado = 'Activo'"
);
$stmt->execute([$idActividad]);

$actividad = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$actividad) {
    http_response_code(404);
    exit('La actividad no existe o no está disponible.');
}

$stmt = $db->prepare(
    'SELECT 1
     FROM resultados
     WHERE id_usuario = ?
       AND id_actividad = ?
     LIMIT 1'
);
$stmt->execute([$idUsuario, $idActividad]);

$yaRespondida = (bool) $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT *
     FROM preguntas
     WHERE id_actividad = ?
       AND estado = 'Activo'
     ORDER BY id_pregunta ASC"
);
$stmt->execute([$idActividad]);

$preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($preguntas as &$pregunta) {
    $stmtOpciones = $db->prepare(
        'SELECT id_opcion, texto, imagen
         FROM opciones_respuesta
         WHERE id_pregunta = ?
         ORDER BY id_opcion ASC'
    );

    $stmtOpciones->execute([
        (int) $pregunta['id_pregunta']
    ]);

    $pregunta['opciones'] = $stmtOpciones->fetchAll(
        PDO::FETCH_ASSOC
    );
}

unset($pregunta);

/*
|--------------------------------------------------------------------------
| Configuración del tiempo
|--------------------------------------------------------------------------
*/

$modoTiempo = TiempoActividad::normalizarModo(
    $actividad['modo_tiempo'] ?? null,
    $actividad['tiempo_limite'] ?? null
);

$segundosConfigurados = max(
    0,
    (int) ($actividad['tiempo_limite'] ?? 0)
);

$porPregunta = $modoTiempo === TiempoActividad::POR_PREGUNTA;
$porBloque = $modoTiempo === 'por_bloque';

$preguntasPorBloque = max(
    1,
    (int) ($actividad['preguntas_por_bloque'] ?? 4)
);

$cantidadPreguntas = count($preguntas);

$cantidadBloques = $porBloque && $cantidadPreguntas > 0
    ? (int) ceil($cantidadPreguntas / $preguntasPorBloque)
    : 0;

if ($porBloque) {
    // El tiempo límite de cada bloque está expresado en segundos.
    $tiempoLimite = $segundosConfigurados * $cantidadBloques;
} else {
    $tiempoLimite = TiempoActividad::segundosTotales(
        $segundosConfigurados,
        $modoTiempo,
        $cantidadPreguntas
    );
}

$holguraPorPregunta = $porPregunta
    ? 10 + $cantidadPreguntas
    : 0;

$claveTemporizador = $idUsuario . ':' . $idActividad;

if (
    !isset($_SESSION['temporizadores_actividad'])
    || !is_array($_SESSION['temporizadores_actividad'])
) {
    $_SESSION['temporizadores_actividad'] = [];
}

/*
|--------------------------------------------------------------------------
| Inicializar el temporizador sin reiniciarlo al actualizar la página
|--------------------------------------------------------------------------
*/

if (
    !$yaRespondida
    && $preguntas
    && $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    if (
        !isset(
            $_SESSION['temporizadores_actividad'][$claveTemporizador]
        )
        || !is_array(
            $_SESSION['temporizadores_actividad'][$claveTemporizador]
        )
    ) {
        $ahora = time();

        $_SESSION['temporizadores_actividad'][$claveTemporizador] = [
            'inicio' => $ahora,
            'token' => bin2hex(random_bytes(32)),
            'bloque_actual' => 0,
            'inicio_bloque' => $ahora,
            'respuestas_guardadas' => [],
        ];
    } elseif ($porBloque) {
        // Compatibilidad con sesiones iniciadas antes de habilitar los bloques.
        $_SESSION['temporizadores_actividad'][$claveTemporizador]
            ['bloque_actual'] ??= 0;

        $_SESSION['temporizadores_actividad'][$claveTemporizador]
            ['inicio_bloque'] ??= time();

        $_SESSION['temporizadores_actividad'][$claveTemporizador]
            ['respuestas_guardadas'] ??= [];
    }
}

$temporizador =
    $_SESSION['temporizadores_actividad'][$claveTemporizador] ?? null;

if (!is_array($temporizador)) {
    $temporizador = null;
}

/*
|--------------------------------------------------------------------------
| Función para validar y evaluar las respuestas recibidas
|--------------------------------------------------------------------------
*/

$calcularRespuestas = static function (
    array $preguntasEvaluar,
    $enviadas,
    bool $permiteSinResponder
) use ($db): array {

    if (!is_array($enviadas)) {
        return [
            'error' => 'Las respuestas enviadas no tienen un formato válido.',
            'respuestas' => [],
        ];
    }

    $idsPermitidos = [];

    foreach ($preguntasEvaluar as $pregunta) {
        $idsPermitidos[(int) $pregunta['id_pregunta']] = true;
    }

    // Rechazar respuestas de preguntas ajenas al bloque activo.
    foreach ($enviadas as $idEnviado => $valorEnviado) {
        $idValidado = filter_var(
            (string) $idEnviado,
            FILTER_VALIDATE_INT
        );

        if (
            $idValidado === false
            || !isset($idsPermitidos[(int) $idValidado])
        ) {
            return [
                'error' => 'Se recibió una respuesta que no pertenece a las preguntas permitidas.',
                'respuestas' => [],
            ];
        }
    }

    $respuestasCalculadas = [];

    foreach ($preguntasEvaluar as $pregunta) {
        $idPregunta = (int) $pregunta['id_pregunta'];
        $puntos = max(0, (int) ($pregunta['puntos'] ?? 0));

        $correcta = false;
        $idOpcionRespuesta = null;
        $respuestaTexto = null;
        $opcionesSeleccionadas = null;

        $tipoEvaluacion = ($pregunta['tipo'] ?? '') === 'video'
            ? ($pregunta['tipo_respuesta'] ?? 'opcion_multiple')
            : ($pregunta['tipo'] ?? '');

        /*
         * Preguntas para completar.
         */
        if ($tipoEvaluacion === 'completar') {
            $entrada = $enviadas[$idPregunta] ?? null;

            if ($entrada === null || $entrada === '') {
                if (!$permiteSinResponder) {
                    return [
                        'error' => 'Responde todas las preguntas antes de continuar.',
                        'respuestas' => [],
                    ];
                }
            } elseif (!is_string($entrada)) {
                return [
                    'error' => 'Una respuesta de texto tiene un formato inválido.',
                    'respuestas' => [],
                ];
            } else {
                $respuestaTexto = trim($entrada);

                if ($respuestaTexto === '') {
                    if (!$permiteSinResponder) {
                        return [
                            'error' => 'Responde todas las preguntas antes de continuar.',
                            'respuestas' => [],
                        ];
                    }
                } else {
                    $stmtCorrecta = $db->prepare(
                        'SELECT texto
                         FROM opciones_respuesta
                         WHERE id_pregunta = ?
                           AND correcta = 1
                         LIMIT 1'
                    );

                    $stmtCorrecta->execute([$idPregunta]);

                    $esperada = trim(
                        (string) $stmtCorrecta->fetchColumn()
                    );

                    $correcta = $esperada !== ''
                        && mb_strtolower(
                            $respuestaTexto,
                            'UTF-8'
                        ) === mb_strtolower(
                            $esperada,
                            'UTF-8'
                        );
                }
            }
        } elseif (
            $tipoEvaluacion === 'opcion_multiple'
            && (int) ($pregunta['seleccion_multiple'] ?? 0) === 1
        ) {
            /*
             * Preguntas con varias opciones seleccionables.
             */
            $entrada = $enviadas[$idPregunta] ?? [];

            if (!is_array($entrada)) {
                return [
                    'error' => 'El formato de las respuestas múltiples no es válido.',
                    'respuestas' => [],
                ];
            }

            $idsElegidos = [];

            foreach ($entrada as $valor) {
                $id = is_scalar($valor)
                    ? filter_var($valor, FILTER_VALIDATE_INT)
                    : false;

                if ($id === false || $id < 1) {
                    return [
                        'error' => 'Una respuesta múltiple contiene una opción inválida.',
                        'respuestas' => [],
                    ];
                }

                $idsElegidos[] = (int) $id;
            }

            $idsElegidos = array_values(
                array_unique($idsElegidos)
            );

            sort($idsElegidos, SORT_NUMERIC);

            if (!$idsElegidos && !$permiteSinResponder) {
                return [
                    'error' => 'Responde todas las preguntas antes de continuar.',
                    'respuestas' => [],
                ];
            }

            $stmtCorrectas = $db->prepare(
                'SELECT id_opcion, correcta
                 FROM opciones_respuesta
                 WHERE id_pregunta = ?'
            );

            $stmtCorrectas->execute([$idPregunta]);

            $filas = $stmtCorrectas->fetchAll(PDO::FETCH_ASSOC);

            $idsValidos = array_map(
                static fn($fila) => (int) $fila['id_opcion'],
                $filas
            );

            if (array_diff($idsElegidos, $idsValidos)) {
                return [
                    'error' => 'Una de las opciones enviadas no pertenece a esta pregunta.',
                    'respuestas' => [],
                ];
            }

            $idsCorrectos = [];

            foreach ($filas as $fila) {
                if ((int) $fila['correcta'] === 1) {
                    $idsCorrectos[] = (int) $fila['id_opcion'];
                }
            }

            sort($idsCorrectos, SORT_NUMERIC);

            $correcta = $idsElegidos !== []
                && $idsElegidos === $idsCorrectos;

            $opcionesSeleccionadas = $idsElegidos;
        } else {
            /*
             * Preguntas de opción única, verdadero/falso e imagen.
             */
            $entrada = $enviadas[$idPregunta] ?? null;

            if ($entrada === null || $entrada === '') {
                if (!$permiteSinResponder) {
                    return [
                        'error' => 'Responde todas las preguntas antes de continuar.',
                        'respuestas' => [],
                    ];
                }
            } else {
                $idOpcion = is_scalar($entrada)
                    ? filter_var($entrada, FILTER_VALIDATE_INT)
                    : false;

                if ($idOpcion === false || $idOpcion < 1) {
                    return [
                        'error' => 'Una respuesta contiene una opción inválida.',
                        'respuestas' => [],
                    ];
                }

                $stmtCorrecta = $db->prepare(
                    'SELECT correcta
                     FROM opciones_respuesta
                     WHERE id_opcion = ?
                       AND id_pregunta = ?'
                );

                $stmtCorrecta->execute([
                    $idOpcion,
                    $idPregunta,
                ]);

                $valorCorrecta = $stmtCorrecta->fetchColumn();

                if ($valorCorrecta === false) {
                    return [
                        'error' => 'Una de las opciones enviadas no pertenece a esta pregunta.',
                        'respuestas' => [],
                    ];
                }

                $idOpcionRespuesta = (int) $idOpcion;
                $correcta = (bool) $valorCorrecta;
            }
        }

        $respuestasCalculadas[] = [
            'id_pregunta' => $idPregunta,
            'id_opcion' => $idOpcionRespuesta,
            'respuesta_texto' => $respuestaTexto,
            'opciones_seleccionadas' => $opcionesSeleccionadas,
            'correcta' => $correcta,
            'puntos' => $puntos,
            'puntos_obtenidos' => $correcta ? $puntos : 0,
        ];
    }

    return [
        'error' => '',
        'respuestas' => $respuestasCalculadas,
    ];
};

/*
|--------------------------------------------------------------------------
| Crear una respuesta vacía para las preguntas no contestadas
|--------------------------------------------------------------------------
*/

$crearRespuestaVacia = static function (
    array $pregunta
): array {
    return [
        'id_pregunta' => (int) $pregunta['id_pregunta'],
        'id_opcion' => null,
        'respuesta_texto' => null,
        'opciones_seleccionadas' => null,
        'correcta' => false,
        'puntos' => max(0, (int) ($pregunta['puntos'] ?? 0)),
        'puntos_obtenidos' => 0,
    ];
};

/*
|--------------------------------------------------------------------------
| Registrar el resultado final de la actividad
|--------------------------------------------------------------------------
*/

$registrarResultado = static function (
    array $respuestasCalculadas
) use ($db, $idUsuario, $idActividad, $actividad, $preguntas): array {

    $puntajeTotal = 0;

    foreach ($preguntas as $pregunta) {
        $puntajeTotal += max(
            0,
            (int) ($pregunta['puntos'] ?? 0)
        );
    }

    try {
        $db->beginTransaction();

        $controller = new ResultadoController(null, null, $db);

        $idResultado = $controller->registrarResultado(
            $idUsuario,
            $idActividad,
            $respuestasCalculadas,
            [
                'puntaje_total' => $puntajeTotal,
                'total_preguntas' => count($preguntas),
                'umbral_aprobacion' => (float) (
                    $actividad['puntaje_minimo'] ?? 70
                ),
            ]
        );

        $db->commit();
    } catch (Throwable $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $ex;
    }

    $stmt = $db->prepare(
        'SELECT puntaje_obtenido, puntaje_total, porcentaje, estado
         FROM resultados
         WHERE id_resultado = ?
           AND id_usuario = ?'
    );

    $stmt->execute([$idResultado, $idUsuario]);

    $filaResultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$filaResultado) {
        throw new RuntimeException(
            'No se pudo recuperar el resultado registrado.'
        );
    }

    return $filaResultado;
};

/*
|--------------------------------------------------------------------------
| Procesar el envío del estudiante
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$yaRespondida) {
    $tokenEnviado = $_POST['token'] ?? '';

    $tokenValido = $temporizador !== null
        && is_string($tokenEnviado)
        && isset($temporizador['token'])
        && hash_equals($temporizador['token'], $tokenEnviado);

    if (!$tokenValido) {
        $error = 'La sesión de la actividad venció. Vuelve a abrirla para continuar.';
    } elseif (!$preguntas) {
        $error = 'Esta actividad todavía no tiene preguntas activas.';
    } elseif ($porBloque) {
        /*
         * Procesamiento exclusivo del modo por bloques.
         */
        $bloqueActual = (int) (
            $temporizador['bloque_actual'] ?? 0
        );

        $bloqueEnviado = filter_var(
            $_POST['bloque'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $bloqueActual < 0
            || $bloqueActual >= $cantidadBloques
            || $bloqueEnviado === false
            || $bloqueEnviado !== $bloqueActual
        ) {
            $error = 'El bloque enviado no coincide con el bloque activo. Actualiza la página para continuar.';
        } elseif ($segundosConfigurados <= 0) {
            $error = 'El tiempo configurado para los bloques no es válido.';
        } else {
            $inicioBloque = (int) (
                $temporizador['inicio_bloque'] ?? time()
            );

            $transcurridoBloque = max(
                0,
                time() - $inicioBloque
            );

            $accionBloque = $_POST['accion_bloque'] ?? 'continuar';

            if (!is_string($accionBloque)) {
                $accionBloque = '';
            }

            $bloqueExpirado = $transcurridoBloque
                >= $segundosConfigurados;

            // Un segundo de tolerancia por diferencias de reloj.
            if (
                $accionBloque === 'expirado'
                && $transcurridoBloque + 1 < $segundosConfigurados
            ) {
                $error = 'El tiempo del bloque todavía no ha terminado.';
            } elseif (
                !in_array(
                    $accionBloque,
                    ['continuar', 'finalizar', 'expirado'],
                    true
                )
            ) {
                $error = 'La acción del bloque no es válida.';
            } else {
                $esUltimoBloque = $bloqueActual
                    >= $cantidadBloques - 1;

                if (
                    !$bloqueExpirado
                    && $accionBloque === 'finalizar'
                    && !$esUltimoBloque
                ) {
                    $error = 'Debes completar los bloques en orden.';
                } elseif (
                    !$bloqueExpirado
                    && $accionBloque === 'continuar'
                    && $esUltimoBloque
                ) {
                    $error = 'Este es el último bloque. Debes finalizar la evaluación.';
                } else {
                    $preguntasBloque = array_slice(
                        $preguntas,
                        $bloqueActual * $preguntasPorBloque,
                        $preguntasPorBloque
                    );

                    /*
                     * Se acepta el envío de respuestas durante un pequeño
                     * margen de latencia. Fuera de ese margen, el bloque
                     * se registra con las preguntas sin responder en blanco.
                     */
                    $aceptarRespuestasEnviadas = !$bloqueExpirado
                        || $transcurridoBloque
                            <= $segundosConfigurados + 10;

                    $enviadas = $aceptarRespuestasEnviadas
                        ? ($_POST['respuestas'] ?? [])
                        : [];

                    $validacion = $calcularRespuestas(
                        $preguntasBloque,
                        $enviadas,
                        $bloqueExpirado
                    );

                    if ($validacion['error'] !== '') {
                        $error = $validacion['error'];
                    } else {
                        /*
                         * Guardar las respuestas del bloque en la sesión
                         * del servidor. Los bloques anteriores no se vuelven
                         * a aceptar ni se pueden modificar.
                         */
                        $guardadas = $temporizador[
                            'respuestas_guardadas'
                        ] ?? [];

                        foreach (
                            $validacion['respuestas'] as $respuesta
                        ) {
                            $guardadas[
                                (int) $respuesta['id_pregunta']
                            ] = $respuesta;
                        }

                        $temporizador['respuestas_guardadas'] = $guardadas;

                        if (!$esUltimoBloque) {
                            $temporizador['bloque_actual'] =
                                $bloqueActual + 1;

                            $temporizador['inicio_bloque'] = time();

                            $_SESSION['temporizadores_actividad']
                                [$claveTemporizador] = $temporizador;

                            header(
                                'Location: actividad.php?id='
                                . $idActividad
                            );
                            exit;
                        }

                        /*
                         * Último bloque: combinar todas las respuestas
                         * y registrar la evaluación completa.
                         */
                        $respuestasGuardadas = $temporizador[
                            'respuestas_guardadas'
                        ];

                        $respuestasFinales = [];

                        foreach ($preguntas as $preguntaFinal) {
                            $idPreguntaFinal = (int) (
                                $preguntaFinal['id_pregunta']
                            );

                            if (
                                isset(
                                    $respuestasGuardadas[
                                        $idPreguntaFinal
                                    ]
                                )
                            ) {
                                $respuestasFinales[] =
                                    $respuestasGuardadas[
                                        $idPreguntaFinal
                                    ];
                            } else {
                                $respuestasFinales[] =
                                    $crearRespuestaVacia(
                                        $preguntaFinal
                                    );
                            }
                        }

                        try {
                            $resultado = $registrarResultado(
                                $respuestasFinales
                            );

                            unset(
                                $_SESSION['temporizadores_actividad']
                                    [$claveTemporizador]
                            );

                            $yaRespondida = true;

                            if ($bloqueExpirado) {
                                $error = 'Se agotó el tiempo del último bloque. Se registraron las respuestas disponibles.';
                            }
                        } catch (DomainException $ex) {
                            unset(
                                $_SESSION['temporizadores_actividad']
                                    [$claveTemporizador]
                            );

                            $yaRespondida = true;
                            $error = $ex->getMessage();
                        } catch (Throwable $ex) {
                            error_log(
                                'BeatCell - error al guardar respuestas '
                                . 'de actividad por bloques: '
                                . $ex->getMessage()
                            );

                            $error = 'No se pudo guardar la evaluación. Verifica los datos e inténtalo de nuevo.';
                        }
                    }
                }
            }
        }
    } else {
        /*
         * Procesamiento de los modos anteriores:
         * tiempo total, por pregunta y sin límite.
         */
        $tiempoTranscurrido = time()
            - (int) $temporizador['inicio'];

        $tiempoAgotado = $tiempoLimite > 0
            && $tiempoTranscurrido
                >= $tiempoLimite + $holguraPorPregunta;

        $dentroDelMargenEnvio = $tiempoLimite > 0
            && $tiempoTranscurrido
                <= $tiempoLimite + $holguraPorPregunta + 2;

        $aceptarRespuestasEnviadas = !$tiempoAgotado
            || $dentroDelMargenEnvio;

        $permiteSinResponder = $porPregunta || $tiempoAgotado;

        $enviadas = $aceptarRespuestasEnviadas
            ? ($_POST['respuestas'] ?? [])
            : [];

        $validacion = $calcularRespuestas(
            $preguntas,
            $enviadas,
            $permiteSinResponder
        );

        if ($validacion['error'] !== '') {
            $error = $validacion['error'];
        } else {
            try {
                $resultado = $registrarResultado(
                    $validacion['respuestas']
                );

                unset(
                    $_SESSION['temporizadores_actividad']
                        [$claveTemporizador]
                );

                $yaRespondida = true;

                if ($tiempoAgotado) {
                    $error = $aceptarRespuestasEnviadas
                        ? 'Se agotó el tiempo; se registraron las respuestas enviadas.'
                        : 'Se agotó el tiempo antes de recibir las respuestas; el intento se registró sin ellas.';
                }
            } catch (DomainException $ex) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                unset(
                    $_SESSION['temporizadores_actividad']
                        [$claveTemporizador]
                );

                $yaRespondida = true;
                $error = $ex->getMessage();
            } catch (Throwable $ex) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log(
                    'BeatCell - error al guardar respuestas de actividad: '
                    . $ex->getMessage()
                );

                $error = 'No se pudo guardar la evaluación. Verifica los datos e inténtalo de nuevo.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Preparar los datos para mostrar la actividad
|--------------------------------------------------------------------------
*/

$tiempoRestante = $temporizador !== null && $tiempoLimite > 0
    ? max(
        0,
        $tiempoLimite
        - (time() - (int) $temporizador['inicio'])
    )
    : 0;

$bloqueActualVista = $porBloque
    ? (int) ($temporizador['bloque_actual'] ?? 0)
    : 0;

$inicioPreguntaVista = $porBloque
    ? $bloqueActualVista * $preguntasPorBloque
    : 0;

$preguntasVista = $porBloque
    ? array_slice(
        $preguntas,
        $inicioPreguntaVista,
        $preguntasPorBloque
    )
    : $preguntas;

$tiempoRestanteBloque = 0;

if ($porBloque && $temporizador !== null) {
    $inicioBloqueVista = (int) (
        $temporizador['inicio_bloque'] ?? time()
    );

    $tiempoRestanteBloque = max(
        0,
        $segundosConfigurados
        - (time() - $inicioBloqueVista)
    );
}

$actividadEnCurso = !$yaRespondida
    && $preguntas !== []
    && $temporizador !== null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= e($actividad['titulo']) ?> | BeatCell</title>

    <link
        rel="stylesheet"
        href="../../public/assets/css/actividades.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/actividades.css') ?>"
    >

    <link
        rel="stylesheet"
        href="../../public/assets/css/estudiante.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/estudiante.css') ?>"
    >

    <?php if ($porPregunta): ?>
        <noscript>
            <style>
                .oculta {
                    display: block !important;
                }
            </style>
        </noscript>
    <?php endif; ?>
</head>

<body>

<?php require __DIR__ . '/_navegacion.php'; ?>

<main>

    <h1><?= e($actividad['titulo']) ?></h1>

    <?php if (!empty($actividad['descripcion'])): ?>
        <p class="descripcion-actividad">
            <?= e($actividad['descripcion']) ?>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p role="alert" class="mensaje-error">
            <?= e($error) ?>
        </p>
    <?php endif; ?>

    <?php if ($resultado): ?>

        <section class="tarjeta-resultado">
            <h2>Resultado guardado</h2>

            <p>
                <strong>Puntaje:</strong>
                <?= (int) $resultado['puntaje_obtenido'] ?>
                de
                <?= (int) $resultado['puntaje_total'] ?>
            </p>

            <p>
                <strong>Porcentaje:</strong>
                <?= e($resultado['porcentaje']) ?>%
            </p>

            <p>
                <strong>Estado:</strong>
                <?= e($resultado['estado']) ?>
            </p>
        </section>

    <?php elseif ($yaRespondida): ?>

        <p class="mensaje-info">
            Ya realizaste el intento permitido para esta actividad.
        </p>

    <?php elseif (!$preguntas): ?>

        <p class="mensaje-info">
            Esta actividad todavía no tiene preguntas activas.
        </p>

    <?php elseif (!$temporizador): ?>

        <p role="alert" class="mensaje-error">
            La sesión de la actividad venció.
            <a href="actividad.php?id=<?= $idActividad ?>">
                Vuelve a abrirla
            </a>.
        </p>

    <?php else: ?>

        <?php if ($porBloque): ?>


        <section class="tarjeta-pregunta">

            <div id="info-bloque">
                <h2>
                    Bloque <?= $bloqueActualVista + 1 ?>
                    de <?= $cantidadBloques ?>
                </h2>

                <p>
                    Preguntas
                    <?= $inicioPreguntaVista + 1 ?>
                    a
                    <?= min(
                        $inicioPreguntaVista + count($preguntasVista),
                        $cantidadPreguntas
                    ) ?>
                    de <?= $cantidadPreguntas ?>.
                </p>

                <p class="mensaje-info">
                    Tienes
                    <?= e(
                        TiempoActividad::formatearDuracion(
                            $segundosConfigurados
                        )
                    ) ?>
                    para resolver este bloque. Al agotarse el tiempo,
                    se guardarán las respuestas disponibles y avanzarás
                    al siguiente bloque. No podrás volver a los bloques
                    cerrados.
                </p>
            </div>

            <button
                type="button"
                id="btn-toggle-info-bloque"
                class="btn-toggle-info-bloque"
                aria-controls="info-bloque"
                aria-expanded="true"
            >
                Ocultar información del bloque
            </button>

            <p
                id="contador-bloque"
                class="contador-bloque-grande"
                role="timer"
                aria-live="polite"
                data-segundos-restantes="<?= $tiempoRestanteBloque ?>"
            ></p>

        </section>


        <?php elseif ($tiempoLimite > 0 && !$porPregunta): ?>

            <p
                id="contador-tiempo"
                role="timer"
                aria-live="polite"
                data-segundos-restantes="<?= $tiempoRestante ?>"
            ></p>

        <?php endif; ?>

        <form
            id="form-actividad"
            method="post"
            action=""
            <?php if ($porPregunta): ?>
                data-segundos-pregunta="<?= $segundosConfigurados ?>"
                data-segundos-totales-restantes="<?= $tiempoRestante + $holguraPorPregunta ?>"
            <?php endif; ?>
        >

            <input
                type="hidden"
                name="token"
                value="<?= e($temporizador['token']) ?>"
            >

            <?php if ($porBloque): ?>

                <input
                    type="hidden"
                    name="bloque"
                    value="<?= $bloqueActualVista ?>"
                >

                <input
                    type="hidden"
                    id="accion-bloque"
                    name="accion_bloque"
                    value="<?= $bloqueActualVista >= $cantidadBloques - 1 ? 'finalizar' : 'continuar' ?>"
                >

            <?php endif; ?>

            <?php if ($porPregunta): ?>

                <p class="mensaje-info">
                    Cada pregunta tiene
                    <?= e(
                        TiempoActividad::formatearDuracion(
                            $segundosConfigurados
                        )
                    ) ?>
                    para responderse. Al terminar su tiempo pasarás
                    automáticamente a la siguiente.
                </p>

                <div
                    id="barra-pregunta"
                    class="barra-pregunta"
                    role="timer"
                >
                    <div class="barra-pregunta-datos">
                        <span id="barra-pregunta-numero">
                            Pregunta 1 de <?= count($preguntas) ?>
                        </span>

                        <span id="barra-pregunta-reloj">
                            Tiempo:
                            <?= e(
                                TiempoActividad::formatearDuracion(
                                    $segundosConfigurados
                                )
                            ) ?>
                        </span>
                    </div>

                    <div class="barra-pregunta-pista">
                        <div
                            id="barra-pregunta-progreso"
                            class="barra-pregunta-progreso"
                        ></div>
                    </div>
                </div>

            <?php endif; ?>

            <?php foreach ($preguntasVista as $i => $pregunta): ?>

                <?php
                $numeroPregunta = $inicioPreguntaVista + $i + 1;
                ?>

                <article
                    class="tarjeta-pregunta<?= ($porPregunta && $i > 0) ? ' oculta' : '' ?>"
                >

                    <header class="encabezado-pregunta">
                        <span class="numero-pregunta">
                            Pregunta <?= $numeroPregunta ?>
                        </span>

                        <span class="puntos-pregunta">
                            <?= (int) $pregunta['puntos'] ?> pts.
                        </span>
                    </header>

                    <h2 class="texto-pregunta">
                        <?= e($pregunta['pregunta']) ?>
                    </h2>

                    <?php if (!empty($pregunta['audio'])): ?>

                        <audio
                            class="audio-pregunta"
                            controls
                            preload="none"
                            playsinline
                        >
                            <source
                                src="../../public/assets/audio/preguntas/<?= e(basename($pregunta['audio'])) ?>"
                            >
                            Tu navegador no soporta la reproducción de audio.
                        </audio>

                    <?php endif; ?>

                    <?php
                    $videoUrl = trim(
                        (string) ($pregunta['video_url'] ?? '')
                    );

                    $videoYoutube = null;
                    $videoMp4 = null;

                    if ($videoUrl !== '') {
                        $partesVideo = parse_url($videoUrl);

                        $hostVideo = strtolower(
                            (string) ($partesVideo['host'] ?? '')
                        );

                        $esHttps = ($partesVideo['scheme'] ?? '') === 'https';

                        if (
                            $esHttps
                            && in_array(
                                $hostVideo,
                                [
                                    'youtube.com',
                                    'www.youtube.com',
                                    'm.youtube.com',
                                ],
                                true
                            )
                        ) {
                            parse_str(
                                (string) ($partesVideo['query'] ?? ''),
                                $paramsVideo
                            );

                            $idVideo = (string) (
                                $paramsVideo['v'] ?? ''
                            );

                            if (
                                $idVideo === ''
                                && preg_match(
                                    '~^/(?:embed|shorts)/([a-zA-Z0-9_-]{11})~',
                                    (string) ($partesVideo['path'] ?? ''),
                                    $m
                                )
                            ) {
                                $idVideo = $m[1];
                            }

                            if (
                                preg_match(
                                    '/^[a-zA-Z0-9_-]{11}$/',
                                    $idVideo
                                )
                            ) {
                                $videoYoutube =
                                    'https://www.youtube-nocookie.com/embed/'
                                    . $idVideo;
                            }
                        } elseif (
                            $esHttps
                            && $hostVideo === 'youtu.be'
                        ) {
                            $idVideo = trim(
                                (string) ($partesVideo['path'] ?? ''),
                                '/'
                            );

                            if (
                                preg_match(
                                    '/^[a-zA-Z0-9_-]{11}$/',
                                    $idVideo
                                )
                            ) {
                                $videoYoutube =
                                    'https://www.youtube-nocookie.com/embed/'
                                    . $idVideo;
                            }
                        } elseif (
                            preg_match(
                                '~^(?:[a-zA-Z0-9_-]+\.mp4|(?:\.\./){0,3}public/assets/video/preguntas/[a-zA-Z0-9_-]+\.mp4|/?assets/video/preguntas/[a-zA-Z0-9_-]+\.mp4)$~i',
                                $videoUrl
                            )
                        ) {
                            $videoMp4 =
                                '../../public/assets/video/preguntas/'
                                . basename($videoUrl);
                        } elseif (
                            $esHttps
                            && preg_match(
                                '~\.mp4(?:$|\?)~i',
                                (string) ($partesVideo['path'] ?? '')
                            )
                        ) {
                            $videoMp4 = $videoUrl;
                        }
                    }
                    ?>

                    <?php if ($videoYoutube !== null): ?>

                        <div
                            class="contenedor-video-pregunta"
                            style="max-width:760px;margin:16px 0;"
                        >
                            <iframe
                                src="<?= e($videoYoutube) ?>"
                                title="Video de la pregunta"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen
                                referrerpolicy="strict-origin-when-cross-origin"
                                style="width:100%;aspect-ratio:16/9;border:0;border-radius:8px;"
                            ></iframe>
                        </div>

                    <?php elseif ($videoMp4 !== null): ?>

                        <div
                            class="contenedor-video-pregunta"
                            style="max-width:760px;margin:16px 0;"
                        >
                            <video
                                controls
                                preload="metadata"
                                playsinline
                                style="width:100%;border-radius:8px;"
                            >
                                <source
                                    src="<?= e($videoMp4) ?>"
                                    type="video/mp4"
                                >
                                Tu navegador no soporta videos MP4.
                            </video>
                        </div>

                    <?php endif; ?>

                    <?php if (!empty($pregunta['imagen'])): ?>

                        <div class="contenedor-imagen-pregunta">
                            <img
                                src="../../public/assets/img/preguntas/<?= e(basename($pregunta['imagen'])) ?>"
                                alt="Imagen de la pregunta"
                                class="imagen-pregunta"
                            >
                        </div>

                    <?php endif; ?>

                    <div
                        class="opciones-contenedor<?= (($pregunta['tipo'] ?? '') === 'imagen') ? ' opciones-imagenes' : '' ?>"
                    >

                        <?php
                        $tipoEvaluacion =
                            ($pregunta['tipo'] ?? '') === 'video'
                            ? ($pregunta['tipo_respuesta'] ?? 'opcion_multiple')
                            : ($pregunta['tipo'] ?? '');
                        ?>

                        <?php if ($tipoEvaluacion === 'completar'): ?>

                            <div class="campo-completar">
                                <label for="p_<?= (int) $pregunta['id_pregunta'] ?>">
                                    Tu respuesta:
                                </label>

                                <input
                                    type="text"
                                    id="p_<?= (int) $pregunta['id_pregunta'] ?>"
                                    name="respuestas[<?= (int) $pregunta['id_pregunta'] ?>]"
                                    placeholder="Escribe tu respuesta..."
                                    <?= ($porPregunta || $porBloque) ? '' : 'required' ?>
                                >
                            </div>

                        <?php else: ?>

                            <?php foreach ($pregunta['opciones'] as $opcion): ?>

                                <?php
                                $esMultiple =
                                    $tipoEvaluacion === 'opcion_multiple'
                                    && (int) (
                                        $pregunta['seleccion_multiple'] ?? 0
                                    ) === 1;
                                ?>

                                <label
                                    class="opcion-respuesta<?= (($pregunta['tipo'] ?? '') === 'imagen') ? ' opcion-respuesta-imagen' : '' ?>"
                                >

                                    <input
                                        type="<?= $esMultiple ? 'checkbox' : 'radio' ?>"
                                        name="respuestas[<?= (int) $pregunta['id_pregunta'] ?>]<?= $esMultiple ? '[]' : '' ?>"
                                        value="<?= (int) $opcion['id_opcion'] ?>"
                                        <?= ($porPregunta || $porBloque || $esMultiple) ? '' : 'required' ?>
                                    >

                                    <span class="texto-opcion">
                                        <?= e($opcion['texto'] ?? '') ?>
                                    </span>

                                    <?php if (!empty($opcion['imagen'])): ?>
                                        <img
                                            src="../../public/assets/img/preguntas/<?= e(basename($opcion['imagen'])) ?>"
                                            alt="Imagen de opción"
                                            class="imagen-opcion"
                                        >
                                    <?php endif; ?>

                                </label>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <?php if (
                        $porPregunta
                        && $i < count($preguntasVista) - 1
                    ): ?>

                        <button
                            type="button"
                            class="btn-siguiente"
                            data-accion="siguiente"
                        >
                            Siguiente pregunta →
                        </button>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

            <?php if ($porBloque): ?>

                <?php if ($bloqueActualVista < $cantidadBloques - 1): ?>

                    <button
                        type="submit"
                        id="btn-continuar-bloque"
                        class="btn-enviar"
                    >
                        Guardar bloque y continuar →
                    </button>

                <?php else: ?>

                    <button
                        type="submit"
                        id="btn-enviar"
                        class="btn-enviar"
                        onclick="return confirm('¿Enviar tus respuestas? Solo tienes un intento.')"
                    >
                        Enviar evaluación
                    </button>

                <?php endif; ?>

            <?php else: ?>

                <button
                    type="submit"
                    id="btn-enviar"
                    class="btn-enviar<?= $porPregunta ? ' oculta' : '' ?>"
                    onclick="return confirm('¿Enviar tus respuestas? Solo tienes un intento.')"
                >
                    Enviar evaluación
                </button>

            <?php endif; ?>

        </form>

        <?php if ($porBloque): ?>

            <script>
                (function () {
                    const formulario =
                        document.getElementById('form-actividad');

                    const contador =
                        document.getElementById('contador-bloque');

                    const accion =
                        document.getElementById('accion-bloque');

                    
                    const infoBloque = document.getElementById('info-bloque');

                    const botonInfo = document.getElementById(
                        'btn-toggle-info-bloque'
                    );

                    botonInfo.addEventListener('click', function () {
                        const estaVisible = !infoBloque.hidden;

                        infoBloque.hidden = estaVisible;

                        botonInfo.textContent = estaVisible
                            ? 'Mostrar información del bloque'
                            : 'Ocultar información del bloque';

                        botonInfo.setAttribute(
                            'aria-expanded',
                            String(!estaVisible)
                        );
                    });

                    const enviarAlExpirar =
                        <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'true' : 'false' ?>;

                    let restante = Number(
                        contador.dataset.segundosRestantes
                    );

                    let intervalo = null;

                    function pintarReloj() {
                        const minutos = Math.floor(restante / 60);

                        const segundos = String(
                            restante % 60
                        ).padStart(2, '0');

                        contador.textContent =
                            'Tiempo restante del bloque: '
                            + minutos
                            + ':'
                            + segundos;

                        contador.classList.toggle(
                            'urgente',
                            restante <= 10
                        );
                    }

                    function terminarBloque() {
                        window.clearInterval(intervalo);

                        if (enviarAlExpirar) {
                            accion.value = 'expirado';

                            // Envía el bloque sin depender de la validación
                            // del navegador; el servidor valida las respuestas.
                            formulario.submit();

                            return;
                        }

                        contador.textContent =
                            'Tiempo agotado. Pulsa el botón para registrar '
                            + 'las respuestas disponibles y continuar.';
                    }

                    if (restante <= 0) {
                        terminarBloque();
                    } else {
                        pintarReloj();

                        intervalo = window.setInterval(function () {
                            restante -= 1;

                            if (restante <= 0) {
                                restante = 0;
                                pintarReloj();
                                terminarBloque();
                                return;
                            }

                            pintarReloj();
                        }, 1000);
                    }
                })();
            </script>

        <?php endif; ?>

        <?php if ($porPregunta): ?>

            <script>
                (function () {
                    const formulario =
                        document.getElementById('form-actividad');

                    const tarjetas = Array.from(
                        formulario.querySelectorAll('.tarjeta-pregunta')
                    );

                    const barra =
                        document.getElementById('barra-pregunta');

                    const etiqueta =
                        document.getElementById('barra-pregunta-numero');

                    const reloj =
                        document.getElementById('barra-pregunta-reloj');

                    const progreso =
                        document.getElementById('barra-pregunta-progreso');

                    const botonEnviar =
                        document.getElementById('btn-enviar');

                    const segundosPorPregunta = Number(
                        formulario.dataset.segundosPregunta
                    );

                    const enviarAlExpirar =
                        <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'true' : 'false' ?>;

                    let totalRestante = Number(
                        formulario.dataset.segundosTotalesRestantes
                    );

                    let actual = 0;
                    let restante = segundosPorPregunta;
                    let intervalo = null;

                    function pintarReloj() {
                        const minutos = Math.floor(restante / 60);

                        const segundos = String(
                            restante % 60
                        ).padStart(2, '0');

                        reloj.textContent =
                            'Tiempo: ' + minutos + ':' + segundos;

                        progreso.style.width =
                            (restante / segundosPorPregunta * 100) + '%';

                        barra.classList.toggle(
                            'urgente',
                            restante <= 5
                        );
                    }

                    function mostrar(indice) {
                        actual = indice;
                        restante = segundosPorPregunta;

                        tarjetas.forEach(function (tarjeta, i) {
                            tarjeta.classList.toggle(
                                'oculta',
                                i !== indice
                            );
                        });

                        etiqueta.textContent =
                            'Pregunta '
                            + (indice + 1)
                            + ' de '
                            + tarjetas.length;

                        botonEnviar.classList.toggle(
                            'oculta',
                            indice !== tarjetas.length - 1
                        );

                        pintarReloj();

                        if (indice > 0) {
                            window.scrollTo(0, 0);
                        }
                    }

                    function terminar() {
                        window.clearInterval(intervalo);

                        if (enviarAlExpirar) {
                            formulario.submit();
                            return;
                        }

                        reloj.textContent =
                            'Tiempo agotado. Envía la actividad para registrar el resultado.';

                        botonEnviar.classList.remove('oculta');
                    }

                    function avanzar() {
                        if (actual < tarjetas.length - 1) {
                            mostrar(actual + 1);
                        } else {
                            terminar();
                        }
                    }

                    formulario.addEventListener('click', function (evento) {
                        if (
                            evento.target.closest(
                                '[data-accion="siguiente"]'
                            )
                        ) {
                            avanzar();
                        }
                    });

                    intervalo = window.setInterval(function () {
                        totalRestante -= 1;
                        restante -= 1;

                        if (totalRestante <= 0) {
                            terminar();
                            return;
                        }

                        if (restante <= 0) {
                            avanzar();
                            return;
                        }

                        pintarReloj();
                    }, 1000);

                    mostrar(0);
                })();
            </script>

        <?php endif; ?>

        <?php if ($tiempoLimite > 0 && !$porPregunta && !$porBloque): ?>

            <script>
                const contadorTiempo =
                    document.getElementById('contador-tiempo');

                const formularioActividad =
                    document.getElementById('form-actividad');

                const enviarAlExpirar =
                    <?= $_SERVER['REQUEST_METHOD'] === 'GET' ? 'true' : 'false' ?>;

                let segundosRestantes = Number(
                    contadorTiempo.dataset.segundosRestantes
                );

                function actualizarContador() {
                    const minutos = Math.floor(
                        segundosRestantes / 60
                    );

                    const segundos = String(
                        segundosRestantes % 60
                    ).padStart(2, '0');

                    contadorTiempo.textContent =
                        `Tiempo restante: ${minutos}:${segundos}`;
                }

                actualizarContador();

                if (segundosRestantes <= 0) {
                    if (enviarAlExpirar) {
                        formularioActividad.submit();
                    } else {
                        contadorTiempo.textContent =
                            'Tiempo agotado. Envía la actividad para registrar el resultado.';
                    }
                } else {
                    const intervalo = window.setInterval(function () {
                        segundosRestantes -= 1;

                        actualizarContador();

                        if (segundosRestantes <= 0) {
                            window.clearInterval(intervalo);

                            if (enviarAlExpirar) {
                                formularioActividad.submit();
                            } else {
                                contadorTiempo.textContent =
                                    'Tiempo agotado. Envía la actividad para registrar el resultado.';
                            }
                        }
                    }, 1000);
                }
            </script>

        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>
