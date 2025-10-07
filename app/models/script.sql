--    
--  SQL Script para la creación de tablas en PostgreSQL

-- Tabla de usuarios
CREATE TABLE usuario (
    idUser SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    apellido VARCHAR(50) NOT NULL,
    correo VARCHAR(100) UNIQUE NOT NULL,
    telefono VARCHAR(20),
    contrasena TEXT NOT NULL,
    verificado BOOLEAN DEFAULT FALSE,
    codigo_verificacion VARCHAR(10),
    creado_en TIMESTAMP DEFAULT NOW()
);

-- Tabla de roles
CREATE TABLE rol (
    idRol SERIAL PRIMARY KEY,
    nombre_rol VARCHAR(20) UNIQUE NOT NULL
);

-- Tabla intermedia usuario-rol (muchos a muchos)
CREATE TABLE usuario_rol (
    idUser INT REFERENCES usuario(idUser) ON DELETE CASCADE,
    idRol INT REFERENCES rol(idRol) ON DELETE CASCADE,
    PRIMARY KEY (idUser, idRol)
);


-- Insertamos roles base
INSERT INTO rol (nombre_rol) VALUES ('usuario'), ('admin'), ('personal');

-- Creamos un usuario
INSERT INTO usuario (nombre, apellido, correo, contrasena)
VALUES ('Juan', 'Pérez', 'juanp@example.com', 'hash_contrasena');

-- Asignamos roles al usuario
INSERT INTO usuario_rol (idUser, idRol)
VALUES (1, 1),  -- usuario normal
       (1, 2);  -- también admin








-- real data base

-- ======= Tablas sociales (como en tu script) =======
CREATE TABLE IF NOT EXISTS "follows" (
  "following_user_id" integer,
  "followed_user_id" integer,
  "created_at" timestamp
);

CREATE TABLE IF NOT EXISTS "users" (
  "id" integer PRIMARY KEY,
  "username" varchar,
  "role" varchar,
  "created_at" timestamp
);

CREATE TABLE IF NOT EXISTS "posts" (
  "id" integer PRIMARY KEY,
  "title" varchar,
  "body" text,
  "user_id" integer NOT NULL,
  "status" varchar,
  "created_at" timestamp
);

-- ======= Núcleo iDomus (sin rol/usuario/usuario_rol) =======
CREATE TABLE IF NOT EXISTS "edificio" (
  "id_edificio" SERIAL PRIMARY KEY,
  "nombre" VARCHAR(100) NOT NULL,
  "direccion" VARCHAR(255) NOT NULL,
  "nro_bloques" INT NOT NULL
);

CREATE TABLE IF NOT EXISTS "bloque" (
  "id_bloque" SERIAL PRIMARY KEY,
  "id_edificio" INT NOT NULL,
  "nombre" VARCHAR(100) NOT NULL,
  "descripcion" TEXT
);

CREATE TABLE IF NOT EXISTS "unidad" (
  "id_unidad" SERIAL PRIMARY KEY,
  "id_bloque" INT NOT NULL,
  "nro_unidad" VARCHAR(20) NOT NULL,
  "piso" INT NOT NULL,
  "metros_cuadrados" NUMERIC(8,2),
  "estado" VARCHAR(50) NOT NULL
);

-- Omitido: usuario, rol, usuario_rol (ya creadas)

CREATE TABLE IF NOT EXISTS "residente_unidad" (
  "id_usuario" INT,
  "id_unidad" INT,
  "tipo_residencia" VARCHAR(20),
  PRIMARY KEY ("id_usuario", "id_unidad")
);

CREATE TABLE IF NOT EXISTS "acceso" (
  "id_acceso" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "id_unidad" INT,
  "fecha_hora" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "tipo_acceso" VARCHAR(20),
  "resultado" VARCHAR(20)
);

CREATE TABLE IF NOT EXISTS "biometrico" (
  "id_biometrico" SERIAL PRIMARY KEY,
  "id_usuario" INT NOT NULL,
  "huella_hash" TEXT,
  "rostro_hash" TEXT,
  "fecha_registro" TIMESTAMP NOT NULL DEFAULT (NOW())
);

