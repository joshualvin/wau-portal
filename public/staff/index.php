<?php
// WAU customer portal (self-hosted) · staff page (portal.wautama.com/staff/)
// Create and manage customer logins, and follow the order and return requests customers send.
// Staff sign in with a name and password listed in config.php ('staff_users').

$APP = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
require $APP . 'lib.php';
require $APP . 'data.php';
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
start_session();
$pdo = db();

function staff() { return isset($_SESSION['staff']) ? $_SESSION['staff'] : null; }
function flash($msg, $kind = 'ok') { $_SESSION['flash'] = array($msg, $kind); }
function back($tab = '') { header('Location: ./' . ($tab ? '?tab=' . $tab : '')); exit; }

// ---------------------------------------------------------------- POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
    if (empty($_COOKIE[session_name()])) { header('Location: ./?nocookie=1'); exit; } // the browser did not keep the sign-in cookie
    flash('The form expired. Please try again.', 'err'); back();
  }
  $do = isset($_POST['do']) ? $_POST['do'] : '';

  if ($do === 'login') {
    $name = strtolower(trim((string)$_POST['name'])); $pw = (string)$_POST['password'];
    $users = array_change_key_case((array)cfgv('staff_users', array()), CASE_LOWER);
    if (attempts_blocked($name, 'staff')) { flash('Too many wrong passwords. Wait 15 minutes.', 'err'); back(); }
    $ok = isset($users[$name]) && password_verify($pw, $users[$name]);
    attempt_log($name, $ok, 'staff');
    if (!$ok) { usleep(400000); flash('Name and password do not match.', 'err'); back(); }
    session_regenerate_id(true); $_SESSION['staff'] = $name; $_SESSION['csrf'] = bin2hex(random_bytes(16));
    back();
  }
  if (!staff()) back();
  if ($do === 'logout') { unset($_SESSION['staff']); session_regenerate_id(true); back(); }

  if ($do === 'create') {
    $email = strtolower(trim((string)$_POST['email'])); $name = trim((string)$_POST['name']); $cust = strtoupper(trim((string)$_POST['customer']));
    if ($p = login_problem($email)) { flash($p, 'err'); back(); }
    if ($cust === '' || !preg_match('/^[A-Z0-9._-]{1,30}$/', $cust)) { flash('Enter the customer code from the main system, e.g. RK001.', 'err'); back(); }
    if (!customer_info($cust)) { flash("Customer code $cust is not in the main system. Check the code (kodepelanggan).", 'err'); back(); }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM ' . E('portal_users') . ' WHERE email = ?'); $exists->execute(array($email));
    if ($exists->fetchColumn()) { flash("$email already has a login. Use Reset password instead.", 'err'); back(); }
    $temp = temp_password();
    $pdo->prepare('INSERT INTO ' . E('portal_users') . ' (email, name, customer, password_hash, must_change, active, created_at, created_by) VALUES (?, ?, ?, ?, 1, 1, ?, ?)')
      ->execute(array($email, $name, $cust, password_hash($temp, PASSWORD_DEFAULT), now(), staff()));
    $_SESSION['reveal'] = array($email, $temp, $cust);
    back();
  }
  if ($do === 'reset' || $do === 'toggle') {
    $id = (int)$_POST['id'];
    $st = $pdo->prepare('SELECT email, customer, active FROM ' . E('portal_users') . ' WHERE id = ?'); $st->execute(array($id)); $u = $st->fetch();
    if (!$u) back();
    if ($do === 'reset') {
      $temp = temp_password();
      $pdo->prepare('UPDATE ' . E('portal_users') . ' SET password_hash = ?, must_change = 1 WHERE id = ?')->execute(array(password_hash($temp, PASSWORD_DEFAULT), $id));
      $_SESSION['reveal'] = array($u['email'], $temp, $u['customer']);
    } else {
      $pdo->prepare('UPDATE ' . E('portal_users') . ' SET active = ? WHERE id = ?')->execute(array((int)$u['active'] ? 0 : 1, $id));
      flash($u['email'] . ((int)$u['active'] ? ' can no longer sign in.' : ' can sign in again.'));
    }
    back();
  }
  if ($do === 'status') {
    $id = (int)$_POST['id'];
    $allowed = array('Received by our team', 'SO created', 'Cannot fulfil', 'Approved', 'Rejected', 'Resolved');
    $status = in_array($_POST['status'], $allowed) ? $_POST['status'] : 'Received by our team';
    $pdo->prepare('REPLACE INTO ' . E('portal_request_status') . ' (request_id, status, so_number, note, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)')
      ->execute(array($id, $status, substr(trim((string)$_POST['so']), 0, 40), substr(trim((string)$_POST['note']), 0, 500), now(), staff()));
    flash('Saved. The customer sees the new status right away.');
    back('requests');
  }
  back();
}

