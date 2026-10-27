<?php
declare(strict_types=1);

function form_start(string $action, array $hidden = [], string $class = '', bool $files = false): void {
    echo '<form method="post" action="/" class="'.e($class).'"'.($files?' enctype="multipart/form-data"':'').'>'.csrf_field().'<input type="hidden" name="action" value="'.e($action).'">';
    foreach ($hidden as $k=>$v) echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';
}
function button_form(string $action, array $hidden, string $label, string $class = 'secondary'): void {
    form_start($action,$hidden,'inline'); echo '<button class="'.e($class).'">'.e($label).'</button></form>';
}
function field(string $label, string $name, string $type = 'text', string $value = '', string $extra = ''): void {
    echo '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'" '.$extra.'></label>';
}
function select_field(string $label, string $name, array $options, mixed $value = '', bool $blank = false): void {
    echo '<label>'.e($label).'<select name="'.e($name).'">';
    if ($blank) echo '<option value="">Alle</option>';
    foreach ($options as $k=>$v) echo '<option value="'.e($k).'"'.((string)$k===(string)$value?' selected':'').'>'.e($v).'</option>';
    echo '</select></label>';
}
function category_options(): array { return array_column(all('SELECT id,name FROM categories ORDER BY name'),'name','id'); }
function state_label(string $s): string { return ['active'=>'Tilgjengeleg','reserved'=>'Reservert','sold'=>'Seld','removed'=>'Trekt','pending'=>'Aktivt','accepted'=>'Godteke','rejected'=>'Avslått','withdrawn'=>'Trekt tilbake'][$s]??$s; }
function layout(string $title, string $content): void {
    $u=user(); $unread=$u?(int)one('SELECT COUNT(*) AS n FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN listings l ON l.id=c.listing_id WHERE (c.buyer_id=? OR l.user_id=?) AND m.sender_id<>? AND m.read_at IS NULL',[$u['id'],$u['id'],$u['id']])['n']:0;
    ?>
    <!doctype html><html lang="nn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · Marked</title><meta name="theme-color" content="#bb4d20"><link rel="stylesheet" href="/assets/style.css"><script src="/assets/app.js" defer></script></head><body>
    <a class="skip" href="#main">Hopp til innhaldet</a>
    <header class="site-header"><div class="wrap nav"><a class="brand" href="/"><span class="brand-icon">m</span>marked<span class="brand-dot">.</span></a><nav aria-label="Hovudmeny"><a href="/">Utforsk</a><?php if ($u): ?><a href="/messages">Meldingar<?= $unread?' <span class="badge">'.$unread.'</span>':'' ?></a><a href="/account">Min side</a><?php if ($u['role']==='admin'): ?><a href="/admin">Admin</a><?php endif; ?><?php else: ?><a href="/login">Logg inn</a><?php endif; ?><a class="button small" href="/new">＋ Ny annonse</a></nav></div></header>
    <main id="main" class="wrap"><?php if (!empty($_SESSION['flash'])): ?><div class="notice" role="status"><?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?><?= $content ?></main>
    <footer class="wrap footer"><a class="brand" href="/">marked.</a><p>Gode ting fortener ein ny heim.</p><span>Ein marknadsplass for heile Noreg · NOK</span></footer></body></html>
    <?php
}
function cards(array $rows): void {
    if (!$rows) { echo '<div class="empty"><h3>Ingen annonsar her enno</h3><p>Prøv eit anna søk, eller ver den første til å leggje ut noko.</p></div>'; return; }
    echo '<div class="cards">';
    foreach ($rows as $l) {
        $photo=one('SELECT id FROM photos WHERE listing_id=? ORDER BY id LIMIT 1',[$l['id']]);
        ?><a class="card" href="/listing?id=<?= (int)$l['id'] ?>"><div class="card-image"><?php if ($photo): ?><img src="/media?id=<?= (int)$photo['id'] ?>" alt="<?= e($l['title']) ?>" loading="lazy"><?php else: ?><span class="placeholder" aria-hidden="true"><?= $l['kind']==='wanted'?'⌕':'◇' ?></span><?php endif; ?><span class="tag"><?= $l['kind']==='wanted'?'Ønskjer å kjøpe':state_label($l['status']) ?></span></div><div class="card-body"><span class="muted location"><?= e($l['municipality']) ?> · <?= e($l['county']) ?></span><h3><?= e($l['title']) ?></h3><strong><?= money($l['price']) ?></strong><span class="muted price-note"><?= $l['kind']==='wanted'?'Budsjett':'Prisantyding' ?></span></div></a><?php
    }
    echo '</div>';
}
function home_page(): string {
    $f=search_filters($_GET); [$where,$p]=search_sql($f); $page=max(1,min(10000,(int)($_GET['page']??1))); $offset=($page-1)*24;
    $total=(int)one("SELECT COUNT(*) AS n FROM listings l JOIN users u ON u.id=l.user_id WHERE $where",$p)['n'];
    $rows=all("SELECT l.* FROM listings l JOIN users u ON u.id=l.user_id WHERE $where ORDER BY l.id DESC LIMIT 24 OFFSET $offset",$p);
    ?>
    <section class="hero"><div><span class="eyebrow">SMÅ FUNN. NYE MOGLEGHEITER.</span><h1>Noko fint for deg.<br><span>Noko nytt for nokon.</span></h1><p>Kjøp brukt, sel vidare og finn det du leitar etter.<br>Rett i nærleiken — eller ein annan stad i Noreg.</p><a class="button" href="/new">Legg ut ein annonse <span aria-hidden="true">↗</span></a></div><div class="hero-art" aria-hidden="true"><div class="art-sun"></div><div class="art-vase"></div><div class="art-leaf one"></div><div class="art-leaf two"></div><div class="art-label">Eit godt funn ♡</div></div></section>
    <section class="search-panel" aria-label="Søk i annonsar"><form method="get" action="/" class="filters"><?php field('Kva leitar du etter?','q','search',$f['q'],'placeholder="Til dømes sykkel eller lenestol"'); select_field('Kategori','category',category_options(),$f['category'],true); select_field('Fylke','county',array_combine(COUNTIES,COUNTIES),$f['county'],true); field('Kommune','municipality','text',$f['municipality'],'placeholder="Alle kommunar" list="municipalities"'); select_field('Annonsetype','kind',['sell'=>'Til sals','wanted'=>'Ønskjer å kjøpe'],$f['kind'],true); ?><button>Søk</button></form><datalist id="municipalities"><?php foreach (all("SELECT DISTINCT municipality FROM listings WHERE status='active' ORDER BY municipality LIMIT 500") as $m): ?><option value="<?= e($m['municipality']) ?>"><?php endforeach; ?></datalist></section>
    <div class="section-heading"><div><span class="eyebrow">FINN DIN NESTE FAVORITT</span><h2><?= $f['q']!==''?'Søkeresultat':'Nye funn på Marked' ?> <span class="count"><?= $total ?></span></h2></div><?php if (user()): button_form('save_search',$f,'♡ Lagre dette søket'); endif; ?></div>
    <?php cards($rows); if ($total>24): ?><nav class="pagination" aria-label="Resultatsider"><?php if ($page>1): ?><a class="button secondary" href="/?<?= e(http_build_query(array_merge($f,['page'=>$page-1]))) ?>">← Førre</a><?php endif; ?><span>Side <?= $page ?> av <?= (int)ceil($total/24) ?></span><?php if ($page*24<$total): ?><a class="button secondary" href="/?<?= e(http_build_query(array_merge($f,['page'=>$page+1]))) ?>">Neste →</a><?php endif; ?></nav><?php endif;
    return 'Brukte skattar og nye funn';
}
function auth_page(string $path): string {
    $type=ltrim($path,'/'); $title=['login'=>'Velkomen tilbake','register'=>'Bli med på Marked','forgot'=>'Gløymt passord?','reset'=>'Vel eit nytt passord','verify'=>'Stadfest e-postadressa'][$type];
    echo '<section class="panel narrow"><span class="eyebrow">DIN NESTE GODE HANDEL STARTAR HER</span><h1>'.e($title).'</h1>';
    form_start($type,[], 'stack');
    if ($type==='register') field('Namnet ditt','name','text','','required maxlength="100" autocomplete="name"');
    if (in_array($type,['login','register','forgot'],true)) field('E-postadresse','email','email','','required maxlength="190" autocomplete="email"');
    if (in_array($type,['login','register','reset'],true)) field('Passord'.($type!=='login'?' (minst 12 teikn)':''),'password','password','','required '.($type==='login'?'autocomplete="current-password"':'minlength="12" maxlength="72" autocomplete="new-password"'));
    if (in_array($type,['reset','verify'],true)) echo '<input type="hidden" name="token" value="'.e($_GET['token']??'').'">';
    echo '<button>'.['login'=>'Logg inn','register'=>'Opprett konto','forgot'=>'Send lenkje','reset'=>'Lagre passord','verify'=>'Stadfest e-post'][$type].'</button></form>';
    if ($type==='login') echo '<p><a href="/forgot">Gløymt passord?</a></p><p>Ny her? <a href="/register">Opprett ein konto</a></p>';
    if ($type==='register') echo '<p>Har du konto? <a href="/login">Logg inn</a></p>';
    echo '</section>'; return $title;
}
function new_page(): string {
    require_user(); ?>
    <section class="panel form-panel"><span class="eyebrow">GI NOKO EIN NY HEIM</span><h1>Ny annonse</h1><p class="muted">Gode bilete og ei ærleg skildring gjer det lettare å finne rett kjøpar.</p>
    <?php form_start('listing',[],'stack',true); ?><div class="two-col"><?php select_field('Eg vil','kind',['sell'=>'Selje noko','wanted'=>'Kjøpe noko']); select_field('Kategori','category_id',category_options()); ?></div>
    <?php field('Overskrift','title','text','','required minlength="3" maxlength="160" placeholder="Kva vil du selje eller kjøpe?"'); ?>
    <label>Skildring<textarea name="description" rows="6" required minlength="10" maxlength="10000" placeholder="Fortel litt om tingen, tilstanden og det andre bør vite."></textarea></label>
    <div class="two-col"><?php field('Prisantyding / budsjett (kr)','price','number','','required min="0.01" max="9999999999" step="0.01"'); field('Kjøp-no-pris (valfritt, berre til sals)','buyout','number','','min="0.01" max="9999999999" step="0.01"'); select_field('Fylke','county',array_combine(COUNTIES,COUNTIES)); field('Kommune','municipality','text','','required maxlength="80"'); select_field('Tilstand','item_condition',array_combine(['Ny','Som ny','Brukt','Treng reparasjon'],['Ny','Som ny','Brukt','Treng reparasjon']),'Brukt'); select_field('Levering','delivery',array_combine(['Henting','Sending','Henting eller sending'],['Henting','Sending','Henting eller sending'])); ?></div>
    <label>Bilete (opptil 8, maks. 8 MB kvar)<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label><p class="muted">Bilete er offentlege. Unngå personopplysningar i bilete og skildring.</p><button>Publiser annonse</button></form></section>
    <?php return 'Ny annonse';
}
function listing_page(int $id): string {
    $l=one('SELECT l.*,u.name AS seller_name,u.suspended_at,c.name AS category FROM listings l JOIN users u ON u.id=l.user_id JOIN categories c ON c.id=l.category_id WHERE l.id=?',[$id]);
    check($l && $l['status']!=='removed' && !$l['suspended_at'],'Annonsen finst ikkje.',404);
    $u=user(); $uid=(int)($u['id']??0); $owner=$uid===(int)$l['user_id']; $participant=$owner || $uid===(int)$l['buyer_id'];
    $photos=all('SELECT id FROM photos WHERE listing_id=? ORDER BY id',[$id]);
    ?>
    <div class="breadcrumbs"><a href="/">Utforsk</a> / <?= e($l['category']) ?> / <?= e($l['title']) ?></div>
    <div class="listing-grid"><div><section class="gallery"><?php if ($photos): foreach ($photos as $p): ?><a href="/media?id=<?= (int)$p['id'] ?>"><img src="/media?id=<?= (int)$p['id'] ?>" alt="<?= e($l['title']) ?>" loading="lazy"></a><?php endforeach; else: ?><div class="gallery-placeholder">◇<span>Ingen bilete</span></div><?php endif; ?></section>
    <section class="panel"><h2>Om annonsen</h2><p class="description"><?= nl2br(e($l['description'])) ?></p><dl class="facts"><div><dt>Tilstand</dt><dd><?= e($l['item_condition']) ?></dd></div><div><dt>Levering</dt><dd><?= e($l['delivery']) ?></dd></div><div><dt>Stad</dt><dd><?= e($l['municipality'].', '.$l['county']) ?></dd></div><div><dt>Lagt ut</dt><dd><?= e(substr($l['created_at'],0,10)) ?></dd></div></dl></section>
    <?php if ($l['kind']==='sell'): ?><section class="panel"><h2>Offentleg bodhistorikk</h2><p class="muted">Prisar og tidspunkt er synlege for alle. Identiteten til bodgivarane er skjult. Motbod er òg offentlege.</p>
    <div class="table-scroll"><table><thead><tr><th>Type</th><th>Pris</th><th>Status</th><th>Tid (UTC)</th></tr></thead><tbody><?php
    $events=all('SELECT o.amount,o.kind,e.status,e.created_at FROM offer_events e JOIN offers o ON o.id=e.offer_id WHERE o.listing_id=? ORDER BY e.id DESC',[$id]);
    foreach ($events as $ev): ?><tr><td><?= ['bid'=>'Bod','buyout'=>'Kjøp-no-førespurnad','counter'=>'Motbod'][$ev['kind']] ?></td><td><?= money($ev['amount']) ?></td><td><?= state_label($ev['status']) ?></td><td><?= e($ev['created_at']) ?></td></tr><?php endforeach; if (!$events): ?><tr><td colspan="4">Ingen bod enno. Kanskje du blir den første?</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
    <?php if ($u): $offers=all('SELECT o.*,u.name FROM offers o JOIN users u ON u.id=o.buyer_id WHERE o.listing_id=?'.($owner?'':' AND o.buyer_id=?').' ORDER BY o.id DESC',$owner?[$id]:[$id,$uid]); if ($offers): ?>
    <section class="panel"><h2><?= $owner?'Administrer bod':'Dine bod og motbod' ?></h2><p class="muted">Denne delen er privat.</p><?php foreach ($offers as $o): ?><div class="offer"><div><strong><?= money($o['amount']) ?></strong> · <?= ['bid'=>'Bod','buyout'=>'Kjøp no','counter'=>'Motbod'][$o['kind']] ?> · <?= state_label($o['status']) ?><?php if ($owner): ?> frå <a href="/profile?id=<?= (int)$o['buyer_id'] ?>"><?= e($o['name']) ?></a><?php endif; ?></div><?php if ($o['status']==='pending' && $l['status']==='active'): ?><div class="actions"><?php $respond=$o['kind']==='counter'?!$owner:$owner; if ($respond): button_form('respond',['offer_id'=>$o['id'],'decision'=>'accepted'],'Godta',''); button_form('respond',['offer_id'=>$o['id'],'decision'=>'rejected'],'Avslå'); else: button_form('respond',['offer_id'=>$o['id'],'decision'=>'withdrawn'],'Trekk tilbake'); endif; ?></div><?php if ($owner && $o['kind']!=='counter'): form_start('offer',['listing_id'=>$id,'kind'=>'counter','buyer_id'=>$o['buyer_id']],'compact'); field('Motbod (kr)','amount','number','','required min="0.01" step="0.01"'); ?><button class="secondary">Send motbod</button></form><?php endif; endif; ?></div><?php endforeach; ?></section>
    <?php endif; endif; ?>
    <?php if ($u && $u['role']==='admin' && !$owner): ?><section class="panel"><h2>Bodgivarar · administrasjon</h2><p class="muted">Berre synleg for administratorar.</p><div class="table-scroll"><table><thead><tr><th>Brukar</th><th>Pris</th><th>Status</th></tr></thead><tbody><?php foreach (all('SELECT o.*,u.name FROM offers o JOIN users u ON u.id=o.buyer_id WHERE o.listing_id=? ORDER BY o.id DESC',[$id]) as $o): ?><tr><td><a href="/profile?id=<?= (int)$o['buyer_id'] ?>"><?= e($o['name']) ?></a></td><td><?= money($o['amount']) ?></td><td><?= state_label($o['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
    </div><aside><section class="panel listing-summary"><span class="tag static"><?= $l['kind']==='wanted'?'Ønskjer å kjøpe':state_label($l['status']) ?></span><h1><?= e($l['title']) ?></h1><p class="muted"><?= e($l['municipality']) ?> · <?= e($l['county']) ?></p><div class="big-price"><?= money($l['price']) ?></div><p class="muted"><?= $l['kind']==='wanted'?'Budsjett':'Prisantyding · alle bod er velkomne' ?></p><hr><p>Lagt ut av <a href="/profile?id=<?= (int)$l['user_id'] ?>"><strong><?= e($l['seller_name']) ?></strong></a></p>
    <?php if (!$u): ?><a class="button full" href="/login">Logg inn for å ta kontakt eller gi bod</a><?php else: ?>
    <div class="actions"><?php button_form('favorite',['listing_id'=>$id],one('SELECT 1 FROM favorites WHERE user_id=? AND listing_id=?',[$uid,$id])?'♥ Lagra':'♡ Lagre'); if (!$owner) button_form('conversation',['listing_id'=>$id],'Send melding'); ?></div>
    <?php if (!$owner && $l['status']==='active' && $l['kind']==='sell'): form_start('offer',['listing_id'=>$id,'kind'=>'bid'],'stack'); field('Ditt bod (kr)','amount','number','','required min="0.01" step="0.01"'); ?><button>Send bod</button></form><p class="muted small-text">Bodet blir offentleg, utan namnet ditt. Seljaren ser kven du er.</p><?php if ($l['buyout']): button_form('offer',['listing_id'=>$id,'kind'=>'buyout'],'Be om kjøp no · '.money($l['buyout']),'full'); ?><p class="muted small-text">Seljaren må godkjenne førespurnaden.</p><?php endif; endif; ?>
    <?php if ($l['status']==='reserved' && $participant): ?><div class="notice"><strong>Reservert handel · <?= money($l['accepted_price']) ?></strong><p>Avtal betaling og levering i meldingar. Begge må stadfeste at handelen er fullført.</p><p>Seljar: <?= $l['seller_confirmed']?'stadfesta':'ventar' ?> · Kjøpar: <?= $l['buyer_confirmed']?'stadfesta':'ventar' ?></p></div><?php
    $buyer=(int)$l['buyer_id']; $c=one('SELECT id FROM conversations WHERE listing_id=? AND buyer_id=?',[$id,$buyer]);
    if (!$owner): button_form('conversation',['listing_id'=>$id],'Opne samtale'); elseif ($c): ?><a class="button secondary" href="/messages?id=<?= (int)$c['id'] ?>">Opne samtale</a><?php endif;
    if (!($owner?$l['seller_confirmed']:$l['buyer_confirmed'])) button_form('trade',['listing_id'=>$id,'decision'=>'confirm'],'Stadfest fullført handel','');
    if ($owner) button_form('trade',['listing_id'=>$id,'decision'=>'reopen'],'Avslutt reservasjon og opne att');
    endif; ?>
    <?php if ($owner && $l['status']==='active'): button_form('close',['listing_id'=>$id],'Trekk annonsen'); endif; ?>
    <?php if ($l['status']==='sold' && $participant && !one('SELECT id FROM reviews WHERE listing_id=? AND author_id=?',[$id,$uid])): ?><h2>Korleis gjekk handelen?</h2><?php form_start('review',['listing_id'=>$id],'stack'); select_field('Stjerner','rating',[5=>'★★★★★ — svært bra',4=>'★★★★',3=>'★★★',2=>'★★',1=>'★']); ?><label>Vurdering<textarea name="body" required minlength="3" maxlength="2000"></textarea></label><button>Send vurdering</button></form><?php endif; ?>
    <?php if (!$owner): ?><details><summary>Rapporter annonsen</summary><?php form_start('report',['listing_id'=>$id],'stack'); ?><label>Grunn<textarea name="reason" required minlength="5" maxlength="2000"></textarea></label><button class="secondary">Send rapport</button></form></details><?php endif; endif; ?>
    <p class="muted small-text">Betaling og levering blir avtalte mellom brukarane. Marked tek ikkje imot betaling.</p></section></aside></div>
    <?php return $l['title'];
}
function account_page(): string {
    $u=require_user(false); $uid=(int)$u['id']; ?>
    <div class="section-heading"><div><span class="eyebrow">DIN LILLE DEL AV MARKED</span><h1>Hei, <?= e($u['name']) ?>!</h1><p class="muted"><?= e($u['email']) ?></p></div><div class="actions"><a class="button secondary" href="/profile?id=<?= $uid ?>">Sjå profilen min</a><?php button_form('logout',[],'Logg ut'); ?></div></div>
    <?php if (!$u['verified_at']): ?><section class="notice"><h2>Stadfest e-postadressa</h2><p>Følg lenkja i e-posten for å publisere annonsar, gi bod og sende meldingar. Lenkja varer i éin time.</p><?php button_form('resend',[],'Send ny lenkje'); ?></section><?php endif; ?>
    <section><div class="section-heading"><h2>Mine annonsar</h2><a class="button" href="/new">＋ Ny annonse</a></div><?php cards(all("SELECT * FROM listings WHERE user_id=? AND status<>'removed' ORDER BY id DESC",[$uid])); ?></section>
    <section><h2>Handlar eg deltek i</h2><?php cards(all("SELECT * FROM listings WHERE buyer_id=? AND status IN ('reserved','sold') ORDER BY id DESC",[$uid])); ?></section>
    <section><h2>Favorittar</h2><?php cards(all("SELECT l.* FROM listings l JOIN favorites f ON f.listing_id=l.id JOIN users u ON u.id=l.user_id WHERE f.user_id=? AND l.status<>'removed' AND u.suspended_at IS NULL ORDER BY l.id DESC",[$uid])); ?></section>
    <section class="panel"><h2>Lagra søk</h2><p class="muted">Nye treff kjem i ein dagleg e-post.</p><?php $searches=all('SELECT * FROM saved_searches WHERE user_id=?',[$uid]); foreach ($searches as $s): $f=json_decode($s['filters'],true); ?><div class="search-row"><a href="/?<?= e(http_build_query($f)) ?>"><?= e(implode(' · ',array_filter([$f['q']?:'Alle søkjeord',$f['county'],$f['municipality'],$f['kind']==='wanted'?'Ønskjer å kjøpe':($f['kind']==='sell'?'Til sals':''),$f['category']?(category_options()[$f['category']]??'Kategori'):'']))) ?></a><?php button_form('delete_search',['id'=>$s['id']],'Slett'); ?></div><?php endforeach; if (!$searches): ?><p>Du har ikkje lagra nokon søk enno.</p><?php endif; ?></section>
    <?php return 'Min side';
}
function messages_page(): string {
    $u=require_user(); $uid=(int)$u['id']; $id=(int)($_GET['id']??0);
    $conversations=all('SELECT c.*,l.title,l.user_id AS seller_id,b.name AS buyer_name,s.name AS seller_name,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_id<>? AND m.read_at IS NULL) AS unread FROM conversations c JOIN listings l ON l.id=c.listing_id JOIN users b ON b.id=c.buyer_id JOIN users s ON s.id=l.user_id WHERE c.buyer_id=? OR l.user_id=? ORDER BY (SELECT MAX(id) FROM messages WHERE conversation_id=c.id) DESC,c.id DESC',[$uid,$uid,$uid]);
    ?><h1>Meldingar</h1><div class="inbox"><aside class="panel conversation-list"><h2>Samtalar</h2><?php foreach ($conversations as $c): ?><a class="conversation <?= $id===(int)$c['id']?'selected':'' ?>" href="/messages?id=<?= (int)$c['id'] ?>"><strong><?= e($uid===(int)$c['buyer_id']?$c['seller_name']:$c['buyer_name']) ?></strong><span><?= e($c['title']) ?></span><?php if ($c['unread']): ?><span class="badge"><?= (int)$c['unread'] ?> nye</span><?php endif; ?></a><?php endforeach; if (!$conversations): ?><p class="muted">Start ein samtale frå ei annonse.</p><?php endif; ?></aside>
    <section class="panel chat"><?php if ($id): $c=conversation($id,$uid); $other=$uid===(int)$c['buyer_id']?(int)$c['seller_id']:(int)$c['buyer_id']; ?><div class="chat-heading"><h2><a href="/listing?id=<?= (int)$c['listing_id'] ?>"><?= e($c['title']) ?></a></h2><a href="/profile?id=<?= $other ?>">Sjå profil</a></div>
    <div id="chat-messages" class="chat-messages" data-conversation="<?= $id ?>" data-after="0" aria-live="polite" aria-label="Meldingar i samtalen"></div><p id="chat-status" class="muted small-text" role="status">Hentar meldingar …</p>
    <?php if (!blocked($uid,$other)): form_start('message',['conversation_id'=>$id],'stack chat-form',true); ?><label>Ny melding<textarea name="body" maxlength="5000" rows="3" placeholder="Skriv ei hyggeleg melding …"></textarea></label><div class="two-col"><label>Legg ved bilete<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label><button>Send melding</button></div></form><noscript>Chat krev JavaScript for å vise meldingar.</noscript><?php else: ?><p class="notice">Kontakt mellom dykk er blokkert.</p><?php endif; ?>
    <?php else: ?><div class="empty"><h2>Ein god handel startar med ein samtale</h2><p>Vel ein samtale, eller send ei melding frå ein annonse.</p></div><?php endif; ?></section></div>
    <?php return 'Meldingar';
}
function profile_page(int $id): string {
    $p=one('SELECT id,name,created_at FROM users WHERE id=? AND suspended_at IS NULL',[$id]); check((bool)$p,'Brukaren finst ikkje.',404);
    $u=user(); $reviews=all('SELECT r.*,u.name FROM reviews r JOIN users u ON u.id=r.author_id WHERE recipient_id=? ORDER BY r.id DESC',[$id]); $avg=one('SELECT AVG(rating) AS average,COUNT(*) AS n FROM reviews WHERE recipient_id=?',[$id]);
    ?><section class="panel profile-header"><div class="avatar"><?= e(strtoupper(substr($p['name'],0,1))) ?></div><div><span class="eyebrow">PÅ MARKED SIDAN <?= e(substr($p['created_at'],0,10)) ?></span><h1><?= e($p['name']) ?></h1><p><?= $avg['n']?'★ '.number_format((float)$avg['average'],1,',','').' · '.(int)$avg['n'].' vurderingar':'Ingen vurderingar enno' ?></p></div><?php if ($u && (int)$u['id']!==$id): button_form('block',['user_id'=>$id],one('SELECT 1 FROM blocks WHERE user_id=? AND blocked_id=?',[$u['id'],$id])?'Opphev blokkering':'Blokker brukar'); endif; ?></section>
    <h2>Annonsar</h2><?php cards(all("SELECT * FROM listings WHERE user_id=? AND status='active' ORDER BY id DESC",[$id])); ?>
    <section class="panel"><h2>Vurderingar frå fullførte handlar</h2><?php foreach ($reviews as $r): ?><article class="review"><strong><?= e($r['name']) ?></strong><span class="stars"><?= str_repeat('★',(int)$r['rating']) ?></span><p><?= nl2br(e($r['body'])) ?></p><small class="muted"><?= e(substr($r['created_at'],0,10)) ?></small></article><?php endforeach; if (!$reviews): ?><p class="muted">Ingen vurderingar enno.</p><?php endif; ?></section>
    <?php if ($u && (int)$u['id']!==$id): ?><details class="panel"><summary>Rapporter brukar</summary><?php form_start('report',['user_id'=>$id],'stack'); ?><label>Grunn<textarea name="reason" required minlength="5" maxlength="2000"></textarea></label><button>Send rapport</button></form></details><?php endif;
    return $p['name'];
}
function admin_page(): string {
    require_admin(); ?>
    <span class="eyebrow">MODERERING OG VEDLIKEHALD</span><h1>Administrasjon</h1>
    <section class="panel"><h2>Opne rapportar</h2><?php $reports=all('SELECT * FROM reports WHERE resolved_at IS NULL ORDER BY id'); foreach ($reports as $r): ?><article class="offer"><p><?= nl2br(e($r['reason'])) ?></p><div class="actions"><?php if ($r['listing_id']): ?><a href="/listing?id=<?= (int)$r['listing_id'] ?>">Sjå annonse #<?= (int)$r['listing_id'] ?></a><?php button_form('admin_remove',['id'=>$r['listing_id']],'Fjern annonse'); else: ?><a href="/profile?id=<?= (int)$r['reported_user_id'] ?>">Sjå brukar #<?= (int)$r['reported_user_id'] ?></a><?php endif; button_form('admin_resolve',['id'=>$r['id']],'Marker handsama'); ?></div></article><?php endforeach; if (!$reports): ?><p>Ingen opne rapportar.</p><?php endif; ?></section>
    <section class="panel"><h2>Brukarar</h2><div class="table-scroll"><table><thead><tr><th>Namn</th><th>E-post</th><th>Status</th><th>Handling</th></tr></thead><tbody><?php foreach (all('SELECT * FROM users ORDER BY id DESC LIMIT 200') as $u): ?><tr><td><a href="/profile?id=<?= (int)$u['id'] ?>"><?= e($u['name']) ?></a></td><td><?= e($u['email']) ?></td><td><?= $u['suspended_at']?'Stengd':($u['verified_at']?'Aktiv':'Ikkje stadfesta') ?></td><td><?php if ($u['role']!=='admin') button_form('admin_suspend',['id'=>$u['id']],$u['suspended_at']?'Opne konto':'Steng konto'); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <section class="panel"><h2>Siste annonsar</h2><div class="table-scroll"><table><thead><tr><th>Annonse</th><th>Status</th><th>Handling</th></tr></thead><tbody><?php foreach (all('SELECT id,title,status FROM listings ORDER BY id DESC LIMIT 100') as $l): ?><tr><td><a href="/listing?id=<?= (int)$l['id'] ?>"><?= e($l['title']) ?></a></td><td><?= state_label($l['status']) ?></td><td><?php if ($l['status']!=='removed') button_form('admin_remove',['id'=>$l['id']],'Fjern'); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <section class="panel"><h2>Kategoriar</h2><p><?= e(implode(' · ',category_options())) ?></p><?php form_start('admin_category',[],'compact'); field('Ny kategori','name','text','','required maxlength="100"'); ?><button>Legg til</button></form></section>
    <?php return 'Administrasjon';
}
