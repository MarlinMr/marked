<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}
date_default_timezone_set('UTC');
function env(string $key, string $default = ''): string { return getenv($key) === false ? $default : getenv($key); }
function db(): PDO {
    static $db;
    if ($db) return $db;
    $options=[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (env('DB_SSL_CA')!=='') { $options[PDO::MYSQL_ATTR_SSL_CA]=env('DB_SSL_CA'); $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true; }
    $db = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_DATABASE', 'marked') . ';charset=utf8mb4', env('DB_USERNAME'), env('DB_PASSWORD'), $options);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function query(string $sql, array $params = []): PDOStatement { $q = db()->prepare($sql); $q->execute($params); return $q; }
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function all(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(mixed $v): string { return number_format((float)$v, abs((float)$v-round((float)$v))>0.0001?2:0, ',', ' ') . ' kr'; }
function url(string $path): string { return rtrim(env('APP_URL', 'http://localhost:8080'), '/') . $path; }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function flash(string $text): void { $_SESSION['flash'] = $text; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    return one('SELECT * FROM users WHERE id=? AND suspended_at IS NULL', [$_SESSION['uid']]);
}
function require_user(bool $verified = true): array {
    $u = user();
    if (!$u) { flash('Logg inn for å halde fram.'); redirect('/login'); }
    if ($verified && !$u['verified_at']) { flash('Stadfest e-postadressa di først. Sjå etter e-posten frå Marked.'); redirect('/account'); }
    return $u;
}
function require_admin(): array { $u = require_user(); if ($u['role'] !== 'admin') throw new RuntimeException('Du har ikkje tilgang.', 403); return $u; }
function check(bool $condition, string $message, int $code = 400): void { if (!$condition) throw new RuntimeException($message, $code); }
function text(string $key, int $min = 1, int $max = 2000): string {
    $v = trim((string)($_POST[$key] ?? ''));
    check(strlen($v) >= $min && strlen($v) <= $max, 'Feltet «' . $key . '» må ha mellom ' . $min . ' og ' . $max . ' teikn.');
    return $v;
}
function password_input(): string {
    $v=(string)($_POST['password']??'');
    check(strlen($v)>=12 && strlen($v)<=72,'Passordet må ha mellom 12 og 72 teikn.');
    return $v;
}
function amount(string $key, bool $optional = false): ?string {
    $v = str_replace(',', '.', trim((string)($_POST[$key] ?? '')));
    if ($v === '' && $optional) return null;
    check((bool)preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $v) && (float)$v > 0, 'Skriv ein gyldig pris større enn null.');
    return $v;
}
function blocked(int $a, int $b): bool { return (bool)one('SELECT 1 FROM blocks WHERE (user_id=? AND blocked_id=?) OR (user_id=? AND blocked_id=?)', [$a,$b,$b,$a]); }
function rate_limit(string $name, int $limit, int $seconds): void {
    $key = hash('sha256', $name . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    query('INSERT INTO rate_limits(bucket,attempts,expires_at) VALUES (?,1,DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<NOW(),1,attempts+1), expires_at=IF(expires_at<NOW(),VALUES(expires_at),expires_at)', [$key,$seconds]);
    check((int)one('SELECT attempts FROM rate_limits WHERE bucket=?', [$key])['attempts'] <= $limit, 'For mange forsøk. Prøv att seinare.', 429);
}
function enqueue(string $email, string $subject, string $body): void { query('INSERT INTO mail_queue(recipient,subject,body) VALUES (?,?,?)', [$email,$subject,$body]); }
function notify(int $uid, string $subject, string $body): void {
    $u = one('SELECT email FROM users WHERE id=? AND suspended_at IS NULL', [$uid]);
    if ($u) enqueue($u['email'], $subject, $body);
}
function token_mail(array $u, string $kind): void {
    $token = bin2hex(random_bytes(32));
    query('DELETE FROM tokens WHERE user_id=? AND kind=?', [$u['id'],$kind]);
    query('INSERT INTO tokens(user_id,token_hash,kind,expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))', [$u['id'],hash('sha256',$token),$kind]);
    enqueue($u['email'], $kind === 'verify' ? 'Stadfest e-postadressa di' : 'Nytt passord på Marked', "Hei, {$u['name']}!\n\nOpne denne lenkja innan ein time:\n" . url('/' . ($kind === 'verify' ? 'verify' : 'reset') . '?token=' . $token));
}
function upload(array $file): ?array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    check($file['error'] === UPLOAD_ERR_OK && $file['size'] <= 8 * 1024 * 1024, 'Biletet må vere under 8 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    check(isset($types[$mime]), 'Bruk JPG, PNG eller WebP.');
    $size = getimagesize($file['tmp_name']);
    check($size && $size[0] <= 12000 && $size[1] <= 12000, 'Ugyldig bilete eller for store dimensjonar.');
    $name = bin2hex(random_bytes(24)) . '.' . $types[$mime];
    check(move_uploaded_file($file['tmp_name'], ROOT . '/storage/uploads/' . $name), 'Kunne ikkje lagre biletet.');
    return ['filename'=>$name,'mime'=>$mime];
}
const COUNTIES = ['Agder','Akershus','Buskerud','Finnmark','Innlandet','Møre og Romsdal','Nordland','Oslo','Rogaland','Telemark','Troms','Trøndelag','Vestfold','Vestland','Østfold'];
require ROOT . '/app/market.php';
