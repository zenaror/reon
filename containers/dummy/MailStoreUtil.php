<?php
// Dummy server-only mailbox backend. Copied into the Dummy server image; production keeps Dovecot.
require_once __DIR__.'/DBUtil.php';
class MailStoreUtil {
 const TRASH='Trash';
 public static function makeId($folder,$id){return $folder.':'.(int)$id;}
 public static function splitId($id){$p=explode(':',(string)$id,2);return count($p)===2&&ctype_digit($p[1])&&in_array($p[0],['INBOX','Trash'])?[$p[0],(int)$p[1]]:null;}
 private static function query($sql,$args=[]){$s=DBUtil::getInstance()->getDB()->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();return $s;}
 public static function rows($user,$folder='INBOX',$body=false){
  $user=explode('@',$user)[0];$s=self::query('SELECT * FROM dummy_mail WHERE recipient=? AND folder=? ORDER BY id',[$user,$folder]);$rows=[];
  foreach($s->get_result() as $r){$r['uid']=(int)$r['id'];$r['id']=self::makeId($folder,$r['uid']);$r['size']=strlen($r['message']);if(!$body)unset($r['message']);$rows[]=$r;}return $rows;
 }
 public static function row($user,$id){$p=self::splitId($id);if(!$p)return null;foreach(self::rows($user,$p[0],true) as $r)if($r['id']===$id)return $r;return null;}
 public static function message($user,$id){return self::row($user,$id)['message']??null;}
 private static function update($user,$ids,$set){$n=0;foreach((array)$ids as $id){$p=self::splitId($id);if(!$p)continue;$s=self::query('UPDATE dummy_mail SET '.$set.' WHERE recipient=? AND id=?',[explode('@',$user)[0],$p[1]]);$n+=$s->affected_rows;}return $n;}
 public static function moveToTrash($u,$ids){return self::update($u,$ids,"folder='Trash',deleted_at=NOW(),deleted_by='web'");}
 public static function restore($u,$ids){return self::update($u,$ids,"folder='INBOX',deleted_at=NULL,deleted_by=NULL");}
 public static function purge($u,$ids){$n=0;foreach((array)$ids as $id){$p=self::splitId($id);if($p)$n+=self::query('DELETE FROM dummy_mail WHERE recipient=? AND id=?',[explode('@',$u)[0],$p[1]])->affected_rows;}return $n;}
 public static function markRead($u,$id,$read=true){self::update($u,[$id],'read_at='.($read?'NOW()':'NULL'));return true;}
 public static function isRead($u,$id){return !empty(self::row($u,$id)['read_at']);}
 public static function stats($u,$hours=24){$r=self::rows($u,'INBOX');return ['total'=>count($r),'recentes'=>count(array_filter($r,fn($m)=>strtotime($m['timestamp'])>=time()-$hours*3600))];}
 public static function purgeOlderThan($u,$days){return self::query("DELETE FROM dummy_mail WHERE recipient=? AND folder='Trash' AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",[explode('@',$u)[0],$days])->affected_rows;}
}
