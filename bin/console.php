<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/mail.php';
if (PHP_SAPI!=='cli') exit;
try {
    switch ($argv[1]??'help') {
        case 'migrate':
            db()->exec(file_get_contents(ROOT.'/database/schema.sql'));
            foreach (['Heim og interiør','Elektronikk','Klede og tilbehøyr','Sport og friluftsliv','Hobby og fritid','Verktøy og hage','Barn og utstyr','Bøker og musikk'] as $name) query('INSERT IGNORE INTO categories(name) VALUES (?)',[$name]);
            fwrite(STDOUT,"Databasen er klar.\n"); break;
        case 'seed':
            check(env('APP_ENV')==='local','Demodata er berre tilgjengelege med APP_ENV=local.');
            require ROOT.'/database/seed.php'; seed(); break;
        case 'admin':
            $email=$argv[2]??'';
            check((bool)one('SELECT id FROM users WHERE email=?',[$email]),'Registrer kontoen først.');
            query("UPDATE users SET role='admin' WHERE email=?",[$email]); fwrite(STDOUT,"Administrator er sett.\n"); break;
        case 'mail': fwrite(STDOUT,process_mail()." e-postar sende.\n"); break;
        case 'digest': fwrite(STDOUT,process_digests()." samandrag lagde i kø.\n"); break;
        default: fwrite(STDOUT,"php bin/console.php migrate|seed|admin <email>|mail|digest\n");
    }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
