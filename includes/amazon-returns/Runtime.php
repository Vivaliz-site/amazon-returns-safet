<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/PolicySeeder.php';

final class SvAmazonReturnsRuntime
{
    /** @return array<string,int> */
    public static function cadences(): array {
        return ['gmail'=>300,'scheduler'=>600,'seller_central'=>300,'financial'=>1800,'sp_api'=>1800,'returns_report'=>7200,'health'=>900,'policy_monitor'=>86400];
    }
    /** @return list<string> */
    public static function dueTasks(array $state, DateTimeImmutable $now): array {
        $now=$now->setTimezone(new DateTimeZone('UTC')); $due=['bootstrap'];
        foreach(self::cadences() as $task=>$seconds){
            $last=$state[$task] ?? null;
            if (!is_string($last)||$last==='') { $due[]=$task; continue; }
            try{$when=(new DateTimeImmutable($last))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable){$due[]=$task;continue;}
            if($now->getTimestamp()-$when->getTimestamp()>=$seconds)$due[]=$task;
        }
        return $due;
    }
    /** @return array<string,mixed> */
    public static function bootstrap(PDO $db): array {
        SvAmazonReturnsSchema::ensure($db); SvAmazonReturnPolicySeeder::ensure($db);
        return ['status'=>'OK','schema_tables'=>count(SvAmazonReturnsSchema::statements()),'policy_seeds'=>count(SvAmazonReturnPolicySeeder::definitions())];
    }
    /** @return array<string,mixed> */
    public static function health(PDO $db, SvAmazonReturnsConfig $config): array {
        $tables=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'amazon_return_%'")->fetchColumn();
        $cases=(int)$db->query('SELECT COUNT(*) FROM amazon_return_cases')->fetchColumn();
        $outbox=(int)$db->query("SELECT COUNT(*) FROM amazon_return_outbox WHERE status IN ('PENDING','PROCESSING')")->fetchColumn();
        return ['status'=>'OK','tables'=>$tables,'cases'=>$cases,'pending_outbox'=>$outbox,'mode'=>$config->mode(),'enabled'=>$config->enabled(),'readiness'=>$config->readiness(),'write_flags'=>$config->writeFlags()];
    }
}
