<?php
// scripts/setup_sample_data.php
// Run from CLI: php scripts/setup_sample_data.php
require_once __DIR__ . '/../src/db.php';
$pdo = DB::pdo();
try {
    // admin account
    $adminUser = 'admin';
    $adminPass = 'AdminPass123!'; // change after first login
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$adminUser]);
    if (!$stmt->fetch()) {
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?,?,?)')->execute([$adminUser, $hash, 'admin']);
        echo "Created admin user: $adminUser / $adminPass\n";
    } else {
        echo "Admin user already exists\n";
    }

    // sample vendors
    $vendors = [
        ['ven1', 'Vendor1Pass!', 'Alpha Books', 'Alice', '9999999991', 'alpha@example.com'],
        ['ven2', 'Vendor2Pass!', 'Beta Books', 'Bob', '9999999992', 'beta@example.com'],
    ];
    foreach ($vendors as $v) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$v[0]]);
        if (!$stmt->fetch()) {
            $hash = password_hash($v[1], PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (username, password_hash, role, vendor_name, contact_person, phone, email) VALUES (?,?,?,?,?,?,?)')->execute([$v[0], $hash, 'vendor', $v[2], $v[3], $v[4], $v[5]]);
            echo "Created vendor: $v[0] / $v[1]\n";
        } else {
            echo "Vendor $v[0] already exists\n";
        }
    }

    // sample currency rate
    $stmt = $pdo->prepare('INSERT INTO currency_rates (currency_code, rate_to_inr, effective_date) VALUES (?,?,?)');
    $stmt->execute(['USD', '83.50', date('Y-m-d')]);

    // sample basket & books
    $bid_code = 'CUP/LIB/' . date('Y') . '/' . date('m') . '/1';
    $stmt = $pdo->prepare('INSERT INTO baskets (bid_code, title, created_by, submission_deadline, notes) VALUES (?,?,?,?,?)');
    $stmt->execute([$bid_code, 'Sample Basket', 1, date('Y-m-d H:i:s', strtotime('+7 days')), 'Sample basket created by setup script']);
    $basket_id = $pdo->lastInsertId();
    $books = [
        ['Introduction to Algorithms', 'Cormen, Leiserson, Rivest, Stein', 'MIT Press', '0262033844', 'Vol.1', 2],
        ['Clean Code', 'Robert C. Martin', 'Prentice Hall', '0132350882', 'Vol.1', 3],
    ];
    $ins = $pdo->prepare('INSERT INTO books (basket_id, title, authors, publisher, isbn, volume, copies_required) VALUES (?,?,?,?,?,?,?)');
    foreach ($books as $bk) {
        $ins->execute([$basket_id, $bk[0], $bk[1], $bk[2], $bk[3], $bk[4], $bk[5]]);
    }

    echo "Sample data created successfully.\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
