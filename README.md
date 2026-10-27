# Marked

A PHP/MySQL marketplace for Norway, in **Nynorsk** and **NOK**. Warm orange accents, peach panels, off-white backgrounds, and responsive layouts.

## Run the Docker test site

Requires Docker with Compose. No local PHP, Composer, or Node installation is needed.

```sh
cp .env.example .env
docker compose up -d --build
docker compose exec web php bin/console.php seed
```

- **Website:** http://localhost:8080
- **Local email inbox:** http://localhost:8025
- MySQL runs privately inside Docker. Test data and uploaded images persist in named volumes.
- Database tables and initial categories are created automatically by the `init` service.
- The scheduler delivers queued email every minute and checks saved searches for daily digests.
- Source files are mounted into the container, so PHP, CSS, and JavaScript edits are reflected immediately.

If port 8080 is occupied, set both `WEB_PORT=8088` and `APP_URL=http://localhost:8088` in `.env`, then run `docker compose up -d`. `MAILPIT_PORT` can also be changed. `.env` is ignored by Git.

The website binds to `0.0.0.0`, so other devices on your local network can open `http://<host-LAN-IP>:<WEB_PORT>`. To make emailed verification and password-reset links work from those devices, set `APP_URL` to that same LAN URL and run `docker compose up -d` again. The local email inbox is available on the host at `http://localhost:8025`.

### Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Seller | `seljar@marked.test` | `Marked-demo-2026` |
| Buyer | `kjopar@marked.test` | `Marked-demo-2026` |
| Administrator | `admin@marked.test` | `Marked-demo-2026` |

Seeding is explicit, repeatable, and restricted to `APP_ENV=local`. It adds eight example listings with locally generated illustrations, verified accounts, and an example bid. Uploaded user images support JPG, PNG, and WebP.

To try a trade, sign in as the buyer, bid on a seller's listing, then sign in as the seller in a different browser/private window to accept. Both users confirm completion before reviewing. To try registration, use any email address and open its verification message in Mailpit; Docker never sends these messages to the internet.

```sh
docker compose logs web scheduler
docker compose exec web php bin/console.php mail
docker compose exec web php bin/console.php digest
docker compose stop
```

`docker compose down` removes containers while retaining data. **`docker compose down -v` deletes the local database and uploaded images.**

## Implemented workflows

- Public listing browsing with category, fylke, kommune, keyword, and sale/wanted filters; paginated results.
- Email/password registration, email verification, sign-in/out, and one-time password-reset links.
- Sale and wanted listings, multiple photos, condition, delivery method, budget/asking price, and optional buyout price.
- Seller profiles, favorites, saved searches, and daily matching-listing emails.
- Bids at any positive amount, seller counteroffers, accept/reject, and withdrawal before acceptance.
- Buyout requests require seller approval; they do not close a listing by themselves.
- Public anonymized bid/counteroffer history, including status changes and UTC timestamps. Sellers and administrators can identify bidders in private controls.
- Accepted offers reserve the listing; competing offers are rejected. The seller can end a reservation and reopen the listing. Both parties confirm completion before it is marked sold.
- One review per participant per completed trade.
- Listing-linked private text/photo conversations, unread counts, polling every four seconds while visible, and email notifications. A conversation is created automatically when a deal is accepted.
- Blocking, listing/user reports, and an admin panel for reports, account suspensions, listing removal, and category creation.

The asking price is a suggestion, not a minimum bid. Offers have no automatic deadline. Counteroffers target one buyer but their prices and timestamps are public. Wanted listings receive messages rather than bids. Payment and delivery are arranged directly between users.

## Code structure

```text
app/bootstrap.php    Environment, PDO, sessions/auth helpers, validation, uploads
app/market.php       Offer/reservation transactions, search, conversation access
app/actions.php      CSRF-protected form actions and authorization
app/views.php        Nynorsk server-rendered pages
app/mail.php         Queued delivery (PHP mail or SMTP), daily search digests
public/              The only directory that should be web-accessible
database/schema.sql  Initial MySQL schema
database/seed.php    Local demo accounts, listings, and illustrations
bin/console.php      Migration, seed, administrator, and mail commands
storage/uploads/     Uploaded media, outside the public document root
tests/               Syntax and end-to-end workflow checks
deploy/              SFTP deployment script and production environment template
.github/workflows/   Automated checks and manual one.com deployment
```

No application framework or external runtime packages are required. Database queries use prepared statements. State-changing actions require CSRF tokens and appropriate account ownership. Trading transitions lock the listing row inside MySQL transactions to serialize competing accept/withdraw/complete operations. Uploads are type/size checked and given random names; private message attachments are served only to participants.

## Checks

With the Docker site running:

```sh
docker compose exec -T web php tests/lint.php
python3 -m unittest discover -s tests -p 'test_deploy.py' -v
python3 tests/e2e.py http://localhost:8080
```

The Python suite uses only the standard library and exercises registration, emailed verification links, password resets, uploads, filtering, favorites, digests, bid privacy, counteroffers, buyout approval, reservation reopening, dual completion, reviews, private chat attachments, blocking, reports, CSRF, access control, and concurrent acceptance from two independent seller sessions.

Run it only against your local Docker site: test support requires `APP_ENV=local`. It clears local rate-limit counters, creates temporary accounts/listings, and removes their database records and files afterward. Test email deliveries remain visible in Mailpit.

## Deploy on one.com / shared hosting

See [docs/deployment.md](docs/deployment.md) for document-root layouts, remote MySQL configuration, email, scheduled tasks, and initial administrator setup. Production credentials are supplied by you through the server's `.env`; the application does not need database access during development.

The **Deploy to one.com** GitHub Actions workflow uses the `one.com` environment and your `ONECOM_*` settings. Since `ONECOM_FILE_DIR` is public, it uploads only public files there and puts application code/configuration/uploads in a separate private directory. See the deployment guide for the required host-key setting, directory access, and first-run configuration. The workflow runs on manual dispatch after checks pass.

## Current boundaries

This is a working first implementation for local testing. Listings can be created and withdrawn; editing published listings is a follow-up. Municipality names are entered as text, with search suggestions from existing listings rather than an authoritative municipality register. Category management currently adds categories. Chat uses polling rather than WebSockets. Digests include at most 20 matching listings per saved search per day. A hosting deployment, provider-specific email setup, and browser/device review should be completed before launch.
