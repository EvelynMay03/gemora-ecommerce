GEMORA - How to Run the Project (Step by Step)

Gemora is a PHP + MySQL e-commerce web application. It is designed to run on
XAMPP (Apache + MySQL + PHP) with the project folder inside htdocs.


1. REQUIREMENTS

  - XAMPP (or any Apache + PHP + MySQL stack)
  - PHP 8.0 or newer (the code uses PHP 8 features)
  - MySQL or MariaDB
  - PHP extensions enabled in php.ini:
      * pdo_mysql
      * fileinfo   (required for image uploads in admin and checkout)


2. PLACE THE PROJECT IN HTDOCS

  1. Copy or clone the Gemora folder into your XAMPP htdocs directory:

       macOS:   /Applications/XAMPP/xamppfiles/htdocs/Gemora
       Windows: C:\xampp\htdocs\Gemora

  2. The folder name "Gemora" becomes part of the URL:
       http://localhost/Gemora/


3. START APACHE AND MYSQL

  1. Open the XAMPP Control Panel.
  2. Start Apache.
  3. Start MySQL.

  Both services must be running before you open the site in a browser.


4. CREATE THE DATABASE

  Option A — phpMyAdmin (recommended for beginners)

    1. Open http://localhost/phpmyadmin in your browser.
    2. Click the "Import" tab.
    3. Choose the file:
         database/query.sql
    4. Click "Go" and wait for the import to finish.

    This creates the database "Gemora" and all required tables.

  Option B — MySQL command line

    1. Open a terminal.
    2. Run:

         mysql -u root -p < /path/to/Gemora/database/query.sql

       On macOS with default XAMPP (no password):

         /Applications/XAMPP/xamppfiles/bin/mysql -u root < database/query.sql

       Run this command from inside the Gemora project folder, or use the full
       path to query.sql.


  Note: The files in database/migrate_*.sql are only needed if you are
  upgrading an older database. For a fresh install, query.sql is enough.


5. CONFIGURE DATABASE CONNECTION (IF NEEDED)

  Default settings match a standard XAMPP install:

    Host:     localhost
    Database: Gemora
    Username: root
    Password: (empty)

  If your MySQL username or password is different, edit:

    database/pdo.php

  Change the connection line to match your setup, for example:

    $pdo = new PDO("mysql:host=localhost;dbname=Gemora;charset=utf8mb4", "root", "your_password");


6. CREATE UPLOAD FOLDERS

  The app stores uploaded files under an uploads/ folder at the project root.
  Create these folders if they do not exist:

    uploads/
    uploads/products/     (product images — admin inventory)
    uploads/payments/     (payment screenshots — checkout)
    uploads/avatars/      (profile photos)

  On macOS/Linux you can run from the Gemora folder:

    mkdir -p uploads/products uploads/payments uploads/avatars
    chmod -R 775 uploads

  Optional static assets referenced by the UI (place in uploads/ if missing):

    uploads/bikini.png    (site logo)
    uploads/lace.jpg      (placeholder product image)


7. CREATE A SUPER ADMIN ACCOUNT

  1. With Apache and MySQL running, open this URL once in your browser:

       http://localhost/Gemora/admin/superadmin.php

  2. You should see a success message. Default credentials:

       Email:    admin@gmail.com
       Password: Admin123456

  3. After setup, log in at:

       http://localhost/Gemora/login.php

     Admin users are redirected to the admin dashboard automatically.

  Security note: Delete or restrict access to admin/superadmin.php after
  creating your admin account on a production server.


8. OPEN THE APPLICATION

  Customer storefront (home page):

    http://localhost/Gemora/
    http://localhost/Gemora/user/home.php

  Register a new customer account:

    http://localhost/Gemora/register.php

  Log in:

    http://localhost/Gemora/login.php

  Admin dashboard (admin or super role required):

    http://localhost/Gemora/admin/admin.php

  Other admin pages:

    Inventory:  http://localhost/Gemora/admin/admin_inventory.php
    Orders:     http://localhost/Gemora/admin/admin_orders.php
    Preorders:  http://localhost/Gemora/admin/admin_preorders.php


9. USER ROLES

  role in the users table:

    customer  — default; can shop, cart, checkout, wishlist, profile
    admin     — access to admin panel
    super     — super admin; full admin access (see admin/superadmin.php)

