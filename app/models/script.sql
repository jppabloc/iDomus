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


-- add admin
INSERT INTO usuario (nombre, apellido, correo, contraseña, verificado)
VALUES ('Admin','Root','admin@admin', '<hash>', true);

-- Generar hash en PHP:
-- echo password_hash('admin', PASSWORD_DEFAULT);



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

-- Tabla de invitaciones / pre-registro
CREATE TABLE IF NOT EXISTS pre_registro (
  id           SERIAL PRIMARY KEY,
  email        VARCHAR(150) UNIQUE NOT NULL,
  token        VARCHAR(64) UNIQUE NOT NULL,
  expira_en    TIMESTAMP NOT NULL,
  usado        BOOLEAN DEFAULT FALSE,
  creado_por   INT,
  creado_en    TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pre_registro_token ON pre_registro(token);

-- =========================
-- (OPCIONAL) LIMPIEZA TOTAL
-- =========================
-- OJO: esto borra datos si ya tenías algo.
-- TRUNCATE TABLE
--   pago_historial,
--   pago,
--   cuota_mantenimiento,
--   residente_unidad,
--   usuario_rol,
--   usuario,
--   rol,
--   unidad,
--   bloque,
--   edificio
-- RESTART IDENTITY CASCADE;

BEGIN;

-- =========================
-- ROLES (base)
-- =========================
INSERT INTO rol (nombre_rol) VALUES ('usuario')
ON CONFLICT (nombre_rol) DO NOTHING;

INSERT INTO rol (nombre_rol) VALUES ('admin')
ON CONFLICT (nombre_rol) DO NOTHING;

INSERT INTO rol (nombre_rol) VALUES ('personal')
ON CONFLICT (nombre_rol) DO NOTHING;

-- =========================
-- EDIFICIOS
-- =========================
INSERT INTO edificio (nombre, direccion, nro_bloques)
VALUES ('Condominio Idomus A', 'Av. Central 123', 2)
ON CONFLICT DO NOTHING;

INSERT INTO edificio (nombre, direccion, nro_bloques)
VALUES ('Condominio Idomus B', 'Calle Norte 456', 1)
ON CONFLICT DO NOTHING;

-- =========================
-- BLOQUES (buscando id_edificio por nombre)
-- =========================
INSERT INTO bloque (id_edificio, nombre, descripcion)
SELECT e.id_edificio, 'Bloque A1', 'Torre principal'
FROM edificio e
WHERE e.nombre = 'Condominio Idomus A'
  AND NOT EXISTS (
    SELECT 1 FROM bloque b WHERE b.nombre='Bloque A1'
      AND b.id_edificio = e.id_edificio
  );

INSERT INTO bloque (id_edificio, nombre, descripcion)
SELECT e.id_edificio, 'Bloque A2', 'Torre lateral'
FROM edificio e
WHERE e.nombre = 'Condominio Idomus A'
  AND NOT EXISTS (
    SELECT 1 FROM bloque b WHERE b.nombre='Bloque A2'
      AND b.id_edificio = e.id_edificio
  );

INSERT INTO bloque (id_edificio, nombre, descripcion)
SELECT e.id_edificio, 'Bloque B1', 'Torre única'
FROM edificio e
WHERE e.nombre = 'Condominio Idomus B'
  AND NOT EXISTS (
    SELECT 1 FROM bloque b WHERE b.nombre='Bloque B1'
      AND b.id_edificio = e.id_edificio
  );

-- =========================
-- UNIDADES (buscando id_bloque por nombre)
-- =========================
-- A1
INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A1-101', 1, 78.5, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A1'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A1-101');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A1-102', 1, 65.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A1'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A1-102');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A1-201', 2, 78.5, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A1'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A1-201');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A1-202', 2, 65.0, 'Vacío'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A1'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A1-202');

-- A2
INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A2-301', 3, 80.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A2'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A2-301');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A2-302', 3, 70.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A2'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A2-302');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A2-401', 4, 90.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A2'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A2-401');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'A2-402', 4, 90.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus A' AND b.nombre='Bloque A2'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='A2-402');

-- B1
INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'B1-101', 1, 75.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus B' AND b.nombre='Bloque B1'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='B1-101');

