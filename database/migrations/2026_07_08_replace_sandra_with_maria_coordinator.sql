START TRANSACTION;

INSERT INTO usuario (
    id_documento,
    tipo_documento,
    nombre,
    apellido,
    correo,
    contrasena,
    telefono,
    foto_perfil,
    fecha_registro,
    id_rol,
    id_ficha,
    id_estado
)
SELECT
    18188181881,
    'CC',
    _utf8mb4 0x4D6172C3AD61,
    'Torres',
    'marye46@hotmail.com',
    contrasena,
    NULL,
    NULL,
    CURDATE(),
    2,
    NULL,
    1
FROM usuario
WHERE id_documento = 1001001002
ON DUPLICATE KEY UPDATE
    tipo_documento = VALUES(tipo_documento),
    nombre = VALUES(nombre),
    apellido = VALUES(apellido),
    correo = VALUES(correo),
    telefono = VALUES(telefono),
    id_rol = VALUES(id_rol),
    id_ficha = VALUES(id_ficha),
    id_estado = VALUES(id_estado);

UPDATE evento
SET id_coordinador = 18188181881
WHERE id_coordinador = 1001001002;

DELETE FROM usuario
WHERE id_documento = 1001001002;

COMMIT;
