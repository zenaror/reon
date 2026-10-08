<?php
// Local development mail capture only. This file is not installed in production.
require_once '../../classes/DBUtil.php';
header('Content-Type: text/html; charset=utf-8');
$address=strtolower(trim((string)($_GET['address']??'')));
function esc($s){return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
echo '<h1>Dummy server — development email</h1><p>Internal only. Use an address ending in @reon.test for signup. Captured messages are visible to developers on this local stack.</p><form><input name="address" type="email" value="'.esc($address).'" placeholder="developer@reon.test"><button>Open mailbox</button></form><p><a href="/signup.php">REON signup</a> · <a href="/">REON home</a></p>';
if(!preg_match('/^[a-z0-9._+-]+@reon\.test$/',$address))exit;
$s=DBUtil::getInstance()->getDB()->prepare('SELECT message FROM dummy_mail WHERE recipient=? ORDER BY id DESC LIMIT 20');$s->bind_param('s',$address);$s->execute();
foreach($s->get_result() as $r){
 $raw=quoted_printable_decode($r['message']);
 echo '<hr><pre>'.esc($raw).'</pre>';
 if(preg_match_all('~https?://[^\s<>"\']+(?:signup_cont|reset_password|confirm_email)\.php[^\s<>"\']*~',$raw,$links))
  foreach(array_unique($links[0]) as $link)echo '<p><a href="'.esc(html_entity_decode($link,ENT_QUOTES,'UTF-8')).'">Open confirmation link</a></p>';
}