CREATE TABLE IF NOT EXISTS "alerta_seguridad" (
  "id_alerta" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "tipo_alerta" VARCHAR(100) NOT NULL,
  "descripcion" TEXT,
  "fecha_hora" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "criticidad" VARCHAR(20) NOT NULL
);

CREATE TABLE IF NOT EXISTS "area_comun" (
  "id_area" SERIAL PRIMARY KEY,
  "id_edificio" INT NOT NULL,
  "nombre_area" VARCHAR(100) NOT NULL,
  "descripcion" TEXT,
  "capacidad" INT,
  "estado" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "reserva" (
  "id_reserva" SERIAL PRIMARY KEY,
  "id_area" INT,
  "id_usuario" INT,
  "fecha_inicio" TIMESTAMP NOT NULL,
  "fecha_fin" TIMESTAMP NOT NULL,
  "estado" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "mantenimiento" (
  "id_mantenimiento" SERIAL PRIMARY KEY,
  "id_area" INT,
  "id_unidad" INT,
  "tipo" VARCHAR(20),
  "descripcion" TEXT,
  "fecha_programada" DATE,
  "fecha_realizada" DATE,
  "estado" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "consumo" (
  "id_consumo" SERIAL PRIMARY KEY,
  "id_unidad" INT,
  "tipo" VARCHAR(20),
  "cantidad" NUMERIC(10,2) NOT NULL,
  "unidad" VARCHAR(20) NOT NULL,
  "fecha_registro" TIMESTAMP NOT NULL DEFAULT (NOW())
);

CREATE TABLE IF NOT EXISTS "pago" (
  "id_pago" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "monto" NUMERIC(10,2) NOT NULL,
  "fecha_pago" DATE NOT NULL,
  "concepto" TEXT,
  "estado" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "cuota_mantenimiento" (
  "id_cuota" SERIAL PRIMARY KEY,
  "id_unidad" INT,
  "monto" NUMERIC(10,2) NOT NULL,
  "fecha_generacion" DATE NOT NULL,
  "fecha_vencimiento" DATE NOT NULL,
  "estado" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "mascota" (
  "id_mascota" SERIAL PRIMARY KEY,
  "id_usuario" INT NOT NULL,
  "nombre" VARCHAR(100) NOT NULL,
  "especie" VARCHAR(50) NOT NULL,
  "raza" VARCHAR(50),
  "edad" INT,
  "registro_vacunacion" TEXT
);

CREATE TABLE IF NOT EXISTS "mensaje" (
  "id_mensaje" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "asunto" VARCHAR(200),
  "contenido" TEXT NOT NULL,
  "fecha_envio" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "tipo" VARCHAR(20),
  "destinatario" VARCHAR(150)
);

CREATE TABLE IF NOT EXISTS "notificacion" (
  "id_notificacion" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "mensaje" TEXT NOT NULL,
  "fecha_envio" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "leido" CHAR(1) DEFAULT 'N'
);

CREATE TABLE IF NOT EXISTS "reclamo" (
  "id_reclamo" SERIAL PRIMARY KEY,
  "id_usuario" INT NOT NULL,
  "id_unidad" INT,
  "asunto" VARCHAR(200) NOT NULL,
  "descripcion" TEXT NOT NULL,
  "fecha_creacion" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "estado" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "dispositivo_rol" (
  "id_dispositivo" SERIAL PRIMARY KEY,
  "id_unidad" INT,
  "nombre" VARCHAR(100) NOT NULL,
  "tipo" VARCHAR(50),
  "fabricante" VARCHAR(100),
  "estado" VARCHAR(50) NOT NULL,
  "fecha_instalacion" DATE NOT NULL
);

CREATE TABLE IF NOT EXISTS "prediccion" (
  "id_prediccion" SERIAL PRIMARY KEY,
  "id_unidad" INT,
  "id_modelo" INT,
  "tipo" VARCHAR(20),
  "valor_estimado" NUMERIC(12,2) NOT NULL,
  "periodo" VARCHAR(20) NOT NULL,
  "fecha_generacion" TIMESTAMP NOT NULL DEFAULT (NOW())
);

CREATE TABLE IF NOT EXISTS "anomalia" (
  "id_anomalia" SERIAL PRIMARY KEY,
  "tipo" VARCHAR(100) NOT NULL,
  "descripcion" TEXT,
  "id_unidad" INT,
  "fecha_detectada" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "nivel_riesgo" VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS "modelo_ia" (
  "id_modelo" SERIAL PRIMARY KEY,
  "nombre" varchar(100),
  "version" varchar(20),
  "descripcion" text,
  "fecha_entrenamiento" timestamp,
  "accuracy" numeric(5,2),
  "archivo_modelo_url" text
);

CREATE TABLE IF NOT EXISTS "reporte_ia" (
  "id_reporte" SERIAL PRIMARY KEY,
  "id_modelo" INT,
  "id_usuario" INT,
  "titulo" VARCHAR(200) NOT NULL,
  "descripcion" TEXT,
  "fecha_generacion" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "archivo_url" TEXT
);

CREATE TABLE IF NOT EXISTS "auditoria" (
  "id_auditoria" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "accion" TEXT NOT NULL,
  "fecha_hora" TIMESTAMP NOT NULL DEFAULT (NOW()),
  "ip_origen" VARCHAR(50),
  "modulo" VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS "configuracion_sistema" (
  "id_config" SERIAL PRIMARY KEY,
  "id_usuario" INT,
  "parametro" VARCHAR(100) NOT NULL,
  "valor" TEXT NOT NULL,
  "fecha_modificacion" TIMESTAMP NOT NULL DEFAULT (NOW())
);

-- === Relaciones (corrigidas para usar iduser) ===

ALTER TABLE "bloque" ADD FOREIGN KEY ("id_edificio") REFERENCES "edificio" ("id_edificio") ON DELETE CASCADE;
ALTER TABLE "unidad" ADD FOREIGN KEY ("id_bloque") REFERENCES "bloque" ("id_bloque") ON DELETE CASCADE;

ALTER TABLE "residente_unidad" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;
ALTER TABLE "residente_unidad" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad") ON DELETE CASCADE;

ALTER TABLE "acceso" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;
ALTER TABLE "acceso" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad");

ALTER TABLE "biometrico" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "alerta_seguridad" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser");

ALTER TABLE "area_comun" ADD FOREIGN KEY ("id_edificio") REFERENCES "edificio" ("id_edificio") ON DELETE CASCADE;

ALTER TABLE "reserva" ADD FOREIGN KEY ("id_area") REFERENCES "area_comun" ("id_area") ON DELETE CASCADE;
ALTER TABLE "reserva" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "mantenimiento" ADD FOREIGN KEY ("id_area") REFERENCES "area_comun" ("id_area");
ALTER TABLE "mantenimiento" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad");

ALTER TABLE "consumo" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad") ON DELETE CASCADE;

ALTER TABLE "pago" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "cuota_mantenimiento" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad") ON DELETE CASCADE;

ALTER TABLE "mascota" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "mensaje" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "notificacion" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "reclamo" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;
ALTER TABLE "reclamo" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad");

ALTER TABLE "dispositivo_rol" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad") ON DELETE CASCADE;

ALTER TABLE "prediccion" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad") ON DELETE CASCADE;
ALTER TABLE "prediccion" ADD FOREIGN KEY ("id_modelo") REFERENCES "modelo_ia" ("id_modelo") ON DELETE CASCADE;

ALTER TABLE "anomalia" ADD FOREIGN KEY ("id_unidad") REFERENCES "unidad" ("id_unidad");

ALTER TABLE "reporte_ia" ADD FOREIGN KEY ("id_modelo") REFERENCES "modelo_ia" ("id_modelo") ON DELETE CASCADE;
ALTER TABLE "reporte_ia" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;

ALTER TABLE "auditoria" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser");

ALTER TABLE "configuracion_sistema" ADD FOREIGN KEY ("id_usuario") REFERENCES "usuario" ("iduser") ON DELETE CASCADE;





-- datos de prueba 🚩

-- inmuebles
INSERT INTO edificio (nombre, direccion, nro_bloques) VALUES
('Edificio Andina', 'Av. 6 de Agosto 123', 2),
('Edificio Illimani', 'Calle Murillo 456', 2);

WITH e AS (SELECT id_edificio, nombre FROM edificio)
INSERT INTO bloque (id_edificio, nombre, descripcion)
SELECT e1.id_edificio, 'Bloque A', 'Bloque principal' FROM e e1 WHERE e1.nombre='Edificio Andina'
UNION ALL
SELECT e1.id_edificio, 'Bloque B', 'Bloque secundario' FROM e e1 WHERE e1.nombre='Edificio Andina'
UNION ALL
SELECT e2.id_edificio, 'Torre 1', 'Torre norte' FROM e e2 WHERE e2.nombre='Edificio Illimani'
UNION ALL
SELECT e2.id_edificio, 'Torre 2', 'Torre sur' FROM e e2 WHERE e2.nombre='Edificio Illimani';

WITH b AS (SELECT id_bloque, nombre FROM bloque)
INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado) VALUES
((SELECT id_bloque FROM b WHERE nombre='Bloque A'), 'A-101', 1, 65.5, 'OCUPADA'),
((SELECT id_bloque FROM b WHERE nombre='Bloque A'), 'A-201', 2, 65.5, 'OCUPADA'),
((SELECT id_bloque FROM b WHERE nombre='Bloque B'), 'B-102', 1, 72,   'OCUPADA'),
((SELECT id_bloque FROM b WHERE nombre='Torre 1'), 'T1-11', 1, 80,   'OCUPADA'),
((SELECT id_bloque FROM b WHERE nombre='Torre 1'), 'T1-21', 2, 80,   'OCUPADA'),
((SELECT id_bloque FROM b WHERE nombre='Torre 2'), 'T2-22', 2, 78,   'OCUPADA');

-- pagos
INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado) VALUES
((SELECT iduser FROM usuario WHERE correo='carlos@correo.com'), 350.00, DATE '2025-09-05', 'Cuota Septiembre', 'PAGADO'),
((SELECT iduser FROM usuario WHERE correo='carlos@correo.com'), 355.00, DATE '2025-10-05', 'Cuota Octubre',   'PAGADO'),
((SELECT iduser FROM usuario WHERE correo='lucia@correo.com'),  420.00, DATE '2025-09-10', 'Cuota Septiembre', 'PAGADO'),
((SELECT iduser FROM usuario WHERE correo='maria@correo.com'),  400.00, DATE '2025-10-01', 'Cuota Octubre',   'PAGADO');

-- consumos
INSERT INTO consumo (id_unidad, tipo, cantidad, unidad, fecha_registro) VALUES
((SELECT id_unidad FROM unidad WHERE nro_unidad='A-101'), 'AGUA', 9.5, 'm3',  TIMESTAMP '2025-09-30 08:00:00'),
((SELECT id_unidad FROM unidad WHERE nro_unidad='A-101'), 'ENERGIA',125,'kWh',TIMESTAMP '2025-09-30 08:00:00'),
((SELECT id_unidad FROM unidad WHERE nro_unidad='T1-21'),'AGUA', 7.2, 'm3',  TIMESTAMP '2025-09-29 09:00:00'),
((SELECT id_unidad FROM unidad WHERE nro_unidad='T1-21'),'ENERGIA',110,'kWh',TIMESTAMP '2025-09-29 09:00:00');

-- cuotas
INSERT INTO cuota_mantenimiento (id_unidad, monto, fecha_generacion, fecha_vencimiento, estado) VALUES
((SELECT id_unidad FROM unidad WHERE nro_unidad='A-101'), 355.00, DATE '2025-10-01', DATE '2025-10-10', 'PENDIENTE'),
((SELECT id_unidad FROM unidad WHERE nro_unidad='T1-21'), 335.00, DATE '2025-10-01', DATE '2025-10-10', 'PENDIENTE'),
((SELECT id_unidad FROM unidad WHERE nro_unidad='A-201'), 350.00, DATE '2025-09-01', DATE '2025-09-10', 'PAGADO');

