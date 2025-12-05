<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';

require_role('vendor');
$user = current_user();
$pdo = DB::pdo();
$stmt = $pdo->query('SELECT id, bid_code, title, submission_deadline FROM baskets WHERE status = "open" ORDER BY published_at DESC');
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
    <h2>Welcome, <?php echo htmlspecialchars($user['vendor_name'] ?? $user['username']); ?></h2>
    <div><a href="../logout.php" class="btn btn-outline-secondary">Logout</a></div>
  </div>

  <h4>Open Baskets</h4>
  <table class="table table-striped">
    <thead><tr><th>Bid Code</th><th>Title</th><th>Deadline</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($baskets as $b): ?>
    <tr>
      <td><?php echo htmlspecialchars($b['bid_code']); ?></td>
      <td><?php echo htmlspecialchars($b['title']); ?></td>
      <td><?php echo htmlspecialchars($b['submission_deadline']); ?></td>
      <td><a href="quote.php?basket_id=<?php echo $b['id']; ?>" class="btn btn-sm btn-primary">Submit/View Quotes</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
