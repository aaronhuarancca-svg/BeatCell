<?php
declare(strict_types=1); // Hace que PHP sea estricto con los tipos declarados.

require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/ValidadorDatos.php';
require_once __DIR__ . '/TiempoActividad.php';

class ActividadModel
{
    private PDO $conn;

    // Consulta base que devuelve los datos de cada actividad y cuenta sus preguntas.
    private const SELECT_BASE = '
        SELECT a.*,
               (SELECT COUNT(*)
                FROM preguntas p
                WHERE p.id_actividad = a.id_actividad) AS preguntas_registradas
        FROM actividades a';

    /**
     * Prepara el modelo para usar la base de datos.
     *
     * Si se pasa una conexión PDO, la reutiliza;
     * si no, crea una mediante Database.
     */
    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    /**
     * Obtiene todas las actividades, opcionalmente solo las activas.
     */
    public function obtenerTodos(bool $soloActivas = false): array
    {
        $sql = self::SELECT_BASE;

        if ($soloActivas) {
            $sql .= " WHERE a.estado = 'Activo'";
        }

        $sql .= ' ORDER BY a.id_tema, a.id_actividad';

        return $this->conn->query($sql)->fetchAll();
    }

    /**
     * Busca una actividad por su id.
     */
    public function obtenerPorId(int $idActividad): ?array
    {
        $sql = self::SELECT_BASE . ' WHERE a.id_actividad = :id_actividad';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(['id_actividad' => $idActividad]);

        $actividad = $stmt->fetch();

        return $actividad === false ? null : $actividad;
    }

    /**
     * Lista las actividades asociadas a un tema.
     */
    public function obtenerPorTema(int $idTema, bool $soloActivas = false): array
    {
        $sql = self::SELECT_BASE . ' WHERE a.id_tema = :id_tema';

        if ($soloActivas) {
            $sql .= " AND a.estado = 'Activo'";
        }

        $sql .= ' ORDER BY a.id_actividad';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(['id_tema' => $idTema]);

        return $stmt->fetchAll();
    }

