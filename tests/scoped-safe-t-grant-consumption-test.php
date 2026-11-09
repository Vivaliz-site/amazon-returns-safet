<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/ScopedSafeTGrantConsumption.php';

function checkOneUse(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('SCOPED_SAFE_T_ONEUSE_FAIL: '.$label);
}
$ddl=(string)file_get_contents(__DIR__.'/../deploy/sql/20261107-scoped-safe-t-grant-consumption.sql');
checkOneUse(str_contains($ddl, 'UNIQUE KEY `uq_scoped_grant_once`'), 'missing atomic UNIQUE index');
checkOneUse(str_contains($ddl, 'AUTO_INCREMENT'), 'no monotonic fencing candidate');
checkOneUse(str_contains($ddl, 'ENGINE=InnoDB'), 'transactional storage required');
$migration=(string)file_get_contents(__DIR__.'/../deploy/sql/20261109-scoped-safe-t-grant-3846-migration.sql');
checkOneUse(str_contains($migration, 'DROP CHECK `chk_scoped_grant_known`'), 'existing table migration missing');
checkOneUse(str_contains($migration, 'CHECK (`grant_number` IN (3556,3673,3846))'), 'migration must preserve both prior grants');
checkOneUse(!str_contains($migration, 'DELETE FROM') && !str_contains($migration, 'TRUNCATE'), 'ledger may not be erased');

checkOneUse(str_contains($ddl, "CHECK (`grant_number` IN (3556,3673,3846))"), 'wrong grants in DDL');
checkOneUse(str_contains($ddl, "`amazon_connection_id`=1"), 'scope missing');

checkOneUse(in_array('sqlite', PDO::getAvailableDrivers(), true),
    'sqlite PDO required for disposable contract test; never use production database');

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec(<<<'SQL'
CREATE TABLE amazon_return_scoped_grant_consumptions(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    amazon_connection_id INTEGER NOT NULL,
    grant_number INTEGER NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    quarantined_at TEXT NULL,
    UNIQUE(tenant_id,amazon_connection_id,grant_number),
    CHECK(grant_number IN (3556,3673,3846)),
    CHECK(tenant_id=1 AND amazon_connection_id=1)
)
SQL);
$ledger=new SvAmazonScopedSafeTGrantConsumption($db);

foreach ([9999,0,-1] as $bad) {
    try { $ledger->reserve($bad,1,1); throw new RuntimeException('bad grant accepted'); }
    catch (InvalidArgumentException) {}
}
foreach ([[3556,2,1],[3556,1,2]] as [$grant,$tenant,$connection]) {
    try { $ledger->reserve($grant,$tenant,$connection); throw new RuntimeException('bad tenant accepted'); }
    catch (InvalidArgumentException) {}
}

$fence3556=$ledger->reserve(3556,1,1);
checkOneUse(is_int($fence3556) && $fence3556>0,'first reservation not durable');
checkOneUse($ledger->reserve(3556,1,1)===null,'second reservation must never succeed');
checkOneUse($ledger->readStatus(3556,1,1)['status']==='RESERVED','first status missing');
checkOneUse(!$ledger->consume(3556,1,1,$fence3556+1),'wrong fencing consumed grant');
checkOneUse(!$ledger->consume(3673,1,1,$fence3556),'cross-grant fencing mismatch');
checkOneUse($ledger->consume(3556,1,1,$fence3556),'first CAS consume failed');
checkOneUse(!$ledger->consume(3556,1,1,$fence3556),'second consume incorrectly succeeded');
checkOneUse($ledger->reserve(3556,1,1)===null,'consumed grant replay accepted');
checkOneUse($ledger->readStatus(3556,1,1)['consumed']===true,'consume timestamp missing');

$fence3673=$ledger->reserve(3673,1,1);
checkOneUse(is_int($fence3673) && $fence3673>$fence3556,'fencing not monotonic');
checkOneUse(!$ledger->consume(3673,1,1,$fence3556),'stale fencing granted another scope');
checkOneUse($ledger->quarantine(3673,1,1,$fence3673),'quarantine failed');
checkOneUse(!$ledger->quarantine(3673,1,1,$fence3673),'quarantine unexpectedly repeated');
checkOneUse(!$ledger->consume(3673,1,1,$fence3673),'quarantined grant became consumable');
checkOneUse($ledger->reserve(3673,1,1)===null,'quarantined grant replay accepted');

// Approved renewal #3846 has a new one-use grant identity, not a replay of #3556.
$fence3846=$ledger->reserve(3846,1,1);
checkOneUse(is_int($fence3846) && $fence3846>$fence3673,'renewal missing independent fencing');
checkOneUse($ledger->reserve(3846,1,1)===null,'renewal reserved twice');
checkOneUse(!$ledger->consume(3556,1,1,$fence3846),'renewal fence crossed grant boundary');
checkOneUse($ledger->consume(3846,1,1,$fence3846),'renewal consume CAS failed');
checkOneUse(!$ledger->consume(3846,1,1,$fence3846),'renewal consumed twice');
checkOneUse($ledger->readStatus(3846,1,1)['consumed']===true,'renewal consumption missing');

unset($ledger);
$reloaded=new SvAmazonScopedSafeTGrantConsumption($db);
checkOneUse($reloaded->reserve(3556,1,1)===null,'restart bypassed prior consumption');
checkOneUse($reloaded->reserve(3673,1,1)===null,'restart bypassed quarantine');
checkOneUse($reloaded->readStatus(3673,1,1)['quarantined']===true,'quarantine state missing');
checkOneUse($reloaded->reserve(3846,1,1)===null,'renewal restart replay accepted');

echo "scoped-safe-t-grant-consumption-test: PASS (disposable SQLite only; MySQL migration staged, no production write)\n";
