
<?php

class UsuarioModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function obtenerTodos(): array
    {
        $sql = "SELECT
                    id_usuario,
                    codigo,
                    dni,
                    nombres,
                    apellidos,
                    correo,
                    rol,
                    estado,
                    fecha_registro
                FROM usuarios
                ORDER BY id_usuario DESC";

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE id_usuario = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function obtenerPorCorreo(string $correo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE correo = ? LIMIT 1'
        );
        $stmt->execute([trim($correo)]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function obtenerPorCodigo(string $codigo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE codigo = ? LIMIT 1'
        );
        $stmt->execute([$codigo]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function obtenerPorDni(string $dni): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE dni = ? LIMIT 1'
        );
        $stmt->execute([$dni]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function crear(
        string $codigo,
        ?string $dni,
        string $nombres,
        string $apellidos,
        string $correo,
        string $password,
        string $rol = 'Estudiante'
    ): bool {
        $sql = 'INSERT INTO usuarios
                (codigo, dni, nombres, apellidos, correo, password, rol)
                VALUES (?, ?, ?, ?, ?, ?, ?)';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            $codigo,
            $dni,
            $nombres,
            $apellidos,
            trim($correo),
            password_hash($password, PASSWORD_DEFAULT),
            $rol
        ]);
    }

    public function actualizar(
        int $id,
        string $codigo,
        ?string $dni,
        string $nombres,
        string $apellidos,
        string $correo,
        string $rol
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET codigo = ?, dni = ?, nombres = ?, apellidos = ?,
                 correo = ?, rol = ?
             WHERE id_usuario = ?'
        );

        return $stmt->execute([
            $codigo,
            $dni,
            $nombres,
            $apellidos,
            trim($correo),
            $rol,
            $id
        ]);
    }

    public function actualizarPassword(int $id, string $password): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET password = ? WHERE id_usuario = ?'
        );

        return $stmt->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $id
        ]);
    }

    public function cambiarEstado(int $id, string $estado): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET estado = ? WHERE id_usuario = ?'
        );

        return $stmt->execute([$estado, $id]);
    }

    public function verificarPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function guardarTokenRecuperacion(
        string $correo,
        string $token,
        string $expira
    ): bool {
        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET password_reset_token = ?, password_reset_expira = ?
             WHERE correo = ?'
        );

        return $stmt->execute([
            $tokenHash,
            $expira,
            trim($correo)
        ]);
    }

    public function obtenerPorToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios
             WHERE password_reset_token = ? LIMIT 1'
        );

        $stmt->execute([$tokenHash]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function cambiarPasswordPorToken(
        string $token,
        string $nuevaPassword
    ): bool {
        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET password = ?,
                 password_reset_token = NULL,
                 password_reset_expira = NULL
             WHERE password_reset_token = ?
               AND password_reset_expira >= CURRENT_TIMESTAMP'
        );

        return $stmt->execute([
            password_hash($nuevaPassword, PASSWORD_DEFAULT),
            $tokenHash
        ]) && $stmt->rowCount() === 1;
    }

    /*
     * SEGURIDAD: INTENTOS FALLIDOS DE INICIO DE SESIÓN
     *
     * El bloqueo se calcula por cuenta y utiliza una ventana
     * de 15 minutos.
     */

    public function registrarIntentoFallido(
        string $correo,
        string $ip
    ): bool {
        $sql = 'INSERT INTO intentos_login
                (correo, ip, fecha_intento)
                VALUES (?, ?, NOW())';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            mb_strtolower(trim($correo), 'UTF-8'),
            $ip
        ]);
    }

    public function contarIntentosFallidos(
        string $correo,
        string $ip = ''
    ): int {
        $sql = 'SELECT COUNT(*)
                FROM intentos_login
                WHERE LOWER(correo) = ?
                  AND fecha_intento >=
                      DATE_SUB(NOW(), INTERVAL 15 MINUTE)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            mb_strtolower(trim($correo), 'UTF-8')
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function limpiarIntentosFallidos(string $correo): bool
    {
        $sql = 'DELETE FROM intentos_login WHERE LOWER(correo) = ?';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            mb_strtolower(trim($correo), 'UTF-8')
        ]);
    }

    public function obtenerSegundosBloqueo(string $correo): int
    {
        /*
         * El quinto intento determina el vencimiento del bloqueo.
         * Se toma el quinto intento más antiguo de la ventana.
         */
        $sql = 'SELECT GREATEST(
                    0,
                    900 - TIMESTAMPDIFF(
                        SECOND,
                        fecha_intento,
                        NOW()
                    )
                )
                FROM intentos_login
                WHERE LOWER(correo) = ?
                  AND fecha_intento >=
                      DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                ORDER BY fecha_intento ASC, id_intento ASC
                LIMIT 1 OFFSET 4';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            mb_strtolower(trim($correo), 'UTF-8')
        ]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
