<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_once '/var/www/src/audit.php';
require_role('admin');
$pdo = DB::pdo();
$basket_id = isset($_GET['basket_id']) ? (int)$_GET['basket_id'] : 0;
if (!$basket_id) { echo 'Missing basket'; exit; }
$stmt = $pdo->prepare('SELECT * FROM baskets WHERE id=?'); $stmt->execute([$basket_id]); $basket = $stmt->fetch();
if (!$basket) { echo 'Basket not found'; exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) die('Invalid CSRF');
    if (!empty($_FILES['csv']['tmp_name'])) {
        $f = fopen($_FILES['csv']['tmp_name'], 'r');
        $header = fgetcsv($f);
        while (($row = fgetcsv($f)) !== false) {
            $data = array_map('trim', $row);
            $title = $data[0] ?? '';
            if (!$title) continue;
            $authors = $data[1] ?? '';
            $publisher = $data[2] ?? '';
            $isbn = $data[3] ?? '';
            $volume = $data[4] ?? null;
            $copies = isset($data[5]) && is_numeric($data[5]) ? (int)$data[5] : 1;
            $ins = $pdo->prepare('INSERT INTO books (basket_id, title, authors, publisher, isbn, volume, copies_required) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$basket_id, $title, $authors, $publisher, $isbn, $volume, $copies]);
            $book_id = $pdo->lastInsertId();
            audit_log('ADD_BOOK','book',$book_id,null,['title'=>$title,'authors'=>$authors,'publisher'=>$publisher,'isbn'=>$isbn,'volume'=>$volume,'copies'=>$copies]);
        }
        fclose($f);
    }
}
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
  <h3>Upload Books for <?php echo htmlspecialchars($basket['bid_code']); ?></h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <div class="mb-3">
      <label class="form-label">CSV file (title,authors,publisher,isbn,volume,copies_required)</label>
      <input type="file" name="csv" class="form-control">
    </div>
    <button class="btn btn-primary">Upload</button>
    <a href="dashboard.php" class="btn btn-secondary">Back</a>
  </form>

  <h4 class="mt-4">Books</h4>
  <table class="table table-striped">
    <thead><tr><th>Title</th><th>Authors</th><th>Publisher</th><th>ISBN</th><th>Volume</th><th>Copies</th></tr></thead>
    <tbody>
<?php foreach ($books as $b): ?>
<tr>
  <td><?php echo htmlspecialchars($b['title']); ?></td>
  <td><?php echo htmlspecialchars($b['authors']); ?></td>
  <td><?php echo htmlspecialchars($b['publisher']); ?></td>
  <td><?php echo htmlspecialchars($b['isbn']); ?></td>
  <td><?php echo htmlspecialchars($b['volume']); ?></td>
  <td><?php echo (int)$b['copies_required']; ?></td>
</tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
