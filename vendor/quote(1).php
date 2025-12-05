<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_once '/var/www/src/helpers.php';
require_once '/var/www/src/audit.php';

require_role('vendor');
$user = current_user();
$pdo = DB::pdo();

// Fetch vendor details (name, email, phone) for print header
$vendorInfo = [
    'vendor_name' => $user['vendor_name'] ?? $user['username'] ?? '',
    'email' => $user['email'] ?? '',
    'phone' => $user['phone'] ?? ''
];
$stmtu = $pdo->prepare('SELECT vendor_name, email, phone FROM users WHERE id = ? LIMIT 1');
$stmtu->execute([$user['id']]);
$vu = $stmtu->fetch();
if ($vu) {
    $vendorInfo['vendor_name'] = $vu['vendor_name'] ?: $vendorInfo['vendor_name'];
    $vendorInfo['email'] = $vu['email'] ?: $vendorInfo['email'];
    $vendorInfo['phone'] = $vu['phone'] ?: $vendorInfo['phone'];
}

$basket_id = isset($_GET['basket_id']) ? (int)$_GET['basket_id'] : 0;
if (!$basket_id) { echo 'Missing basket'; exit; }

$stmt = $pdo->prepare('SELECT * FROM baskets WHERE id = ?'); $stmt->execute([$basket_id]);
$basket = $stmt->fetch(); if (!$basket) { echo 'Basket not found'; exit; }
$deadline = strtotime($basket['submission_deadline']);
$now = time();
$editable = ($now <= $deadline) && $basket['status'] === 'open';

$stmt = $pdo->prepare('SELECT * FROM books WHERE basket_id = ?'); $stmt->execute([$basket_id]);
$books = $stmt->fetchAll();

// Fetch list of currency codes entered by admin (latest effective rate per currency)
$currencyCodes = [];
$rates = [];
$stmtc = $pdo->query("SELECT currency_code, rate_to_inr, effective_date FROM currency_rates ORDER BY currency_code, effective_date DESC");
foreach ($stmtc->fetchAll() as $r) {
    $cc = $r['currency_code'];
    if (!isset($rates[$cc])) {
        $rates[$cc] = (float)$r['rate_to_inr'];
        $currencyCodes[] = $cc;
    }
}
if (!in_array('INR', $currencyCodes)) {
    $currencyCodes[] = 'INR';
    if (!isset($rates['INR'])) $rates['INR'] = 1.0;
}

// Determine if we are showing the "submitted-only" screen after save
$show_submitted_only = isset($_GET['submitted']) && ($_GET['submitted'] == '1');

// get last submission datetime for this vendor & basket (for footer)
$submission_date = null;
$stmtSub = $pdo->prepare('SELECT MAX(submitted_at) AS last_sub FROM quotes WHERE basket_id = ? AND vendor_id = ?');
$stmtSub->execute([$basket_id, $user['id']]);
$sr = $stmtSub->fetch();
if ($sr && $sr['last_sub']) $submission_date = $sr['last_sub'];
else $submission_date = date('Y-m-d H:i:s');

