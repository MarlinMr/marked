<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
session_set_cookie_params(['httponly'=>true,'secure'=>env('SESSION_SECURE')==='1','samesite'=>'Lax','path'=>'/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store');
require ROOT . '/app/views.php';
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') { require ROOT.'/app/actions.php'; action((string)($_POST['action']??'')); }
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) throw new RuntimeException('Metoden er ikkje støtta.',405);
    if ($path==='/media') {
        $id=(int)($_GET['id']??0);
        if (($_GET['type']??'')==='message') {
            $u=require_user(); $m=one('SELECT * FROM messages WHERE id=?',[$id]); check($m && $m['filename'],'Biletet finst ikkje.',404);
            conversation((int)$m['conversation_id'],(int)$u['id']); $p=$m;
        } else {
            $p=one("SELECT p.* FROM photos p JOIN listings l ON l.id=p.listing_id JOIN users u ON u.id=l.user_id WHERE p.id=? AND l.status<>'removed' AND u.suspended_at IS NULL",[$id]);
            check((bool)$p,'Biletet finst ikkje.',404);
        }
        $file=ROOT.'/storage/uploads/'.$p['filename']; check(is_file($file),'Biletet finst ikkje.',404);
        header('Content-Type: '.$p['mime']); header('Content-Length: '.filesize($file)); readfile($file); exit;
    }
    if ($path==='/api/messages') {
        $u=user(); check($u && $u['verified_at'],'Logg inn for å lese meldingar.',401);
        $cid=(int)($_GET['id']??0); conversation($cid,(int)$u['id']);
        $rows=all('SELECT id,sender_id,body,filename,created_at FROM messages WHERE conversation_id=? AND id>? ORDER BY id LIMIT 100',[$cid,max(0,(int)($_GET['after']??0))]);
        if ($rows) query('UPDATE messages SET read_at=NOW() WHERE conversation_id=? AND sender_id<>? AND id<=? AND read_at IS NULL',[$cid,$u['id'],end($rows)['id']]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['messages'=>array_map(fn($m)=>['id'=>(int)$m['id'],'own'=>(int)$m['sender_id']===(int)$u['id'],'body'=>$m['body'],'image'=>$m['filename']?'/media?type=message&id='.$m['id']:null,'time'=>$m['created_at'].' UTC'],$rows)],JSON_THROW_ON_ERROR); exit;
    }
    ob_start();
    $title=match($path) {
        '/' => home_page(), '/login','/register','/forgot','/reset','/verify' => auth_page($path),
        '/listing' => listing_page((int)($_GET['id']??0)), '/new' => new_page(),
        '/account' => account_page(), '/messages' => messages_page(), '/profile' => profile_page((int)($_GET['id']??0)),
        '/admin' => admin_page(), default => throw new RuntimeException('Sida finst ikkje.',404)
    };
    $content=ob_get_clean(); layout($title,$content);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    $code=$e instanceof RuntimeException && in_array($e->getCode(),[400,401,403,404,405,429],true)?$e->getCode():500;
    http_response_code($code);
    if ($code===500) error_log((string)$e);
    $message=$code===500?'Noko gjekk gale. Prøv att om litt.':$e->getMessage();
    if ($path==='/api/messages') { header('Content-Type: application/json'); echo json_encode(['error'=>$message]); }
    else layout('Noko gjekk gale','<section class="panel narrow"><h1>'.e($message).'</h1><p>Du kan gå tilbake og prøve på nytt.</p><a class="button" href="/">Til framsida</a></section>');
}
