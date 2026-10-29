<?php

/**
 * Reglas y textos del tiempo de una actividad.
 *
 * tiempo_limite SIEMPRE se guarda en segundos; modo_tiempo indica cómo interpretarlo:
 *  - sin_limite:   no hay reloj (tiempo_limite = NULL).
 *  - total:        segundos para toda la actividad (en gestión se captura en minutos).
 *  - por_pregunta: segundos para cada pregunta.
 *  - por_bloque:   segundos para cada bloque de preguntas.
 *
 * Gestión y estudiante usan esta misma clase para validar y mostrar el tiempo,
 * así ambos ven siempre la misma información.
 */
final class TiempoActividad
{
    public const SIN_LIMITE = 'sin_limite';
    public const TOTAL = 'total';
    public const POR_PREGUNTA = 'por_pregunta';
    public const POR_BLOQUE = 'por_bloque';
    public const MODOS = [self::SIN_LIMITE, self::TOTAL, self::POR_PREGUNTA, self::POR_BLOQUE];

    public const MIN_TOTAL_MINUTOS = 1;
    public const MAX_TOTAL_MINUTOS = 600;
    public const MIN_PREGUNTA_SEGUNDOS = 5;
    public const MAX_PREGUNTA_SEGUNDOS = 600;
    public const MIN_BLOQUE_MINUTOS = 1;
    public const MAX_BLOQUE_MINUTOS = 600;
    public const MIN_PREGUNTAS_BLOQUE = 2;
    public const MAX_PREGUNTAS_BLOQUE = 50;

    /**
     * Devuelve un modo válido. Si la fila no trae modo_tiempo (por ejemplo, datos antiguos)
     * se deduce de tiempo_limite; un modo con reloj pero sin segundos equivale a "sin límite".
     */
    public static function normalizarModo($modo, $segundos): string
    {
        $segundos = (int) $segundos;
        if (is_string($modo) && in_array($modo, self::MODOS, true)) {
            return ($modo !== self::SIN_LIMITE && $segundos <= 0) ? self::SIN_LIMITE : $modo;
        }

        return $segundos > 0 ? self::TOTAL : self::SIN_LIMITE;
    }

    /** Convierte segundos a un texto corto: "20 s", "10 min" o "1 min 30 s". */
    public static function formatearDuracion($segundos): string
    {
        $segundos = max(0, (int) $segundos);
        if ($segundos < 60) {
            return $segundos . ' s';
        }

        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;

        return $resto === 0 ? $minutos . ' min' : $minutos . ' min ' . $resto . ' s';
    }

    /**
     * Texto para mostrar en listados según el modo de tiempo.
     * Ejemplos: "Sin límite", "10 min en total", "20 s por pregunta" o
     * "5 min por bloque de 4 preguntas (≈ 15 min en total)".
     */
    public static function describir(
        $segundos,
        $modo = null,
        ?int $numPreguntas = null,
        ?int $preguntasPorBloque = null
    ): string {
        $modo = self::normalizarModo($modo, $segundos);
        if ($modo === self::SIN_LIMITE) {
            return 'Sin límite';
        }
        if ($modo === self::TOTAL) {
            return self::formatearDuracion($segundos) . ' en total';
        }

        if ($modo === self::POR_BLOQUE) {
            $texto = self::formatearDuracion($segundos) . ' por bloque';
            if ($preguntasPorBloque !== null && $preguntasPorBloque > 0) {
                $texto .= ' de ' . $preguntasPorBloque . ' preguntas';
                if ($numPreguntas !== null && $numPreguntas > 0) {
                    $total = self::segundosTotales($segundos, self::POR_BLOQUE, $numPreguntas, $preguntasPorBloque);
                    $texto .= ' (≈ ' . self::formatearDuracion($total) . ' en total)';
                }
            }
            return $texto;
        }

        $texto = self::formatearDuracion($segundos) . ' por pregunta';
        if ($numPreguntas !== null && $numPreguntas > 0) {
            $texto .= ' (≈ ' . self::formatearDuracion((int) $segundos * $numPreguntas) . ' en total)';
        }

        return $texto;
    }

