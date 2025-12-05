<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/audit.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) $err = 'Invalid CSRF token';
    else {
        $u = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';
        if (login_user($u, $p)) {
            if ($_SESSION['user']['role'] !== 'vendor') { logout(); $err = 'Not a vendor account'; }
            else { audit_log('LOGIN_SUCCESS','auth',null,null,['username'=>$u]); header('Location: vendor/dashboard.php'); exit; }
        } else { $err = 'Login failed'; audit_log('LOGIN_FAILED','auth',null,null,['username'=>$u]); }
    }
}
?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Library Bidding Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>

</head>
<body class="p-4">
<div class="container">
  <div class="row justify-content-center"><div class="col-md-6">
    <div class="card p-3 shadow-sm">
      <h3>Vendor Login</h3>
      <?php if ($err) echo '<div class="alert alert-danger">'.htmlspecialchars($err).'</div>'; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control"></div>
        <button class="btn btn-primary">Login</button>
      </form>
    </div>
  </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
