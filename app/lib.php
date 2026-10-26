<?php
// WAU customer portal (self-hosted) · shared helpers: settings, database, sessions, sign-in limits.
// Runs on the office Windows server (XAMPP). Settings and passwords come from C:\wau-portal\config.php,
// which is outside the web folder (C:\wau-portal\public), so it can never be downloaded.

date_default_timezone_set('Asia/Jakarta');

function portal_cfg() {
  static $cfg = null;
  if ($cfg === null) {
    $file = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($file)) { http_response_code(500); exit('config.php not found next to the public folder.'); }
    $cfg = require $file;
  }
  return $cfg;
}
function cfgv($key, $default = null) { $c = portal_cfg(); return array_key_exists($key, $c) ? $c[$key] : $default; }

// One connection to the MySQL server: si_wa is only read, the portal's own tables live in wau_erp.
function db() {
  static $pdo = null;
  if ($pdo === null) {
    $c = portal_cfg();
    $pdo = new PDO('mysql:host=' . $c['db_host'] . ';port=' . (int)$c['db_port'] . ';dbname=' . $c['db_name'] . ';charset=utf8',
      $c['db_user'], $c['db_password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 15));
    ensure_tables($pdo);
  }
  return $pdo;
}
// Table in wau_erp, e.g. E('portal_users') = `wau_erp`.`portal_users`
function E($table) { return '`' . str_replace('`', '', cfgv('erp_db', 'wau_erp')) . '`.`' . $table . '`'; }

function ensure_tables($pdo) {
  // MySQL 5.1: utf8 (not utf8mb4), no JSON type.
  $pdo->exec('CREATE TABLE IF NOT EXISTS ' . E('portal_users') . " (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL, name VARCHAR(200) NOT NULL DEFAULT '',
    customer VARCHAR(30) NOT NULL, password_hash VARCHAR(255) NOT NULL, must_change TINYINT NOT NULL DEFAULT 1, active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL, created_by VARCHAR(100) NOT NULL DEFAULT '', last_login DATETIME NULL, UNIQUE KEY (email)) DEFAULT CHARSET=utf8");
  $pdo->exec('CREATE TABLE IF NOT EXISTS ' . E('portal_attempts') . " (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, ip VARCHAR(64) NOT NULL, login VARCHAR(190) NOT NULL,
    ok TINYINT NOT NULL, area VARCHAR(10) NOT NULL DEFAULT 'customer', KEY (at)) DEFAULT CHARSET=utf8");
  $pdo->exec('CREATE TABLE IF NOT EXISTS ' . E('portal_requests') . " (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, customer VARCHAR(30) NOT NULL,
    login VARCHAR(190) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, payload MEDIUMTEXT NOT NULL, KEY (customer, created_at)) DEFAULT CHARSET=utf8");
  $pdo->exec('CREATE TABLE IF NOT EXISTS ' . E('portal_request_status') . " (request_id INT PRIMARY KEY, status VARCHAR(30) NOT NULL, so_number VARCHAR(40) NOT NULL DEFAULT '',
    note VARCHAR(500) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL, updated_by VARCHAR(100) NOT NULL DEFAULT '') DEFAULT CHARSET=utf8");
}

function now() { return date('Y-m-d H:i:s'); }
function today() { return date('Y-m-d'); }
// Behind the Cloudflare Tunnel every request comes from 127.0.0.1; Cloudflare passes the visitor's address in CF-Connecting-IP.
function client_ip() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
  if (($ip === '127.0.0.1' || $ip === '::1') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
  return substr($ip, 0, 64);
}

// Sessions: cookie not readable by scripts, sent only over HTTPS (Cloudflare always serves HTTPS).
function start_session() {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name('WAUPORTAL');
  // Secure cookie when the visit is HTTPS (through the Cloudflare tunnel). Opening http://127.0.0.1:8081 on the server
  // itself still works, otherwise the browser would drop the cookie and every sign-in would fail without a message.
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
  session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'secure' => $https && (bool)cfgv('secure_cookies', true), 'httponly' => true, 'samesite' => 'Lax'));
  $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wau_portal_sessions';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  if (is_dir($dir)) session_save_path($dir);
  ini_set('session.gc_maxlifetime', (string)((int)cfgv('session_hours', 8) * 3600));
  session_start();
  $idle = (int)cfgv('session_hours', 8) * 3600;
  if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > $idle) { $_SESSION = array(); session_regenerate_id(true); }
  $_SESSION['seen'] = time();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function csrf_ok($token) { return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token); }

// Too many wrong passwords: 5 per login or 20 per address in 15 minutes.
function attempts_blocked($login, $area) {
  $st = db()->prepare('SELECT SUM(login = ?), SUM(ip = ?) FROM ' . E('portal_attempts') . ' WHERE at >= ? AND ok = 0 AND area = ?');
  $st->execute(array($login, client_ip(), date('Y-m-d H:i:s', time() - 900), $area));
  $r = $st->fetch(PDO::FETCH_NUM);
  return (int)$r[0] >= 5 || (int)$r[1] >= 20;
}
function attempt_log($login, $ok, $area) {
  db()->prepare('INSERT INTO ' . E('portal_attempts') . ' (at, ip, login, ok, area) VALUES (?, ?, ?, ?, ?)')->execute(array(now(), client_ip(), $login, $ok ? 1 : 0, $area));
  if (mt_rand(1, 50) === 1) db()->prepare('DELETE FROM ' . E('portal_attempts') . ' WHERE at < ?')->execute(array(date('Y-m-d H:i:s', time() - 90 * 86400)));
}

function password_problem($pw) {
  if (strlen($pw) < 8) return 'Use at least 8 characters.';
  if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use both letters and numbers.';
  return '';
}
function temp_password() {
  $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $out = '';
  for ($i = 0; $i < 12; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
  return $out . random_int(2, 9);
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