    /**
     * Segundos máximos para completar toda la actividad (0 = sin límite).
     * En modo por_bloque, cada bloque recibe el tiempo completo configurado.
     */
    public static function segundosTotales(
        $segundos,
        $modo,
        int $numPreguntas,
        ?int $preguntasPorBloque = null
    ): int {
        $segundos = max(0, (int) $segundos);
        $modo = self::normalizarModo($modo, $segundos);
        if ($modo === self::TOTAL) {
            return $segundos;
        }
        if ($modo === self::POR_PREGUNTA) {
            return $segundos * max(0, $numPreguntas);
        }
        if ($modo === self::POR_BLOQUE) {
            if ($numPreguntas <= 0 || $preguntasPorBloque === null || $preguntasPorBloque <= 0) {
                return 0;
            }
            $cantidadBloques = (int) ceil($numPreguntas / $preguntasPorBloque);
            return $segundos * $cantidadBloques;
        }

        return 0;
    }

    // ------------------------------------------------------------------
    // Fecha límite de la actividad (opcional). Se guarda como DATETIME sin zona
    // y se compara con la hora actual del servidor PHP.
    // ------------------------------------------------------------------
    public const SIN_FECHA = 'sin_fecha';
    public const VIGENTE = 'vigente';
    public const PRONTO = 'pronto';
    public const VENCIDA = 'vencida';
    public const DIAS_VENCE_PRONTO = 3;

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * Valida la fecha límite que llega del formulario (input datetime-local) o de la base
     * y la devuelve como "Y-m-d H:i:s". Vacío o null significa "sin fecha límite".
     */
    public static function normalizarFechaLimite($valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (!is_string($valor)) {
            throw new InvalidArgumentException('La fecha límite no es válida.');
        }
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $formato) {
            $fecha = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
            $errores = DateTimeImmutable::getLastErrors();
            $limpio = $errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0);
            if ($fecha instanceof DateTimeImmutable && $limpio) {
                $anio = (int) $fecha->format('Y');
                if ($anio < 2000 || $anio > 2100) {
                    throw new InvalidArgumentException('La fecha límite debe estar entre los años 2000 y 2100.');
                }
                return $fecha->format('Y-m-d H:i:s');
            }
        }

        throw new InvalidArgumentException('La fecha límite no es válida.');
    }

    /** Convierte el valor guardado en la base a objeto fecha, o null si no hay fecha. */
    private static function aFecha($valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '' || strpos($valor, '0000-00-00') === 0) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($valor));

        return $fecha instanceof DateTimeImmutable ? $fecha : null;
    }

    /** sin_fecha, vigente, pronto (vence en los próximos 3 días) o vencida. */
    public static function estadoFecha($valor, ?DateTimeInterface $ahora = null): string
    {
        $limite = self::aFecha($valor);
        if ($limite === null) {
            return self::SIN_FECHA;
        }
        $ahora = $ahora !== null ? DateTimeImmutable::createFromInterface($ahora) : new DateTimeImmutable('now');
        if ($limite < $ahora) {
            return self::VENCIDA;
        }

        return $limite <= $ahora->modify('+' . self::DIAS_VENCE_PRONTO . ' days') ? self::PRONTO : self::VIGENTE;
    }

    /** Fecha corta en español: "15 oct 2026, 23:59". */
    public static function formatearFecha($valor): string
    {
        $fecha = self::aFecha($valor);
        if ($fecha === null) {
            return '';
        }

        return $fecha->format('j') . ' ' . self::MESES[(int) $fecha->format('n') - 1] . ' ' . $fecha->format('Y, H:i');
    }

    /** Texto para listados: "Sin fecha límite", "Vence el 15 oct 2026, 23:59" o "Venció el ...". */
    public static function describirFecha($valor, ?DateTimeInterface $ahora = null): string
    {
        $estado = self::estadoFecha($valor, $ahora);
        if ($estado === self::SIN_FECHA) {
            return 'Sin fecha límite';
        }

        return ($estado === self::VENCIDA ? 'Venció el ' : 'Vence el ') . self::formatearFecha($valor);
    }

    /** Aviso corto para mostrar junto a la fecha: "Vence pronto", "Vencida" o vacío. */
    public static function avisoFecha(string $estado): string
    {
        if ($estado === self::PRONTO) {
            return 'Vence pronto';
        }

        return $estado === self::VENCIDA ? 'Vencida' : '';
    }
}