<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_once '/var/www/src/helpers.php';
require_once '/var/www/src/audit.php';
require_role('admin');
$pdo = DB::pdo();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) $err = 'Invalid CSRF';
    else {
        $title = $_POST['title'] ?? '';
        $deadline = $_POST['submission_deadline'] ?? '';
        $notes = $_POST['notes'] ?? '';
        if (!$deadline) $err = 'Deadline required';
        else {
            $bid_code = generate_bid_code();
            $stmt = $pdo->prepare('INSERT INTO baskets (bid_code, title, created_by, submission_deadline, notes) VALUES (?,?,?,?,?)');
            $stmt->execute([$bid_code, $title, $_SESSION['user']['id'], $deadline, $notes]);
            $basket_id = $pdo->lastInsertId();
            audit_log('CREATE_BASKET','basket',$basket_id,null,['title'=>$title,'deadline'=>$deadline]);
            header('Location: dashboard.php'); exit;
        }
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
  <h3>Create Basket</h3>
  <?php if ($err) echo '<div class="alert alert-danger">'.htmlspecialchars($err).'</div>'; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <div class="mb-3"><label class="form-label">Title</label><input name="title" class="form-control"></div>
    <div class="mb-3"><label class="form-label">Submission deadline (YYYY-MM-DD HH:MM:SS)</label><input name="submission_deadline" class="form-control"></div>
    <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control"></textarea></div>
    <button type="submit" class="btn btn-primary">Create</button>
    <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
  </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
