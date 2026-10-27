<?php
declare(strict_types=1);

function action(string $action): never {
    check(hash_equals(csrf(), (string)($_POST['csrf'] ?? '')), 'Skjemaet er utgått. Last sida på nytt.', 403);
    if ($action === 'register') {
        rate_limit('register', 8, 3600);
        $name = text('name',2,100); $email = strtolower(text('email',5,190)); $pw = password_input();
        check((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Ugyldig e-postadresse.');
        check(!one('SELECT id FROM users WHERE email=?',[$email]),'Denne e-postadressa er allereie registrert.');
        query('INSERT INTO users(name,email,password) VALUES (?,?,?)',[$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['uid'] = (int)db()->lastInsertId();
        token_mail(user(),'verify'); flash('Velkomen! Vi har sendt deg ei lenkje for å stadfeste e-postadressa.'); redirect('/account');
    }
    if ($action === 'login') {
        rate_limit('login',20,900);
        $u = one('SELECT * FROM users WHERE email=? AND suspended_at IS NULL',[strtolower(text('email',1,190))]);
        $valid = password_verify((string)($_POST['password'] ?? ''), $u['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        check($u && $valid,'Feil e-postadresse eller passord.');
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; redirect('/account');
    }
    if ($action === 'forgot') {
        rate_limit('forgot',5,3600);
        $u=one('SELECT * FROM users WHERE email=? AND suspended_at IS NULL',[strtolower(text('email',1,190))]);
        if ($u) token_mail($u,'reset');
        flash('Dersom adressa er registrert, får du ein e-post med ei lenkje.'); redirect('/login');
    }
    if ($action === 'reset' || $action === 'verify') {
        $token=text('token',64,64); $kind=$action === 'reset' ? 'reset' : 'verify';
        $pw = $kind === 'reset' ? password_input() : null;
        transaction(function () use ($token,$kind,$pw) {
            $t=one('SELECT * FROM tokens WHERE token_hash=? AND kind=? AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token),$kind]);
            check((bool)$t,'Lenkja er ugyldig eller utgått.');
            if ($kind === 'verify') query('UPDATE users SET verified_at=NOW() WHERE id=?',[$t['user_id']]);
            else query('UPDATE users SET password=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$t['user_id']]);
            query('DELETE FROM tokens WHERE user_id=? AND kind=?',[$t['user_id'],$kind]);
        });
        flash($kind === 'verify' ? 'E-postadressa er stadfesta.' : 'Passordet er endra. Du kan logge inn.'); redirect($kind === 'verify' && user() ? '/account' : '/login');
    }
    $u = require_user(false); $uid=(int)$u['id'];
    if ($action === 'logout') { $_SESSION=[]; session_regenerate_id(true); redirect('/'); }
    if ($action === 'resend') { rate_limit('resend',3,3600); if (!$u['verified_at']) token_mail($u,'verify'); flash('Ny stadfestingslenkje er send.'); redirect('/account'); }
    require_user();
    if ($action === 'listing') {
        rate_limit('listing',30,3600);
        $title=text('title',3,160); $description=text('description',10,10000); $county=text('county',1,60); $municipality=text('municipality',1,80);
        $condition=text('item_condition',1,40); $delivery=text('delivery',1,40); $kind=text('kind',1,10);
        check(in_array($county,COUNTIES,true),'Vel eit fylke.'); check(in_array($kind,['sell','wanted'],true),'Vel annonsetype.');
        check(in_array($condition,['Ny','Som ny','Brukt','Treng reparasjon'],true),'Vel tilstand.');
        check(in_array($delivery,['Henting','Sending','Henting eller sending'],true),'Vel leveringsmåte.');
        $category=(int)($_POST['category_id']??0); check((bool)one('SELECT id FROM categories WHERE id=?',[$category]),'Vel kategori.');
        $price=amount('price'); $buyout=$kind === 'sell' ? amount('buyout',true) : null;
        $files=$_FILES['photos']??null; check(!$files || count($files['name'])<=8,'Maksimalt åtte bilete.');
        $saved=[];
        try {
            if ($files) foreach ($files['name'] as $i=>$name) {
                $p=upload(['name'=>$name,'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]]);
                if ($p) $saved[]=$p;
            }
            $lid=transaction(function () use ($uid,$category,$kind,$title,$description,$county,$municipality,$condition,$delivery,$price,$buyout,$saved) {
                query('INSERT INTO listings(user_id,category_id,kind,title,description,county,municipality,item_condition,delivery,price,buyout) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$uid,$category,$kind,$title,$description,$county,$municipality,$condition,$delivery,$price,$buyout]);
                $id=(int)db()->lastInsertId();
                foreach ($saved as $p) query('INSERT INTO photos(listing_id,filename,mime) VALUES (?,?,?)',[$id,$p['filename'],$p['mime']]);
                return $id;
            });
        } catch (Throwable $e) { foreach ($saved as $p) @unlink(ROOT.'/storage/uploads/'.$p['filename']); throw $e; }
        flash('Annonsen er publisert!'); redirect('/listing?id='.$lid);
    }
    if ($action === 'offer') {
        rate_limit('offer',60,3600); $lid=(int)($_POST['listing_id']??0); $kind=text('kind',1,10);
        make_offer($lid,$uid,$kind,$kind === 'buyout' ? null : amount('amount'),isset($_POST['buyer_id'])?(int)$_POST['buyer_id']:null);
        flash('Bodet er sendt.'); redirect('/listing?id='.$lid);
    }
    if ($action === 'respond') {
        $id=(int)($_POST['offer_id']??0); respond_offer($id,$uid,text('decision',1,20));
        $o=one('SELECT listing_id FROM offers WHERE id=?',[$id]); flash('Bodet er oppdatert.'); redirect('/listing?id='.$o['listing_id']);
    }
    if ($action === 'trade') { $lid=(int)($_POST['listing_id']??0); trade($lid,$uid,text('decision',1,20)); flash('Handelen er oppdatert.'); redirect('/listing?id='.$lid); }
    if ($action === 'close') {
        $lid=(int)($_POST['listing_id']??0);
        transaction(function () use ($lid,$uid) {
            $l=listing($lid,true); check((int)$l['user_id']===$uid && $l['status']==='active','Annonsen kan ikkje trekkjast no.');
            query("UPDATE listings SET status='removed' WHERE id=?",[$lid]);
            foreach (all("SELECT id FROM offers WHERE listing_id=? AND status='pending'",[$lid]) as $o) event((int)$o['id'],'rejected');
            query("UPDATE offers SET status='rejected' WHERE listing_id=? AND status='pending'",[$lid]);
        });
        flash('Annonsen er trekt.'); redirect('/account');
    }
    if ($action === 'favorite') {
        $lid=(int)($_POST['listing_id']??0); listing($lid);
        if (one('SELECT 1 FROM favorites WHERE user_id=? AND listing_id=?',[$uid,$lid])) query('DELETE FROM favorites WHERE user_id=? AND listing_id=?',[$uid,$lid]);
        else query('INSERT INTO favorites(user_id,listing_id) VALUES (?,?)',[$uid,$lid]);
        redirect('/listing?id='.$lid);
    }
    if ($action === 'save_search') {
        check((int)one('SELECT COUNT(*) AS n FROM saved_searches WHERE user_id=?',[$uid])['n']<25,'Du kan lagre opptil 25 søk.');
        query('INSERT INTO saved_searches(user_id,filters) VALUES (?,?)',[$uid,json_encode(search_filters($_POST),JSON_UNESCAPED_UNICODE)]);
        flash('Søket er lagra. Du får ein dagleg e-post når nye annonsar passar.'); redirect('/account');
    }
    if ($action === 'delete_search') { query('DELETE FROM saved_searches WHERE id=? AND user_id=?',[(int)($_POST['id']??0),$uid]); redirect('/account'); }
    if ($action === 'conversation') {
        $l=listing((int)($_POST['listing_id']??0));
        check($l['status']!=='removed' && (int)$l['user_id']!==$uid,'Du kan ikkje starte denne samtalen.');
        check(!blocked($uid,(int)$l['user_id']),'Kontakt er blokkert.');
        query('INSERT IGNORE INTO conversations(listing_id,buyer_id) VALUES (?,?)',[$l['id'],$uid]);
        $c=one('SELECT id FROM conversations WHERE listing_id=? AND buyer_id=?',[$l['id'],$uid]); redirect('/messages?id='.$c['id']);
    }
    if ($action === 'message') {
        rate_limit('message',120,3600); $cid=(int)($_POST['conversation_id']??0); $c=conversation($cid,$uid);
        $other=$uid===(int)$c['buyer_id']?(int)$c['seller_id']:(int)$c['buyer_id'];
        check(!blocked($uid,$other),'Kontakt er blokkert.');
        check((bool)one('SELECT id FROM users WHERE id=? AND suspended_at IS NULL',[$other]),'Brukaren er ikkje tilgjengeleg.');
        $body=trim((string)($_POST['body']??'')); check(strlen($body)<=5000,'Meldinga er for lang.');
        $p=isset($_FILES['photo'])?upload($_FILES['photo']):null;
        check($body!=='' || $p,'Skriv ei melding eller legg ved eit bilete.');
        try {
            transaction(function () use ($cid,$uid,$body,$p,$other) {
                $unread=one('SELECT id FROM messages WHERE conversation_id=? AND sender_id=? AND read_at IS NULL',[$cid,$uid]);
                query('INSERT INTO messages(conversation_id,sender_id,body,filename,mime) VALUES (?,?,?,?,?)',[$cid,$uid,$body,$p['filename']??null,$p['mime']??null]);
                if (!$unread) notify($other,'Ny melding på Marked','Du har ei ny privat melding. '.url('/messages?id='.$cid));
            });
        } catch (Throwable $e) { if ($p) @unlink(ROOT.'/storage/uploads/'.$p['filename']); throw $e; }
        redirect('/messages?id='.$cid);
    }
    if ($action === 'block') {
        $other=(int)($_POST['user_id']??0); check($other!==$uid && (bool)one('SELECT id FROM users WHERE id=?',[$other]),'Ugyldig brukar.');
        if (one('SELECT 1 FROM blocks WHERE user_id=? AND blocked_id=?',[$uid,$other])) query('DELETE FROM blocks WHERE user_id=? AND blocked_id=?',[$uid,$other]);
        else query('INSERT INTO blocks(user_id,blocked_id) VALUES (?,?)',[$uid,$other]);
        redirect('/profile?id='.$other);
    }
    if ($action === 'review') {
        $lid=(int)($_POST['listing_id']??0); $rating=(int)($_POST['rating']??0); $body=text('body',3,2000);
        transaction(function () use ($lid,$uid,$rating,$body) {
            $l=listing($lid,true); check($l['status']==='sold' && in_array($uid,[(int)$l['buyer_id'],(int)$l['user_id']],true),'Du kan berre vurdere ein fullført handel.',403);
            check($rating>=1 && $rating<=5,'Vel mellom éi og fem stjerner.');
            check(!one('SELECT id FROM reviews WHERE listing_id=? AND author_id=?',[$lid,$uid]),'Du har allereie vurdert denne handelen.');
            $recipient=$uid===(int)$l['user_id']?(int)$l['buyer_id']:(int)$l['user_id'];
            query('INSERT INTO reviews(listing_id,author_id,recipient_id,rating,body) VALUES (?,?,?,?,?)',[$lid,$uid,$recipient,$rating,$body]);
        });
        flash('Takk for vurderinga!'); redirect('/listing?id='.$lid);
    }
    if ($action === 'report') {
        rate_limit('report',10,3600); $lid=(int)($_POST['listing_id']??0); $other=(int)($_POST['user_id']??0);
        if ($lid) listing($lid); else check((bool)one('SELECT id FROM users WHERE id=?',[$other]),'Ugyldig brukar.');
        query('INSERT INTO reports(reporter_id,listing_id,reported_user_id,reason) VALUES (?,?,?,?)',[$uid,$lid?:null,$lid?null:$other,text('reason',5,2000)]);
        flash('Takk. Rapporten er send til administrator.'); redirect('/account');
    }
    if (str_starts_with($action,'admin_')) {
        require_admin(); $id=(int)($_POST['id']??0);
        if ($action==='admin_resolve') query('UPDATE reports SET resolved_at=NOW() WHERE id=?',[$id]);
        elseif ($action==='admin_suspend') { check($id!==$uid,'Du kan ikkje stengje din eigen konto.'); query("UPDATE users SET suspended_at=IF(suspended_at IS NULL,NOW(),NULL) WHERE id=? AND role<>'admin'",[$id]); }
        elseif ($action==='admin_remove') {
            transaction(function () use ($id) {
                $l=listing($id,true); query("UPDATE listings SET status='removed' WHERE id=?",[$id]);
                foreach (all("SELECT id FROM offers WHERE listing_id=? AND status='pending'",[$id]) as $o) event((int)$o['id'],'rejected');
                query("UPDATE offers SET status='rejected' WHERE listing_id=? AND status='pending'",[$id]);
            });
        } elseif ($action==='admin_category') query('INSERT IGNORE INTO categories(name) VALUES (?)',[text('name',2,100)]);
        else throw new RuntimeException('Ukjend handling.',404);
        flash('Endringa er lagra.'); redirect('/admin');
    }
    throw new RuntimeException('Ukjend handling.',404);
}
