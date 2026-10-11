REQUIREMENTS(Once download and installed - Works Completely offline/local): Windows >= 10

- Node JS:
https://nodejs.org/en/download
#Direct dl: https://nodejs.org/dist/v24.21.0/node-v24.21.0-x64.msi

- Xampp: https://www.apachefriends.org/
#Run as admin when opening the program.
Step 1:
Copy - "xampp path directory/php" folder.
Step 2:
Ctrl + R then enter: 
rundll32.exe sysdm.cpl,EditEnvironmentVariables
Step 3:
- User variables - Path - Edit - New - Paste "xampp path directory/php" Path - Ok.

- Composer: https://getcomposer.org/download/
#Needed once for the mail library (password reset). Requires internet once.
Step 1:
Install Composer, then restart the terminal.
Step 2:
In the project folder, run:
composer install
#This creates vendor/ (PHPMailer). vendor/ is git-ignored: every fresh
#clone must run this once while online. Afterwards the system works offline.
#Mail also needs: a Gmail App Password in MAIL_PASSWORD inside
#php_backend/.private/.env (Google Account -> Security -> 2-Step Verification
#-> App passwords; never the Gmail login password), and the admin email set
#on the User Management page.

-- Sample Data to import:
["2023-10", 25, "2023-11", 20, "2023-12", 50, "2024-01", 21, "2024-02", 17, "2024-03", 30, "2024-04", 17, "2024-05", 25, "2024-06", 23, "2024-07", 24, "2024-08", 15, "2024-09", 45, "2024-10", 19, "2024-11", 20, "2024-12", 21, "2025-01", 18, "2025-02", 14, "2025-03", 10, "2025-04", 8, "2025-05", 9, "2025-06", 11, "2025-07", 18, "2025-08", 12, "2025-09", 17, "2025-10", 19, "2025-11", 21, "2025-12", 18, "2026-01", 20, "2026-02", 12, "2026-03", 6, "2026-04", 7, "2026-05", 10, "2026-06", 11, "2026-07", 10, "2026-08", 7, "2026-09", 8]

=======================================
------------- DATABASE ----------------
=======================================
-- Latest DB (fresh install: run everything below in phpMyAdmin):
-- Existing DB: run only the MIGRATION blocks in `database_query`.

CREATE DATABASE IF NOT EXISTS rural_urban;
USE rural_urban;

CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(30),
    user VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100),
    email VARCHAR(255) NULL,
    pass VARCHAR(255),
    admin BOOLEAN UNIQUE DEFAULT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active'
);

CREATE TABLE total (
    total_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00
);

CREATE TABLE stock_daily (
    day DATE PRIMARY KEY,
    total_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00
);

CREATE TABLE inventory (
    prod_id VARCHAR(15) PRIMARY KEY,
    product VARCHAR(30) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(10) NOT NULL,
    status VARCHAR(50) DEFAULT 'Ongoing',
    description TINYTEXT,
    stock_in DECIMAL(10,2),
    stock_out DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE production (
    production_id INT PRIMARY KEY AUTO_INCREMENT,
    batch_id VARCHAR(20) NOT NULL,
    production_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    item VARCHAR(15),
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(10) NOT NULL,
    status VARCHAR(30) DEFAULT 'Ongoing',
    receiver VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user VARCHAR(50) NOT NULL,
    user_role VARCHAR(30),
    action VARCHAR(30) NOT NULL,
    ref_id VARCHAR(15),
    product VARCHAR(50),
    quantity DECIMAL(10,2),
    unit VARCHAR(10),
    receiver VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE forecasting_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product VARCHAR(50) NOT NULL,
    months_used INT NOT NULL,
    alpha DECIMAL(4,2) NOT NULL,
    beta DECIMAL(4,2) NULL,
    method VARCHAR(32) NOT NULL DEFAULT 'SES (monthly)',
    forecast_qty DECIMAL(10,2) NOT NULL,
    forecast_month CHAR(7) NOT NULL,
    monthly_json MEDIUMTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user VARCHAR(50) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS reset_bans (
    user VARCHAR(50) PRIMARY KEY,
    level INT NOT NULL DEFAULT 1,
    until DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user VARCHAR(50) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

-- Indexes for the queries the system actually runs:
CREATE INDEX idx_inventory_status ON inventory(status);
CREATE INDEX idx_inventory_updated ON inventory(updated_at);
CREATE INDEX idx_production_date ON production(production_date);
CREATE INDEX idx_production_status ON production(status);
CREATE INDEX idx_production_updated ON production(updated_at);
CREATE UNIQUE INDEX uq_production_batch ON production(batch_id);
CREATE INDEX idx_history_created ON history(created_at);
CREATE INDEX idx_password_resets_user ON password_resets(user);
CREATE INDEX idx_login_attempts_user ON login_attempts(user);
CREATE INDEX idx_forecasting_history_created ON forecasting_history(created_at);
CREATE INDEX idx_accounts_user ON accounts(user);