ALTER TABLE usuario
    ADD COLUMN tipo_documento VARCHAR(10) NOT NULL DEFAULT 'CC' AFTER id_documento;
