# Shared-hosting deployment

## Requirements

- PHP **8.3+** with PDO MySQL, Fileinfo, sessions, OpenSSL, and standard image-info functions.
- MySQL **8.0+** using InnoDB and utf8mb4; the configured database must already exist.
- An Apache-compatible web server with rewrite support, or equivalent routing of non-file requests to `public/index.php`.
- A writable `storage/uploads` directory and a persistent writable PHP session directory.
- Outbound access from the PHP host to your database and mail server.
- A way to run scheduled PHP CLI commands for email and saved-search digests.

Confirm these capabilities with your specific one.com plan. Docker is for local development; upload the PHP application to the shared host itself.

## GitHub Actions deployment to a public `ONECOM_FILE_DIR`

The repository includes **Application checks** and a manually started **Deploy to one.com** workflow. Deployment runs the application checks first, then uploads using SFTP. No remote shell access is required for the upload.

Configure these in the GitHub repository's **Settings → Environments → one.com** (repository-level secrets also work):

| Setting | Type | Purpose |
| --- | --- | --- |
| `ONECOM_SFTP_HOST` | Secret | SFTP server hostname, without a URL scheme |
| `ONECOM_SFTP_USER` | Secret | SFTP username |
| `ONECOM_SFTP_PASSWORD` | Secret | SFTP password, if using password authentication |
| `ONECOM_SSH_KEY` | Secret | Optional unencrypted private deployment key; its public key must be authorized on the server |
| `ONECOM_FILE_DIR` | Secret or variable | Existing public website directory, e.g. `/account/public_html` |
| `ONECOM_KNOWN_HOSTS` | Secret or variable | Verified SSH host-key entries in OpenSSH `known_hosts` format |
| `ONECOM_PRIVATE_DIR` | Optional secret or variable | Existing-parent path for private application files; defaults to a sibling `marked-private` directory |
| `ONECOM_SITE_URL` | Secret or variable | Canonical HTTPS website origin; required when the application folder is inside the public root |
| `ONECOM_SFTP_PORT` | Optional variable | SFTP port; defaults to `22` |
| `ONECOM_PHP_BIN` | Optional variable | SSH PHP executable used by the scheduled worker; defaults to `php` |

Either a password or private key is required. If both are supplied, the client attempts key authentication and can use the password when the server rejects that key. A malformed/encrypted key must be corrected or the key secret removed to use password-only authentication.

Obtain the server's SSH host key from the provider or collect its `known_hosts` entry with `ssh-keyscan -H your-sftp-host` and independently verify the fingerprint against the provider's published/server information. Put the verified entry into `ONECOM_KNOWN_HOSTS`. For a nonstandard port, use `ssh-keyscan -H -p PORT HOST`. This is the server's public host key, not your private `ONECOM_SSH_KEY`.

With `ONECOM_FILE_DIR=/account/public_html`, the default result is:

```text
/account/
  marked-private/
    .env.example            # Production template, uploaded by the workflow
    .env                    # Create this yourself; never overwritten/downloaded
    app/
    bin/console.php
    database/schema.sql
    storage/uploads/        # Preserved across deployments
    storage/logs/
  public_html/              # ONECOM_FILE_DIR: public website
    .htaccess
    index.php               # Automatically points to the private bootstrap
    assets/
```

The SFTP account must be able to write to both directories, and PHP must be able to read the private application and write its upload directory. If one.com provides a specific private directory, set `ONECOM_PRIVATE_DIR` to it. SSH and web PHP can have different filesystem views even when they report the same user ID and script path; a successful CLI migration does not prove that web PHP can access a directory under the SSH home.

### Hosting restricted to the webroot

If web PHP cannot see the SSH home directory and you cannot write beside the webroot, explicitly place the application in a protected child folder:

```text
ONECOM_FILE_DIR=/path/to/public-folder
ONECOM_PRIVATE_DIR=/path/to/public-folder/_marked
ONECOM_SITE_URL=https://your-domain.no
```

Use the actual SFTP paths for the first two values. If the SFTP account is jailed directly to the site root, those paths can be `/` and `/_marked`. `ONECOM_SITE_URL` must be the canonical HTTPS origin serving that directory, with no URL subdirectory or redirects.

