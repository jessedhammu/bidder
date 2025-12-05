<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_once '/var/www/src/audit.php';

require_role('admin');
$pdo = DB::pdo();
$stmt = $pdo->query('SELECT * FROM baskets ORDER BY published_at DESC');
$baskets = $stmt->fetchAll();
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
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Admin Dashboard</h2>
    <div><a href="../logout.php" class="btn btn-outline-secondary">Logout</a></div>
  </div>

  <div class="mb-3">
    <a href="create_basket.php" class="btn btn-primary me-2">Create new basket</a>
    <a href="set_rates.php" class="btn btn-secondary me-2">Set currency rates</a>
    <a href="export_csv.php" class="btn btn-success me-2">Export CSV</a>
    <a href="audit_logs.php" class="btn btn-warning">Audit Logs</a>
  </div>

  <h4>Baskets</h4>
  <table class="table table-bordered">
    <thead><tr><th>Bid Code</th><th>Title</th><th>Deadline</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
<?php foreach ($baskets as $b): ?>
<tr>
  <td><?php echo htmlspecialchars($b['bid_code']); ?></td>
  <td><?php echo htmlspecialchars($b['title']); ?></td>
  <td><?php echo htmlspecialchars($b['submission_deadline']); ?></td>
  <td><?php echo htmlspecialchars($b['status']); ?></td>
  <td>
    <a href="upload_books.php?basket_id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-primary">Upload/Manage Books</a>
    <a href="view_results.php?basket_id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-success">View Results</a>
    <?php if ($b['status']==='open'): ?>
      <a href="close_basket.php?basket_id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-danger">Close</a>
    <?php else: ?>
      <a href="close_basket.php?basket_id=<?php echo $b['id']; ?>&action=open" class="btn btn-sm btn-outline-warning">Re-open</a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
