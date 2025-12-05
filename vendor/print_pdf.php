<?php
// /var/www/html/bidder/vendor/print_pdf.php
// Generate PDF of submitted quotes for a vendor and basket (Dompdf, manual autoload).

// basic protections and includes
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';

require_role('vendor');
$user = current_user();
$pdo = DB::pdo();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// quick debug log
error_log("print_pdf.php started for vendor_id={$user['id']}");

// basket id
$basket_id = isset($_GET['basket_id']) ? (int)$_GET['basket_id'] : 0;
if (!$basket_id) { http_response_code(400); echo 'Missing basket'; exit; }

// fetch basket
$stmt = $pdo->prepare('SELECT * FROM baskets WHERE id = ?');
$stmt->execute([$basket_id]);
$basket = $stmt->fetch();
if (!$basket) { http_response_code(404); echo 'Basket not found'; exit; }

// fetch quotes
$stmt = $pdo->prepare('SELECT q.*, b.title, b.authors, b.publisher, b.isbn, b.volume, b.copies_required
                       FROM quotes q
                       JOIN books b ON q.book_id=b.id
                       WHERE q.basket_id=? AND q.vendor_id=?
                       ORDER BY b.title ASC');
$stmt->execute([$basket_id, $user['id']]);
$rows = $stmt->fetchAll();

// safe vendor info
$vendor_name  = !empty($user['vendor_name']) ? $user['vendor_name'] : ($user['username'] ?? '');
$vendor_email = $user['email'] ?? '';
$vendor_phone = $user['phone'] ?? '';

// compute last submission date for footer (use latest submitted_at)
$stmtSub = $pdo->prepare('SELECT MAX(submitted_at) AS last_sub FROM quotes WHERE basket_id = ? AND vendor_id = ?');
$stmtSub->execute([$basket_id, $user['id']]);
$sr = $stmtSub->fetch();
$submission_date = $sr && $sr['last_sub'] ? date('Y-m-d H:i', strtotime($sr['last_sub'])) : date('Y-m-d H:i');

// --- vendorInfo: read session + DB (same logic as quote.php) ---
$vendorInfo = [
    'vendor_name' => $user['vendor_name'] ?? $user['username'] ?? '',
    'email'       => $user['email'] ?? '',
    'phone'       => $user['phone'] ?? ''
];

$stmtu = $pdo->prepare('SELECT vendor_name, email, phone FROM users WHERE id = ? LIMIT 1');
$stmtu->execute([$user['id']]);
$vu = $stmtu->fetch();
if ($vu) {
    $vendorInfo['vendor_name'] = $vu['vendor_name'] ?: $vendorInfo['vendor_name'];
    $vendorInfo['email']       = $vu['email'] ?: $vendorInfo['email'];
    $vendorInfo['phone']       = $vu['phone'] ?: $vendorInfo['phone'];
}

// canonical scalar fallbacks used in header
$vendor_name  = $vendorInfo['vendor_name'];
$vendor_email = $vendorInfo['email'];
$vendor_phone = $vendorInfo['phone'];


// Build the HTML for the PDF
$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page { size: A4 landscape; margin: 10mm; }
body{ font-family: DejaVu Sans, Arial, sans-serif; font-size:11px; color:#000; }
.header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.header .left { text-align:left; }
.header .right { text-align:right; }
table{ border-collapse:collapse; width:100%; table-layout: fixed; font-size:8px; }
th, td{ border:1px solid #ccc; padding:6px; vertical-align:top; word-wrap:break-word; word-break:break-word; white-space:normal; hyphens:auto; }
th{ background:#f3f3f3; font-weight:600; }
.small { font-size:9px; color:#555; }
/* Column widths tuned for A4 landscape */
.col-title { width:16%; }
.col-authors { width:10%; }
.col-pub { width:10%; }
.col-isbn { width:6%; }
.col-copies { width:4%; }
.col-base { width:7%; text-align:right; }
.col-curr { width:4%; text-align:left; }
.col-inr { width:7%; text-align:right; }
.col-gross { width:7%; text-align:right; }
.col-discpct { width:5%; text-align:right; }
.col-discamt { width:5%; text-align:right; }
.col-net { width:7%; text-align:right; }
.col-supply { width:4%; text-align:center; }
.col-remarks { width:4%; } 
</style></head><body>';

// --- Header: vendor name + optional email/phone ---
$html .= '<div class="header"><div class="left">';

$displayName = !empty($vendor_name) ? $vendor_name : (!empty($vendorInfo['vendor_name']) ? $vendorInfo['vendor_name'] : ($user['username'] ?? 'Vendor'));
$html .= '<strong>' . htmlspecialchars($displayName) . '</strong><br>';

// only show email/phone if values exist
$displayEmail = $vendor_email ?? ($vendorInfo['email'] ?? ($user['email'] ?? ''));
$displayPhone = $vendor_phone ?? ($vendorInfo['phone'] ?? ($user['phone'] ?? ''));

if (!empty($displayEmail)) {
    $html .= '<span class="small">Email: ' . htmlspecialchars($displayEmail) . '</span><br>';
}
if (!empty($displayPhone)) {
    $html .= '<span class="small">Phone: ' . htmlspecialchars($displayPhone) . '</span>';
}

$html .= '</div><div class="right">';
$html .= '<strong>Basket:</strong> ' . htmlspecialchars($basket['bid_code']) . '<br><span class="small">' . htmlspecialchars($basket['title']) . '</span>';
$html .= '</div></div>';
// --- end header ---


// Table header (now includes Authors, ISBN, Volume; remarks reduced)
$html .= '<table><thead><tr>
<th class="col-title">Title</th>
<th class="col-authors">Author(s)</th>
<th class="col-pub">Publisher</th>
<th class="col-isbn">ISBN</th>
<th class="col-copies">Copies</th>
<th class="col-base">Base Price</th>
<th class="col-curr">Curr</th>
<th class="col-inr">INR</th>
<th class="col-gross">Gross</th>
<th class="col-discpct">Disc %</th>
<th class="col-discamt">Disc Amt</th>
<th class="col-net">Net Payable</th>
<th class="col-supply">Supply Days</th>
<th class="col-remarks">Remarks</th>
</tr></thead><tbody>';

if (empty($rows)) {
    // colspan should match number of columns (13)
    $html .= '<tr><td colspan="13">No quotes submitted for this basket.</td></tr>';
} else {
    foreach ($rows as $q) {
        $html .= '<tr>';
        $html .= '<td>' . nl2br(htmlspecialchars($q['title'])) . '</td>';
        $html .= '<td>' . nl2br(htmlspecialchars($q['authors'])) . '</td>';
    	$html .= '<td>' . nl2br(htmlspecialchars($q['publisher'])) . '</td>';
        $html .= '<td>' . nl2br(htmlspecialchars($q['isbn'])) . '</td>';
    	$html .= '<td>' . nl2br(htmlspecialchars($q['copies_required'])) . '</td>';
        $html .= '<td style="text-align:right;">' . number_format((float)$q['base_price'], 2) . '</td>';
        $html .= '<td style="text-align:center;">' . htmlspecialchars($q['currency_code']) . '</td>';
        $html .= '<td style="text-align:right;">' . number_format((float)$q['inr_price'], 2) . '</td>';
        $html .= '<td style="text-align:right;">' . number_format((float)$q['gross_price'], 2) . '</td>';
        $html .= '<td style="text-align:right;">' . number_format((float)$q['discount_percent'], 2) . '</td>';
        $html .= '<td style="text-align:right;">' . number_format((float)$q['discount_amount'], 2) . '</td>';
        $html .= '<td style="text-align:right;">' . number_format((float)$q['net_payable'], 2) . '</td>';
        $html .= '<td style="text-align:center;">' . htmlspecialchars($q['supply_time_days']) . '</td>';
        // remarks smaller column; enable line breaks for long remarks
        $html .= '<td>' . nl2br(htmlspecialchars($q['vendor_remarks'])) . '</td>';
        $html .= '</tr>';
    }
}

$html .= '</tbody></table>';

$html .= '<div style="margin-top:8px;font-size:10px;color:#333;">';
$html .= 'Submission Date: ' . htmlspecialchars($submission_date) . ' &nbsp; | &nbsp; Vendor: ' . htmlspecialchars($displayName);
$html .= '</div>';

// close body/html
$html .= '</body></html>';

// ----------------- Dompdf autoload -----------------
// Prefer a local dompdf autoloader or composer; try several likely paths
$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',            // composer path relative
    __DIR__ . '/../../dompdf/autoload.inc.php',        // relative to current script
    '/var/www/html/bidder/dompdf/autoload.inc.php',    // absolute path (explicit)
];

$found = false;
foreach ($autoloadPaths as $p) {
    if (file_exists($p) && is_readable($p)) {
        require_once $p;
        $found = true;
        break;
    }
}
if (!$found) {
    // helpful server-side message and stop
    error_log("Dompdf autoloader missing. Checked paths: " . implode(', ', $autoloadPaths));
    http_response_code(500);
    echo "dompdf autoloader not found. Please ensure dompdf/autoload.inc.php exists and is readable by the webserver.";
    exit;
}

// ensure Dompdf class exists
if (!class_exists('Dompdf\\Dompdf')) {
    error_log("Dompdf class not found after including autoload. Please check dompdf installation.");
    http_response_code(500);
    echo "Dompdf class not found. Check server logs.";
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

// set Dompdf options (optional)
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans'); // good for Unicode

$dompdf = new Dompdf($options);

// Load and render
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Add page numbers and right-side footer via canvas in a backwards-compatible way
try {
    $canvas = $dompdf->get_canvas();

    // detect width/height methods across dompdf versions
    if (method_exists($canvas, 'get_width') && method_exists($canvas, 'get_height')) {
        $w = $canvas->get_width();
        $h = $canvas->get_height();
    } elseif (method_exists($canvas, 'get_page_width') && method_exists($canvas, 'get_page_height')) {
        $w = $canvas->get_page_width();
        $h = $canvas->get_page_height();
    } else {
        // fallback A4 landscape approx (points)
        $w = 842;
        $h = 595;
    }

    // font + size
    $font = $dompdf->getFontMetrics()->get_font('Helvetica', 'normal');
    $size = 9;

    // page count
    $pages = 1;
    if (method_exists($canvas, 'get_page_count')) {
        $pages = $canvas->get_page_count();
    } elseif (method_exists($dompdf, 'get_canvas') && method_exists($dompdf->get_canvas(), 'get_page_count')) {
        $pages = $dompdf->get_canvas()->get_page_count();
    }

    $submission_text = "Submission Date: " . $submission_date;
    $vendor_text = $vendor_name;

    for ($i = 1; $i <= $pages; $i++) {
        $pageText = "Page {$i} of {$pages}";
        $textWidth = $dompdf->getFontMetrics()->getTextWidth($pageText, $font, $size);
        $canvas->page_text(($w - $textWidth) / 2, $h - 18, $pageText, $font, $size, array(0,0,0), $i);

        $rightText = $submission_text . "   " . $vendor_text;
        // adjust x position slightly from right edge
        $canvas->page_text($w - 220, $h - 18, $rightText, $font, $size, array(0,0,0), $i);
    }
} catch (Throwable $e) {
    error_log("Error writing footer/page numbers: " . $e->getMessage());
    // continue: don't break PDF generation; footer optional
}

// Stream the PDF (clear buffers first)
try {
    while (ob_get_level() > 0) ob_end_clean();
    $filename = 'quotes_' . preg_replace('/[^A-Za-z0-9_\-]/','_', $basket['bid_code']) . '.pdf';
    $dompdf->stream($filename, ["Attachment" => 0]);
    exit;
} catch (Throwable $e) {
    error_log("Dompdf stream error: " . $e->getMessage());
    http_response_code(500);
    echo "An error occurred while generating the PDF. Check server logs for details.";
    exit;
}
?>
