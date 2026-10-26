<?php
// WAU customer portal (self-hosted) · API for the customer pages.
// Sign-in, password change, the product list, order requests and order tracking.
// Every answer is limited to the signed-in customer's own code. No prices, payments or invoices are sent.

$APP = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
require $APP . 'lib.php';
require $APP . 'data.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Never print PHP errors into the answer. A crash is written to the PHP error log and the customer gets a clear message.
ini_set('display_errors', '0');
@set_time_limit(90);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
    error_log('WAU portal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(array('ok' => false, 'error' => 'The portal had a problem loading this page. Please try again in a minute, or contact our sales team.'));
  }
});

// Names in si_wa may hold old (non UTF-8) characters: replace them instead of failing the whole answer.
define('JSON_FLAGS', JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_FLAGS); exit; }
function bad($msg, $code = 400) { out(array('ok' => false, 'error' => $msg), $code); }

try { db(); } catch (Exception $e) { bad('The portal is not available right now. Please try again later.', 503); }
start_session();

$action = isset($_GET['a']) ? (string)$_GET['a'] : '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = array();
if ($post) {
  if (!csrf_ok(isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '')) bad('Your session expired. Reload the page and try again.', 403);
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) $in = array();
}

// The signed-in user, read again on every request so a login that is turned off stops at once.
function current_user() {
  static $u = false;
  if ($u !== false) return $u;
  $u = null;
  if (!empty($_SESSION['uid'])) {
    $st = db()->prepare('SELECT id, email, name, customer, must_change, active FROM ' . E('portal_users') . ' WHERE id = ?');
    $st->execute(array((int)$_SESSION['uid']));
    $row = $st->fetch();
    if ($row && (int)$row['active']) { $u = $row; $c = customer_info($row['customer']); $u['company'] = $c ? $c['name'] : $row['customer']; $u['terms'] = $c ? $c['top'] : 0; }
    else unset($_SESSION['uid']);
  }
  return $u;
}
function need_user($allow_must_change = false) {
  $u = current_user();
  if (!$u) bad('Please sign in.', 401);
  if ((int)$u['must_change'] && !$allow_must_change) bad('Please choose a new password first.', 409);
  return $u;
}
// Order status for the customer: only what they need to follow delivery.
function order_summary($o) {
  return array('no' => $o['no'], 'date' => $o['date'], 'status' => $o['status'], 'status_date' => $o['status_date'],
    'rolls' => $o['rolls'], 'meters' => $o['meters'], 'items' => $o['items'], 'rolls_to_come' => $o['rolls_to_come'],
    'sj_numbers' => array_map(function ($s) { return $s['sj']; }, $o['sjs']));
}
function order_detail($o) {
  $d = order_summary($o); $d['history'] = $o['history'];
  $d['lines'] = array(); foreach ($o['lines'] as $l) $d['lines'][] = array('art' => $l['art'], 'color' => $l['color'], 'name' => $l['name'],
    'color_name' => $l['color_name'], 'rolls' => $l['rolls'], 'meters' => $l['meters'], 'on_sj' => $l['on_sj']);
  $d['sjs'] = array(); foreach ($o['sjs'] as $s) $d['sjs'][] = array('sj' => $s['sj'], 'date' => $s['date'], 'shipped_date' => $s['shipped_date'],
    'delivered' => $s['delivered'], 'delivered_date' => $s['delivered_date'], 'rolls' => $s['rolls'], 'meters' => $s['meters'],
    'lines' => array_map(function ($l) { return array('art' => $l['art'], 'color' => $l['color'], 'name' => $l['name'], 'color_name' => $l['color_name'], 'rolls' => $l['rolls'], 'meters' => $l['meters']); }, $s['lines']));
  return $d;
}
// The customer's order requests of the last 90 days, with what our staff set on the staff page.
function my_requests($customer) {
  $st = db()->prepare('SELECT r.id, r.created_at, r.payload, s.status, s.so_number, s.note FROM ' . E('portal_requests') . ' r
    LEFT JOIN ' . E('portal_request_status') . ' s ON s.request_id = r.id WHERE r.customer = ? AND r.kind = ? AND r.created_at >= ? ORDER BY r.id DESC');
  $st->execute(array($customer, 'order', date('Y-m-d H:i:s', strtotime('-90 days'))));
  $rows = $st->fetchAll(); $arts = array();
  foreach ($rows as $i => $r) { $rows[$i]['payload'] = json_decode($r['payload'], true); foreach ($rows[$i]['payload']['items'] as $it) $arts[] = $it['art']; }
  $names = $arts ? article_names($arts, true) : array();
  $out = array();
  foreach ($rows as $r) {
    $p = $r['payload'];
    foreach ($p['items'] as $i => $it) { $l = label_for($names, $it['art'], $it['color']); $p['items'][$i] += array('name' => $l['name'], 'color_name' => $l['color_name'], 'meters' => round($it['rolls'] * $l['roll_m'], 2)); }
    $out[] = array('id' => (int)$r['id'], 'ref' => 'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT), 'created_at' => $r['created_at'],
      'status' => $r['status'] ? $r['status'] : 'Sent', 'so_number' => (string)$r['so_number'], 'staff_note' => (string)$r['note'], 'data' => $p);
  }
  return $out;
}

switch ($action) {

case 'me':
  $u = current_user();
  out(array('ok' => true, 'csrf' => $_SESSION['csrf'], 'contact' => array('email' => cfgv('contact_email', 'sales@wautama.com'), 'phone' => cfgv('contact_phone', '(031) 843 7377')),
    'user' => $u ? array('name' => $u['name'], 'email' => $u['email'], 'customer' => $u['customer'], 'company' => $u['company'], 'terms' => (int)$u['terms'],
      'must_change' => (int)$u['must_change']) : null));

case 'login':
  if (!$post) bad('POST only', 405);
  $email = strtolower(trim(isset($in['email']) ? (string)$in['email'] : ''));
  $pw = isset($in['password']) ? (string)$in['password'] : '';
  if ($email === '' || $pw === '') bad('Enter your email or username, and your password.');
  if (attempts_blocked($email, 'customer')) bad('Too many wrong passwords. Wait 15 minutes, or contact our sales team.', 429);
  $st = db()->prepare('SELECT id, password_hash, active FROM ' . E('portal_users') . ' WHERE email = ?');
  $st->execute(array($email));
  $u = $st->fetch();
  $ok = $u && (int)$u['active'] && password_verify($pw, $u['password_hash']);
  attempt_log($email, $ok, 'customer');
  if (!$ok) { usleep(400000); bad('That login and password do not match an active account.', 401); }
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
  db()->prepare('UPDATE ' . E('portal_users') . ' SET last_login = ? WHERE id = ?')->execute(array(now(), (int)$u['id']));
  out(array('ok' => true, 'csrf' => $_SESSION['csrf']));

case 'logout':
  if (!$post) bad('POST only', 405);
  $_SESSION = array(); session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(16));
  out(array('ok' => true, 'csrf' => $_SESSION['csrf']));

case 'password':
  if (!$post) bad('POST only', 405);
  $u = need_user(true);
  $st = db()->prepare('SELECT password_hash FROM ' . E('portal_users') . ' WHERE id = ?'); $st->execute(array((int)$u['id'])); $hash = $st->fetchColumn();
  if (!password_verify(isset($in['current']) ? (string)$in['current'] : '', $hash)) bad('Your current password is not correct.');
  $new = isset($in['new']) ? (string)$in['new'] : '';
  if ($p = password_problem($new)) bad($p);
  if (password_verify($new, $hash)) bad('Choose a password different from the current one.');
  db()->prepare('UPDATE ' . E('portal_users') . ' SET password_hash = ?, must_change = 0 WHERE id = ?')->execute(array(password_hash($new, PASSWORD_DEFAULT), (int)$u['id']));
  session_regenerate_id(true);
  out(array('ok' => true));

case 'products':
  $u = need_user();
  out(array('ok' => true, 'products' => array_values(catalog_for($u['customer']))));

case 'orders':
  $u = need_user(); $d = customer_data($u['customer']);
  out(array('ok' => true, 'orders' => array_values(array_map('order_summary', $d['orders'])), 'requests' => my_requests($u['customer'])));

case 'order':
  $u = need_user(); $d = customer_data($u['customer']);
  $no = isset($_GET['no']) ? (string)$_GET['no'] : '';
  if (!isset($d['orders'][$no])) bad('Order not found.', 404);   // only this customer's own orders
  out(array('ok' => true, 'order' => order_detail($d['orders'][$no])));

case 'order_request':
  if (!$post) bad('POST only', 405);
  $u = need_user();
  $catalog = catalog_for($u['customer']); $items = array();
  foreach ((array)(isset($in['items']) ? $in['items'] : array()) as $it) {
    $k = (string)$it['art'] . '|' . (string)$it['color']; $r = (int)$it['rolls'];
    if (!isset($catalog[$k]) || $r < 1 || $r > 999) bad('One of the products is not available. Reload the page and try again.');
    $items[] = array('art' => (string)$it['art'], 'color' => (string)$it['color'], 'rolls' => $r);
  }
  if (!$items) bad('Add at least one product.');
  if (count($items) > 50) bad('An order can have up to 50 products.');
  $date = isset($in['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date']) ? $in['date'] : '';
  $payload = array('items' => $items, 'po' => substr(trim((string)$in['po']), 0, 60), 'deliver_to' => substr(trim((string)$in['deliver_to']), 0, 300),
    'date' => $date, 'notes' => substr(trim((string)$in['notes']), 0, 1000));
  db()->prepare('INSERT INTO ' . E('portal_requests') . ' (kind, customer, login, created_at, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute(array('order', $u['customer'], $u['email'], now(), json_encode($payload)));
  out(array('ok' => true, 'ref' => 'REQ-' . str_pad(db()->lastInsertId(), 4, '0', STR_PAD_LEFT)));

default:
  bad('Unknown request', 404);
}
