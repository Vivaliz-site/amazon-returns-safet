<?php
declare(strict_types=1);

require_once __DIR__.'/Runtime.php';

final class SvAmazonCockpitHealth
{
    private const BUSINESS_FRESHNESS_SECONDS=64800;

    public static function operatorStatus(int $humanActions,int $operationalProblems): string
    {
        if($humanActions>0)return 'USER_ACTION_REQUIRED';
        if($operationalProblems>0)return 'DEGRADED';
        return 'NORMAL';
    }

    /** @return array<string,mixed> */
    public static function build(
        SvAmazonTenantPersistence $p,
        SvAmazonReturnsConfig $config,
        array $caseSummary,
        DateTimeImmutable $now
    ): array {
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        $runtime=SvAmazonReturnsRuntime::health($p,$config,$now);
        $humanActions=max(0,(int)($runtime['pending_reviews']??0));
        $automaticWork=max(0,(int)($caseSummary['automatic_work_cases']??0));
        $concluded=max(0,(int)($caseSummary['concluded_cases']??0));
        $problems=[];
        $gateDefinitions=[
            'unclassified'=>['amount'=>'unclassified_amount','status'=>'UNCLASSIFIED'],
            'eligible_without_action'=>['amount'=>'eligible_without_action_amount','status'=>'AUTOMATION_DUE'],
            'expired_without_treatment'=>['amount'=>'expired_without_treatment_amount','status'=>'OVERDUE'],
            'credit_without_reconciliation'=>['amount'=>'credit_without_reconciliation_amount','status'=>'RECONCILIATION'],
        ];
        foreach($gateDefinitions as $key=>$definition){
            $count=max(0,(int)($caseSummary[$key]??0));
            if($count<1)continue;
            $problems[]=[
                'key'=>$key,
                'affected_cases'=>$count,
                'affected_amount'=>number_format((float)($caseSummary[$definition['amount']]??0),2,'.',''),
                'owner'=>'SYSTEM',
                'status'=>$definition['status'],
                'observed_at'=>$now->format(DATE_ATOM),
            ];
        }
        $deadLetters=max(0,(int)($runtime['dead_letters']??0));
        if($deadLetters>0){
            $problems[]=[
                'key'=>'dead_letters',
                'affected_cases'=>$deadLetters,
                'affected_amount'=>null,
                'owner'=>'SYSTEM',
                'status'=>'FAILED',
                'observed_at'=>$now->format(DATE_ATOM),
            ];
        }
        $readiness=is_array($runtime['readiness']??null)?$runtime['readiness']:[];
        $connectors=[
            'amazon'=>self::sourceConnector(
                $p,'sp_api',(bool)($readiness['sp_api']['ready']??false),$now
            ),
            'gmail'=>self::sourceConnector(
                $p,'gmail',(bool)($readiness['gmail']['ready']??false),$now
            ),
            'seller_central'=>self::sellerCentralConnector($p,$runtime,$now),
        ];
        foreach($connectors as $name=>$connector){
            if(!in_array((string)($connector['status']??''),['DEGRADED','UNKNOWN'],true))continue;
            $problems[]=[
                'key'=>'connector_'.$name,
                'affected_cases'=>0,
                'affected_amount'=>null,
                'owner'=>'SYSTEM',
                'status'=>(string)$connector['status'],
                'observed_at'=>$connector['observed_at']??null,
            ];
        }

        $cycle=$p->cursors->load('OPERATIONAL','cycle_success');
        $lastSuccess=self::cursorTimestamp($cycle);
        $problemCount=count($problems);

        return [
            'operator_status'=>self::operatorStatus($humanActions,$problemCount),
            'human_action_count'=>$humanActions,
            'automatic_work_count'=>$automaticWork,
            'concluded_count'=>$concluded,
            'operational_problem_count'=>$problemCount,
            'last_successful_cycle_at'=>$lastSuccess,
            'connectors'=>$connectors,
            'operational_problems'=>$problems,
        ];
    }

    /** @return array{status:string,observed_at:?string,reason:string} */
    private static function sourceConnector(
        SvAmazonTenantPersistence $p,
        string $task,
        bool $ready,
        DateTimeImmutable $now
    ): array {
        $cursor=$p->cursors->load('OPERATIONAL_TASK',$task);
        $observedAt=self::cursorTimestamp($cursor);
        if(!$ready){
            return ['status'=>'DEGRADED','observed_at'=>$observedAt,'reason'=>'not_ready'];
        }
        if(!is_array($cursor)){
            return ['status'=>'OK','observed_at'=>null,'reason'=>'configured'];
        }
        $taskStatus=strtoupper(trim((string)($cursor['metadata']['status']??'UNKNOWN')));
        if(in_array($taskStatus,['FAILED','PARTIAL'],true)){
            return ['status'=>'DEGRADED','observed_at'=>$observedAt,'reason'=>'last_run_failed'];
        }
        if($observedAt!==null){
            try{
                $seen=new DateTimeImmutable($observedAt,new DateTimeZone('UTC'));
                if($now->getTimestamp()-$seen->getTimestamp()>self::BUSINESS_FRESHNESS_SECONDS){
                    return ['status'=>'DEGRADED','observed_at'=>$observedAt,'reason'=>'stale'];
                }
            }catch(Throwable){
                return ['status'=>'UNKNOWN','observed_at'=>null,'reason'=>'invalid_observation'];
            }
        }
        return ['status'=>'OK','observed_at'=>$observedAt,'reason'=>'source_fresh'];
    }

    /** @return array{status:string,observed_at:?string,reason:string} */
    private static function sellerCentralConnector(
        SvAmazonTenantPersistence $p,
        array $runtime,
        DateTimeImmutable $now
    ): array {
        $readiness=is_array($runtime['readiness']??null)?$runtime['readiness']:[];
        $required=(bool)($runtime['enabled']??false) && (bool)($readiness['seller_central_bridge']['ready']??false);
        if(!$required){
            return ['status'=>'NOT_REQUIRED','observed_at'=>null,'reason'=>'on_demand'];
        }
        $browser=is_array($runtime['seller_central_browser']??null)?$runtime['seller_central_browser']:[];
        $status=strtoupper(trim((string)($browser['status']??'UNKNOWN')));
        $cursor=$p->cursors->load('SELLER_CENTRAL','browser_auth');
        $observedAt=self::cursorTimestamp($cursor);
        if($status==='DEGRADED'){
            return ['status'=>'DEGRADED','observed_at'=>$observedAt,'reason'=>'browser_degraded'];
        }
        if($status===''||$status==='UNKNOWN'){
            return ['status'=>'UNKNOWN','observed_at'=>$observedAt,'reason'=>'browser_unknown'];
        }
        if($observedAt!==null){
            try{
                $seen=new DateTimeImmutable($observedAt,new DateTimeZone('UTC'));
                if($now->getTimestamp()-$seen->getTimestamp()>self::BUSINESS_FRESHNESS_SECONDS){
                    return ['status'=>'DEGRADED','observed_at'=>$observedAt,'reason'=>'stale'];
                }
            }catch(Throwable){
                return ['status'=>'UNKNOWN','observed_at'=>null,'reason'=>'invalid_observation'];
            }
        }
        return ['status'=>'OK','observed_at'=>$observedAt,'reason'=>'source_fresh'];
    }

    private static function cursorTimestamp(?array $cursor): ?string
    {
        if(!is_array($cursor))return null;
        foreach(['value','observed_at'] as $key){
            $value=trim((string)($cursor[$key]??''));
            if($value==='')continue;
            try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);}
            catch(Throwable){continue;}
        }
        return null;
    }
}