INSERT INTO unidad (id_bloque, nro_unidad, piso, metros_cuadrados, estado)
SELECT b.id_bloque, 'B1-201', 2, 75.0, 'Ocupado'
FROM bloque b
JOIN edificio e ON e.id_edificio=b.id_edificio
WHERE e.nombre='Condominio Idomus B' AND b.nombre='Bloque B1'
  AND NOT EXISTS (SELECT 1 FROM unidad u WHERE u.nro_unidad='B1-201');

-- =========================
-- USUARIOS (2 admins + 10 residentes)
-- =========================
-- Admins
INSERT INTO usuario (nombre, apellido, correo, telefono, contrasena, verificado)
VALUES ('Carlos','Admin','cadmin@idomus.com','70000001','hash_admin',true)
ON CONFLICT (correo) DO NOTHING;

INSERT INTO usuario (nombre, apellido, correo, telefono, contrasena, verificado)
VALUES ('Lucía','Supervisor','lsuper@idomus.com','70000002','hash_admin',true)
ON CONFLICT (correo) DO NOTHING;

-- Residentes
INSERT INTO usuario (nombre, apellido, correo, telefono, contrasena, verificado) VALUES
 ('Juan','Pérez','juan.perez@vecinos.com','70000011','hash_res',true),
 ('Ana','López','ana.lopez@vecinos.com','70000012','hash_res',true),
 ('María','Gutiérrez','maria.gtz@vecinos.com','70000013','hash_res',true),
 ('Pedro','Quispe','pedro.qp@vecinos.com','70000014','hash_res',true),
 ('Sofía','Ramos','sofia.rm@vecinos.com','70000015','hash_res',true),
 ('Diego','Fernández','diego.fdz@vecinos.com','70000016','hash_res',true),
 ('Elena','Mendoza','elena.mdz@vecinos.com','70000017','hash_res',true),
 ('Luis','Vargas','luis.vg@vecinos.com','70000018','hash_res',true),
 ('Valeria','Arias','valeria.ar@vecinos.com','70000019','hash_res',true),
 ('Jorge','Salazar','jorge.sz@vecinos.com','70000020','hash_res',true)
ON CONFLICT (correo) DO NOTHING;

-- Roles de usuarios
INSERT INTO usuario_rol (iduser, idrol)
SELECT u.iduser, r.idrol
FROM usuario u
JOIN rol r ON r.nombre_rol='admin'
WHERE u.correo IN ('cadmin@idomus.com','lsuper@idomus.com')
ON CONFLICT DO NOTHING;

INSERT INTO usuario_rol (iduser, idrol)
SELECT u.iduser, r.idrol
FROM usuario u
JOIN rol r ON r.nombre_rol='usuario'
WHERE u.correo IN ('juan.perez@vecinos.com','ana.lopez@vecinos.com','maria.gtz@vecinos.com','pedro.qp@vecinos.com',
                   'sofia.rm@vecinos.com','diego.fdz@vecinos.com','elena.mdz@vecinos.com','luis.vg@vecinos.com',
                   'valeria.ar@vecinos.com','jorge.sz@vecinos.com')
ON CONFLICT DO NOTHING;

-- =========================
-- RESIDENTE x UNIDAD (1:1)
-- =========================
INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A1-101'
WHERE u.correo='juan.perez@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A1-102'
WHERE u.correo='ana.lopez@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Inquilino'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A1-201'
WHERE u.correo='maria.gtz@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Inquilino'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A1-202'
WHERE u.correo='pedro.qp@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A2-301'
WHERE u.correo='sofia.rm@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A2-302'
WHERE u.correo='diego.fdz@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A2-401'
WHERE u.correo='elena.mdz@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='A2-402'
WHERE u.correo='luis.vg@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='B1-101'
WHERE u.correo='valeria.ar@vecinos.com'
ON CONFLICT DO NOTHING;

INSERT INTO residente_unidad (id_usuario, id_unidad, tipo_residencia)
SELECT u.iduser, un.id_unidad, 'Propietario'
FROM usuario u
JOIN unidad un ON un.nro_unidad='B1-201'
WHERE u.correo='jorge.sz@vecinos.com'
ON CONFLICT DO NOTHING;