// Handle POST actions: either delete (single quote delete) or Save (bulk save)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) { die('Invalid CSRF'); }

    // Delete quote (single) - still allowed if editable
    if (isset($_POST['action']) && $_POST['action'] === 'delete_quote') {
        $quote_id = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : 0;
        if (!$editable) { $err = 'Submission period is over; cannot delete'; }
        else if ($quote_id) {
            $stmtq = $pdo->prepare('SELECT * FROM quotes WHERE id = ? AND vendor_id = ? LIMIT 1');
            $stmtq->execute([$quote_id, $user['id']]);
            $qrow = $stmtq->fetch();
            if ($qrow) {
                audit_log('DELETE_QUOTE', 'quote', $quote_id, $qrow, null);
                $del = $pdo->prepare('DELETE FROM quotes WHERE id = ?');
                $del->execute([$quote_id]);
                header('Location: quote.php?basket_id=' . $basket_id);
                exit;
            } else {
                $err = 'Quote not found or not owned by you.';
            }
        }
    } else {
        // BULK SAVE: vendor clicked the single Save button at bottom.
        if (!$editable) { die('Submission period is over; cannot submit'); }

        foreach ($books as $book) {
            $bid = $book['id'];
            // skip if nothing submitted for this book
            if (!isset($_POST['base_price'][$bid]) || $_POST['base_price'][$bid] === '') continue;

            $base = floatval($_POST['base_price'][$bid]);
            if ($base <= 0) continue;

            $currency = $_POST['currency_code'][$bid] ?? 'INR';
            if (!in_array($currency, $currencyCodes)) $currency = 'INR';

            $discount = isset($_POST['discount_percent'][$bid]) ? floatval($_POST['discount_percent'][$bid]) : 0.0;
            $supply = isset($_POST['supply_time_days'][$bid]) && $_POST['supply_time_days'][$bid] !== '' ? (int)$_POST['supply_time_days'][$bid] : null;
            $remarks = isset($_POST['vendor_remarks'][$bid]) ? trim($_POST['vendor_remarks'][$bid]) : null;

            // authoritative server-side calculation (2 decimals)
            try {
                $calc = calculate_prices($base, $currency, $book['copies_required'], $discount, date('Y-m-d', strtotime($basket['published_at'])));
            } catch (Exception $e) {
                // fallback to embedded rates
                $rate = $rates[$currency] ?? 1.0;
                $inr_price_fallback = round($base * $rate, 2);
                $gross_fallback = round($inr_price_fallback * $book['copies_required'], 2);
                $discount_amount_fallback = round($gross_fallback * ($discount / 100.0), 2);
                $net_fallback = round($gross_fallback - $discount_amount_fallback, 2);
                $calc = [
                    'inr_price' => $inr_price_fallback,
                    'gross_price' => $gross_fallback,
                    'discount_amount' => $discount_amount_fallback,
                    'net_payable' => $net_fallback
                ];
            }

            // upsert
            $stmtq = $pdo->prepare('SELECT * FROM quotes WHERE basket_id = ? AND book_id = ? AND vendor_id = ?');
            $stmtq->execute([$basket_id, $bid, $user['id']]);
            $existing = $stmtq->fetch();
            $after = [
                'base_price' => $base,
                'currency_code' => $currency,
                'inr_price' => $calc['inr_price'],
                'copies' => $book['copies_required'],
                'gross_price' => $calc['gross_price'],
                'discount_percent' => $discount,
                'discount_amount' => $calc['discount_amount'],
                'net_payable' => $calc['net_payable'],
                'supply_time_days' => $supply,
                'vendor_remarks' => $remarks
            ];
            if ($existing) {
                $before = $existing;
                $qstmt = $pdo->prepare('UPDATE quotes SET base_price=?, currency_code=?, inr_price=?, copies=?, gross_price=?, discount_percent=?, discount_amount=?, net_payable=?, supply_time_days=?, vendor_remarks=?, submitted_at=NOW() WHERE id=?');
                $qstmt->execute([$base, $currency, $calc['inr_price'], $book['copies_required'], $calc['gross_price'], $discount, $calc['discount_amount'], $calc['net_payable'], $supply, $remarks, $existing['id']]);
                audit_log('UPDATE_QUOTE', 'quote', $existing['id'], $before, $after);
            } else {
                $qstmt = $pdo->prepare('INSERT INTO quotes (basket_id, book_id, vendor_id, base_price, currency_code, inr_price, copies, gross_price, discount_percent, discount_amount, net_payable, supply_time_days, vendor_remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $qstmt->execute([$basket_id, $bid, $user['id'], $base, $currency, $calc['inr_price'], $book['copies_required'], $calc['gross_price'], $discount, $calc['discount_amount'], $calc['net_payable'], $supply, $remarks]);
                $newId = $pdo->lastInsertId();
                audit_log('CREATE_QUOTE', 'quote', $newId, null, $after);
            }
        }

        // Redirect to submitted-only view
        header('Location: quote.php?basket_id=' . $basket_id . '&submitted=1');
        exit;
    }
}