The deployer first installs `_marked/.htaccess` containing `Require all denied`. It then uploads harmless temporary marker files and verifies both that the website serves the public marker unchanged and that the protected marker returns HTTP **403**. Only after this succeeds are application files uploaded. Marker files are removed after the check. A missing/ignored `.htaccess`, wrong domain, redirect, or non-403 response aborts the deployment before application/configuration templates are uploaded.

The generated public `index.php` uses `$markedPrivate = __DIR__ . '/_marked';`. Apache denies direct HTTP access to this directory, but PHP can read its files and serve authorized media through the application. After a successful deployment, copy your existing production `.env` to `_marked/.env`; keep the existing database. The folder is physically inside the webroot but is not intended to be web-accessible.

Update any scheduled mail/digest commands to use the new `_marked/bin/console.php` filesystem path as well.

Verify that `https://your-domain.no/_marked/.env.example` returns 403 before copying `.env`. Do not remove the child `.htaccess`; later deployments reinstall and verify it. The repository's `deploy/private.htaccess` is the exact access-denial file if you need to inspect it.

The workflow uploads application files, the schema, a production environment template, and public assets. It excludes your local `.env`, demo seeder, demo media, Docker configuration, and tests. It preserves existing server configuration, user uploads, and unrelated files. Each file is replaced through OpenSSH's atomic-rename extension, with `index.php` published last; the deployment as a whole is not a transactional release or automatic rollback.

### First deployment

1. After the files/workflows are pushed to GitHub, open **Actions → Deploy to one.com → Run workflow**. The workflow uses the GitHub environment named `one.com`; change the workflow's `environment` field if yours has another name.
2. Using your SFTP client, copy the uploaded private `.env.example` to `.env` and fill in the real database, domain, and mail settings described below. Restrict `.env` to the hosting account (typically permissions `600`). Until `.env` exists, the uploaded public entry point returns a Nynorsk setup message with HTTP 503.
3. Install the database schema, set up mail/digest scheduling, and create your real administrator as described below. The upload workflow does not run migrations or change production accounts.
4. Perform the hosted workflow checks below. Later deployments preserve `.env` and uploaded media; schema changes must be applied deliberately.

The deployer can also be run from a trusted machine with the same settings exported as environment variables:

```sh
python3 -m pip install -r deploy/requirements.txt
python3 deploy/onecom.py
```

Packaging checks require only Python's standard library:

```sh
python3 -m unittest discover -s tests -p 'test_deploy*.py' -v
```

## 1. Application files and document root

The document root normally contains only the contents of **`public/`**, not the repository root. On restricted hosting, it can additionally contain the HTTP-protected application folder described above. The `.env`, application code, uploads, tests, and database files must not be publicly accessible.

Preferred layout:

```text
private/marked/
  .env
  app/
  bin/
  database/
  storage/uploads/
  public/              <-- domain's document root
```

If the host fixes your web root to `public_html`, keep the application outside it and copy only the contents of `public/` into `public_html/`. Update the `require` in the copied `public_html/index.php` to the absolute path of `private/marked/app/bootstrap.php`. The bootstrap derives all other private paths from its own location. Include the hidden `.htaccess` file when uploading public files.

The site assumes it is served at the domain root, rather than a URL subdirectory. Make `storage/uploads` writable by PHP using the host's file ownership/permission tools.

## 2. Production environment

Create a private `.env` file based on `.env.example`. Use your own values:

```dotenv
APP_ENV=production
APP_URL=https://your-domain.no
SESSION_SECURE=1
DB_HOST=your-database-host
DB_PORT=3306
DB_DATABASE=your-database-name
DB_USERNAME=your-database-user
DB_PASSWORD=your-database-password
MAIL_TRANSPORT=mail
MAIL_FROM=hei@your-domain.no
```

Values are literal strings; do not put inline comments after them. Keep this file out of version control. Enable HTTPS for the domain before setting `SESSION_SECURE=1`.

For a remote database that uses certificate-verified TLS, add:

```dotenv
DB_SSL_CA=/absolute/private/path/provider-ca.pem
```

The database provider must allow connections from the web host. Use the provider's hostname and CA certificate as required; no production database credentials belong in the repository.

