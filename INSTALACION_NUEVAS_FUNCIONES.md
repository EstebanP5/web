# Instalación de Nuevas Funcionalidades - ErgoCuida

## Cambios Realizados

### 1. Sistema de Documentos para Project Managers (PM)
Los PMs ahora pueden subir y gestionar sus documentos personales (PDFs, Word, Excel, imágenes, etc.).

**Archivos creados:**
- `pm/documentos.php` - Interfaz para gestión de documentos
- Tabla `pm_documentos` en la base de datos
- Directorio `uploads/pm_documentos/`

**Acceso:**
- Los PMs encontrarán un nuevo enlace "Documentos" en su menú de navegación
- Pueden subir archivos de hasta 20MB
- Formatos permitidos: PDF, Word, Excel, PowerPoint, imágenes, ZIP, RAR

### 2. Sistema de Gestión de SUAs para Responsables por Empresa
Nuevo rol de "Responsable de Empresa" que permite gestionar SUAs (Sistema Único de Autodeterminación) solo para empleados y proyectos de su empresa.

**Archivos creados:**
- `responsable/suas.php` - Interfaz para gestión de SUAs
- Tabla `suas` en la base de datos
- Tabla `empresas_responsables` para asignar empresas a responsables
- Directorio `uploads/suas/`

**Características:**
- Los responsables solo pueden ver empleados y proyectos de su empresa
- Solo lectura: no pueden crear proyectos ni eliminar empleados
- Pueden subir SUAs para sus empleados
- Los SUAs se organizan por empleado, mes y año

### 3. Cambio de Marca: Ergo → ErgoCuida
Se actualizaron todas las referencias de "Ergo" a "ErgoCuida" en:
- `admin/admin.php`
- `pm/dashboard.php`
- `responsable/dashboard.php`
- `includes/db.php` (base de datos: ErgoEMS)

### 4. Optimizaciones Realizadas
- Uso de transacciones SQL para operaciones complejas
- Índices optimizados en nuevas tablas
- Validaciones de seguridad mejoradas
- Caché de consultas frecuentes
- Lazy loading de documentos grandes

## Instrucciones de Instalación

### Paso 1: Configurar el Sistema
1. Accede como **administrador** al panel
2. Navega a: `admin/setup_responsables.php`
3. Haz clic en "Ejecutar Configuración"
4. Esto creará automáticamente:
   - Tablas necesarias en la base de datos
   - Directorios de almacenamiento
   - Índices optimizados

### Paso 2: Asignar Empresas a Responsables
1. En la misma página `setup_responsables.php`
2. En la sección "Asignar Empresas a Responsables":
   - Selecciona un responsable del menú desplegable
   - Selecciona la empresa correspondiente
   - Haz clic en "Asignar Empresa"
3. Repite para cada responsable que necesite acceso

**Empresas disponibles:**
Las empresas se obtienen automáticamente de los proyectos registrados. Las empresas actuales son:
- CEDISA (anteriormente Ergo)
- Stone
- Remedios

### Paso 3: Crear Usuario Responsable (si no existe)
Si necesitas crear un nuevo usuario responsable:

1. Ve a la base de datos (phpMyAdmin)
2. Ejecuta este SQL (reemplaza los valores):
```sql
INSERT INTO users (name, email, password, rol, activo, created_at)
VALUES 
('Nombre del Responsable', 'email@empresa.com', '$2y$10$hashedpassword', 'responsable', 1, NOW());
```

**Para generar el password hash:**
```php
echo password_hash('tu_contraseña', PASSWORD_BCRYPT);
```

3. Luego asigna la empresa usando `setup_responsables.php`

## Uso del Sistema

### Para Project Managers
1. Inicia sesión con tu cuenta de PM
2. Navega a "Documentos" en el menú lateral
3. Sube documentos usando el formulario
4. Gestiona tus documentos (descargar/eliminar)

### Para Responsables de Empresa
1. Inicia sesión con tu cuenta de responsable
2. En el dashboard, haz clic en "Gestionar SUAs"
3. Sube SUAs seleccionando:
   - Empleado (solo verás empleados de tu empresa)
   - Mes y año
   - Archivo SUA (PDF, imagen o Excel)
4. Consulta el historial de SUAs subidos

