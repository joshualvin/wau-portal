<?php
// WAU customer portal (self-hosted) · API for the customer pages.
// Every answer is limited to the signed-in customer's own code: one customer can never see another's orders or invoices.

$APP = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
require $APP . 'lib.php';
require $APP . 'data.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }
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
function summary($o) { $s = $o; unset($s['lines'], $s['sjs'], $s['history'], $s['note']); return $s; }

function requests_for($customer, $kind) {
  $st = db()->prepare('SELECT r.id, r.created_at, r.payload, s.status, s.so_number, s.note FROM ' . E('portal_requests') . ' r
    LEFT JOIN ' . E('portal_request_status') . ' s ON s.request_id = r.id WHERE r.customer = ? AND r.kind = ? AND r.created_at >= ? ORDER BY r.id DESC');
  $st->execute(array($customer, $kind, date('Y-m-d H:i:s', strtotime('-90 days'))));
  $rows = $st->fetchAll();
  $arts = array();
  foreach ($rows as $i => $r) {
    $rows[$i]['payload'] = json_decode($r['payload'], true);
    if ($kind === 'order') foreach ($rows[$i]['payload']['items'] as $it) $arts[] = $it['art']; else $arts[] = $rows[$i]['payload']['art'];
  }
  $names = $arts ? article_names($arts) : array();
  $out = array();
  foreach ($rows as $r) {
    $p = $r['payload'];
    if ($kind === 'order') foreach ($p['items'] as $i => $it) { $l = label_for($names, $it['art'], $it['color']); $p['items'][$i] += array('name' => $l['name'], 'color_name' => $l['color_name'], 'meters' => round($it['rolls'] * $l['roll_m'], 2)); }
    else { $l = label_for($names, $p['art'], $p['color']); $p['name'] = $l['name']; $p['color_name'] = $l['color_name']; }
    $out[] = array('id' => (int)$r['id'], 'ref' => ($kind === 'order' ? 'REQ-' : 'RET-') . str_pad($r['id'], 4, '0', STR_PAD_LEFT), 'created_at' => $r['created_at'],
      'status' => $r['status'] ? $r['status'] : 'Sent', 'so_number' => (string)$r['so_number'], 'staff_note' => (string)$r['note'], 'data' => $p);
  }
  return $out;
}

switch ($action) {

case 'me':
  $u = current_user();
  out(array('ok' => true, 'csrf' => $_SESSION['csrf'], 'show_amounts' => (bool)cfgv('show_invoice_amounts', false),
    'contact' => array('email' => cfgv('contact_email', 'sales@wautama.com'), 'phone' => cfgv('contact_phone', '(031) 843 7377')),
    'user' => $u ? array('name' => $u['name'], 'email' => $u['email'], 'customer' => $u['customer'], 'company' => $u['company'], 'terms' => (int)$u['terms'],
      'must_change' => (int)$u['must_change']) : null));

case 'login':
  if (!$post) bad('POST only', 405);
  $email = strtolower(trim(isset($in['email']) ? (string)$in['email'] : ''));
  $pw = isset($in['password']) ? (string)$in['password'] : '';
  if ($email === '' || $pw === '') bad('Enter your email and password.');
  if (attempts_blocked($email, 'customer')) bad('Too many wrong passwords. Wait 15 minutes, or contact our sales team.', 429);
  $st = db()->prepare('SELECT id, password_hash, active FROM ' . E('portal_users') . ' WHERE email = ?');
  $st->execute(array($email));
  $u = $st->fetch();
  $ok = $u && (int)$u['active'] && password_verify($pw, $u['password_hash']);
  attempt_log($email, $ok, 'customer');
  if (!$ok) { usleep(400000); bad('That email and password do not match an active account.', 401); }
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

case 'dashboard':
  $u = need_user(); $d = customer_data($u['customer']);
  $unpaid = array(); foreach ($d['invoices'] as $i) if ($i['status'] !== 'Paid') $unpaid[] = $i;
  out(array('ok' => true, 'orders' => array_values(array_map('summary', $d['orders'])), 'unpaid' => $unpaid,
    'requests' => requests_for($u['customer'], 'order'), 'returns' => requests_for($u['customer'], 'return')));

case 'products':
  $u = need_user();
  out(array('ok' => true, 'products' => array_values(catalog_for($u['customer']))));

case 'orders':
  $u = need_user(); $d = customer_data($u['customer']);
  out(array('ok' => true, 'orders' => array_values(array_map('summary', $d['orders'])), 'requests' => requests_for($u['customer'], 'order')));

case 'order':
  $u = need_user(); $d = customer_data($u['customer']);
  $no = isset($_GET['no']) ? (string)$_GET['no'] : '';
  if (!isset($d['orders'][$no])) bad('Order not found.', 404);
  $o = $d['orders'][$no];
  $o['invoices'] = array(); foreach ($d['invoices'] as $i) if (in_array($no, $i['orders'])) $o['invoices'][] = $i;
  out(array('ok' => true, 'order' => $o));

case 'invoices':
  $u = need_user(); $d = customer_data($u['customer']);
  out(array('ok' => true, 'invoices' => $d['invoices'], 'terms' => (int)$u['terms']));

case 'returns':
  $u = need_user(); $d = customer_data($u['customer']);
  $delivered = array(); foreach ($d['orders'] as $o) if (in_array($o['status'], array('Delivered', 'Partly delivered'))) $delivered[] = $o;
  out(array('ok' => true, 'returns' => requests_for($u['customer'], 'return'), 'delivered' => array_slice($delivered, 0, 100)));

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

case 'return_request':
  if (!$post) bad('POST only', 405);
  $u = need_user(); $d = customer_data($u['customer']);
  $so = (string)(isset($in['so']) ? $in['so'] : '');
  if (!isset($d['orders'][$so])) bad('Choose one of your delivered orders.');
  $line = null;
  foreach ($d['orders'][$so]['lines'] as $l) if ($l['art'] === (string)$in['art'] && $l['color'] === (string)$in['color']) $line = $l;
  if (!$line) bad('Choose a product from that order.');
  $rolls = (int)$in['rolls'];
  if ($rolls < 1 || $rolls > max(1, (int)$line['rolls'])) bad('Rolls affected must be between 1 and ' . (int)$line['rolls'] . '.');
  $reasons = array('Surface defect', 'Color mismatch', 'Wrong product delivered', 'Damaged in delivery', 'Short length on roll', 'Other');
  $reason = in_array($in['reason'], $reasons) ? $in['reason'] : 'Other';
  $desc = trim((string)$in['description']);
  if ($desc === '') bad('Describe the problem so our team can review it.');
  $payload = array('so' => $so, 'art' => $line['art'], 'color' => $line['color'], 'rolls' => $rolls, 'reason' => $reason, 'description' => substr($desc, 0, 2000));
  db()->prepare('INSERT INTO ' . E('portal_requests') . ' (kind, customer, login, created_at, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute(array('return', $u['customer'], $u['email'], now(), json_encode($payload)));
  out(array('ok' => true, 'ref' => 'RET-' . str_pad(db()->lastInsertId(), 4, '0', STR_PAD_LEFT)));

default:
  bad('Unknown request', 404);
}
