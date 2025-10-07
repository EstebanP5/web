# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Dependencies
- Install PHP dependencies: `composer install`
- PDF parsing uses smalot/pdfparser library

### Database Setup
- Database: MySQL on port 3306 (`ergoems.ddns.net`)
- Database name: `emergencias`
- Default credentials live in `includes/db.php` and `common/db.php` (`ErgoEMS` / `C4nt0n4DBu53r$2024`), overridable via environment variables (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`)
- Use `admin/sql_pm_setup.sql` for PM-related table setup

### Local Development
- Start local server: `php -S localhost:8000` from project root
- Access application at: `http://localhost:8000`
- Main entry point (`index.php`) redirects to `admin/admin.php`

## Architecture Overview

### Role-Based System
Four user roles with separate interfaces:
- **admin**: Full system access (`admin/` directory)
- **pm**: Project Manager access (`pm/` directory) 
- **responsable**: Supervisor access (`responsable/` directory)
- **empleado**: Employee access (redirects to `responsable/dashboard.php`)

### Core Components

#### Authentication & Session Management
- `login.php`: Main login form with email-based authentication and role-based redirects
- `common/auth.php`: Session validation middleware
- Session stores: `user_id`, `user_name`, `user_rol`, and `usuario` array
- Password support: Both bcrypt hashed and plain text (legacy compatibility)
- Role fallback system for employee detection

#### Database Structure
- **users**: Unified user table with email, password (bcrypt/plain), rol, and activo fields
- **grupos**: Projects table (legacy name, represents projects) with token, coordinates, and PM info
- **empleados**: Employee records with NSS/CURP fields and grupo_id relationship
- **project_managers**: PM profile table linked to users via user_id
- **proyectos_pm**: Many-to-many relationship between projects and PMs
- **asistencia**: Attendance tracking with GPS coordinates and photo paths
- **videos**: Educational video storage with proyecto_id assignment

#### Key Modules
- **Attendance System**: GPS-based check-in/out with photo capture and processing
  - Original and processed photos stored separately
  - Token-based public access for field workers
- **Project Management**: Multi-user project assignment with coordinate mapping
- **Employee Management**: Full CRUD with NSS/CURP document tracking
- **PDF Processing**: SUA document parsing with NSS/CURP extraction using regex patterns
- **Video Library**: Educational content management with project assignment

### File Organization
- `admin/`: Administrative interface and user management
- `pm/`: Project manager dashboards and tools
- `responsable/`: Supervisor project monitoring and employee dashboard  
- `empleado/`: Employee-specific interface (minimal)
- `common/`: Shared utilities, authentication, and database connections
- `public/`: Public-facing attendance and emergency features (no auth required)
- `includes/`: Database connection and helper functions
- `uploads/`: File storage for PDFs and attendance photos
- `vendor/`: Composer dependencies (smalot/pdfparser)

### Important Technical Details
- Database uses legacy table name 'grupos' for projects
- Attendance photos stored in `admin/uploads/asistencias/{proyecto_id}/{fecha}/{tipo}/` structure (`tipo`: `entradas`, `salidas`, `descansos`, `reanudar`, etc.)
- Both original and processed photos saved with timestamp filenames
- PDF files processed and stored in `uploads/` with SUA prefix and timestamp
- SMS logging capability (see `sms_log_*.log` files)
- Emergency features accessible without authentication in `public/`
- Public attendance accessible via token-based URLs
- Duplicate database connection files in `includes/` and `common/` (both used)
- Video files stored in `uploads/videos/` with generated filenames