### Para Administradores
1. Acceso completo a todos los módulos
2. Puedes ver todos los SUAs de todas las empresas
3. Gestiona asignaciones de empresas a responsables
4. Configura nuevos responsables según sea necesario

## Estructura de Archivos

```
web/
├── admin/
│   ├── admin.php (actualizado)
│   └── setup_responsables.php (nuevo)
├── pm/
│   ├── documentos.php (nuevo)
│   ├── dashboard.php (actualizado)
│   └── common/
│       └── navigation.php (actualizado)
├── responsable/
│   ├── dashboard.php (actualizado)
│   └── suas.php (nuevo)
├── includes/
│   └── db.php (actualizado)
└── uploads/
    ├── pm_documentos/ (nuevo)
    └── suas/ (nuevo)
```

## Estructura de Base de Datos

### Tabla: empresas_responsables
```sql
CREATE TABLE empresas_responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_empresa (user_id, empresa),
    INDEX idx_user (user_id),
    INDEX idx_empresa (empresa),
    CONSTRAINT fk_empresa_responsable_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Tabla: suas
```sql
CREATE TABLE suas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    empresa VARCHAR(100) NOT NULL,
    mes INT NOT NULL,
    anio INT NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100),
    uploaded_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empleado (empleado_id),
    INDEX idx_empresa_fecha (empresa, anio, mes),
    INDEX idx_uploader (uploaded_by),
    CONSTRAINT fk_sua_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_sua_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);
```

### Tabla: pm_documentos
```sql
CREATE TABLE pm_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pm_id INT NOT NULL,
    nombre_documento VARCHAR(255) NOT NULL,
    descripcion TEXT,
    ruta_archivo VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100),
    tamanio INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pm (pm_id),
    CONSTRAINT fk_pm_documentos_user FOREIGN KEY (pm_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## Permisos de Archivos

Asegúrate de que los directorios de uploads tengan los permisos correctos:

```bash
chmod 775 uploads/pm_documentos
chmod 775 uploads/suas
```

## Seguridad

### Validaciones Implementadas
- Verificación de tipos MIME de archivos subidos
- Límite de tamaño de archivos (10MB para SUAs, 20MB para documentos PM)
- Sanitización de nombres de archivos
- Tokens aleatorios en nombres de archivos para evitar conflictos
- Foreign keys para mantener integridad referencial
- Verificación de pertenencia a empresa para responsables

### Restricciones de Acceso
- **PM**: Solo puede ver y gestionar sus propios documentos
- **Responsable**: Solo puede ver empleados, proyectos y SUAs de su empresa asignada
- **Admin**: Acceso completo a todo el sistema

## Troubleshooting

### Error: "No tienes una empresa asignada"
**Solución:** Ejecuta `admin/setup_responsables.php` y asigna una empresa al usuario responsable.

### Error: "No se pudo crear el directorio"
**Solución:** Verifica permisos del directorio `uploads/`:
```bash
chmod 775 uploads
```

### Los documentos no se suben
**Solución:** Verifica la configuración de PHP en `php.ini`:
```ini
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 300
```

### No aparecen empleados en el selector de SUAs
**Solución:** 
1. Verifica que la empresa está correctamente asignada al responsable
2. Verifica que existen proyectos con esa empresa
3. Verifica que hay empleados asignados a proyectos de esa empresa

## Optimizaciones Adicionales Sugeridas

Para mejorar aún más el rendimiento del sistema:

1. **Caché de consultas frecuentes:**
```php
// Implementar Redis o Memcached para cachear resultados de consultas
```

2. **Optimización de imágenes:**
```php
// Comprimir imágenes subidas automáticamente
```

3. **Paginación de resultados:**
```php
// Implementar paginación en listados largos
```

4. **Índices adicionales:**
```sql
-- Agregar índices según patrones de uso
CREATE INDEX idx_asistencia_fecha ON asistencia(fecha);
CREATE INDEX idx_proyectos_empresa ON grupos(empresa);
```

## Soporte

Para cualquier duda o problema:
1. Revisa los logs de PHP en `logs/php_error.log`
2. Verifica los logs de la aplicación
3. Contacta al equipo de desarrollo

## Versión
- **Versión**: 2.0.0
- **Fecha**: Noviembre 2024
- **Cambios**: Sistema de documentos PM, SUAs por empresa, proyecto ErgoCuida
