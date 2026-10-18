CREATE TABLE IF NOT EXISTS intentos_login (
    id_intento INT AUTO_INCREMENT PRIMARY KEY,
    correo VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    fecha_intento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_correo_fecha (correo, fecha_intento),
    INDEX idx_ip_fecha (ip, fecha_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
