<?php
// WAU customer portal · staff page (portal.wautama.com/staff/). Moved into WAU ERP on 27 Oct 2026: staff now sign in with
// their WAU ERP account at Sales › Customer portal (requests, custom articles, customer logins), which uses the same
// tables in wau_erp. This address only sends people there. Another address: 'erp_staff_url' in config.php.
$APP = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
require $APP . 'lib.php';
header('Cache-Control: no-store');
header('Location: ' . cfgv('erp_staff_url', 'https://erp.wautama.com/erp.php#portal'), true, 302);
