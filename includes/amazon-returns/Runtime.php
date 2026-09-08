<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/PolicySeeder.php';
require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonReturnsRuntime
{
    /** @return array<string,int> */
    public static function cadences(): array
    {
        return [
            'gmail'=>300,
            'scheduler'=>600,
            'review_operations'=>300,
            'seller_central'=>300,
            'financial'=>1800,
            'sp_api'=>1800,
            'returns_report'=>7200,
            'health'=>900,
            'policy_monitor'=>86400,
        ];
    }

    /** @return list<string> */
    public static function dueTasks(array $state, DateTimeImmutable $now): array
    {
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        $due=['bootstrap'];
        foreach(self::cadences() as $task=>$seconds){
            $last=$state[$task] ?? null;
            if(!is_string($last) || $last===''){
                $due[]=$task;
                continue;
            }
            try{
                $when=(new DateTimeImmutable($last))->setTimezone(new DateTimeZone('UTC'));
            }catch(Throwable){
                $due[]=$task;
                continue;
            }
            if($now->getTimestamp()-$when->getTimestamp()>=$seconds)$due[]=$task;
        }
        return $due;
    }

    /** @return array<string,mixed> */
    public static function bootstrap(PDO $db, SvAmazonTenantContext $context): array
    {
        SvAmazonReturnsSchema::ensure($db);
        $p=SvAmazonTenantPersistence::create($db,$context);
        $policySeeds=SvAmazonReturnPolicySeeder::ensure($p->policies);
        $policyAudit=$policySeeds>0 ? SvAmazonReturnPolicySeeder::auditDefinitions($p->policies->allActive()) : ['valid'=>true,'policy_key'=>null];
        if(!$policyAudit['valid'])throw new RuntimeException('Active operational policy does not match approved opening rule.');
        return [
            'status'=>'OK',
            'tenant_id'=>$context->tenantId(),
            'amazon_connection_id'=>$context->amazonConnectionId(),
            'schema_tables'=>count(SvAmazonReturnsSchema::statements()),
            'policy_seeds'=>$policySeeds,
            'policy_audit'=>$policyAudit,
        ];
    }

    /** @return array<string,mixed> */
    public static function health(
        SvAmazonTenantPersistence $p,
        SvAmazonReturnsConfig $config
    ): array {
        $db=$p->db();
        $tables=(int)$db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema=DATABASE() AND table_name LIKE 'amazon_return_%'"
        )?->fetchColumn();
        $reviewNotifyEmail=trim($config->get('AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL'));
        $reviewNotificationReady=filter_var($reviewNotifyEmail,FILTER_VALIDATE_EMAIL)!==false;
        return [
            'status'=>'OK',
            'tenant_id'=>$p->context()->tenantId(),
            'amazon_connection_id'=>$p->context()->amazonConnectionId(),
            'tables'=>$tables,
            'cases'=>$p->cases->countAll(),
            'pending_outbox'=>$p->outbox->countPendingProcessing(),
            'dead_letters'=>$p->outbox->countDeadLetters(),
            'pending_reviews'=>$p->reviews->countOpen(),
            'rule_conflicts'=>$p->reviews->countOpenByReason('LEARNED_RULE_CONFLICT'),
            'rule_applications'=>$p->ruleApplications->countAll(),
            'ai_suggestion_failures'=>$p->reviews->countAiFailures(),
            'review_ai_ready'=>$config->reviewAiReady(),
            'review_notification_ready'=>$reviewNotificationReady,
            'learned_rule_execution_enabled'=>$config->learnedRuleExecutionEnabled(),
            'review_memory'=>[
                'pending_reviews'=>$p->reviews->countOpen(),
                'rule_conflicts'=>$p->reviews->countOpenByReason('LEARNED_RULE_CONFLICT'),
                'rule_applications'=>$p->ruleApplications->countAll(),
                'ai_suggestion_failures'=>$p->reviews->countAiFailures(),
                'review_ai_ready'=>$config->reviewAiReady(),
                'review_notification_ready'=>$reviewNotificationReady,
                'learned_rule_execution_enabled'=>$config->learnedRuleExecutionEnabled(),
            ],
            'source_cursors'=>$p->cursors->count(),
            'mode'=>$config->mode(),
            'enabled'=>$config->enabled(),
            'readiness'=>$config->readiness(),
            'write_flags'=>$config->writeFlags(),
        ];
    }
}
