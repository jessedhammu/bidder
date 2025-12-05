<?php
// public/admin/set_rates.php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';

require_role('admin');
$pdo = DB::pdo();

$msg = '';
$err = '';

// Handle POST actions: create or update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) {
        $err = 'Invalid CSRF token';
    } else {
        $action = $_POST['action'] ?? 'create';
        if ($action === 'create') {
            $cc = strtoupper(trim($_POST['currency_code'] ?? ''));
            $rate = floatval($_POST['rate_to_inr'] ?? 0);
            $date = $_POST['effective_date'] ?? date('Y-m-d');
            if ($cc && $rate > 0) {
                $stmt = $pdo->prepare('INSERT INTO currency_rates (currency_code, rate_to_inr, effective_date) VALUES (?,?,?)');
                $stmt->execute([$cc, $rate, $date]);
                $newId = $pdo->lastInsertId();
                audit_log('CREATE_RATE', 'currency_rate', $newId, null, ['currency_code'=>$cc,'rate_to_inr'=>$rate,'effective_date'=>$date]);
                $msg = 'Rate saved';
            } else {
                $err = 'Please provide a currency code and valid rate.';
            }
        } elseif ($action === 'update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $rate = floatval($_POST['rate_to_inr'] ?? 0);
            $date = $_POST['effective_date'] ?? date('Y-m-d');
            if ($id && $rate > 0) {
                // load before for audit
                $stmtPrev = $pdo->prepare('SELECT * FROM currency_rates WHERE id = ? LIMIT 1');
                $stmtPrev->execute([$id]);
                $prev = $stmtPrev->fetch();
                if (!$prev) {
                    $err = 'Rate record not found.';
                } else {
                    $stmt = $pdo->prepare('UPDATE currency_rates SET rate_to_inr = ?, effective_date = ? WHERE id = ?');
                    $stmt->execute([$rate, $date, $id]);
                    $after = ['currency_code'=>$prev['currency_code'],'rate_to_inr'=>$rate,'effective_date'=>$date];
                    audit_log('UPDATE_RATE','currency_rate',$id,$prev,$after);
                    $msg = 'Rate updated';
                }
            } else {
                $err = 'Invalid rate or id.';
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id) {
                $stmtPrev = $pdo->prepare('SELECT * FROM currency_rates WHERE id = ? LIMIT 1');
                $stmtPrev->execute([$id]);
                $prev = $stmtPrev->fetch();
                if ($prev) {
                    $stmt = $pdo->prepare('DELETE FROM currency_rates WHERE id = ?');
                    $stmt->execute([$id]);
                    audit_log('DELETE_RATE','currency_rate',$id,$prev,null);
                    $msg = 'Rate deleted';
                } else {
                    $err = 'Rate not found';
                }
            } else {
                $err = 'Invalid id';
            }
        }
    }
}

// read rates (for listing and optionally editing)
$stmt = $pdo->query('SELECT * FROM currency_rates ORDER BY currency_code, effective_date DESC');
$rates = $stmt->fetchAll();

// if editing a specific row, get its data (via GET ?edit_id=)
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_row = null;
if ($edit_id) {
    $stmt = $pdo->prepare('SELECT * FROM currency_rates WHERE id = ? LIMIT 1');
    $stmt->execute([$edit_id]);
    $edit_row = $stmt->fetch();
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Currency Rates - Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Currency Rates</h3>
    <div>
      <a href="dashboard.php" class="btn btn-secondary">Back</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <div class="card p-3 mb-4">
    <?php if ($edit_row): ?>
      <h5>Edit Rate: <?php echo htmlspecialchars($edit_row['currency_code']); ?> (ID <?php echo $edit_row['id']; ?>)</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$edit_row['id']; ?>">
        <div class="col-md-3">
          <label class="form-label">Currency</label>
          <input class="form-control" value="<?php echo htmlspecialchars($edit_row['currency_code']); ?>" disabled>
        </div>
        <div class="col-md-3">
          <label class="form-label">Rate to INR</label>
          <input name="rate_to_inr" class="form-control" value="<?php echo htmlspecialchars($edit_row['rate_to_inr']); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Effective Date</label>
          <input name="effective_date" class="form-control" value="<?php echo htmlspecialchars($edit_row['effective_date']); ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary me-2">Save Changes</button>
          <a href="set_rates.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
      <hr>
      <form method="post" onsubmit="return confirm('Delete this rate? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?php echo (int)$edit_row['id']; ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete this rate</button>
      </form>

    <?php else: ?>
      <h5>Add New Rate</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-3">
          <label class="form-label">Currency Code (e.g. USD)</label>
          <input name="currency_code" class="form-control" placeholder="USD">
        </div>
        <div class="col-md-3">
          <label class="form-label">Rate to INR</label>
          <input name="rate_to_inr" class="form-control" placeholder="83.50">
        </div>
        <div class="col-md-3">
          <label class="form-label">Effective Date</label>
          <input name="effective_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary">Save</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <h5>Existing Rates</h5>
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th>ID</th>
        <th>Currency</th>
        <th>Rate to INR</th>
        <th>Effective Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rates as $r): ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo htmlspecialchars($r['currency_code']); ?></td>
        <td><?php echo htmlspecialchars($r['rate_to_inr']); ?></td>
        <td><?php echo htmlspecialchars($r['effective_date']); ?></td>
        <td>
          <a class="btn btn-sm btn-outline-primary" href="set_rates.php?edit_id=<?php echo (int)$r['id']; ?>">Edit</a>
          <!-- quick delete form (with confirmation) -->
          <form method="post" style="display:inline-block" onsubmit="return confirm('Delete this rate?');">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <button class="btn btn-sm btn-outline-danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
