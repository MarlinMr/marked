<?php
declare(strict_types=1);

function deliver_mail(string $recipient, string $subject, string $body): void {
    $from=env('MAIL_FROM','hei@example.com');
    check((bool)filter_var($from,FILTER_VALIDATE_EMAIL) && (bool)filter_var($recipient,FILTER_VALIDATE_EMAIL),'Ugyldig e-postadresse.');
    $encoded='=?UTF-8?B?'.base64_encode($subject).'?=';
    $headers='From: Marked <'.$from.">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $encodedBody=chunk_split(base64_encode($body),76,"\r\n");
    if (env('MAIL_TRANSPORT','mail')==='mail') {
        check(mail($recipient,$encoded,$encodedBody,$headers),'Kunne ikkje sende e-post.'); return;
    }
    $host=env('MAIL_HOST'); $port=(int)env('MAIL_PORT','587'); $encryption=env('MAIL_ENCRYPTION');
    $socket=stream_socket_client(($encryption==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$error,15);
    if (!$socket) throw new RuntimeException('SMTP: '.$error);
    stream_set_timeout($socket,15);
    $read=function (array $codes) use ($socket): void {
        $line='';
        do { $line=fgets($socket,2048); if ($line===false) throw new RuntimeException('SMTP-sambandet vart brote.'); } while (isset($line[3]) && $line[3]==='-');
        if (!in_array((int)substr($line,0,3),$codes,true)) throw new RuntimeException('SMTP avviste meldinga: '.trim($line));
    };
    $send=function (string $command, array $codes) use ($socket,$read): void { fwrite($socket,$command."\r\n"); $read($codes); };
    try {
        $read([220]); $send('EHLO marked.local',[250]);
        if ($encryption==='tls') {
            $send('STARTTLS',[220]);
            check(stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)===true,'Kunne ikkje starte SMTP-kryptering.');
            $send('EHLO marked.local',[250]);
        }
        if (env('MAIL_USERNAME')!=='') { $send('AUTH LOGIN',[334]); $send(base64_encode(env('MAIL_USERNAME')),[334]); $send(base64_encode(env('MAIL_PASSWORD')),[235]); }
        $send('MAIL FROM:<'.$from.'>',[250]); $send('RCPT TO:<'.$recipient.'>',[250,251]); $send('DATA',[354]);
        $data='To: <'.$recipient.">\r\nSubject: ".$encoded."\r\nDate: ".gmdate('r')."\r\n".$headers."\r\n\r\n".$encodedBody;
        fwrite($socket,preg_replace('/^\./m','..',$data)."\r\n.\r\n"); $read([250]); $send('QUIT',[221]);
    } finally { fclose($socket); }
}
function process_mail(): int {
    if (!(int)one("SELECT GET_LOCK('marked_mail',0) AS acquired")['acquired']) return 0;
    $sent=0;
    try {
        foreach (all('SELECT * FROM mail_queue WHERE sent_at IS NULL AND attempts<5 ORDER BY id LIMIT 50') as $m) {
            query('UPDATE mail_queue SET attempts=attempts+1 WHERE id=?',[$m['id']]);
            try {
                deliver_mail($m['recipient'],$m['subject'],$m['body']);
                query('UPDATE mail_queue SET sent_at=NOW() WHERE id=?',[$m['id']]); $sent++;
            } catch (Throwable $e) { error_log('Mail #'.$m['id'].': '.$e->getMessage()); }
        }
    } finally { query("SELECT RELEASE_LOCK('marked_mail')"); }
    return $sent;
}
function process_digests(): int {
    if (!(int)one("SELECT GET_LOCK('marked_digest',0) AS acquired")['acquired']) return 0;
    $sent=0;
    try {
        foreach (all('SELECT s.*,u.email FROM saved_searches s JOIN users u ON u.id=s.user_id WHERE s.last_sent_at<=DATE_SUB(NOW(),INTERVAL 1 DAY) AND u.verified_at IS NOT NULL AND u.suspended_at IS NULL') as $s) {
            transaction(function () use ($s,&$sent) {
                [$where,$p]=search_sql(search_filters(json_decode($s['filters'],true)));
                $cutoff=one('SELECT NOW() AS now')['now'];
                $rows=all("SELECT l.id,l.title,l.price FROM listings l JOIN users u ON u.id=l.user_id WHERE $where AND l.created_at>? AND l.created_at<=? ORDER BY l.id DESC LIMIT 20",array_merge($p,[$s['last_sent_at'],$cutoff]));
                if ($rows) {
                    $body="Nye funn som passar søket ditt:\n\n";
                    foreach ($rows as $l) $body.=$l['title'].' · '.money($l['price'])."\n".url('/listing?id='.$l['id'])."\n\n";
                    $body.='Administrer søka dine: '.url('/account');
                    enqueue($s['email'],'Nye treff på Marked',$body); $sent++;
                }
                query('UPDATE saved_searches SET last_sent_at=? WHERE id=?',[$cutoff,$s['id']]);
            });
        }
    } finally { query("SELECT RELEASE_LOCK('marked_digest')"); }
    return $sent;
}