-- =========================
-- CUOTAS DE MANTENIMIENTO (Octubre 2025)
-- =========================
INSERT INTO cuota_mantenimiento (id_unidad, monto, fecha_generacion, fecha_vencimiento, estado)
SELECT un.id_unidad,
       CASE 
         WHEN un.nro_unidad IN ('A2-401','A2-402') THEN 180.00
         WHEN un.nro_unidad IN ('A1-201','B1-201') THEN 160.00
         ELSE 150.00
       END AS monto,
       DATE '2025-10-01' AS fecha_generacion,
       DATE '2025-10-15' AS fecha_vencimiento,
       'Pendiente' AS estado
FROM unidad un
WHERE un.nro_unidad IN ('A1-101','A1-102','A1-201','A1-202',
                        'A2-301','A2-302','A2-401','A2-402',
                        'B1-101','B1-201')
  AND NOT EXISTS (
    SELECT 1 FROM cuota_mantenimiento cm
    WHERE cm.id_unidad = un.id_unidad
      AND cm.fecha_generacion = DATE '2025-10-01'
  );

-- =========================
-- PAGOS (algunos ya pagaron)
-- =========================
INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
SELECT u.iduser, 150.00, DATE '2025-10-05', 'Cuota Octubre', 'Pagado'
FROM usuario u WHERE u.correo='ana.lopez@vecinos.com'
  AND NOT EXISTS (SELECT 1 FROM pago p WHERE p.id_usuario=u.iduser AND p.fecha_pago=DATE '2025-10-05');

INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
SELECT u.iduser, 150.00, DATE '2025-10-07', 'Cuota Octubre', 'Pagado'
FROM usuario u WHERE u.correo='sofia.rm@vecinos.com'
  AND NOT EXISTS (SELECT 1 FROM pago p WHERE p.id_usuario=u.iduser AND p.fecha_pago=DATE '2025-10-07');

INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
SELECT u.iduser, 180.00, DATE '2025-10-08', 'Cuota Octubre', 'Pagado'
FROM usuario u WHERE u.correo='elena.mdz@vecinos.com'
  AND NOT EXISTS (SELECT 1 FROM pago p WHERE p.id_usuario=u.iduser AND p.fecha_pago=DATE '2025-10-08');

INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
SELECT u.iduser, 150.00, DATE '2025-10-09', 'Cuota Octubre', 'Pagado'
FROM usuario u WHERE u.correo='valeria.ar@vecinos.com'
  AND NOT EXISTS (SELECT 1 FROM pago p WHERE p.id_usuario=u.iduser AND p.fecha_pago=DATE '2025-10-09');

INSERT INTO pago (id_usuario, monto, fecha_pago, concepto, estado)
SELECT u.iduser, 160.00, DATE '2025-10-10', 'Cuota Octubre', 'Pagado'
FROM usuario u WHERE u.correo='jorge.sz@vecinos.com'
  AND NOT EXISTS (SELECT 1 FROM pago p WHERE p.id_usuario=u.iduser AND p.fecha_pago=DATE '2025-10-10');

-- Marcar como Pagado las cuotas de esas unidades (JOIN seguro)
WITH pagar AS (
  SELECT un.id_unidad
  FROM unidad un
  WHERE un.nro_unidad IN ('A1-102','A2-301','A2-401','B1-101','B1-201')
)
UPDATE cuota_mantenimiento cm
SET estado = 'Pagado'
FROM pagar p
WHERE cm.id_unidad = p.id_unidad
  AND cm.fecha_generacion = DATE '2025-10-01';

-- =========================
-- VISTA para morosidad.php
-- =========================
CREATE OR REPLACE VIEW vw_morosidad AS
SELECT 
  u.iduser,
  (u.nombre || ' ' || u.apellido) AS usuario,
  un.nro_unidad AS unidad,
  cm.monto,
  'Mantenimiento'::text AS concepto,
  cm.fecha_vencimiento,
  cm.estado  -- 'Pendiente' / 'Pagado'
FROM cuota_mantenimiento cm
JOIN unidad un         ON un.id_unidad = cm.id_unidad
JOIN residente_unidad ru ON ru.id_unidad = un.id_unidad
JOIN usuario u         ON u.iduser = ru.id_usuario
ORDER BY cm.estado DESC, u.apellido, u.nombre;

COMMIT;

