# Working on the WAU customer portal

Read README.md first: it covers what the portal does, install, updates and auto-pull. This file adds the context and the rules
that are not written there.

## The business

- **Wahyu Agung Utama** (WAU), Surabaya. Customers know it as a **PT SIMNU representative** (think: SIMNU's Surabaya
  office). Never call WAU a "distributor" or "reseller" in anything customers see, never name other factories, never say
  where WAU sells (sales territory).
- WAU sells synthetic leather **in rolls only, priced per meter**. Prices differ per customer, so the portal shows **no prices**.
  Custom colors or specs: only "ask our sales team".
- Contact: sales@wautama.com, (031) 843 7377.

## Where things run

| What | Where |
|---|---|
| Portal (this repo) | Office Windows Server **192.168.20.102**, XAMPP (Apache + PHP 8), folder `C:\wau-portal` |
| Public address | https://portal.wautama.com through the **Cloudflare Tunnel** shared with WAU ERP; Apache serves only `public\` on **127.0.0.1:8081** |
| Main system database | `si_wa` on the same server, **MySQL 5.1**: read only for the portal user `wau_portal` |
| Portal tables | `wau_erp` database, tables `portal_*` |
| Staff screens | WAU ERP (separate repo), Sales › Customer portal; `/staff/` only redirects there |
| Company website | https://wautama.com, one static `index.html` in Hostinger `public_html` (not in this repo) |
| Careers site | https://career.wautama.com, a Node.js site on Hostinger (not in this repo) |

## Rules for the code

- **si_wa is read only.** Never write to it, never ask for more rights on it. Write only to `wau_erp.portal_*`.
- **Each customer sees only their own data.** Every query is limited to the signed-in login's customer code; an SO, SJ,
  request or photo of another customer answers 404. Keep it that way in every new action.
- **Nothing financial goes to customers**: no prices, amounts, payments or invoices in any API answer.
- **Stock is only "in stock" or "out of stock"**, never a quantity, also not in the API answer.
- Articles marked inactive (`masterbarang.IsAktif`, setting `cols['art_active']`) are hidden and cannot be ordered.
- MySQL 5.1: connection charset `utf8` (not utf8mb4); no window functions or CTEs. Old names in si_wa can hold Windows-1252
  characters: `tr()` in `app/data.php` converts them, and JSON is encoded with `JSON_INVALID_UTF8_SUBSTITUTE`, so one odd name
  never empties a whole answer.
- Column names differ from the obvious ones (e.g. `masterbarang.nama` / `warna`, `masterpelanggan.nama`): always go through
  `T()` / `C()` and the `tables` / `cols` settings, and add new columns to `check.php`.
- Passwords live only in `config.php` (git-ignored, outside `public\`). Never commit secrets, never print them.
- The page tells browsers not to translate it; the portal is Bahasa Indonesia by default with an EN switch (`ID`, `ID_RX` in
  `public/index.html`). Any new text needs both languages.

## Order status as customers see it

No SJ yet = Order Received · SJ made = Order Processed · SJ on a delivery trip = Shipped (Out for delivery / Arriving soon from
the drivers' app) · all SJ delivered = Delivered, otherwise Partly delivered ("x of y rolls"). One SO can have several SJ; each SJ
is tracked as its own shipment. A request (REQ-0007) becomes its SO when the SO's note names it.

## Deploying

Push to `main`: the server's auto-pull picks it up within 2 minutes (`deploy\auto-pull.log`). If a change needs a new setting,
say so and have the user run `C:\xampp\php\php.exe C:\wau-portal\check.php`. Apache config changes (`deploy\apache-portal.conf`)
are not applied by a pull: the user copies them into `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, checks with
`C:\xampp\apache\bin\httpd.exe -t` and restarts Apache (Stop, then Start).

Lessons from setting it up: Windows Apache rejects backslashes in `<DirectoryMatch>` regexes; `DirectoryIndex` must list
`index.php` for `/staff/`; a secure-only cookie is dropped on `http://127.0.0.1:8081`, so the session cookie is secure only on
HTTPS visits (the tunnel sends `X-Forwarded-Proto: https`).

## Testing

There is no test suite. Test against a MySQL-compatible database with sample si_wa / wau_erp tables and `php -S` on
`public/`, then drive the pages with Playwright: sign in, order request, My orders, a phone width (390 px) and dark mode,
and check that another customer's SO answers 404. Run `php -l` on every PHP file you change.

## Commits

One change per commit; the subject says what the customer or staff will notice ("Order status: Arriving soon when the
driver is close to the customer"), the body says why if it is not obvious.
