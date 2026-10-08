<?php
// Use REON's real account/config generator, enabling libmobile's documented
// SMTP 25 -> 587 option for the local dummy. Preserve every other byte.
ob_start();
require dirname(__DIR__,3).'/dummy-adapter-original.php';
$bytes=ob_get_clean();
if (strlen($bytes)===512 && substr($bytes,0x100,2)==='LM') {
    $bytes[0x10c]="\x01";
    $sum=0;
    foreach(str_split(substr($bytes,0x105,0x60-5)) as $byte) $sum+=ord($byte);
    $bytes=substr_replace($bytes,pack('v',$sum&0xffff),0x103,2);
}
echo $bytes;
