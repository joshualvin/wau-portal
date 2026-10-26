<?php
// WAU customer portal (self-hosted) · live data from si_wa, read only.
// The same rules as the earlier sync:
//   no SJ yet = Order Received · SJ made = Order Processed · SJ on a delivery trip = Shipped · every SJ delivered = Delivered
//   (Partly delivered while rolls are still to come). Every delivered SJ is an invoice, due = delivery date + TOP.
// Table and column names come from config.php ('tables' / 'cols').

function T($key) { $t = cfgv('tables'); return '`' . str_replace('`', '', $t[$key]) . '`'; }
function C($key) { $c = cfgv('cols'); return '`' . str_replace('`', '', $c[$key]) . '`'; }
function q_rows($sql, $params = array()) { $st = db()->prepare($sql); $st->execute(array_values($params)); return $st->fetchAll(PDO::FETCH_NUM); }
function marks($list) { return implode(',', array_fill(0, count($list), '?')); }
function chunks_of($list) { return array_chunk(array_values(array_unique($list)), 300); }
function d10($v) { $v = substr((string)$v, 0, 10); return ($v === '' || $v === '0000-00-00') ? null : $v; }
// Trim, and turn old Windows (latin) characters from si_wa into proper UTF-8 so names show correctly.
function tr($v) {
  $v = trim((string)$v);
  if ($v !== '' && !preg_match('//u', $v) && function_exists('mb_convert_encoding')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
  return $v;
}
function add_days($date, $days) { return $date ? date('Y-m-d', strtotime($date . ' +' . (int)$days . ' days')) : null; }

// Small file cache for figures shared by every customer (free stock), so a busy hour does not re-read the whole stock table.
function cached($key, $ttl, $fn) {
  $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wau_portal_cache';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $file = $dir . DIRECTORY_SEPARATOR . md5($key) . '.json';
  if (is_file($file) && time() - filemtime($file) < $ttl) { $v = json_decode((string)@file_get_contents($file), true); if (is_array($v)) return $v; }
  $v = call_user_func($fn);
  @file_put_contents($file, json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
  return $v;
}

function customer_info($code) {
  $r = q_rows('SELECT ' . C('cust_code') . ', ' . C('cust_name') . ', ' . C('cust_top') . ' FROM ' . T('customers') . ' WHERE ' . C('cust_code') . ' = ?', array($code));
  return $r ? array('code' => tr($r[0][0]), 'name' => tr($r[0][1]), 'top' => (int)$r[0][2]) : null;
}

// Article and color names and roll length for a list of article codes: 'art|color' => array(name, color_name, roll_m)
// Articles marked inactive in masterbarang (cols 'art_active', e.g. IsAktif) are left out, so customers cannot see or order them.
function is_inactive($v) {
  if ($v === null) return false;
  if ($v === "\0") return true;                                   // BIT(1) column, value 0
  return in_array(strtoupper(trim((string)$v)), array('0', 'N', 'F', 'FALSE', 'NO', 'TIDAK'), true);
}
function article_names($arts) {
  $map = array();
  $cols = cfgv('cols'); $active = isset($cols['art_active']) && $cols['art_active'] !== '' ? ', ' . C('art_active') : '';
  foreach (chunks_of($arts) as $part)
    foreach (q_rows('SELECT ' . C('art_code') . ', ' . C('art_color') . ', ' . C('art_name') . ', ' . C('art_color_name') . ', ' . C('art_roll_m') . $active . '
        FROM ' . T('articles') . ' WHERE ' . C('art_code') . ' IN (' . marks($part) . ')', $part) as $r) {
      if ($active && is_inactive($r[5])) continue;
      $map[tr($r[0]) . '|' . tr($r[1])] = array('name' => tr($r[2]) !== '' ? tr($r[2]) : tr($r[0]), 'color_name' => tr($r[3]) !== '' ? tr($r[3]) : tr($r[1]), 'roll_m' => (float)$r[4]);
    }
  return $map;
}
function label_for($names, $art, $color) {
  return isset($names[$art . '|' . $color]) ? $names[$art . '|' . $color] : array('name' => $art, 'color_name' => $color, 'roll_m' => 0);
}

// Everything about one customer's orders and invoices, read live. Cached for the length of one request.
function customer_data($code) {
  static $memo = array();
  if (isset($memo[$code])) return $memo[$code];
  $info = customer_info($code);
  $top_default = $info ? $info['top'] : 0;
  $since = date('Y-m-d', strtotime('-' . (int)cfgv('months', 12) . ' months'));

  // Sales orders: recent ones, every older one still open, and older ones whose SJ is still unpaid.
  $orders = array();
  $head = 'SELECT h.noso, h.tgl, h.top, h.keterangan FROM ' . T('so_head') . ' h ';
  foreach (q_rows($head . 'WHERE h.kodepelanggan = ? AND (h.tgl >= ? OR EXISTS (SELECT 1 FROM ' . T('so_line') . ' d WHERE d.noso = h.noso AND d.completed = 0))',
      array($code, $since)) as $r)
    $orders[tr($r[0])] = array('noso' => tr($r[0]), 'date' => d10($r[1]), 'top' => (int)$r[2], 'note' => tr($r[3]), 'lines' => array(), 'sjs' => array());
  $extra = array();
  foreach (q_rows('SELECT DISTINCT j.noso FROM ' . T('sj_line') . ' j JOIN ' . T('sj_head') . ' h ON h.nosj = j.nosj JOIN ' . T('so_head') . ' s ON s.noso = j.noso
      WHERE h.lunas = 0 AND s.kodepelanggan = ?', array($code)) as $r) if (!isset($orders[tr($r[0])])) $extra[] = tr($r[0]);
  foreach (chunks_of($extra) as $part)
    foreach (q_rows($head . 'WHERE h.noso IN (' . marks($part) . ')', $part) as $r)
      $orders[tr($r[0])] = array('noso' => tr($r[0]), 'date' => d10($r[1]), 'top' => (int)$r[2], 'note' => tr($r[3]), 'lines' => array(), 'sjs' => array());
  $so_numbers = array_keys($orders);

  $arts = array();
  foreach (chunks_of($so_numbers) as $part)
    foreach (q_rows('SELECT noso, kodebarang, kodewarna, jumlah, completed+0 FROM ' . T('so_line') . ' WHERE noso IN (' . marks($part) . ') ORDER BY noso, kodebarang, kodewarna', $part) as $r) {
      $orders[tr($r[0])]['lines'][] = array('art' => tr($r[1]), 'color' => tr($r[2]), 'rolls' => (int)$r[3], 'done' => (int)$r[4], 'on_sj' => 0);
      $arts[] = tr($r[1]);
    }

  // Surat jalan of those orders.
  $sj = array();
  foreach (chunks_of($so_numbers) as $part)
    foreach (q_rows('SELECT h.nosj, h.tgl, h.delivery+0, h.tgldelivery, h.nofk, h.lunas+0, j.noso, j.kodebarang, j.kodewarna, SUM(j.jumlah), SUM(j.jumlah * j.meter)
        FROM ' . T('sj_line') . ' j JOIN ' . T('sj_head') . ' h ON h.nosj = j.nosj WHERE j.noso IN (' . marks($part) . ')
        GROUP BY h.nosj, h.tgl, h.delivery, h.tgldelivery, h.nofk, h.lunas, j.noso, j.kodebarang, j.kodewarna', $part) as $r) {
      $no = tr($r[0]); $so = tr($r[6]);
      if (!isset($sj[$no])) $sj[$no] = array('date' => d10($r[1]), 'delivered' => (int)$r[2] ? 1 : 0, 'delivered_date' => d10($r[3]), 'invoice' => tr($r[4]),
        'settled' => (int)$r[5] ? 1 : 0, 'shipped_date' => null, 'by_so' => array(), 'amount' => 0.0);
      if (!isset($sj[$no]['by_so'][$so])) $sj[$no]['by_so'][$so] = array('rolls' => 0, 'meters' => 0.0);
      $sj[$no]['by_so'][$so]['rolls'] += (int)$r[9];
      $sj[$no]['by_so'][$so]['meters'] += (float)$r[10];
      foreach ($orders[$so]['lines'] as $i => $line)
        if ($line['art'] === tr($r[7]) && $line['color'] === tr($r[8])) { $orders[$so]['lines'][$i]['on_sj'] += (int)$r[9]; break; }
    }
  $sj_numbers = array_keys($sj);
  foreach (chunks_of($sj_numbers) as $part) {
    // Invoice value: the whole SJ (it can carry more than one order).
    foreach (q_rows('SELECT nosj, SUM(jumlah * meter * hargaso) FROM ' . T('sj_line') . ' WHERE nosj IN (' . marks($part) . ') GROUP BY nosj', $part) as $r)
      $sj[tr($r[0])]['amount'] = (float)$r[1];
    // On a delivery trip = Shipped.
    foreach (q_rows('SELECT d.' . C('trip_line_sj') . ', MIN(h.' . C('trip_date') . ') FROM ' . T('trip_line') . ' d JOIN ' . T('trip_head') . ' h ON h.' . C('trip_no') . ' = d.' . C('trip_line_no') . '
        WHERE d.' . C('trip_line_sj') . ' IN (' . marks($part) . ') GROUP BY d.' . C('trip_line_sj'), $part) as $r)
      if (isset($sj[tr($r[0])])) $sj[tr($r[0])]['shipped_date'] = d10($r[1]);
    // Payments allocated to each SJ.
    foreach (q_rows('SELECT ' . C('pay_sj') . ', SUM(' . C('pay_amount') . ') FROM ' . T('pay_line') . ' WHERE ' . C('pay_sj') . ' IN (' . marks($part) . ') GROUP BY ' . C('pay_sj'), $part) as $r)
      if (isset($sj[tr($r[0])])) $sj[tr($r[0])]['paid'] = (float)$r[1];
  }
  $names = article_names($arts);

  // Order status.
  foreach ($orders as $so => $o) {
    $list = array(); $open = array();
    foreach ($sj as $no => $s) if (isset($s['by_so'][$so])) {
      $item = array('sj' => $no, 'date' => $s['date'], 'delivered' => $s['delivered'], 'delivered_date' => $s['delivered_date'],
        'shipped_date' => $s['shipped_date'], 'rolls' => $s['by_so'][$so]['rolls'], 'meters' => round($s['by_so'][$so]['meters'], 2));
      $list[] = $item;
      if (!$s['delivered']) $open[] = $item;
    }
    usort($list, 'sj_by_date');
    $to_come = 0;
    foreach ($o['lines'] as $line) if (!$line['done']) $to_come += max(0, $line['rolls'] - $line['on_sj']);
    $hist = array('received' => $o['date'], 'processed' => null, 'shipped' => null, 'delivered' => null);
    foreach ($list as $item) {
      if ($item['date'] && (!$hist['processed'] || $item['date'] < $hist['processed'])) $hist['processed'] = $item['date'];
      if ($item['shipped_date'] && (!$hist['shipped'] || $item['shipped_date'] < $hist['shipped'])) $hist['shipped'] = $item['shipped_date'];
      if ($item['delivered'] && $item['delivered_date'] && (!$hist['delivered'] || $item['delivered_date'] > $hist['delivered'])) $hist['delivered'] = $item['delivered_date'];
    }
    if (!$list) { $status = 'Order Received'; $when = $o['date']; }
    elseif ($open) {
      $on_trip = false; foreach ($open as $item) if ($item['shipped_date']) $on_trip = true;
      $status = $on_trip ? 'Shipped' : 'Order Processed'; $when = $on_trip ? $hist['shipped'] : $hist['processed'];
    } elseif ($to_come > 0) { $status = 'Partly delivered'; $when = $hist['delivered']; }
    else { $status = 'Delivered'; $when = $hist['delivered']; }
    $rolls = 0; $meters = 0.0; $items = array();
    foreach ($o['lines'] as $i => $line) {
      $l = label_for($names, $line['art'], $line['color']);
      $m = $line['rolls'] * $l['roll_m'];
      $o['lines'][$i] += array('name' => $l['name'], 'color_name' => $l['color_name'], 'meters' => round($m, 2));
      $rolls += $line['rolls']; $meters += $m; $items[$l['name'] . ' ' . $l['color_name']] = true;
    }
    $orders[$so] = array('no' => $so, 'date' => $o['date'], 'note' => $o['note'], 'top' => $o['top'], 'status' => $status, 'status_date' => $when,
      'history' => $hist, 'rolls' => $rolls, 'meters' => round($meters, 2), 'items' => implode(', ', array_keys($items)), 'rolls_to_come' => $to_come,
      'lines' => $o['lines'], 'sjs' => $list);
  }
  uasort($orders, 'order_newest_first');

  // Invoices: every delivered SJ; paid ones for the last 12 months, unpaid ones however old.
  $show = (bool)cfgv('show_invoice_amounts', false);
  $invoices = array(); $cut = date('Y-m-d', strtotime('-12 months'));
  foreach ($sj as $no => $s) {
    if (!$s['delivered'] || ($s['settled'] && $s['delivered_date'] < $cut)) continue;
    $so_list = array_keys($s['by_so']);
    $top = 0; foreach ($so_list as $so) $top = max($top, (int)$orders[$so]['top']);
    if (!$top) $top = $top_default;
    $due = add_days($s['delivered_date'] ? $s['delivered_date'] : $s['date'], $top);
    $paid = isset($s['paid']) ? $s['paid'] : 0.0;
    if ($s['settled']) $status = 'Paid'; elseif ($due && $due < today()) $status = 'Overdue'; else $status = 'Unpaid';
    $invoices[] = array('sj' => $no, 'invoice' => $s['invoice'] !== '' ? $s['invoice'] : $no, 'delivered' => $s['delivered_date'], 'due' => $due,
      'status' => $status, 'orders' => $so_list, 'days_late' => $status === 'Overdue' ? (int)floor((strtotime(today()) - strtotime($due)) / 86400) : 0,
      'amount' => $show ? round($s['amount'], 2) : null, 'paid' => $show ? round($paid, 2) : null,
      'owed' => $show ? ($s['settled'] ? 0 : max(0, round($s['amount'] - $paid, 2))) : null);
  }
  usort($invoices, 'invoice_order');
  foreach ($orders as $so => $o) unset($orders[$so]['top']);
  return $memo[$code] = array('customer' => $info, 'orders' => $orders, 'invoices' => $invoices);
}
function sj_by_date($a, $b) { return strcmp((string)$a['date'] . $a['sj'], (string)$b['date'] . $b['sj']); }
function order_newest_first($a, $b) { return strcmp((string)$b['date'] . $b['no'], (string)$a['date'] . $a['no']); }
function invoice_order($a, $b) {
  $rank = array('Overdue' => 0, 'Unpaid' => 1, 'Paid' => 2);
  if ($rank[$a['status']] !== $rank[$b['status']]) return $rank[$a['status']] - $rank[$b['status']];
  return strcmp((string)$b['due'], (string)$a['due']);
}

// The catalog for one customer: articles with free stock, plus what they ordered in the last 24 months.
function free_stock() {
  return cached('free_stock', (int)cfgv('stock_cache_seconds', 120), 'read_free_stock');
}
function read_free_stock() {
  $ignore = array(); foreach ((array)cfgv('ignore_warehouses', array()) as $w) $ignore[] = tr($w);
  $free = array();
  foreach (q_rows('SELECT ' . C('stock_art') . ', ' . C('stock_color') . ', SUM(CASE WHEN ' . C('stock_onhand') . ' > ' . C('stock_alloc') . ' THEN ' . C('stock_onhand') . ' - ' . C('stock_alloc') . ' ELSE 0 END)
      FROM ' . T('stock') . ($ignore ? ' WHERE ' . C('stock_wh') . ' NOT IN (' . marks($ignore) . ')' : '') . ' GROUP BY ' . C('stock_art') . ', ' . C('stock_color'), $ignore) as $r)
    $free[tr($r[0]) . '|' . tr($r[1])] = (int)$r[2];
  return $free;
}
function catalog_for($code) {
  static $memo = array();
  if (isset($memo[$code])) return $memo[$code];
  $free = free_stock();
  $wanted = array();
  foreach ($free as $key => $rolls) if ($rolls > 0) $wanted[$key] = true;
  foreach (q_rows('SELECT DISTINCT d.kodebarang, d.kodewarna FROM ' . T('so_line') . ' d JOIN ' . T('so_head') . ' h ON h.noso = d.noso WHERE h.kodepelanggan = ? AND h.tgl >= ?',
      array($code, date('Y-m-d', strtotime('-24 months')))) as $r) $wanted[tr($r[0]) . '|' . tr($r[1])] = true;
  $arts = array(); foreach (array_keys($wanted) as $key) { $p = explode('|', $key, 2); $arts[] = $p[0]; }
  $names = cached('articles2_' . md5(implode(',', array_unique($arts))), 600, function () use ($arts) { return article_names($arts); });
  $list = array();
  foreach ($names as $key => $n) {
    if (!isset($wanted[$key])) continue;
    $p = explode('|', $key, 2); $rolls = isset($free[$key]) ? $free[$key] : 0;
    $list[$key] = array('art' => $p[0], 'color' => $p[1], 'name' => $n['name'], 'color_name' => $n['color_name'], 'roll_m' => $n['roll_m'],
      'stock' => $rolls > 0 ? 'in' : 'out'); // only in or out: stock quantities are never sent to customers
  }
  uasort($list, 'product_by_name');
  return $memo[$code] = $list;
}
function product_by_name($a, $b) { return strcasecmp($a['name'] . ' ' . $a['color_name'], $b['name'] . ' ' . $b['color_name']); }
