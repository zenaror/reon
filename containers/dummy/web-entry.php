<?php
// Dummy server configuration uses the public template, never Oracle credentials.
$c=json_decode(file_get_contents(__DIR__.'/config.example.json'),true);
$c=array_replace($c,[
 'hostname'=>getenv('DUMMY_EXTERNAL_IP').':'.(getenv('DUMMY_HTTP_PORT')?:'80'),
 'email_domain'=>'reon.test','email_domain_dion'=>'reon.dion.ne.jp',
 'mysql_host'=>'database','mysql_database'=>'reon','mysql_user'=>'reon',
 'mysql_password'=>getenv('DUMMY_DB_PASSWORD'),
 'smtp_host'=>'email','smtp_port'=>25,'smtp_auth'=>false,'smtp_secure'=>'',
 'local_smtp_host'=>'email','email_block_pepper'=>getenv('DUMMY_INTERNAL_TOKEN'),
]);
file_put_contents(__DIR__.'/config.json',json_encode($c));
file_put_contents('/etc/msmtprc',"defaults\nauth off\ntls off\naccount default\nhost email\nport 25\nfrom system@reon.dion.ne.jp\n");
if (($argv[1]??'')==='migrate') {
 require_once __DIR__.'/web/classes/UserUtil.php';
 passthru('php web/vendor/bin/phinx migrate -c phinx.php',$status);
 if ($status!==0) exit($status);
 require __DIR__.'/dummy-seed.php';
 passthru('php web/vendor/bin/phinx seed:run -c phinx.php -s GameboyWars3Seeder',$status);exit($status);
}
pcntl_exec('/usr/local/bin/docker-php-entrypoint',array_slice($argv,1));
