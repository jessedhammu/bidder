<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_once '/var/www/src/helpers.php';

require_role('admin');
$pdo = DB::pdo();
$basket_id = isset($_GET['basket_id']) ? (int)$_GET['basket_id'] : 0;
if (!$basket_id) { echo 'Missing basket'; exit; }
$stmt = $pdo->prepare('SELECT * FROM baskets WHERE id=?'); $stmt->execute([$basket_id]); $basket = $stmt->fetch();
if (!$basket) { echo 'Basket not found'; exit; }
$l1 = l1_for_basket($basket_id);
$stmt = $pdo->prepare('SELECT * FROM books WHERE basket_id=?'); $stmt->execute([$basket_id]); $books = $stmt->fetchAll();
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
  <h3>Results for <?php echo htmlspecialchars($basket['bid_code']); ?></h3>
  <p><a href="dashboard.php" class="btn btn-secondary">Back</a> <a href="export_csv.php?basket_id=<?php echo $basket_id; ?>" class="btn btn-success">Export CSV</a></p>
  <table class="table table-bordered">
    <thead><tr><th>Title</th><th>L1 Vendor</th><th>Net Payable</th><th>Supply Days</th></tr></thead>
    <tbody>
<?php foreach ($books as $b): $chosen = $l1[$b['id']] ?? null; ?>
<tr>
  <td><?php echo htmlspecialchars($b['title']); ?></td>
  <?php if ($chosen): ?>
    <td><?php echo htmlspecialchars($chosen['vendor_name']); ?></td>
    <td><?php echo htmlspecialchars($chosen['net_payable']); ?></td>
    <td><?php echo htmlspecialchars($chosen['supply_time_days']); ?></td>
  <?php else: ?>
    <td colspan="3">No quotes</td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
