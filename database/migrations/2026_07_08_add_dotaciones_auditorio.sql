ALTER TABLE auditorio
    ADD COLUMN cantidad_computadores INT NULL AFTER capacidad,
    ADD COLUMN tiene_aire_acondicionado TINYINT(1) NULL AFTER cantidad_computadores,
    ADD COLUMN tiene_ventilador TINYINT(1) NULL AFTER tiene_aire_acondicionado,
    ADD COLUMN tiene_tablero TINYINT(1) NULL AFTER tiene_ventilador,
    ADD COLUMN tiene_televisor TINYINT(1) NULL AFTER tiene_tablero;
