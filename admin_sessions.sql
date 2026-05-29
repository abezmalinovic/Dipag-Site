-- DIPAG Admin — Tablas de administración
-- Ejecutar una vez en dipag_db
-- Son independientes de la tabla 'usuarios'

-- Tabla de administradores (separada de usuarios)
CREATE TABLE IF NOT EXISTS admins (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    activo     TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de sesiones admin (referencia a admins, no a usuarios)
CREATE TABLE IF NOT EXISTS admin_sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    admin_id   INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar admin inicial (contraseña: Admin2025!)
-- Hash generado con password_hash("Admin2025!", PASSWORD_DEFAULT)
INSERT IGNORE INTO admins (nombre, email, password) VALUES
('Antoine', 'contacto@dipag.cl', '$2y$10$boKYv6yisXnIzj67eh8tw.SOP9FeEm8PIPI1rczTWuCtL/YD4nONy');