// fetch vendor's existing quotes for this basket (to show in the submitted table or prefill form)
$vendorQuotes = [];
$stmt = $pdo->prepare('SELECT * FROM quotes WHERE basket_id = ? AND vendor_id = ?');
$stmt->execute([$basket_id, $user['id']]);
foreach ($stmt->fetchAll() as $q) $vendorQuotes[$q['book_id']] = $q;

// If a vendor clicked an Edit link previously, we still keep $edit_book behavior for prefill when not in submitted-only screen
$edit_book_id = isset($_GET['edit_book']) ? (int)$_GET['edit_book'] : 0;
$edit_quote = null;
if ($edit_book_id && isset($vendorQuotes[$edit_book_id])) {
    $edit_quote = $vendorQuotes[$edit_book_id];
    if ($show_submitted_only) $edit_quote = null;
}

?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Submit Quotes - Vendor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .calc-cell { background: #f8f9fa; }
  .small-muted { font-size:0.9rem; color:#6c757d; }

  /* LANDSCAPE print and layout */
  @page {
    size: A4 landscape;
    margin: 12mm 10mm;
  }

/* LANDSCAPE print and layout */
@page {
  size: A4 landscape;
  margin: 12mm 10mm;
}

@media print {
  body { font-size: 11px; color: #000; }
  .no-print { display: none !important; }
  .form-container { display: none !important; }
  .submitted-area { display: block !important; }

  /* make table fit: fixed layout, smaller font, wrap words */
  table { table-layout: fixed !important; width: 100% !important; font-size: 10px; }
  table th, table td { word-break: break-word; white-space: normal; padding: 6px; vertical-align: top; }

  /* prevent scrollbars in print */
  .table-responsive { overflow: visible !important; }

  /* print header and footer are fixed so they appear on every page */
  .print-header { position: fixed; top: 0; left: 0; right: 0; height: 60px; padding: 6px 12px; border-bottom: 1px solid #ddd; background: #fff; display: block; }
  .print-footer { position: fixed; bottom: 0; left: 0; right: 0; height: 44px; padding: 6px 12px; border-top: 1px solid #ddd; background: #fff; display: block; }

  .printed-content { margin-top: 80px; margin-bottom: 56px; }

  /* center page number element */
  .print-footer .center { position: absolute; left: 50%; transform: translateX(-50%); top: 8px; font-weight:600; }

  /* right side info (submission date and vendor name) */
  .print-footer .right { position: absolute; right: 12px; top: 6px; text-align: right; font-size: 11px; }

  /* Page numbering: current page from counter(page). counter(pages) is not supported everywhere. */
  /* This will show "Page X" reliably; "Page X of Y" may not show Y in some browsers. */
  .page-number:after { content: "Page " counter(page); }

  /* Attempt total pages - not universally supported */
  .page-number-total:after { content: "Page " counter(page) " of " counter(pages); }

}


  /* Desktop layout tweaks: responsive table min widths (but print will force fixed table) */
  .table-wider { min-width: 1200px; }
  .table-wider-sub { min-width: 1100px; }
</style>
</head>
<body class="p-4">
<div class="container-fluid"><!-- wider layout -->

  <!-- print header (hidden on screen, shown on print via @media print) -->
  <div class="print-header" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <div>
        <strong><?php echo htmlspecialchars($vendorInfo['vendor_name']); ?></strong><br>
        <span style="font-size:12px;"><?php echo htmlspecialchars($vendorInfo['email']); ?> | <?php echo htmlspecialchars($vendorInfo['phone']); ?></span>
      </div>
      <div style="text-align:right;">
        <span style="font-size:14px;"><strong>Basket:</strong> <?php echo htmlspecialchars($basket['bid_code']); ?></span><br>
        <span style="font-size:12px;"><?php echo htmlspecialchars($basket['title']); ?></span>
      </div>
    </div>
  </div>

  <!-- print footer (hidden on screen, shown on print) -->
  <div class="print-footer" style="display:none;">
    <div class="center"><span class="page-number"></span></div>
    <div class="right">
      <div><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($submission_date))); ?></div>
      <div><?php echo htmlspecialchars($vendorInfo['vendor_name']); ?></div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h3>Basket: <?php echo htmlspecialchars($basket['bid_code'] . ' - ' . $basket['title']); ?></h3>
    <div><a class="btn btn-outline-secondary" href="../logout.php">Logout</a></div>
  </div>

  <p class="no-print">Deadline: <?php echo htmlspecialchars($basket['submission_deadline']); ?> |
    <?php echo $editable ? '<span class="badge bg-success">Open</span>' : '<span class="badge bg-danger">Closed</span>'; ?>
  </p>

<?php if (!$show_submitted_only && $editable): ?>
  <!-- BULK form: vendor fills all rows and clicks single Save -->
  <div class="form-container">
  <form method="post" id="quotesForm">
  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
  <div class="table-responsive no-print"><!-- allows horizontal scroll if needed on screen -->
  <table class="table table-bordered table-wider" style="width:100%;">
  <thead><tr>
    <th>Title</th><th>Authors</th><th>Publisher</th><th>ISBN</th><th>Volume</th><th>Copies</th>
    <th>Base Price</th><th>Currency</th>
    <th>INR Price</th><th>Gross Price</th><th>Discount %</th><th>Discount Amount</th><th>Net Payable</th>
    <th>Supply Days</th><th>Vendor Remarks</th>
  </tr></thead>
  <tbody>
  <?php foreach ($books as $b):
      $pref = $vendorQuotes[$b['id']] ?? null;
      $is_editing_this = ($edit_quote && $edit_quote['book_id'] == $b['id']);
      $pref_base = $is_editing_this ? $edit_quote['base_price'] : ($pref['base_price'] ?? '');
      $pref_curr = $is_editing_this ? $edit_quote['currency_code'] : ($pref['currency_code'] ?? 'INR');
      $pref_disc = $is_editing_this ? $edit_quote['discount_percent'] : ($pref['discount_percent'] ?? '0');
      $pref_supply = $is_editing_this ? $edit_quote['supply_time_days'] : ($pref['supply_time_days'] ?? '');
      $pref_remarks = $is_editing_this ? $edit_quote['vendor_remarks'] : ($pref['vendor_remarks'] ?? '');
  ?>
  <tr id="bookrow-<?php echo $b['id']; ?>" data-copies="<?php echo (int)$b['copies_required']; ?>">
    <td><?php echo htmlspecialchars($b['title']); ?></td>
    <td><?php echo htmlspecialchars($b['authors']); ?></td>
    <td><?php echo htmlspecialchars($b['publisher']); ?></td>
    <td><?php echo htmlspecialchars($b['isbn']); ?></td>
    <td><?php echo htmlspecialchars($b['volume']); ?></td>
    <td class="small-muted"><?php echo (int)$b['copies_required']; ?></td>

    <td><input name="base_price[<?php echo $b['id']; ?>]" id="base_<?php echo $b['id']; ?>" value="<?php echo htmlspecialchars($pref_base); ?>" class="form-control input-num"></td>

    <td>
      <select name="currency_code[<?php echo $b['id']; ?>]" id="curr_<?php echo $b['id']; ?>" class="form-select currency-select">
        <?php
          $sel = $pref_curr ?? 'INR';
          foreach ($currencyCodes as $cc) {
              $s = ($cc === $sel) ? 'selected' : '';
              echo '<option value="' . htmlspecialchars($cc) . '" ' . $s . '>' . htmlspecialchars($cc) . '</option>';
          }
        ?>
      </select>
    </td>

    <td class="calc-cell" id="inr_<?php echo $b['id']; ?>">-</td>
    <td class="calc-cell" id="gross_<?php echo $b['id']; ?>">-</td>
    <td><input name="discount_percent[<?php echo $b['id']; ?>]" id="disc_<?php echo $b['id']; ?>" value="<?php echo htmlspecialchars($pref_disc); ?>" class="form-control input-num"></td>
    <td class="calc-cell" id="discamt_<?php echo $b['id']; ?>">-</td>
    <td class="calc-cell" id="net_<?php echo $b['id']; ?>">-</td>

    <td><input name="supply_time_days[<?php echo $b['id']; ?>]" value="<?php echo htmlspecialchars($pref_supply); ?>" class="form-control"></td>
    <td><input name="vendor_remarks[<?php echo $b['id']; ?>]" value="<?php echo htmlspecialchars($pref_remarks); ?>" class="form-control"></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>

  <div class="d-flex justify-content-end mt-2 no-print">
    <button class="btn btn-success" id="saveAllBtn" type="submit">Save & Submit All Quotes</button>
  </div>
  </form>
  </div>
<?php else: ?>
  <!-- Submitted-only view or basket not editable: show submitted quotes with print button -->
  <div class="d-flex justify-content-between align-items-center mb-2 no-print">
    <h5>Your submitted quotes for this basket</h5>
    <div>
      <!-- Print button at top -->
      <button class="btn btn-outline-primary" onclick="doPrint()">Print</button>
      <a class="btn btn-secondary" href="quote.php?basket_id=<?php echo $basket_id; ?>">Return</a>
    </div>
  </div>
<?php endif; ?>

<!-- Submitted quotes table (always visible) -->
<h4 class="mt-4 no-print">Your Submitted Quotes</h4>
<div class="table-responsive submitted-area">
<table class="table table-striped table-wider-sub printed-content" style="width:100%;">
<thead><tr><th>Title</th><th>Base</th><th>Currency</th><th>INR Price</th><th>Gross</th><th>Discount %</th><th>Discount Amount</th><th>Net</th><th>Supply</th><th>Remarks</th>
<?php if ($editable): ?><th class="no-print">Actions</th><?php endif; ?>
</tr></thead>
<tbody>
<?php
$stmt = $pdo->prepare('SELECT q.*, b.title FROM quotes q JOIN books b ON q.book_id=b.id WHERE q.basket_id=? AND q.vendor_id=?');
$stmt->execute([$basket_id, $user['id']]);
$rows = $stmt->fetchAll();
foreach ($rows as $q):
?>
<tr>
  <td><?php echo htmlspecialchars($q['title']); ?></td>
  <td><?php echo number_format((float)$q['base_price'], 2); ?></td>
  <td><?php echo htmlspecialchars($q['currency_code']); ?></td>
  <td><?php echo number_format((float)$q['inr_price'], 2); ?></td>
  <td><?php echo number_format((float)$q['gross_price'], 2); ?></td>
  <td><?php echo number_format((float)$q['discount_percent'], 2); ?></td>
  <td><?php echo number_format((float)$q['discount_amount'], 2); ?></td>
  <td><?php echo number_format((float)$q['net_payable'], 2); ?></td>
  <td><?php echo htmlspecialchars($q['supply_time_days']); ?></td>
  <td><?php echo htmlspecialchars($q['vendor_remarks']); ?></td>

  <?php if ($editable): ?>
  <td class="no-print">
    <!-- Edit: prefill row -->
    <a class="btn btn-sm btn-outline-primary" href="quote.php?basket_id=<?php echo $basket_id; ?>&edit_book=<?php echo $q['book_id']; ?>">Edit</a>

    <!-- Delete single quote (allowed when basket is open) -->
    <form method="post" style="display:inline-block" onsubmit="return confirm('Delete this quote?');">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="action" value="delete_quote">
      <input type="hidden" name="quote_id" value="<?php echo (int)$q['id']; ?>">
      <button class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
  </td>
  <?php endif; ?>

</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Print button at bottom for convenience (visible only when not printing) -->
<div class="d-flex justify-content-end my-3 no-print">
  <button class="btn btn-primary" onclick="doPrint()">Print Submitted Quotes</button>
<!-- replace Print button -->
<button class="btn btn-outline-primary" onclick="window.open('print_pdf.php?basket_id=<?php echo $basket_id; ?>','_blank')">PDF</button>

</div>

</div>

<script>
// Embedded currency rates (from server) for client-side calculations
const currencyRates = <?php echo json_encode($rates, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

// vendor info for header (not editable)
const vendorHeader = {
  name: <?php echo json_encode($vendorInfo['vendor_name']); ?>,
  email: <?php echo json_encode($vendorInfo['email']); ?>,
  phone: <?php echo json_encode($vendorInfo['phone']); ?>,
  basket: <?php echo json_encode($basket['bid_code']); ?>
};

// rounding helper: two decimals
function roundVal(v) { return (Math.round((v + Number.EPSILON) * 100) / 100).toFixed(2); }

function processRow(bookId) {
    const baseEl = document.getElementById('base_' + bookId);
    const currEl = document.getElementById('curr_' + bookId);
    const discEl = document.getElementById('disc_' + bookId);
    const inrCell = document.getElementById('inr_' + bookId);
    const grossCell = document.getElementById('gross_' + bookId);
    const discAmtCell = document.getElementById('discamt_' + bookId);
    const netCell = document.getElementById('net_' + bookId);
    const row = document.getElementById('bookrow-' + bookId);
    if (!row) return;
    const copies = parseInt(row.getAttribute('data-copies')) || 1;

    const base = parseFloat(baseEl.value) || 0;
    const currency = currEl.value || 'INR';
    const discount = parseFloat(discEl.value) || 0;

    const rate = parseFloat(currencyRates[currency] || 1.0);
    const inrPrice = base * rate;
    const gross = inrPrice * copies;
    const discAmount = gross * (discount / 100.0);
    const net = gross - discAmount;

    inrCell.textContent = roundVal(inrPrice);
    grossCell.textContent = roundVal(gross);
    discAmtCell.textContent = roundVal(discAmount);
    netCell.textContent = roundVal(net);
}

document.addEventListener('DOMContentLoaded', function() {
    // calculate for all visible rows (if the form is present)
    const rows = document.querySelectorAll('tr[id^="bookrow-"]');
    rows.forEach(function(row) {
        const id = row.id.replace('bookrow-', '');
        ['base_' + id, 'curr_' + id, 'disc_' + id].forEach(function(elemId) {
            const el = document.getElementById(elemId);
            if (!el) return;
            el.addEventListener('input', function() { processRow(id); });
            el.addEventListener('change', function() { processRow(id); });
        });
        processRow(id);
    });

    // prevent double submits: disable Save button after click (for the single Save btn)
    const saveBtn = document.getElementById('saveAllBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';
        });
    }
});

// print helper: set header/footer visible briefly then call print
function doPrint() {
    // populate print header/footer then show them
    var header = document.querySelector('.print-header');
    var footer = document.querySelector('.print-footer');
    if (header) {
        header.style.display = 'block';
        header.innerHTML = '<div style="display:flex; justify-content:space-between; align-items:center;">' +
            '<div><strong>' + escapeHtml(vendorHeader.name) + '</strong><br><span style="font-size:12px;">' + escapeHtml(vendorHeader.email) + ' | ' + escapeHtml(vendorHeader.phone) + '</span></div>' +
            '<div style="text-align:right;"><span style="font-size:14px;"><strong>Basket:</strong> ' + escapeHtml(vendorHeader.basket) + '</span></div>' +
            '</div>';
    }
	if (footer) {
    	footer.style.display = 'block';
    	// center page number element; actual page number rendered by CSS counter(page)
    	footer.innerHTML = '<div class="center"><span class="page-number"></span></div>' +
                   '<div class="right">Submission Date: <?php echo htmlspecialchars(date("Y-m-d H:i", strtotime($submission_date))); ?><br><?php echo htmlspecialchars($vendorInfo["vendor_name"]); ?></div>';

		}


    // Small timeout to ensure DOM updated before print
    setTimeout(function() {
        window.print();
        // hide header/footer after print dialog closed
        if (header) header.style.display = 'none';
        if (footer) footer.style.display = 'none';
    }, 250);
}

function escapeHtml(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
