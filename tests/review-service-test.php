<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReviewService.php';
function rsSame($a,$b,$m){if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));} function rsThrows($f,$m){try{$f();}catch(Throwable){return;}throw new RuntimeException($m);}
class RsDb{public bool $tx=false;function beginTransaction(){$this->tx=true;}function commit(){$this->tx=false;}function rollBack(){$this->tx=false;}function inTransaction(){return $this->tx;}}
class RsReviews{public array $r;function __construct(){$this->r=['id'=>1,'case_id'=>31,'status'=>'OPEN','version'=>1,'context'=>['signature'=>['review_reason'=>'X','material_conflict'=>false]]];}function find($id){return $this->r;}function lock($id){return $this->r;}function decide($id,$v,$d){if($this->r['version']!==$v||$this->r['status']!=='OPEN')throw new RuntimeException('stale');$this->r['version']++;$this->r['status']='DECIDED';$this->r['human_decision']=$d;return $this->r;}}
class RsRules{public array $rows=[];function active(){return $this->rows;}function promote($d){$r=$d+['id'=>count($this->rows)+1,'version'=>1,'status'=>'ACTIVE'];$this->rows[]=$r;return $r;}function revision(){return hash('sha256',json_encode($this->rows));}}
class RsCases{function openCases($n){return [];}function find($id){return null;}}
class RsEvents{function eventsForCase($id){return [];}function append($e){return 1;}}
class RsApps{function record($a){return 1;}}
class RsPolicies{function allActive(){return [];}}
class RsOutbox{function enqueue($k,$c,$p,$i){return 1;}}
class RsP{public $reviews,$learnedRules,$cases,$events,$ruleApplications,$policies,$outbox;private $d;function __construct(){$this->d=new RsDb;$this->reviews=new RsReviews;$this->learnedRules=new RsRules;$this->cases=new RsCases;$this->events=new RsEvents;$this->ruleApplications=new RsApps;$this->policies=new RsPolicies;$this->outbox=new RsOutbox;}function db(){return $this->d;}}
$p=new RsP;$coord=new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p,null);$s=new SvAmazonReviewService($p,$coord);
$d=['decision_mode'=>'APPROVED','final_action'=>'CHECK_FINANCES','parameters'=>['date_binding'=>'NONE'],'source_version'=>'test'];$preview=$s->preview(1,$d);rsSame([],array_column($preview['matching_cases'],'id'),'empty candidates');$r=$s->submit(1,1,$d,'Fred');rsSame(1,$r['rule_id'],'promoted');rsSame('Fred',$p->reviews->r['human_decision']['actor'],'actor persisted');rsThrows(fn()=>$s->submit(1,1,$d,'Fred'),'stale version rejected');
$p2=new RsP;$s2=new SvAmazonReviewService($p2,new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p2,null));$e=$d;$e['decision_mode']='EXCEPTION';$r=$s2->submit(1,1,$e,'Fred');rsSame(null,$r['rule_id'],'exception no rule');rsSame(0,count($p2->learnedRules->rows),'exception no memory');
$p3=new RsP;$s3=new SvAmazonReviewService($p3,new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p3,null));$literal=$d;$literal['parameters']=['date'=>'2026-09-10'];rsThrows(fn()=>$s3->preview(1,$literal),'literal reusable date rejected');
echo "review-service-test: OK\n";
