<?php
declare(strict_types=1);

function listing(int $id, bool $lock = false): array {
    $l = one('SELECT * FROM listings WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    check((bool)$l, 'Annonsen finst ikkje.', 404);
    return $l;
}
function transaction(callable $callback): mixed {
    db()->beginTransaction();
    try { $result = $callback(); db()->commit(); return $result; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function event(int $id, string $status): void { query('INSERT INTO offer_events(offer_id,status) VALUES (?,?)', [$id,$status]); }
function make_offer(int $lid, int $uid, string $kind, ?string $price, ?int $buyer = null): void {
    transaction(function () use ($lid,$uid,$kind,$price,$buyer) {
        $l = listing($lid, true);
        check($l['status'] === 'active' && $l['kind'] === 'sell', 'Annonsen er ikkje open for bod.');
        check(in_array($kind,['bid','buyout','counter'],true), 'Ugyldig bodtype.');
        if ($kind === 'counter') {
            check((int)$l['user_id'] === $uid && $buyer && $buyer !== $uid, 'Berre seljaren kan gi motbod.',403);
            check((bool)one("SELECT id FROM offers WHERE listing_id=? AND buyer_id=? AND kind IN ('bid','buyout') AND status='pending'",[$lid,$buyer]), 'Kjøparen har ikkje eit aktivt bod.');
        } else { check((int)$l['user_id'] !== $uid, 'Du kan ikkje by på din eigen annonse.'); $buyer = $uid; }
        check(!blocked((int)$l['user_id'], $buyer), 'Kontakt mellom desse brukarane er blokkert.',403);
        check((bool)one('SELECT id FROM users WHERE id=? AND verified_at IS NOT NULL AND suspended_at IS NULL',[$buyer]),'Brukaren kan ikkje handle no.');
        if ($kind === 'buyout') { check($l['buyout'] !== null,'Annonsen har ikkje kjøp-no-pris.'); $price = $l['buyout']; }
        check($price !== null && (float)$price > 0 && (float)$price < 10000000000, 'Ugyldig pris.');
        query('INSERT INTO offers(listing_id,buyer_id,amount,kind) VALUES (?,?,?,?)',[$lid,$buyer,$price,$kind]);
        event((int)db()->lastInsertId(), 'pending');
        notify($kind === 'counter' ? $buyer : (int)$l['user_id'], 'Nytt bod på Marked', 'Du har fått eit nytt bod eller motbod. ' . url('/listing?id=' . $lid));
    });
}
function respond_offer(int $id, int $uid, string $decision): void {
    transaction(function () use ($id,$uid,$decision) {
        $base = one('SELECT listing_id FROM offers WHERE id=?',[$id]);
        check((bool)$base,'Bodet finst ikkje.',404);
        $l = listing((int)$base['listing_id'], true);
        $o = one('SELECT * FROM offers WHERE id=? FOR UPDATE',[$id]);
        check($l['status'] === 'active' && $o['status'] === 'pending', 'Bodet er ikkje lenger aktivt.');
        $isSeller = (int)$l['user_id'] === $uid;
        $isBuyer = (int)$o['buyer_id'] === $uid;
        if ($decision === 'withdrawn') {
            check(($o['kind'] === 'counter' && $isSeller) || ($o['kind'] !== 'counter' && $isBuyer),'Du kan ikkje trekkje dette bodet.',403);
        } else {
            check(in_array($decision,['accepted','rejected'],true),'Ugyldig val.');
            check($o['kind'] === 'counter' ? $isBuyer : $isSeller, 'Du kan ikkje svare på dette bodet.',403);
        }
        if ($decision === 'accepted') {
            check(!blocked((int)$l['user_id'],(int)$o['buyer_id']), 'Kontakt er blokkert.',403);
            check((int)one('SELECT COUNT(*) AS n FROM users WHERE id IN (?,?) AND suspended_at IS NULL AND verified_at IS NOT NULL',[$l['user_id'],$o['buyer_id']])['n'] === 2,'Ein av brukarane kan ikkje handle no.');
            query("UPDATE listings SET status='reserved',buyer_id=?,accepted_price=?,seller_confirmed=0,buyer_confirmed=0 WHERE id=?",[$o['buyer_id'],$o['amount'],$l['id']]);
            query('INSERT IGNORE INTO conversations(listing_id,buyer_id) VALUES (?,?)',[$l['id'],$o['buyer_id']]);
            foreach (all("SELECT id FROM offers WHERE listing_id=? AND status='pending' AND id<>?",[$l['id'],$id]) as $other) event((int)$other['id'],'rejected');
            query("UPDATE offers SET status='rejected' WHERE listing_id=? AND status='pending' AND id<>?",[$l['id'],$id]);
        }
        query('UPDATE offers SET status=? WHERE id=?',[$decision,$id]); event($id,$decision);
        $recipient = $isSeller ? (int)$o['buyer_id'] : (int)$l['user_id'];
        notify($recipient,'Bodet ditt er oppdatert', 'Sjå status på handelen: ' . url('/listing?id=' . $l['id']));
    });
}
function trade(int $lid, int $uid, string $action): void {
    transaction(function () use ($lid,$uid,$action) {
        $l = listing($lid,true);
        check($l['status'] === 'reserved','Annonsen er ikkje reservert.');
        check(in_array($uid,[(int)$l['user_id'],(int)$l['buyer_id']],true),'Du deltek ikkje i denne handelen.',403);
        if ($action === 'reopen') {
            check($uid === (int)$l['user_id'],'Berre seljaren kan opne annonsen att.',403);
            query("UPDATE listings SET status='active',buyer_id=NULL,accepted_price=NULL,seller_confirmed=0,buyer_confirmed=0 WHERE id=?",[$lid]);
            notify((int)$l['buyer_id'],'Reservasjonen er avslutta',url('/listing?id='.$lid));
        } else {
            check($action === 'confirm','Ugyldig val.');
            $col = $uid === (int)$l['user_id'] ? 'seller_confirmed' : 'buyer_confirmed';
            query("UPDATE listings SET $col=1 WHERE id=?",[$lid]);
            query("UPDATE listings SET status='sold' WHERE id=? AND seller_confirmed=1 AND buyer_confirmed=1",[$lid]);
            notify($uid === (int)$l['user_id'] ? (int)$l['buyer_id'] : (int)$l['user_id'],'Handelen er oppdatert',url('/listing?id='.$lid));
        }
    });
}
function search_filters(array $input): array {
    return ['q'=>substr(trim((string)($input['q'] ?? '')),0,160),'category'=>(int)($input['category'] ?? 0),'county'=>in_array($input['county'] ?? '',COUNTIES,true) ? $input['county'] : '', 'municipality'=>substr(trim((string)($input['municipality'] ?? '')),0,80),'kind'=>in_array($input['kind'] ?? '',['sell','wanted'],true) ? $input['kind'] : ''];
}
function search_sql(array $f): array {
    $where = ["l.status='active'", 'u.suspended_at IS NULL']; $p = [];
    if ($f['q'] !== '') { $where[] = '(l.title LIKE ? OR l.description LIKE ?)'; $p[] = '%'.$f['q'].'%'; $p[] = '%'.$f['q'].'%'; }
    foreach (['category'=>'category_id','county'=>'county','municipality'=>'municipality','kind'=>'kind'] as $k=>$column) {
        if ($f[$k]) { $where[] = "l.$column=?"; $p[] = $f[$k]; }
    }
    return [implode(' AND ',$where),$p];
}
function conversation(int $id, int $uid): array {
    $c = one('SELECT c.*,l.user_id AS seller_id,l.title FROM conversations c JOIN listings l ON l.id=c.listing_id WHERE c.id=?',[$id]);
    check($c && in_array($uid,[(int)$c['buyer_id'],(int)$c['seller_id']],true),'Samtalen finst ikkje.',404);
    return $c;
}
