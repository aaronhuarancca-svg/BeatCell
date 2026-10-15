SELECT
    p.id_pregunta,
    p.pregunta,
    p.tipo,
    p.video_url,
    p.seleccion_multiple,
    p.puntos,
    o.texto AS alternativa,
    o.correcta
FROM preguntas p
LEFT JOIN opciones_respuesta o
    ON p.id_pregunta = o.id_pregunta
WHERE p.id_actividad = 6
ORDER BY p.id_pregunta, o.id_opcion;