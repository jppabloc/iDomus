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