    /**
     * Crea una actividad usando los datos recibidos.
     *
     * @param array $datos Datos del formulario.
     * @return int Id generado para la actividad nueva.
     */
    public function crear(array $datos): int
    {
        $idTema = $this->validarIdTema($datos['id_tema'] ?? null);

        $titulo = ValidadorDatos::texto(
            $datos['titulo'] ?? null,
            'titulo'
        );

        $descripcion = ValidadorDatos::texto(
            $datos['descripcion'] ?? null,
            'descripcion',
            true
        );

        $tipo = ValidadorDatos::texto(
            $datos['tipo'] ?? 'Cuestionario',
            'tipo'
        );

        // Obtiene el modo, los segundos y el tamaño del bloque.
        [$modoTiempo, $tiempoLimite, $preguntasPorBloque]
            = $this->resolverTiempo($datos);

        $puntajeMinimo = $this->validarPuntajeMinimo(
            $datos['puntaje_minimo'] ?? null
        );

        $fechaLimite = TiempoActividad::normalizarFechaLimite(
            $datos['fecha_limite'] ?? null
        );

        $estado = ValidadorDatos::estado(
            $datos['estado'] ?? 'Activo'
        );

        $sql = 'INSERT INTO actividades
                    (
                        id_tema,
                        titulo,
                        descripcion,
                        tipo,
                        modo_tiempo,
                        tiempo_limite,
                        preguntas_por_bloque,
                        puntaje_minimo,
                        fecha_limite,
                        estado
                    )
                VALUES
                    (
                        :id_tema,
                        :titulo,
                        :descripcion,
                        :tipo,
                        :modo_tiempo,
                        :tiempo_limite,
                        :preguntas_por_bloque,
                        :puntaje_minimo,
                        :fecha_limite,
                        :estado
                    )';

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            'id_tema' => $idTema,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'tipo' => $tipo,
            'modo_tiempo' => $modoTiempo,
            'tiempo_limite' => $tiempoLimite,
            'preguntas_por_bloque' => $preguntasPorBloque,
            'puntaje_minimo' => $puntajeMinimo,
            'fecha_limite' => $fechaLimite,
            'estado' => $estado,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Actualiza únicamente los campos permitidos que se incluyan en $datos.
     */
    public function actualizar(int $idActividad, array $datos): bool
    {
        if ($idActividad < 1) {
            throw new InvalidArgumentException(
                'El id de la actividad debe ser mayor que cero.'
            );
        }

        // Lista blanca de campos que se pueden actualizar.
        $permitidos = [
            'id_tema',
            'titulo',
            'descripcion',
            'tipo',
            'modo_tiempo',
            'tiempo_limite',
            'preguntas_por_bloque',
            'puntaje_minimo',
            'fecha_limite',
            'estado',
        ];

        $campos = array_intersect_key(
            $datos,
            array_flip($permitidos)
        );

        // Mantiene coherente el tamaño del bloque con el modo elegido.
        if (array_key_exists('modo_tiempo', $campos)) {
            if ($campos['modo_tiempo'] === TiempoActividad::POR_BLOQUE) {
                if (
                    !array_key_exists('preguntas_por_bloque', $campos)
                    || $this->validarPreguntasPorBloque(
                        $campos['preguntas_por_bloque']
                    ) === null
                ) {
                    throw new InvalidArgumentException(
                        'Debes indicar cuántas preguntas tendrá cada bloque.'
                    );
                }
            } else {
                // Los otros modos no necesitan un tamaño de bloque.
                $campos['preguntas_por_bloque'] = null;
            }
        }

        if ($campos === []) {
            throw new InvalidArgumentException(
                'No se enviaron campos válidos para actualizar.'
            );
        }

        $sets = [];
        $parametros = ['id_actividad' => $idActividad];

        foreach ($campos as $campo => $valor) {
            switch ($campo) {
                case 'id_tema':
                    $parametros[$campo] = $this->validarIdTema($valor);
                    break;

                case 'titulo':
                case 'tipo':
                    $parametros[$campo] = ValidadorDatos::texto(
                        $valor,
                        $campo
                    );
                    break;

                case 'descripcion':
                    $parametros[$campo] = ValidadorDatos::texto(
                        $valor,
                        $campo,
                        true
                    );
                    break;

                case 'tiempo_limite':
                    $parametros[$campo] = $this->validarEnteroOpcional(
                        $valor,
                        $campo
                    );
                    break;

                case 'preguntas_por_bloque':
                    $parametros[$campo] = $this->validarPreguntasPorBloque(
                        $valor
                    );
                    break;

                case 'modo_tiempo':
                    if (
                        !is_string($valor)
                        || !in_array($valor, TiempoActividad::MODOS, true)
                    ) {
                        throw new InvalidArgumentException(
                            'El modo de tiempo no es válido.'
                        );
                    }

                    $parametros[$campo] = $valor;
                    break;

                case 'puntaje_minimo':
                    $parametros[$campo] = $this->validarPuntajeMinimo(
                        $valor
                    );
                    break;

                case 'fecha_limite':
                    $parametros[$campo] = TiempoActividad::normalizarFechaLimite(
                        $valor
                    );
                    break;

                case 'estado':
                    $parametros[$campo] = ValidadorDatos::estado($valor);
                    break;

                default:
                    continue 2;
            }

            $sets[] = $campo . ' = :' . $campo;
        }

        if ($sets === []) {
            throw new InvalidArgumentException(
                'No se enviaron campos válidos para actualizar.'
            );
        }

        $sql = 'UPDATE actividades SET '
            . implode(', ', $sets)
            . ' WHERE id_actividad = :id_actividad';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cambia el estado de una actividad.
     */
    public function cambiarEstado(int $idActividad, string $estado): bool
    {
        return $this->actualizar(
            $idActividad,
            ['estado' => $estado]
        );
    }

    /**
     * Elimina una actividad.
     */
    public function eliminar(int $idActividad): bool
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM actividades WHERE id_actividad = :id_actividad'
        );

        $stmt->execute(['id_actividad' => $idActividad]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cuenta las preguntas registradas en una actividad.
     */
    public function contarPreguntas(int $idActividad): int
    {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM preguntas
             WHERE id_actividad = :id_actividad'
        );

        $stmt->execute(['id_actividad' => $idActividad]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Comprueba que el id_tema sea un entero positivo.
     */
    private function validarIdTema($valor): int
    {
        $idTema = filter_var($valor, FILTER_VALIDATE_INT);

        if ($idTema === false || $idTema < 1) {
            throw new InvalidArgumentException(
                'id_tema debe ser un entero mayor que cero.'
            );
        }

        return $idTema;
    }

    /**
     * Valida un entero opcional no negativo.
     */
    private function validarEnteroOpcional(
        $valor,
        string $campo
    ): ?int {
        if ($valor === null || $valor === '') {
            return null;
        }

        $numero = filter_var($valor, FILTER_VALIDATE_INT);

        if ($numero === false || $numero < 0) {
            throw new InvalidArgumentException(
                $campo . ' debe ser un entero igual o mayor que cero.'
            );
        }

        return $numero;
    }

    /**
     * Valida cuántas preguntas tendrá cada bloque.
     *
     * Permite null cuando no se usa el modo por bloques.
     */
    private function validarPreguntasPorBloque($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $cantidad = filter_var($valor, FILTER_VALIDATE_INT);

        if (
            $cantidad === false
            || $cantidad < TiempoActividad::MIN_PREGUNTAS_BLOQUE
            || $cantidad > TiempoActividad::MAX_PREGUNTAS_BLOQUE
        ) {
            throw new InvalidArgumentException(sprintf(
                'La cantidad de preguntas por bloque debe ser un entero entre %d y %d.',
                TiempoActividad::MIN_PREGUNTAS_BLOQUE,
                TiempoActividad::MAX_PREGUNTAS_BLOQUE
            ));
        }

        return $cantidad;
    }

    /**
     * Interpreta los campos de tiempo y devuelve:
     *
     * [modo_tiempo, tiempo_limite en segundos, preguntas_por_bloque]
     *
     * sin_limite: no usa límite de tiempo.
     * total: convierte tiempo_minutos a segundos.
     * por_pregunta: utiliza tiempo_por_pregunta en segundos.
     * por_bloque: convierte tiempo_por_bloque_minutos a segundos
     *             y valida preguntas_por_bloque.
     *
     * Si no llega modo_tiempo, mantiene la compatibilidad
     * con llamadas antiguas que envían tiempo_limite en segundos.
     */
    private function resolverTiempo(array $datos): array
    {
        $modo = $datos['modo_tiempo'] ?? null;

        // Compatibilidad con llamadas antiguas.
        if ($modo === null || $modo === '') {
            $segundos = $this->validarEnteroOpcional(
                $datos['tiempo_limite'] ?? null,
                'tiempo_limite'
            );

            return ($segundos === null || $segundos === 0)
                ? [TiempoActividad::SIN_LIMITE, null, null]
                : [TiempoActividad::TOTAL, $segundos, null];
        }

        if (
            !is_string($modo)
            || !in_array($modo, TiempoActividad::MODOS, true)
        ) {
            throw new InvalidArgumentException(
                'El modo de tiempo no es válido.'
            );
        }

        if ($modo === TiempoActividad::SIN_LIMITE) {
            return [TiempoActividad::SIN_LIMITE, null, null];
        }

        // Tiempo total para toda la actividad.
        if ($modo === TiempoActividad::TOTAL) {
            $minutos = filter_var(
                $datos['tiempo_minutos'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $minutos === false
                || $minutos < TiempoActividad::MIN_TOTAL_MINUTOS
                || $minutos > TiempoActividad::MAX_TOTAL_MINUTOS
            ) {
                throw new InvalidArgumentException(sprintf(
                    'La duración total debe ser un número entero de minutos entre %d y %d.',
                    TiempoActividad::MIN_TOTAL_MINUTOS,
                    TiempoActividad::MAX_TOTAL_MINUTOS
                ));
            }

            return [
                TiempoActividad::TOTAL,
                $minutos * 60,
                null
            ];
        }

        // Nuevo modo: tiempo independiente para cada bloque.
        if ($modo === TiempoActividad::POR_BLOQUE) {
            // Se admite tiempo_bloque_minutos como alias.
            $minutos = filter_var(
                $datos['tiempo_por_bloque_minutos']
                    ?? $datos['tiempo_bloque_minutos']
                    ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $minutos === false
                || $minutos < TiempoActividad::MIN_BLOQUE_MINUTOS
                || $minutos > TiempoActividad::MAX_BLOQUE_MINUTOS
            ) {
                throw new InvalidArgumentException(sprintf(
                    'La duración de cada bloque debe ser un número entero de minutos entre %d y %d.',
                    TiempoActividad::MIN_BLOQUE_MINUTOS,
                    TiempoActividad::MAX_BLOQUE_MINUTOS
                ));
            }

            $preguntasPorBloque = $this->validarPreguntasPorBloque(
                $datos['preguntas_por_bloque'] ?? null
            );

            if ($preguntasPorBloque === null) {
                throw new InvalidArgumentException(
                    'Debes indicar cuántas preguntas tendrá cada bloque.'
                );
            }

            return [
                TiempoActividad::POR_BLOQUE,
                $minutos * 60,
                $preguntasPorBloque
            ];
        }

        // Tiempo individual por pregunta.
        $segundos = filter_var(
            $datos['tiempo_por_pregunta'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $segundos === false
            || $segundos < TiempoActividad::MIN_PREGUNTA_SEGUNDOS
            || $segundos > TiempoActividad::MAX_PREGUNTA_SEGUNDOS
        ) {
            throw new InvalidArgumentException(sprintf(
                'El tiempo por pregunta debe ser un número entero de segundos entre %d y %d.',
                TiempoActividad::MIN_PREGUNTA_SEGUNDOS,
                TiempoActividad::MAX_PREGUNTA_SEGUNDOS
            ));
        }

        return [
            TiempoActividad::POR_PREGUNTA,
            $segundos,
            null
        ];
    }

    /**
     * Valida un número decimal opcional no negativo.
     */
    private function validarDecimalOpcional(
        $valor,
        string $campo
    ): ?float {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_numeric($valor) || (float) $valor < 0) {
            throw new InvalidArgumentException(
                $campo . ' debe ser un número igual o mayor que cero.'
            );
        }

        return (float) $valor;
    }

    /**
     * Valida el puntaje mínimo porcentual.
     */
    private function validarPuntajeMinimo($valor): ?float
    {
        $puntajeMinimo = $this->validarDecimalOpcional(
            $valor,
            'puntaje_minimo'
        );

        if ($puntajeMinimo !== null && $puntajeMinimo > 100) {
            throw new InvalidArgumentException(
                'El puntaje mínimo debe ser un porcentaje entre 0 y 100.'
            );
        }

        return $puntajeMinimo;
    }
}
