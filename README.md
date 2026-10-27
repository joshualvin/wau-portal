# WAU customer portal

Customer portal for Wahyu Agung Utama, self-hosted on the office Windows Server next to wau-erp.
Customers sign in at **https://portal.wautama.com** to request a quote: they pick rolls from the product list (with stock), add PO, delivery address, date and notes, and send. No prices are shown; the salesperson sends the price and makes the SO once the customer agrees. Customers also follow their requests and orders. The portal is in Bahasa Indonesia by default, with an English switch (wording in `public/index.html`, `ID` and `ID_RX`).
Staff manage requests, custom articles and customer logins in **WAU ERP, Sales › Customer portal** (https://erp.wautama.com/erp.php#portal), signed in with their WAU ERP account; customer logins are for WAU ERP administrators. https://portal.wautama.com/staff/ sends them there (moved 27 Oct 2026; the old staff passwords in config.php, `staff_users`, are no longer used).

- Reads `si_wa` live, **read only**. Logins and requests are saved in `wau_erp` (tables `portal_*`).
- Each request gets a reference (REQ-0001). Staff see it in WAU ERP (Home › Portal orders, Sales › Customer portal), create the SO in the main system and link it.
- **Revise or cancel**: while a request is open (not SO created, Cannot fulfil or Cancelled) the customer can press *Revise request* (its products replace the quote list and its details fill the checkout; sending updates the same REQ number, keeps the earlier version as `previous` and makes it new again for sales, marked revised) or *Cancel request* (status *Cancelled*). Checked on the server (`my_open_request()`).
- **Request becomes its SO**: the salesperson types the request number in the SO's note in the main system (exactly *REQ-0007*, with the dash). The portal then lists that SO in its place ("from REQ-0007"; opening the request opens the SO), shows it as Order Processed until its first surat jalan, and wau-erp's Home drops the request. Typing the SO number on the staff page links them too.
- Statuses of a quote request: *Sent* (no status yet) → *Received by our team*, shown to the customer as **Acknowledged by <salesperson>** (set by the **Confirm** button in WAU ERP) → *SO created* (SO linked) or *Cannot fulfil*. The request page shows them on the order progress bar: Order Received (with who acknowledged it), Order Processed once the SO is linked. *Being processed* still exists for the staff screen but no longer lights Order Processed.
- Reached from the internet only through the Cloudflare Tunnel; Apache serves it on 127.0.0.1:8081.

## Folders

| Path | What |
|---|---|
| `public/` | The only folder Apache serves: `index.html` (customer pages), `api/portal.php`, `staff/index.php` (sends staff to WAU ERP) |
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
