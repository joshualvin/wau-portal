# WAU customer portal

Customer portal for Wahyu Agung Utama, self-hosted on the office Windows Server next to wau-erp.
Customers sign in at **https://portal.wautama.com** to send order requests: they pick rolls from the product list (with stock), add PO, delivery address, date and notes, and send. Nothing else is shown to them.
Staff manage logins and requests at **https://portal.wautama.com/staff/**.

- Reads `si_wa` live, **read only**. Logins and requests are saved in `wau_erp` (tables `portal_*`).
- Each request gets a reference (REQ-0001). Staff see it on the Requests tab, create the SO in wau-erp and mark it done.
- **Request becomes its SO**: the salesperson types the request number in the SO's note in the main system (exactly *REQ-0007*, with the dash). The portal then lists that SO in its place ("from REQ-0007"; opening the request opens the SO), shows it as Order Processed until its first surat jalan, and wau-erp's Home drops the request. Typing the SO number on the staff page links them too.
- Statuses of an order request: *Sent* (no status yet) → *Received by our team* → *Being processed* (also set by the **Confirm** button on wau-erp's Home, Portal orders) → *SO created* or *Cannot fulfil*. The request page shows them on the order progress bar: sent = Order Received, Being processed or SO created = Order Processed (with its date).
- Reached from the internet only through the Cloudflare Tunnel; Apache serves it on 127.0.0.1:8081.

## Folders

| Path | What |
|---|---|
| `public/` | The only folder Apache serves: `index.html` (customer pages), `api/portal.php`, `staff/index.php` |
| `app/` | PHP helpers (`lib.php`, `data.php`), not reachable from the web |
| `config.example.php` | Copy to `config.php` (git-ignored) and fill in |
| `check.php` | Setup check: `C:\xampp\php\php.exe C:\wau-portal\check.php` |
| `deploy/` | Apache site (`apache-portal.conf`) and `update.bat` |

## First install on the server

1. **Git for Windows**: install from git-scm.com if `git --version` does not work.
2. **Get the code** (private repository, read-only deploy key, see below):
   `git clone git@github.com:OWNER/wau-portal.git C:\wau-portal`
3. **MySQL user** (as root; same pattern as wau_sync, `localhost` host):
   ```sql
   CREATE USER 'wau_portal'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT SELECT ON si_wa.* TO 'wau_portal'@'localhost';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON wau_erp.* TO 'wau_portal'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. **Settings**: copy `config.example.php` to `config.php`, fill in the password, staff users and the `cols` block
   (same names as in `C:\wau-sync\config.php`, e.g. `'cust_name' => 'nama'`).
5. **Check**: `C:\xampp\php\php.exe C:\wau-portal\check.php` until it says *everything is ready*.
6. **Apache**: append `deploy\apache-portal.conf` to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, make sure
   `LoadModule headers_module` is enabled in `httpd.conf`, restart Apache. Test on the server: http://127.0.0.1:8081/
7. **Cloudflare Tunnel**: Zero Trust → Networks → Tunnels → the existing wau-erp tunnel → Public hostnames → Add:
   subdomain `portal`, domain `wautama.com`, service **HTTP** `127.0.0.1:8081`.

## Updating

Push changes to GitHub, then on the server run `C:\wau-portal\deploy\update.bat`.
`config.php` is never in the repository, so an update never touches passwords or settings.

## Deploy key (server pulls without a personal password)

On the server: `ssh-keygen -t ed25519 -C wau-portal-server -f %USERPROFILE%\.ssh\wau_portal` (no passphrase),
then on GitHub: repository → Settings → Deploy keys → Add, paste `wau_portal.pub`, leave **Allow write access** off.
Tell Git to use it, in `%USERPROFILE%\.ssh\config`:
```
Host github.com
  IdentityFile ~/.ssh/wau_portal
```
