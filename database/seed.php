<?php
declare(strict_types=1);

function seed(): void {
    if (one("SELECT id FROM users WHERE email='seljar@marked.test'")) { fwrite(STDOUT,"Demodata finst allereie.\n"); return; }
    $password=password_hash('Marked-demo-2026',PASSWORD_DEFAULT);
    foreach ([['Ingrid Solheim','seljar@marked.test','user'],['Ola Vik','kjopar@marked.test','user'],['Administrator','admin@marked.test','admin']] as [$name,$email,$role]) {
        query('INSERT INTO users(name,email,password,role,verified_at) VALUES (?,?,?,?,NOW())',[$name,$email,$password,$role]);
    }
    $seller=(int)one("SELECT id FROM users WHERE email='seljar@marked.test'")['id'];
    $buyer=(int)one("SELECT id FROM users WHERE email='kjopar@marked.test'")['id'];
    $items=[
        ['Lenestol i varm eik','Heim og interiør','Vestland','Bergen',1200,1800,'chair'],
        ['Bysykkel klar for nye turar','Sport og friluftsliv','Oslo','Oslo',1800,2500,'bike'],
        ['Kamera for dei små augneblinkane','Elektronikk','Trøndelag','Trondheim',2400,3200,'camera'],
        ['Grøne vener i fine potter','Verktøy og hage','Rogaland','Stavanger',150,250,'plant'],
        ['Hovudtelefonar med god lyd','Elektronikk','Vestland','Bergen',650,900,'headphones'],
        ['Skrivebord med plass til idear','Heim og interiør','Agder','Kristiansand',800,1200,'desk'],
        ['Ønskjer å kjøpe tursko, str. 39','Sport og friluftsliv','Akershus','Bærum',700,null,'wanted'],
        ['Ei lita samling gode bøker','Bøker og musikk','Møre og Romsdal','Ålesund',200,300,'books'],
    ];
    $first=null;
    foreach ($items as $i=>[$title,$category,$county,$municipality,$price,$buyout,$art]) {
        $cat=(int)one('SELECT id FROM categories WHERE name=?',[$category])['id'];
        query('INSERT INTO listings(user_id,category_id,kind,title,description,county,municipality,item_condition,delivery,price,buyout) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$art==='wanted'?$buyer:$seller,$cat,$art==='wanted'?'wanted':'sell',$title,$art==='wanted'?'Eg leitar etter eit par gode tursko. Ta gjerne kontakt om du har eit par du ikkje brukar lenger.':'Pent brukt og godt teken vare på. Sel vidare fordi eg ikkje brukar denne like mykje lenger. Ta gjerne kontakt om du lurer på noko! Dette er ei demoannonse.',$county,$municipality,'Brukt','Henting eller sending',$price,$buyout]);
        $lid=(int)db()->lastInsertId(); $first??=$lid;
        if ($art==='wanted') continue;
        $bg=['#ece1d3','#e1e7db','#eedecf','#e8eddf'][$i%4];
        $shape=match($art) {
            'chair'=>'<path d="M205 230v-75q0-35 35-35h100q35 0 35 35v75" fill="#c79e73"/><rect x="190" y="220" width="200" height="45" rx="14" fill="#a87549"/><path d="M215 262l-15 95m165-95 15 95M190 225v-35m200 35v-35" stroke="#845d3c" stroke-width="15" stroke-linecap="round"/>',
            'bike'=>'<g fill="none" stroke="#536856" stroke-width="10"><circle cx="180" cy="280" r="65"/><circle cx="420" cy="280" r="65"/><path d="m180 280 70-115 70 115H180l105-90 35 90 70-130 30 130m-55-130h50m-190 10h50"/></g>',
            'camera'=>'<rect x="160" y="165" width="280" height="170" rx="24" fill="#54534f"/><path d="M210 165v-25h90l20 25" fill="#696b63"/><circle cx="305" cy="250" r="65" fill="#929d96"/><circle cx="305" cy="250" r="46" fill="#384b48"/><circle cx="290" cy="236" r="17" fill="#758a7f"/><rect x="380" y="187" width="35" height="20" rx="5" fill="#d8bd91"/>',
            'plant'=>'<path d="M245 260h130l-20 110h-90z" fill="#bb7353"/><path d="M310 275V130" stroke="#526f4c" stroke-width="8"/><g fill="#738a60"><ellipse cx="270" cy="195" rx="50" ry="23" transform="rotate(35 270 195)"/><ellipse cx="350" cy="150" rx="50" ry="23" transform="rotate(-40 350 150)"/><ellipse cx="345" cy="235" rx="45" ry="20" transform="rotate(-35 345 235)"/></g>',
            'headphones'=>'<path d="M205 260v-55a95 95 0 0 1 190 0v55" fill="none" stroke="#5f695b" stroke-width="35"/><rect x="180" y="225" width="65" height="110" rx="26" fill="#8d967f"/><rect x="355" y="225" width="65" height="110" rx="26" fill="#8d967f"/>',
            'desk'=>'<rect x="120" y="205" width="360" height="25" rx="7" fill="#bc9368"/><path d="M150 230v130m300-130v130" stroke="#75654e" stroke-width="13"/><rect x="190" y="125" width="120" height="80" rx="6" fill="#6a7468"/><rect x="200" y="135" width="100" height="57" fill="#b6c3ad"/><path d="M375 205v-60l35-30" fill="none" stroke="#b96d4c" stroke-width="9"/><path d="m390 105 45 30-55 15z" fill="#b96d4c"/>',
            default=>'<g transform="rotate(-8 300 240)"><rect x="175" y="120" width="65" height="235" rx="7" fill="#a36d50"/><rect x="245" y="130" width="60" height="225" rx="7" fill="#7e8b6a"/><rect x="310" y="145" width="60" height="210" rx="7" fill="#d0a76f"/><path d="M185 150h43m27 25h40m25 10h40" stroke="#ecdcc4" stroke-width="5"/></g>',
        };
        $svg='<svg xmlns="http://www.w3.org/2000/svg" width="600" height="450" viewBox="0 0 600 450"><rect width="600" height="450" fill="'.$bg.'"/><ellipse cx="300" cy="370" rx="190" ry="14" fill="#000" opacity=".05"/>'.$shape.'</svg>';
        $name='demo-'.bin2hex(random_bytes(12)).'.svg'; file_put_contents(ROOT.'/storage/uploads/'.$name,$svg);
        query('INSERT INTO photos(listing_id,filename,mime) VALUES (?,?,?)',[$lid,$name,'image/svg+xml']);
    }
    make_offer($first,$buyer,'bid','1000');
    fwrite(STDOUT,"Demodata er klare. Kontoar: seljar@marked.test, kjopar@marked.test, admin@marked.test\nPassord: Marked-demo-2026\n");
}