// ---------------------------------------------------------------- page
$tab = isset($_GET['tab']) && $_GET['tab'] === 'requests' ? 'requests' : 'accounts';
$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null; unset($_SESSION['flash']);
if (!$flash && isset($_GET['nocookie'])) $flash = array('Your browser did not keep the sign-in cookie. Open the staff page at https://portal.wautama.com/staff/ and allow cookies for this site.', 'err');
$reveal = isset($_SESSION['reveal']) ? $_SESSION['reveal'] : null; unset($_SESSION['reveal']);
$csrf = h($_SESSION['csrf']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>WAU Portal · Staff</title><link rel="icon" type="image/png" href="data:image/png;base64,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">
<style>
:root{--bg:#F6F6F4;--surface:#fff;--ink:#121314;--muted:#62656A;--line:#E4E4E1;--side:#18191B;--accent:#C2622D;--accent-soft:#F7E9DF;--good:#1F7A4D;--good-soft:#E3F2EA;--bad:#B42318;--bad-soft:#FBE7E5;--warn:#9A6400;--warn-soft:#FBF0D9}
@media (prefers-color-scheme:dark){:root{--bg:#0D0E0F;--surface:#17181A;--ink:#EDEDEB;--muted:#9A9DA2;--line:#2A2B2E;--side:#121315;--accent:#E07F45;--accent-soft:#2E2019;--good:#5CC48E;--good-soft:#15291F;--bad:#F2796B;--bad-soft:#331816;--warn:#E3B04B;--warn-soft:#2E2412;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
header{background:var(--side);color:#F4F4F2;padding:14px max(16px,env(safe-area-inset-left));display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
header b{font-weight:600}header form{margin:0}
main{max-width:1150px;margin:0 auto;padding:24px max(16px,env(safe-area-inset-left)) 60px;display:grid;gap:18px}
h1{font-size:1.5rem;margin:0;letter-spacing:-.02em}h2{font-size:1.05rem;margin:0 0 12px}
.tabs{display:flex;gap:6px}.tabs a{padding:8px 14px;border-radius:999px;text-decoration:none;color:var(--muted);font-weight:500;border:1px solid var(--line);background:var(--surface)}
.tabs a.on{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.panel{background:var(--surface);border-radius:14px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04),0 6px 20px rgba(0,0,0,.05);min-width:0}
.tbl{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.9rem}th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);text-align:left;font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}tr:last-child td{border-bottom:0}
input,select,textarea{font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 10px;min-width:0}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.row label{display:grid;gap:4px;font-size:.8rem;color:var(--muted);flex:1 1 180px}
button{font:inherit;font-weight:600;border-radius:999px;padding:8px 14px;border:1px solid var(--line);background:var(--surface);color:var(--ink);cursor:pointer}
button.primary{background:var(--accent);border-color:var(--accent);color:#fff}button.small{padding:5px 10px;font-size:.8rem}
.pill{display:inline-block;font-size:.72rem;font-weight:600;padding:3px 9px;border-radius:999px;white-space:nowrap}
.ok{background:var(--good-soft);color:var(--good)}.err{background:var(--bad-soft);color:var(--bad)}.warn{background:var(--warn-soft);color:var(--warn)}.mute{background:var(--bg);color:var(--muted)}
.msg{padding:12px 16px;border-radius:12px}.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85em}.muted{color:var(--muted)}
.reveal{background:var(--accent-soft);border-radius:14px;padding:18px;display:grid;gap:8px}.reveal code{font-size:1.2rem;user-select:all;background:var(--surface);padding:6px 10px;border-radius:8px;justify-self:start}
details summary{cursor:pointer;color:var(--accent);font-weight:600}.login{max-width:380px;margin:10vh auto;display:grid;gap:12px}
/* motion: every save reloads the page, so pages cross-fade and the result of the action (message, temporary password) arrives last */
:root{--ease-out:cubic-bezier(.16,1,.3,1);--rise:6px}
@view-transition{navigation:auto}
::view-transition-old(root),::view-transition-new(root){animation-duration:.18s}
@keyframes in{from{opacity:0;transform:translateY(var(--rise))}}
@keyframes mark{from{background:color-mix(in srgb,var(--accent) 35%,var(--surface))}}
.msg,.reveal{animation:in .3s var(--ease-out) .12s backwards}
.reveal code{animation:mark 1.1s var(--ease-out) .4s backwards}
details[open]>form{animation:in .2s var(--ease-out) backwards}
button{transition:background-color .15s,border-color .15s,transform .12s}
button:hover{border-color:var(--ink)}
button.primary:hover{background:color-mix(in srgb,var(--accent) 88%,black);border-color:color-mix(in srgb,var(--accent) 88%,black)}
button:active{transform:translateY(1px)}
.tabs a{transition:background-color .15s,color .15s,border-color .15s}.tabs a:not(.on):hover{color:var(--ink);border-color:var(--ink)}
@media (prefers-reduced-motion:reduce){:root{--rise:0px}button:active{transform:none}}
</style></head><body>
<?php if (!staff()): ?>
<form class="panel login" method="post">
  <img src="data:image/png;base64,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" alt="Wahyu Agung Utama" width="44" height="44">
  <h1>Portal staff</h1><p class="muted" style="margin:0">Sign in to manage customer logins and requests.</p>
  <?php if ($flash): ?><div class="msg <?= $flash[1] === 'err' ? 'err' : 'ok' ?>"><?= h($flash[0]) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="login">
  <label class="row" style="display:grid;gap:4px">Name<input name="name" autocomplete="username" required></label>
  <label class="row" style="display:grid;gap:4px">Password<input name="password" type="password" autocomplete="current-password" required></label>
  <button class="primary">Sign in</button>
</form>
<?php else:
  $synced = array();
  foreach ($pdo->query('SELECT DISTINCT customer FROM ' . E('portal_users')) as $c) { $ci = customer_info($c['customer']); if ($ci) $synced[$ci['code']] = $ci['name']; }
?>
<header><b style="display:flex;align-items:center;gap:10px"><img src="data:image/png;base64,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" alt="" width="28" height="28">WAU Portal · Staff</b><span style="display:flex;gap:12px;align-items:center"><span class="muted" style="color:#A3A6AB">Signed in as <?= h(staff()) ?></span>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="logout"><button class="small">Sign out</button></form></span></header>
<main>
  <div class="row" style="justify-content:space-between"><nav class="tabs"><a href="./" class="<?= $tab === 'accounts' ? 'on' : '' ?>">Customer logins</a><a href="./?tab=requests" class="<?= $tab === 'requests' ? 'on' : '' ?>">Requests</a></nav></div>
  <?php if ($flash): ?><div class="msg <?= $flash[1] === 'err' ? 'err' : 'ok' ?>"><?= h($flash[0]) ?></div><?php endif; ?>
  <?php if ($reveal): ?><div class="reveal">
    <b>Temporary password for <?= h($reveal[0]) ?></b>
    <code><?= h($reveal[1]) ?></code>
    <span>Give it to the customer by phone or in person. It is shown only once. They choose their own password when they first sign in at <b>portal.wautama.com</b>.</span>
  </div><?php endif; ?>

<?php if ($tab === 'accounts'):
  $users = $pdo->query('SELECT * FROM ' . E('portal_users') . ' ORDER BY active DESC, customer, email')->fetchAll(); ?>
  <section class="panel"><h2>New customer login</h2>
    <form method="post" class="row"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="create">
      <label>Email or username<input name="email" required autocapitalize="none" spellcheck="false" placeholder="name@company.co.id or budi.rk001"></label>
      <label>Contact name<input name="name" placeholder="Budi Santoso"></label>
      <label>Customer code<input name="customer" required placeholder="RK001"></label>
      <button class="primary">Create login</button></form></section>
  <section class="panel"><h2>Logins (<?= count($users) ?>)</h2><div class="tbl"><table>
    <tr><th>Login</th><th>Name</th><th>Customer</th><th>Main system</th><th>Last sign-in</th><th>Status</th><th></th></tr>
    <?php foreach ($users as $u): ?><tr>
      <td><?= h($u['email']) ?></td><td><?= h($u['name']) ?></td>
      <td><span class="mono"><?= h($u['customer']) ?></span><br><span class="muted"><?= h(isset($synced[$u['customer']]) ? $synced[$u['customer']] : '') ?></span></td>
      <td><?= isset($synced[$u['customer']]) ? '<span class="pill ok">Found</span>' : '<span class="pill err">Not in main system</span>' ?></td>
      <td><?= $u['last_login'] ? h(date('j M Y H:i', strtotime($u['last_login']))) : '<span class="muted">Never</span>' ?></td>
      <td><?= (int)$u['active'] ? ((int)$u['must_change'] ? '<span class="pill mute">Waiting for first sign-in</span>' : '<span class="pill ok">Active</span>') : '<span class="pill err">Off</span>' ?></td>
      <td style="white-space:nowrap"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <button class="small" name="do" value="reset">Reset password</button> <button class="small" name="do" value="toggle"><?= (int)$u['active'] ? 'Turn off' : 'Turn on' ?></button></form></td>
    </tr><?php endforeach; if (!$users) echo '<tr><td colspan="7" class="muted">No logins yet. Create the first one above.</td></tr>'; ?>
  </table></div></section>

<?php else:
  $reqs = $pdo->query('SELECT r.*, s.status, s.so_number, s.note, s.updated_at, s.updated_by FROM ' . E('portal_requests') . ' r LEFT JOIN ' . E('portal_request_status') . ' s ON s.request_id = r.id ORDER BY r.id DESC LIMIT 200')->fetchAll();
  $arts = array(); foreach ($reqs as $r) { $p = json_decode($r['payload'], true); if ($r['kind'] === 'order') { foreach ($p['items'] as $it) $arts[] = $it['art']; } else $arts[] = $p['art']; }
  $names = $arts ? article_names($arts) : array(); ?>
  <section class="panel"><h2>Order requests</h2>
  <p class="muted" style="margin-top:0">Requests are saved in wau_erp (table portal_requests). Set a status here so the customer can follow it.</p>
  <div class="tbl"><table><tr><th>Request</th><th>Customer</th><th>Details</th><th>Status</th><th>Update</th></tr>
  <?php foreach ($reqs as $r): $p = json_decode($r['payload'], true); $isOrder = $r['kind'] === 'order';
    $state = $r['status'] ? $r['status'] : 'Sent'; ?>
    <tr><td><b class="mono"><?= ($isOrder ? 'REQ-' : 'RET-') . str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></b><br><span class="muted"><?= $isOrder ? 'Order' : 'Return' ?> · <?= h(date('j M Y H:i', strtotime($r['created_at']))) ?></span></td>
      <td><span class="mono"><?= h($r['customer']) ?></span><br><span class="muted"><?= h($r['login']) ?></span></td>
      <td><?php if ($isOrder) { foreach ($p['items'] as $it) { $n = isset($names[$it['art'] . '|' . $it['color']]) ? $names[$it['art'] . '|' . $it['color']] : null;
          echo h($it['rolls']) . ' × ' . h($n ? $n['name'] . ' ' . $n['color_name'] : $it['art'] . ' ' . $it['color']) . ' <span class="mono muted">' . h($it['art'] . '·' . $it['color']) . '</span><br>'; }
          echo '<span class="muted">' . ($p['po'] !== '' ? 'PO ' . h($p['po']) . ' · ' : '') . ($p['date'] ? 'wanted ' . h($p['date']) . ' · ' : '') . h($p['deliver_to']) . '</span>';
          if ($p['notes'] !== '') echo '<br><i>' . h($p['notes']) . '</i>';
        } else { echo h($p['so']) . ' · ' . h($p['rolls']) . ' roll(s) ' . h($p['art'] . '·' . $p['color']) . ' · <b>' . h($p['reason']) . '</b><br><i>' . h($p['description']) . '</i>'; } ?></td>
      <td><span class="pill <?= in_array($state, array('SO created', 'Approved', 'Resolved')) ? 'ok' : (in_array($state, array('Cannot fulfil', 'Rejected')) ? 'err' : 'warn') ?>"><?= h($state) ?></span>
        <?= $r['so_number'] ? '<br><span class="mono">' . h($r['so_number']) . '</span>' : '' ?><?= $r['note'] ? '<br><span class="muted">' . h($r['note']) . '</span>' : '' ?></td>
      <td><details><summary>Update</summary><form method="post" style="display:grid;gap:6px;margin-top:8px;min-width:220px">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select name="status"><?php foreach ($isOrder ? array('Received by our team', 'SO created', 'Cannot fulfil') : array('Received by our team', 'Approved', 'Rejected', 'Resolved') as $s)
          echo '<option' . ($s === $state ? ' selected' : '') . '>' . h($s) . '</option>'; ?></select>
        <?php if ($isOrder): ?><input name="so" placeholder="SO number" value="<?= h($r['so_number']) ?>"><?php endif; ?>
        <input name="note" placeholder="Note for the customer" value="<?= h($r['note']) ?>"><button class="small primary">Save</button></form></details></td></tr>
  <?php endforeach; if (!$reqs) echo '<tr><td colspan="5" class="muted">No requests yet.</td></tr>'; ?>
  </table></div></section>
<?php endif; ?>
</main>
<?php endif; ?>
</body></html>
