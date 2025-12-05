<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_role('admin');
$pdo = DB::pdo();
$logs = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 1000')->fetchAll();
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
  <h3>Audit Logs</h3>
  <table class="table table-striped table-sm">
    <thead><tr><th>Date</th><th>Actor</th><th>Role</th><th>Action</th><th>Object</th><th>Before</th><th>After</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td><?php echo $l['created_at']; ?></td>
      <td><?php echo htmlspecialchars($l['actor_id']); ?></td>
      <td><?php echo htmlspecialchars($l['actor_role']); ?></td>
      <td><?php echo htmlspecialchars($l['action']); ?></td>
      <td><?php echo htmlspecialchars($l['object_type'] . ' #' . $l['object_id']); ?></td>
      <td><pre><?php echo htmlspecialchars($l['before_data']); ?></pre></td>
      <td><pre><?php echo htmlspecialchars($l['after_data']); ?></pre></td>
      <td><?php echo htmlspecialchars($l['ip_address']); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