## 3. Create tables and categories

Using the host's terminal/SSH access:

```sh
php /absolute/private/path/marked/bin/console.php migrate
```

The initial migration creates missing tables and categories and can be repeated. Future schema changes should be added as explicit versioned migrations; rerunning this initial schema does not alter existing columns.

If CLI database access is unavailable, import `database/schema.sql` using the provider's database management tool, then insert categories through that tool or the admin panel. Example initial categories: Heim og interiør; Elektronikk; Klede og tilbehøyr; Sport og friluftsliv; Hobby og fritid; Verktøy og hage; Barn og utstyr; Bøker og musikk.

Do not run the local demo seeder on production.

## 4. Email delivery

`MAIL_TRANSPORT=mail` uses the hosting provider's PHP `mail()` service. The sender address should be a configured address on your domain.

If you use authenticated SMTP instead:

```dotenv
MAIL_TRANSPORT=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=hei@your-domain.no
MAIL_PASSWORD=your-mail-password
MAIL_FROM=hei@your-domain.no
```

`MAIL_ENCRYPTION=ssl` supports implicit TLS (commonly port 465). Leave encryption empty only for a trusted local relay such as Mailpit. Use the mail provider's actual settings.

Registration, password resets, messages, and trade events enqueue mail. Configure the provider's scheduler to run these commands, using its actual PHP binary and absolute application path:

```cron
* * * * * /usr/bin/php /absolute/private/path/marked/bin/console.php mail
15 * * * * /usr/bin/php /absolute/private/path/marked/bin/console.php digest
```

The digest command can run hourly; each saved search is processed no more than once per 24 hours. MySQL advisory locks prevent overlapping workers from processing the same queue simultaneously. Delivery failures are logged to PHP's error log and retried up to five attempts. To retry a failed delivery after fixing the configuration, reset its `mail_queue.attempts` to zero in the database management tool.

### No cron on one.com: GitHub Actions worker

The **Process marketplace email** workflow runs approximately every five minutes using GitHub's scheduler, and also supports **Run workflow** for an immediate manual run. It connects over SSH using the same `one.com` environment and `ONECOM_SFTP_HOST`, port, username/password or key, `ONECOM_KNOWN_HOSTS`, public directory, and private directory settings as deployment.

It resolves the installed private directory over SFTP, checks that the console and `.env` exist, and executes:

```sh
php /resolved/private/path/bin/console.php digest
php /resolved/private/path/bin/console.php mail
```

Mail credentials remain exclusively in the server's `.env`. The workflow does not download that file, upload application files, or reinstall the database. The hosting account must allow SSH commands, not just SFTP. If needed, set the optional GitHub environment variable `ONECOM_PHP_BIN` to the host's PHP executable path. The resolved SFTP filesystem path must also be accessible to the SSH session.

After pushing the workflow to the default branch, open **Actions → Process marketplace email → Run workflow** to verify the first run. Subsequent runs are scheduled automatically; GitHub may delay scheduled executions, so email delivery is not guaranteed at an exact minute. Each saved search still produces at most one digest per 24 hours. If the repository or environment requires Actions/secret access to be enabled, enable it there. Check this workflow's logs for command output and mail errors.

There is no need for one.com cron when using this workflow, and there is no public endpoint that runs administrative jobs.

## 5. Administrator setup

Register your real account and verify its email address, then run:

```sh
php /absolute/private/path/marked/bin/console.php admin you@example.com
```

Without SSH, the same promotion can be done through the provider's database management tool:

```sql
UPDATE users SET role = 'admin' WHERE email = 'you@example.com';
```

The admin panel will appear in navigation when signed in. Administrators can view bidder identities but cannot read other users' private conversations through the application.

## 6. Operate and verify

Use the local test site to explore workflows first. On the hosted site, verify a registration email, password reset, image upload, message notification, offer acceptance, and dual completion with real test accounts. Confirm the domain cannot serve `.env` or private files and that private attachments require the relevant conversation participant.

Back up the MySQL database and `storage/uploads` together. Keep PHP error display disabled and error logging enabled. The Docker PHP settings in `docker/php.ini` provide a reference for upload and session settings; shared hosting must apply those through its own PHP configuration panel.
