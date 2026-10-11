# PawHome — Pet Adoption & Veterinary Care System

PawHome is a comprehensive, multi-role web application designed for pet adoption, shelter management, and veterinary clinical care. Built with vanilla PHP and MySQL, it connects prospective adopters, animal shelters, licensed veterinarians, and platform administrators in a single workflow.

---

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Benefits of Using PawHome](#benefits-of-using-pawhome)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Installation Guide (XAMPP for Windows)](#installation-guide-xampp-for-windows)
- [Database Setup](#database-setup)
- [Configuration](#configuration)
- [Demo Accounts](#demo-accounts)
- [Project Structure](#project-structure)
- [Security Architecture](#security-architecture)
- [Troubleshooting](#troubleshooting)
- [Known Limitations](#known-limitations)
- [License](#license)

---

## Overview

PawHome facilitates animal welfare by providing:
- A public pet discovery catalog with dynamic age and category filters.
- A secure, verified adoption lifecycle from application to official certificate issuance.
- An appointment booking system preventing schedule overlaps.
- Complete electronic medical records (EMR) for pets, including checkups, prescriptions, and vaccination tracking.
- Role-segregated dashboards and real-time adopter-shelter messaging.

---

## Key Features

### 1. Multi-Role Authentication & Verification
- **Adopters:** Register with instant access to pet browsing, adoption applications, and vet booking.
- **Shelters & Veterinarians:** Sign up with required verification credentials (registration number, licence, certificates). Accounts start in a `pending` state and must be reviewed and approved by an administrator before access is granted.
- **Administrator:** Review and approve/reject pending registrations with inline document verification previews.

### 2. Pet Discovery & Adoption Lifecycle
- Filter available pets by category (Dog, Cat, Rabbit) and size (Small, Medium, Large) with an interactive min–max age slider.
- Detailed pet profiles showing health condition, vaccination status, neuter status, temperament, and background story.
- Adoption application submission with personal statements and optional ID/housing documentation.
- Shelter approval workflow scheduling physical handovers.
- Official printable/PDF **Certificate of Adoption** upon completed handover.

### 3. Veterinary Clinic Management & Booking
- Strict appointment scheduling holding slots for both vet and adopter to prevent double-booking.
- Clinical checkup recording: weight, body temperature, symptoms, clinical findings, diagnosis, and treatment plan.
- Digital prescriptions with medicine dosage, frequency, and instructions.
- Vaccination tracker categorized by priority (`Routine`, `Priority`, `Urgent`) tracking both `Due` and `Given` doses with batch numbers and booster dates.

### 4. Communication & Administration
- Adopter-to-shelter messaging interface with live conversation history and unread badges.
- Administrator management of pet categories, platform statistics, and adoption reports.

---

## Benefits of Using PawHome

PawHome brings pet adoption, shelter operations, and veterinary care together in one platform.

### 1. For Pet Adopters
- **Discover Pets Easily:** Browse available pets and filter listings by category, size, and age.
- **Apply Online:** Submit adoption applications through the website.
- **Follow Adoption Requests:** Keep track of adoption requests and handover steps.
- **Adoption Certificates:** Access a printable Certificate of Adoption after a completed handover.
- **Communicate with Shelters:** Communicate with shelters through the built-in messaging system.

### 2. For Animal Shelters
- **Manage Pet Listings:** Organize pets available for adoption.
- **Review Applications:** View and process adoption applications.
- **Coordinate Handovers:** Manage the steps involved in completing an adoption.
- **Communicate with Adopters:** Keep conversations and message history in one place.

### 3. For Veterinarians
- **Manage Appointments:** Organize veterinary appointments and help prevent overlapping bookings.
- **Record Checkups:** Store pet health information, symptoms, diagnoses, and treatment plans.
- **Manage Prescriptions:** Record medicine dosages, frequency, and instructions.
- **Track Vaccinations:** Organize vaccination status, due dates, and booster information.

### 4. For Administrators
- **Review Registrations:** Review and approve or reject pending shelter and veterinarian accounts.
- **Manage Categories:** Maintain pet categories on the platform.
- **Monitor Platform Activity:** Access platform statistics and adoption reports.
- **Role-Based Access:** Support appropriate access permissions for different user roles.

### 5. Overall Benefits
- Makes pet discovery and adoption applications more convenient.
- Helps shelters organize pet listings, applications, and handovers.
- Keeps veterinary appointments and pet medical records organized.
- Improves communication between adopters and shelters.
- Organizes important adoption and animal-care information in one platform.

**PawHome's Goal:** To make pet adoption more accessible, help shelters manage adoption workflows, and support organized veterinary care through one platform.

---

## Technology Stack

- **Backend:** PHP 8.0+ (Vanilla PHP, Procedural & Functional)
- **Database:** MySQL 5.7+ / MariaDB 10.4+ (InnoDB engine with foreign key constraints)
- **Frontend:** Vanilla HTML5, CSS3 (Modern Glassmorphism, CSS Grid, Flexbox), Vanilla JavaScript (ES6+)
- **Server:** Apache (XAMPP environment)

---

## Prerequisites

Before setting up PawHome, ensure you have:

1. **XAMPP for Windows** installed (PHP 8.0 or higher).
2. The following PHP extensions enabled in `php.ini` (standard in XAMPP):
   - `mysqli` — Database driver
   - `fileinfo` — Secure MIME-type detection for uploads
   - `mbstring` — Multibyte string processing
   - `session` — Session state management

---

## Installation Guide (XAMPP for Windows)

### Step 1: Place Files in `htdocs`
Clone or extract the repository directly into your XAMPP web root:
```text
C:\xampp\htdocs\PawHome
```
*(If your XAMPP installation is on another drive, adjust accordingly, e.g. `D:\xampp\htdocs\PawHome`)*.

### Step 2: Start Apache and MySQL
1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

---

## Database Setup

You can set up the database using either of the following two methods:

### Method A: 1-Click Import via phpMyAdmin (Recommended)
1. Open your web browser and go to `http://localhost/phpmyadmin/`.
2. Click the **Import** tab at the top.
3. Choose the file **`pawhome.sql`** located in the project root directory.
4. Click **Import** at the bottom of the page.
   *(This automatically creates the `pawhome` database, all 13 relational tables, and default pet categories)*.

### Method B: Automated Web Installer
1. Open your browser and navigate to:
   ```text
   http://localhost/PawHome/install.php
   ```
2. Click **Install / reset database**.
   *(This executes `db/schema.sql`, configures upload directories, and seeds comprehensive demo accounts and sample pets)*.

---

## Configuration

Database credentials are managed in [`config.php`](config.php):

```php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'pawhome');
```

- **Default:** Pre-configured for standard local XAMPP (`root` user with an empty password).
- **Custom Environment:** To override credentials without modifying version-controlled files, create a file named `config.local.php` in the root folder (already included in `.gitignore`):
  ```php
  <?php
  define('DB_USER', 'your_user');
  define('DB_PASS', 'your_password');
  ```
- A template file [`config.example.php`](config.example.php) is also provided.

---

## Accessing the Application

Once installed, open your browser and navigate to:
```text
http://localhost/PawHome/
```

---

## Demo Accounts

If you installed using Method B (`install.php`), the following demo accounts are available. All accounts use the password **`Paw12345`**:

| Role | Email | Description |
| :--- | :--- | :--- |
| **Admin** | `admin@pawhome.test` | Full platform access, category management, approvals |
| **Adopter** | `sarah@pawhome.test` | Active adopter with adopted pet history and applications |
| **Shelter** | `shelter@pawhome.test` | Hope Haven Shelter — manages pets, applications, and handovers |
| **Shelter** | `pawsafe@pawhome.test` | PawSafe Rescue — handles special-needs animal listings |
| **Veterinarian** | `vet@pawhome.test` | Dr. Amelia Ruiz — clinical checkups, prescriptions, vaccines |
| **Veterinarian** | `marcus@pawhome.test` | Dr. Marcus Webb — orthopedic surgical and recovery care |

*(You can also create a new account anytime on the [Sign Up](signup.php) page)*.

---

## Project Structure

```text
PawHome/
├── index.php                 # Landing page and featured pets
├── browse.php                # Pet search, filters, and age slider
├── pet.php                   # Pet profile & adoption application modal
├── login.php                 # Authentication endpoint
├── logout.php                # Session destruction and CSRF-protected logout
├── signup.php                # Multi-role registration with document upload
├── adopter.php               # Adopter portal (pets, appointments, chat)
├── shelter.php               # Shelter portal (intake, applications, handovers)
├── shelter-pet.php           # Individual pet management view for shelters
├── add-pet.php               # New pet intake form with validation
├── vet.php                   # Veterinary portal (appointments, checkups, vaccines)
├── admin.php                 # Admin dashboard, statistics, category management
├── approval.php              # Approval handler for pending shelters & vets
├── vet-approval.php          # Dedicated vet approval redirect handler
├── certificate.php           # Printable adoption certificate
├── history.php               # Historical adoption log
├── file.php                  # Secure private file delivery gateway
├── config.php                # Environment-aware configuration
├── config.example.php        # Configuration template
├── pawhome.sql               # Clean database import file
├── admin.css                 # Admin and approval layout styles
├── brand-links.css           # Global typography and brand styling
├── api/
│   ├── messages.php          # Async chat polling and message submission
│   └── slots.php             # Real-time vet appointment slot availability
├── assets/
│   ├── app.css               # Shared UI design system and components
│   ├── js/                   # Vanilla JavaScript controllers (UI, booking, chat)
│   └── *.png                 # Curated sample animal illustrations and brand logo
├── db/
│   ├── schema.sql            # Core relational table definitions
│   ├── seed_helpers.php      # Helper functions for database seeding
│   ├── demo_extra.php        # Extended demo data seeder
│   └── add_demo_data.php     # CLI script to enrich existing databases
├── includes/
│   ├── db.php                # MySQLi database wrapper and prepared query helpers
│   ├── auth.php              # Session validation, role authorization, redirections
│   ├── helpers.php           # CSRF token generator, XSS escaping, flash notices
│   ├── medical.php           # Health, checkup, and prescription business logic
│   ├── messages.php          # Server-rendered chat panel components
│   ├── public.php            # Public pet listings and catalog helpers
│   └── slots.php             # Appointment slot collision & validation logic
└── uploads/
    ├── pets/                 # Public pet photos (.htaccess protected)
    └── private/              # User IDs and medical records (.htaccess blocked)
```

---

## Security Architecture

PawHome incorporates modern security best practices:

1. **SQL Injection Defense:** All database interactions use prepared statements (`mysqli_prepare` and `mysqli_stmt_bind_param`) across all queries.
2. **Cross-Site Scripting (XSS):** All dynamic browser output is sanitized using HTML entity escaping (`e()` wrapper for `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
3. **Cross-Site Request Forgery (CSRF):** Forms and API mutation endpoints validate a cryptographically secure 32-byte session token (`verify_csrf()`) via `hash_equals()`.
4. **Session Security:**
   - Cookie parameters set to `HttpOnly` and `SameSite=Lax`.
   - `session_regenerate_id(true)` executed upon authentication.
   - User status actively validated against the database on each request.
5. **Role-Based Access Control (RBAC):** Restrictive `require_role()` gatekeepers prevent unauthorized access to role-specific dashboards.
6. **Upload Isolation & Protection:**
   - File uploads are validated by binary MIME inspection (`finfo`) rather than untrusted client extensions.
   - Files are stored with random hexadecimal identifiers (`bin2hex(random_bytes(12))`).
   - `uploads/pets/.htaccess` blocks server execution of script extensions (`.php`, `.phtml`, `.phar`).
   - `uploads/private/.htaccess` enforces `Require all denied` to block direct HTTP access.
7. **Directory Traversal Defense:** `file.php` uses `realpath()` resolution and prefix checking to ensure private downloads never escape the authorized upload boundary.

---

## Troubleshooting

### 1. Database Connection Failed
- **Symptom:** White screen or error message indicating connection failed.
- **Fix:** Ensure MySQL is started in XAMPP. Verify in `config.php` that `DB_HOST` is `localhost`, `DB_USER` is `root`, and `DB_PASS` is empty `""`.

### 2. Missing Table or "Table 'pawhome.users' doesn't exist"
- **Symptom:** Query failure when logging in or browsing.
- **Fix:** Import `pawhome.sql` in phpMyAdmin or visit `http://localhost/PawHome/install.php` to regenerate tables.

### 3. File Upload Errors
- **Symptom:** "File upload failed" or "File too large".
- **Fix:** PawHome limits uploads to 5 MB by default. Verify in your `php.ini` that `upload_max_filesize` and `post_max_size` are set to at least `8M`. Ensure write permissions exist on the `uploads/` directory.

### 4. Styles or JavaScript Not Updating
- **Symptom:** Browser shows stale styles or button actions fail.
- **Fix:** Clear browser cache or press `Ctrl + F5` (Windows) to force a hard reload.

---

## Known Limitations

- **Polling vs WebSockets:** The messaging panel uses AJAX polling (every 5 seconds) rather than WebSockets.
- **Email Delivery:** Notification emails are simulated within the local environment; external SMTP configuration is not included out of the box.

---

## License

This project is licensed under **All Rights Reserved** (Copyright © 2026 Mahiduzzaman Rafee). It is published for portfolio and educational viewing purposes only. See the [LICENSE](LICENSE) file for complete details.
