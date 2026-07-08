CREATE TABLE IF NOT EXISTS solicitud_usuario (
    id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento VARCHAR(10) NOT NULL DEFAULT 'CC',
    id_documento BIGINT NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    correo VARCHAR(100) NOT NULL,
    telefono VARCHAR(15) NULL,
    id_rol INT NOT NULL,
    id_ficha INT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    observacion VARCHAR(255) NULL,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_respuesta DATETIME NULL,
    id_admin_respuesta BIGINT NULL,
    INDEX idx_solicitud_estado (estado),
    INDEX idx_solicitud_documento (id_documento),
    INDEX idx_solicitud_correo (correo)
);
