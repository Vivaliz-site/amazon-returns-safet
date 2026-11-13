<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/ReviewOperations.php';
SvAmazonReturnsAdminAuth::requireLogin(true);SvAmazonReturnsAdminAuth::releaseSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_reviews_reply(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_reviews_reply(['success'=>false,'error'=>'Banco indisponível.'],503);SvAmazonReturnsSchema::ensure($db);
 $config=new SvAmazonReturnsConfig();$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $page=max(1,(int)($_GET['page']??1));$per=max(25,min(100,(int)($_GET['per_page']??50)));$filters=[];
 $view=strtolower(trim((string)($_GET['view']??'all')));
 if(!in_array($view,['all','human','operational'],true))throw new InvalidArgumentException();
 if(isset($_GET['reason'])){$v=trim((string)$_GET['reason']);if($v==='')throw new InvalidArgumentException();$filters['reason']=$v;}
 if(isset($_GET['case_id'])){$v=filter_var($_GET['case_id'],FILTER_VALIDATE_INT);if($v===false||$v<1)throw new InvalidArgumentException();$filters['case_id']=$v;}
 $queue=$p->reviews->openQueue($filters);
 $humanRows=array_values(array_filter($queue,SvAmazonReviewOperations::requiresManualDecision(...)));
 $humanTotal=count($humanRows);$operationalTotal=count($queue)-$humanTotal;
 $rows=match($view){
     'human'=>$humanRows,
     'operational'=>array_values(array_filter($queue,static fn(array $r):bool=>!SvAmazonReviewOperations::requiresManualDecision($r))),
     default=>$queue,
 };
 $total=count($rows);$rows=array_slice($rows,($page-1)*$per,$per);
 $items=array_map(static function(array $r):array {
     $item=array_intersect_key($r,array_flip(['id','case_id','reason','status','version','decision_mode','actor','source_version','ai_provider','ai_model','ai_error_count','created_at','decided_at']));
     $item['requires_human_decision']=SvAmazonReviewOperations::requiresManualDecision($r);
     return $item;
 },$rows);
 sv_amz_reviews_reply(['success'=>true,'items'=>$items,'page'=>$page,'per_page'=>$per,'total'=>$total,
     'human_total'=>$humanTotal,'operational_total'=>$operationalTotal]);
}catch(InvalidArgumentException){sv_amz_reviews_reply(['success'=>false,'error'=>'Filtros inválidos.'],422);}catch(Throwable $e){error_log('[amazon-returns-reviews] '.get_class($e));sv_amz_reviews_reply(['success'=>false,'error'=>'Não foi possível consultar revisões.'],500);}
