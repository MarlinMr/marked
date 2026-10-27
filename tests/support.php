<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
check(PHP_SAPI==='cli' && env('APP_ENV')==='local','Testhjelpa er berre for lokal CLI.');
$command=$argv[1]??''; $prefix=$argv[2]??'';
check((bool)preg_match('/^test_[a-f0-9]{12}$/D',$prefix),'Ugyldig testprefiks.');
if ($command==='prepare') {
    query('DELETE FROM rate_limits');
} elseif ($command==='mail') {
    echo json_encode(all('SELECT recipient,subject,body,sent_at FROM mail_queue WHERE recipient LIKE ? ORDER BY id',[$prefix.'%']));
} elseif ($command==='admin') {
    query("UPDATE users SET role='admin' WHERE email=?",[$prefix.'_stranger@example.test']);
} elseif ($command==='cleanup') {
    transaction(function () use ($prefix) {
        $users=array_column(all('SELECT id FROM users WHERE email LIKE ?',[$prefix.'%']),'id');
        if (!$users) return;
        $ids=implode(',',array_map('intval',$users));
        $lids=array_column(all("SELECT id FROM listings WHERE user_id IN ($ids)"),'id');
        if ($lids) {
            $ls=implode(',',array_map('intval',$lids));
            foreach (all("SELECT filename FROM photos WHERE listing_id IN ($ls) UNION ALL SELECT m.filename FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.listing_id IN ($ls) AND m.filename IS NOT NULL") as $p) @unlink(ROOT.'/storage/uploads/'.$p['filename']);
            query("DELETE e FROM offer_events e JOIN offers o ON o.id=e.offer_id WHERE o.listing_id IN ($ls)");
            query("DELETE FROM offers WHERE listing_id IN ($ls)");
            query("DELETE m FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.listing_id IN ($ls)");
            query("DELETE FROM conversations WHERE listing_id IN ($ls)");
            foreach (['favorites','reviews','reports'] as $table) query("DELETE FROM $table WHERE listing_id IN ($ls)");
            query("DELETE FROM photos WHERE listing_id IN ($ls)"); query("DELETE FROM listings WHERE id IN ($ls)");
        }
        query("DELETE FROM blocks WHERE user_id IN ($ids) OR blocked_id IN ($ids)");
        query("DELETE FROM reports WHERE reporter_id IN ($ids) OR reported_user_id IN ($ids)");
        foreach (['saved_searches','tokens'] as $table) query("DELETE FROM $table WHERE user_id IN ($ids)");
        query("DELETE FROM users WHERE id IN ($ids)"); query('DELETE FROM mail_queue WHERE recipient LIKE ?',[$prefix.'%']);
        query('DELETE FROM categories WHERE name LIKE ?',[$prefix.'%']);
    });
} elseif ($command==='digest') {
    query('UPDATE saved_searches s JOIN users u ON u.id=s.user_id SET s.last_sent_at=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE u.email LIKE ?',[$prefix.'%']);
    require ROOT.'/app/mail.php'; process_digests();
} else throw new RuntimeException('Ukjend testhandling.');
