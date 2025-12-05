<?php
require_once '/var/www/src/config.php';

if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'];
    if ($role === 'admin') header('Location: ' . BASE_URL . 'admin/dashboard.php');
    else header('Location: ' . BASE_URL . 'vendor/dashboard.php');
    exit;
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Library Bidding Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>

</head>
<body class="p-4">
<div class="container">
  <div class="card shadow-sm p-4">
    <h1 class="h3">Library Bidding Portal</h1>
    <p class="lead">Publish baskets, collect vendor quotes, and automatically determine L1 winners.</p>
    <p>
      <a href="login_admin.php" class="btn btn-primary me-2">Admin login</a>
      <a href="login_vendor.php" class="btn btn-secondary">Vendor login</a>
    </p>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
