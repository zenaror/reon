<?php
// Set local adapter destinations on first install; preserve operator overrides.
require_once __DIR__.'/../web/classes/DBUtil.php';
$ip = $argv[1] ?? '';
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    fwrite(STDERR, "EXTERNAL_IP must be IPv4 for adapter settings\n");
    exit(1);
}
$db = DBUtil::getInstance()->getDB();
$stmt = $db->prepare('INSERT INTO sys_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE name=name');
foreach (['bin_dns1_host', 'bin_relay_host'] as $name) {
    $stmt->bind_param('ss', $name, $ip);
    $stmt->execute();
}
echo "Adapter destinations initialized; existing settings preserved.\n";
