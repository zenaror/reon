<?php
require_once '../../classes/TemplateUtil.php';require_once '../../classes/SessionUtil.php';require_once '../../classes/AdminUtil.php';require_once '../../classes/CsrfUtil.php';require_once '../../classes/GameSupportUtil.php';require_once '../../classes/ServiceStatusUtil.php';
session_start();AdminUtil::guard();$notice=null;$kind='ok';
$health=ServiceStatusUtil::getInstance()->getAll();
if($_SERVER['REQUEST_METHOD']==='POST') {
 CsrfUtil::check();$module=(string)($_POST['module']??'');$state=(string)($_POST['state']??'');
 $meta=GameSupportUtil::MODULES[$module]??null;
 if(!$meta||!empty($meta['required'])||!in_array($state,['0','1'],true)){$notice=TemplateUtil::translate('game-support.required');$kind='bad';}
 elseif($state==='1'&&in_array('mail',$meta['dependencies'],true)&&($health['mail']['status']??'unknown')==='down'){$notice=TemplateUtil::translate('game-support.mail-down');$kind='bad';}
 else {SettingsUtil::getInstance()->set('game_support_'.$module,$state);AdminUtil::getInstance()->log('game-support.set',$module,$state);header('Location: /admin/game_support.php?saved=1');exit;}
}
if(isset($_GET['saved']))$notice=TemplateUtil::translate('game-support.saved');
$modules=[];foreach(GameSupportUtil::MODULES as $key=>$meta)$modules[$key]=$meta+['enabled'=>GameSupportUtil::enabled($key)];
echo TemplateUtil::render('admin/game_support',['modules'=>$modules,'dependencies'=>GameSupportUtil::dependencies(),'health'=>$health,'notice'=>$notice,'notice_kind'=>$kind]);
