<?php
// WAU customer portal (self-hosted) · settings. Copy to C:\wau-portal\config.php and fill in.
// This file holds passwords. It sits OUTSIDE the web folder (C:\wau-portal\public), so it can never be downloaded.
return array(
  // ---- MySQL on this server: si_wa is only read; logins and requests are saved in wau_erp ----
  'db_host'     => '127.0.0.1',
  'db_port'     => 3306,
  'db_user'     => 'wau_portal',               // see the guide: SELECT on si_wa, write rights on wau_erp only
  'db_password' => 'PASTE-THE-PASSWORD',
  'db_name'     => 'si_wa',
  'erp_db'      => 'wau_erp',

  // ---- Staff side: Sales › Customer portal in WAU ERP (sign in with the WAU ERP account) ----
  // portal.wautama.com/staff/ sends staff there. 'staff_users' is no longer used.
  'erp_staff_url' => 'https://erp.wautama.com/erp.php#portal',

  // ---- What customers see ----
  'ignore_warehouses' => array('GU001'),
  'stock_cache_seconds' => 120,      // free stock is read again at most this often
  'contact_email' => 'sales@wautama.com',
  'contact_phone' => '(031) 843 7377',

  // ---- Sign-in ----
  'session_hours'  => 8,
  'secure_cookies' => true,          // keep true: Cloudflare serves the portal over HTTPS

  // ---- Table and column names in si_wa (copy this block from C:\wau-sync\config.php if you corrected any there) ----
  'tables' => array(
    'so_head' => 'soheader', 'so_line' => 'sodetail', 'sj_head' => 'sjheader', 'sj_line' => 'sjdetail',
    'articles' => 'masterbarang', 'customers' => 'masterpelanggan', 'stock' => 'stock',
    'trip_head' => 'shipmenth', 'trip_line' => 'shipmentd', 'pay_line' => 'bpdetail',
  ),
  'cols' => array(
    'cust_code' => 'kodepelanggan', 'cust_name' => 'nama', 'cust_top' => 'top',
    'art_code' => 'kodebarang', 'art_color' => 'kodewarna', 'art_name' => 'nama', 'art_color_name' => 'warna', 'art_roll_m' => 'defaultmeter',
    'art_active' => 'IsAktif',     // inactive articles are hidden from customers; '' = show all
    'stock_art' => 'kodebarang', 'stock_color' => 'kodewarna', 'stock_onhand' => 'rollonhand', 'stock_alloc' => 'rollonallocatedsj', 'stock_wh' => 'kodegudang',
    'trip_no' => 'NoShipment', 'trip_date' => 'Tanggal', 'trip_line_no' => 'NoShipment', 'trip_line_sj' => 'NoSJ',
    'pay_sj' => 'nosj', 'pay_amount' => 'jumlah',
  ),
);
