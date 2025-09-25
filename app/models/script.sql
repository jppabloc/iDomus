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
