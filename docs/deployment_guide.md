# Deployment Guide

This guide explains three deployment options for the Library Bidding Portal:
1. Ubuntu LAMP (recommended for production)
2. cPanel (shared hosting)
3. XAMPP (local development / testing)

## 1) Ubuntu LAMP (Ubuntu 20.04/22.04)
### Install Apache, PHP, MySQL
```bash
sudo apt update
sudo apt install apache2 mysql-server php php-mysql libapache2-mod-php unzip -y
```
### Secure MySQL
```bash
sudo mysql_secure_installation
```
### Create database and user
```sql
sudo mysql -u root -p
CREATE DATABASE library_bids CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dbuser'@'localhost' IDENTIFIED BY 'dbpass';
GRANT ALL ON library_bids.* TO 'dbuser'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```
### Import schema
```bash
mysql -u dbuser -p library_bids < sql/schema.sql
```
### Configure application
- Edit `src/config.php` DB credentials.
- Place `public/` folder as Apache document root or configure a VirtualHost to point to `.../public`.
- Move `src/` outside webroot if possible for security.

Example VirtualHost:
```
<VirtualHost *:80>
    ServerName bids.example.org
    DocumentRoot /var/www/library_bidding_portal_complete/public
    <Directory /var/www/library_bidding_portal_complete/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
Restart Apache:
```bash
sudo systemctl restart apache2
```
### Run setup script (create sample data)
```bash
php scripts/setup_sample_data.php
```
### Security recommendations
- Serve over HTTPS (Let's Encrypt certbot).
- Set proper file permissions: webserver user should own public files.
- Put `src/` outside webroot or restrict access with .htaccess.
- Disable display_errors in production (php.ini).
