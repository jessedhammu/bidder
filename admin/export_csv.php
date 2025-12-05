<?php
require_once '/var/www/src/config.php';
require_once '/var/www/src/auth.php';
require_once '/var/www/src/db.php';
require_role('admin');
$pdo = DB::pdo();
$basket_id = isset($_GET['basket_id']) ? (int)$_GET['basket_id'] : 0;

if ($basket_id) {
    $stmt = $pdo->prepare('SELECT b.*, q.*, u.vendor_name FROM books b LEFT JOIN (SELECT * FROM quotes WHERE basket_id=?) q ON b.id=q.book_id LEFT JOIN users u ON q.vendor_id=u.id WHERE b.basket_id=?');
    $stmt->execute([$basket_id, $basket_id]); $rows = $stmt->fetchAll();
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="l1_report_' . $basket_id . '.csv"'); $out = fopen('php://output','w');
    fputcsv($out, ['Book Title','Authors','Volume','Copies','Vendor','Base Price','Currency','INR Price','Gross','Discount %','Discount Amount','Net Payable','Supply Days','Vendor Remarks']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['title'],$r['authors'],$r['volume'],$r['copies_required'],$r['vendor_name'],$r['base_price'],$r['currency_code'],$r['inr_price'],$r['gross_price'],$r['discount_percent'],$r['discount_amount'],$r['net_payable'],$r['supply_time_days'],$r['vendor_remarks']]);
    }
    fclose($out); exit;
} else {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="all_quotes.csv"'); $out = fopen('php://output','w');
    fputcsv($out, ['Basket','Book Title','Volume','Vendor','Base Price','Currency','INR Price','Gross','Discount %','Discount Amount','Net Payable','Supply Days','Remarks','Submitted At']);
    $stmt = $pdo->query('SELECT b.bid_code, bk.title, bk.volume, u.vendor_name, q.* FROM quotes q JOIN baskets b ON q.basket_id=b.id JOIN books bk ON q.book_id=bk.id JOIN users u ON q.vendor_id=u.id ORDER BY b.published_at DESC');
    foreach ($stmt->fetchAll() as $r) { fputcsv($out, [$r['bid_code'],$r['title'],$r['volume'],$r['vendor_name'],$r['base_price'],$r['currency_code'],$r['inr_price'],$r['gross_price'],$r['discount_percent'],$r['discount_amount'],$r['net_payable'],$r['supply_time_days'],$r['vendor_remarks'],$r['submitted_at']]); }
    fclose($out); exit;
}
