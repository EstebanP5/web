-- DDL for PM management
CREATE TABLE IF NOT EXISTS project_managers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNIQUE,
  nombre VARCHAR(150) NOT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Assuming users table exists with UNIQUE(email)
-- Create a PM (users + project_managers)
-- Replace placeholders with real values
INSERT INTO users (name, email, password, rol, activo)
VALUES ('<NOMBRE>', '<EMAIL>', '<HASHED_PASSWORD>', 'pm', 1);

-- Get the inserted id and create the PM profile
INSERT INTO project_managers (user_id, nombre, telefono, activo)
VALUES (LAST_INSERT_ID(), '<NOMBRE>', '<TELEFONO>', 1);

-- Example password hashing in PHP:
-- $hash = password_hash('MiContraseñaSegura123', PASSWORD_BCRYPT);
