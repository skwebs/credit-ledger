# Card Ledger — React + TypeScript + PHP + SQLite

A mobile-first, single-user credit-card expense manager styled like a native Android finance app. The project supports both purchase calendar-month reporting and card-specific billing-cycle tracking.

## Features

- **Android-style dark UI:** compact spacing, safe-area support, accessible touch targets, responsive modal sheets, bottom navigation, and top-level toast messages.
- **Fast initial load:** after the session check, one authenticated `bootstrap` API request returns the dashboard, card list, recent transactions, UPI apps, payments, statements, and settings together to avoid six concurrent PHP/SQLite requests on shared hosting.
- **Dashboard:** combined active billing-cycle expenses across all active cards, previous billed/unpaid dues, a collapsible summary, card-specific current-cycle spending and dues, plus the five latest transactions.
- **Budget:** a persistent combined billing-cycle budget. The bar uses a green-to-amber used-budget segment, gray remaining budget, and red excess when spending exceeds the limit. Calendar-month purchase totals are shown separately.
- **Transactions:** newest-first entries grouped by transaction calendar month; search row and filter drawer; date, card, payment-method and UPI app filters; each row also shows the card's settlement-based cycle range.
- **Card details:** dedicated billing-cycle picker and transaction list for that card. Cycle allocation uses the card's configured billing rule and settlement date. Different cards can use different cycles.
- **Fast expense entry:** amount first, no automatic keyboard focus, transaction date/time, same-settlement-date checkbox, horizontal card tiles, UPI-first method choices, merchant/notes and optional JPG/PNG/WebP attachment (up to 5 MB).
- **Statements and payments:** automatic statement snapshots for completed cycles, full/partial bill payments, oldest-due-first payment allocation and unpaid/partially paid/paid status. Bill payments never count as new expenses.
- **Settings:** add/edit/archive credit cards, edit each card's billing convention, manage UPI app names, configure budget, view payment history, and install guidance.
- **Authentication:** first-run account setup, password hashing, password show/hide toggle, CSRF protection, optional 30-day Remember me token stored as an HttpOnly cookie and hashed on the server.
- **Installable PWA:** app manifest, icons, scoped service worker, standalone display mode, and static app-shell caching. Private API responses are deliberately not cached.
- **PHP + SQLite:** PDO prepared statements, integer paise for money, SQLite foreign keys and schema migration for attachments/settings/remember tokens.

## Requirements

- PHP 8.1+ with `pdo_sqlite` and `fileinfo` enabled.
- Node.js 20+ and npm to create the optimized frontend build.
- HTTPS strongly recommended and required for reliable PWA installation/service-worker behavior on a public website.

## Local development (Windows PowerShell)

Open PowerShell in this project directory.

1. Install frontend dependencies:

   ```powershell
   npm install
   ```

2. Start PHP in Terminal 1, from the project root:

   ```powershell
   php -S 127.0.0.1:8080 -t .
   ```

3. Start Vite in Terminal 2:

   ```powershell
   npm run dev
   ```

4. Open the Vite URL, typically `http://localhost:5173/`. During development Vite proxies `/api` to PHP on `127.0.0.1:8080`. The production API base path is derived from Vite's configured base path, so local development and the production subdirectory use the proper URL.

5. Build the optimized frontend:

   ```powershell
   npm run build
   ```

   Vite writes the production frontend into `dist/`. The included `dist/` is a CDN-based browser-ready fallback and requires an internet connection for React, Lucide, and Tailwind CDN assets. `npm run build` replaces it with Vite's normal bundled production build when dependencies are installed; the Tailwind CSS v4 browser CDN remains enabled to match the existing project setup.

## Shared-hosting deployment at `/credit-card/`

The Vite production base is configured as `/credit-card/`. If you deploy to a different path, update `vite.config.ts`, the PWA manifest, then rebuild.

1. Enable HTTPS/SSL for `web.isatish.in` in cPanel.
2. Run `npm install` and `npm run build` on your computer.
3. In cPanel File Manager, open the document-root subdirectory that maps to `https://web.isatish.in/credit-card/` (usually `public_html/credit-card/`).
4. Upload and extract the **contents of `dist/`** directly into that directory. `index.html`, `assets/`, `manifest.webmanifest`, `sw.js`, and `icons/` should be at the deployment root—not inside another `dist/` directory.
5. Upload the project's `api/` directory alongside the frontend so `api/index.php` is available at `/credit-card/api/index.php`.
6. Keep `storage/.htaccess`. The API's default database path is `storage/app.sqlite`; it creates the schema automatically on first API access. Do not overwrite or delete an existing `storage/app.sqlite` during frontend updates.
7. Prefer moving `storage/` outside the public document root and setting `CCM_DB_PATH` to its absolute path if your hosting panel supports application environment variables. Otherwise, keep the supplied deny rule, test that the database cannot be downloaded, and ensure PHP has write permission to the storage directory.
8. Do **not** upload `node_modules/` or the React source directory as part of the public frontend. Keep `api/` and protected database storage alongside the built frontend.

### Verify deployment

- Frontend: `https://web.isatish.in/credit-card/`
- API status: `https://web.isatish.in/credit-card/api/index.php?action=status`

The API status should return JSON. If it returns a 404, confirm that API requests use `/credit-card/api/index.php`, and that `api/index.php` is in the matching document-root subdirectory. If PHP reports `could not find driver`, enable PDO SQLite in cPanel.

