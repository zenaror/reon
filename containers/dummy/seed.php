<?php
require_once __DIR__.'/web/classes/UserUtil.php';
require_once __DIR__.'/web/classes/SettingsUtil.php';
$db=DBUtil::getInstance()->getDB();
if ($db->query("SHOW TABLES LIKE 'sdk_mail'")->num_rows && !$db->query("SHOW TABLES LIKE 'dummy_mail'")->num_rows) $db->query("RENAME TABLE sdk_mail TO dummy_mail");
$old=$db->query("SELECT id,password FROM sys_users WHERE username='sdkoperator' AND is_admin=1")->fetch_assoc();
if ($old) {
 $hash=password_verify('sdkadmin1',$old['password'])?password_hash('dummyadmin1',PASSWORD_DEFAULT):$old['password'];
 $s=$db->prepare("UPDATE sys_users SET username='devadmin',email='devadmin@reon.test',password=? WHERE id=?");$s->bind_param('si',$hash,$old['id']);$s->execute();
}
$db->query("CREATE TABLE IF NOT EXISTS dummy_mail (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, recipient VARCHAR(190) NOT NULL, sender VARCHAR(190) NOT NULL, message LONGBLOB NOT NULL, folder VARCHAR(16) NOT NULL DEFAULT 'INBOX', timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL, retrieved_at DATETIME NULL, deleted_at DATETIME NULL, deleted_by VARCHAR(16) NULL, INDEX(recipient))");
if (!$db->query("SELECT id FROM sys_users WHERE username='devadmin'")->num_rows) {
 $u=UserUtil::getInstance();
 if ($u->createUser('devadmin@reon.test','devadmin','dummyadmin1','dummyadmin1')!==0) throw new RuntimeException('Dummy server devadmin creation failed');
 $db->query("UPDATE sys_users SET is_admin=1 WHERE username='devadmin'");
}
foreach (['bin_dns1_host'=>getenv('DUMMY_EXTERNAL_IP'),'bin_dns1_port'=>getenv('DUMMY_DNS_PORT')?:'53','bin_relay_host'=>getenv('DUMMY_EXTERNAL_IP'),'bin_unmetered'=>'1'] as $k=>$v) SettingsUtil::getInstance()->set($k,$v);
echo "Dummy server database ready.\n";
