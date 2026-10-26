<?php
// WAU customer portal · setup check. Run on the server:   C:\xampp\php\php.exe C:\wau-portal\check.php
// Tests config.php, the database user's rights and every table and column name the portal reads. Changes nothing in si_wa.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__FILE__) . '/app/lib.php';
require dirname(__FILE__) . '/app/data.php';

$problems = 0;
function say($m) { echo $m, "\n"; }
function bad_line($m) { global $problems; $problems++; say('  PROBLEM  ' . $m); }

say('PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '7.4', '<') ? '  (needs 7.4 or newer)' : ''));
if (version_compare(PHP_VERSION, '7.4', '<')) $problems++;
foreach (array('pdo_mysql', 'json', 'session') as $ext) if (!extension_loaded($ext)) bad_line("PHP extension $ext is not enabled in php.ini");

try { $pdo = db(); say('Database: connected as ' . cfgv('db_user') . ', portal tables ready in ' . cfgv('erp_db') . '.'); }
catch (Exception $e) { bad_line('Cannot connect or create the portal tables: ' . $e->getMessage()); say('Check finished with problems.'); exit(1); }

$c = cfgv('cols');
$need = array(
  'so_head' => array('noso', 'tgl', 'kodepelanggan', 'top', 'keterangan'),
  'so_line' => array('noso', 'kodebarang', 'kodewarna', 'jumlah', 'completed'),
  'sj_head' => array('nosj', 'tgl', 'delivery', 'tgldelivery', 'nofk', 'lunas'),
  'sj_line' => array('nosj', 'noso', 'kodebarang', 'kodewarna', 'jumlah', 'meter', 'hargaso'),
  'customers' => array($c['cust_code'], $c['cust_name'], $c['cust_top']),
  'articles' => array_filter(array($c['art_code'], $c['art_color'], $c['art_name'], $c['art_color_name'], $c['art_roll_m'], isset($c['art_active']) ? $c['art_active'] : '')),
  'stock' => array($c['stock_art'], $c['stock_color'], $c['stock_onhand'], $c['stock_alloc'], $c['stock_wh']),
  'trip_head' => array($c['trip_no'], $c['trip_date']),
  'trip_line' => array($c['trip_line_no'], $c['trip_line_sj']),
  'pay_line' => array($c['pay_sj'], $c['pay_amount']),
);
$tables = cfgv('tables');
foreach ($need as $key => $cols) {
  try { $have = array(); foreach ($pdo->query('SHOW COLUMNS FROM ' . T($key))->fetchAll(PDO::FETCH_NUM) as $r) $have[strtolower($r[0])] = $r[0]; }
  catch (Exception $e) { bad_line("table {$tables[$key]} not found or not readable (tables['$key'] in config.php)"); continue; }
  $missing = array(); foreach ($cols as $col) if (!isset($have[strtolower($col)])) $missing[] = $col;
  if ($missing) { bad_line($tables[$key] . ': column(s) not found: ' . implode(', ', $missing)); say('           columns it has: ' . implode(', ', array_values($have))); }
  else say('  ok  ' . $tables[$key]);
}
if (!empty($c['art_active'])) { try {
  $n = array(0, 0); foreach ($pdo->query('SELECT ' . C('art_active') . ' FROM ' . T('articles'))->fetchAll(PDO::FETCH_NUM) as $r) $n[is_inactive($r[0]) ? 1 : 0]++;
  say('  ok  articles: ' . $n[0] . ' active, ' . $n[1] . ' inactive (hidden from customers)'); } catch (Exception $e) {} }
try { $pdo->exec('INSERT INTO ' . E('portal_attempts') . " (at, ip, login, ok, area) VALUES (NOW(), 'check', 'check.php', 1, 'check')");
  $pdo->exec('DELETE FROM ' . E('portal_attempts') . " WHERE area = 'check'"); say('  ok  ' . cfgv('erp_db') . ' (write)'); }
catch (Exception $e) { bad_line('cannot write to ' . cfgv('erp_db') . ': ' . $e->getMessage()); }
try { $pdo->exec('CREATE TABLE ' . cfgv('db_name') . '.wau_portal_write_test (x INT)'); $pdo->exec('DROP TABLE ' . cfgv('db_name') . '.wau_portal_write_test');
  bad_line('the database user can CHANGE ' . cfgv('db_name') . '. Give it only SELECT there (see the guide).'); }
catch (Exception $e) { say('  ok  ' . cfgv('db_name') . ' is read-only for this user'); }
if (!cfgv('staff_users')) say('  Note: no staff_users in config.php yet, so nobody can sign in to /staff/.');
if (!cfgv('secure_cookies', true)) say('  Note: secure_cookies is false. Set it to true for the live site.');
say($problems ? "Check finished with $problems problem(s)." : 'Check finished: everything is ready.');
exit($problems ? 1 : 0);