## Database/security notes

- Money is stored as integer paise to avoid floating-point rounding errors.
- Purchase reporting by calendar month uses `txn_at`; per-card cycle selection and budget aggregation use each card's `settlement_date` and configured billing rule.
- Issued statements are snapshots. The API guards against editing/deleting a transaction in a generated statement because a controlled statement correction workflow is needed to preserve audit history.
- Bill payments reduce statement dues. They are never entered into purchase totals.
- Remember-me tokens are random, HttpOnly cookies; only SHA-256 hashes are persisted in SQLite. Logout revokes the current token.
- Receipt images are validated by MIME type and size and stored under the database's storage directory; they're served through an authenticated API endpoint rather than a public attachment URL.
- The service worker caches the app shell and static assets only. Requests under `/api/` are excluded from the cache so private financial data is never cached by the PWA worker.
- Use HTTPS, maintain regular SQLite backups, and do not share a database file that contains real financial records.

## Project layout

```text
api/index.php                 PHP JSON API, auth, SQLite schema, billing logic
api/.htaccess                 API directory rules
storage/.htaccess             Denies direct web access to storage
frontend/src/App.tsx          React UI and transaction flows
frontend/src/main.tsx         React entry point
frontend/src/style.css        Dark Android-style visual refinements
frontend/src/vite-env.d.ts    Vite TypeScript environment definitions
public/manifest.webmanifest   PWA manifest
public/sw.js                  PWA service worker
public/icons/                 192px and 512px PWA icons
dist/                         Ready-to-serve fallback output; replaced by npm run build
vite.config.ts                Local proxy and production subdirectory base
```

## UI and statement workflow updates

- Dashboard Monthly Overview and Credit Cards sections collapse independently.
- Display density is user-selectable under Settings: Compact or Comfortable. The preference is stored in this browser.
- Credit-card tiles support horizontal scrolling by touch, mouse wheel, and visible arrow controls.
- UPI app selection uses a searchable sheet instead of a native dropdown.
- Credit-card details include a compact **Add Transaction** action that preselects the current card in the transaction form.
- Billing-cycle selection opens a mobile-friendly bottom sheet, showing **Current** first and historical cycles by full month/year, with the cycle date range and statement amount. Selecting a cycle updates its statement details and transaction list.
- Transaction merchant/particular is optional; category and comma-separated tags are available alongside notes and receipt attachments.
- Transactions in past cycles may be added/edited/deleted unless that statement has been manually locked.
- For a generated statement, use **Confirm statement** to record the amount from the bank statement. Confirmation and manual locking are separate actions. Locking requires confirmation; unlock before editing transactions in a locked cycle.
- Confirmed bank statement amounts are used for the statement's outstanding balance and payment allocation. If transaction changes alter the recorded transaction total, the confirmation is cleared and should be repeated.

## Updating an existing installation

1. Back up `storage/app.sqlite` and any uploaded receipt files before deploying.
2. Upload the updated `api/` directory and the **contents of `dist/`** into the existing `/credit-card/` application directory.
3. Do not replace or delete `storage/app.sqlite`.
4. Database schema changes are applied automatically when the PHP API opens the database. Keep `storage/` writable by PHP but not publicly browsable/downloadable.
5. Use HTTPS for PWA installation and persistent login cookies.

The included `dist/` is the CDN fallback build. To create an optimized Vite build locally, run `npm install` and `npm run build` in the project root.

## UI fixes in this package (2026-10-19)

- The credit-card detail page now has a floating Add Transaction action above bottom navigation. The duplicate button above the cycle transaction list was removed; the card-summary action remains and preselects the card.
- Dashboard overview now places previous dues on the left and current-cycle spending / budget on the right, with the progress bar and remaining/excess amount below.
- Transaction amount input, card tiles, date/time field, and settlement-date checkbox use a more compact layout.
- Save Expense / Save changes and Cancel are sticky at the bottom of the transaction form while scrolling, with mobile safe-area spacing.
- Billing-cycle selection remains a bottom sheet with full month/year labels.

Deployment: upload the contents of `dist/` to the public `/credit-card/` directory, while preserving `api/`, `.htaccess`, and your existing `storage/` directory. **Back up `storage/app.sqlite` before deployment and do not replace it with a ZIP copy.** Source changes are in `frontend/src/`; the production CDN fallback is in `dist/`.

### UPI application settings

In **Settings → UPI applications**, select the pencil icon to rename an app inline. Save with the check icon or cancel with the X icon (Escape also cancels). The trash icon deactivates an app after confirmation; it does not delete historical transactions or change their stored app IDs. Duplicate names and blank names are rejected by the API.

## Targeted UI restoration and build dependency

- Restored **Add Card** in the Dashboard's Credit Cards section. It opens the existing CardForm in create mode and uses the existing `POST /api/index.php?action=cards` route. The existing Edit Card action on the card details page and update route are preserved.
- UPI applications now use the same inline-add pattern as Categories: Add app reveals an inline name field and Save action. Existing pencil/tick/X inline editing and deactivation behaviour remain intact.
- TypeScript is pinned to `5.9.3` in `devDependencies` for the `build:cdn` workflow. Run `npm install` once to create/update `package-lock.json`, then commit that lockfile and use `npm ci` for repeatable builds. This environment could not reach the npm registry to generate a lockfile, so no lockfile is included.

No SQLite database or user attachments are included in this archive. Back up the production `storage/` directory before deployment.
