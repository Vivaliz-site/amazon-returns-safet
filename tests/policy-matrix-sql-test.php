<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/PolicyMatrix.php';
$rows=SvAmazonReturnPolicySeeder::definitions();
$fixtures=['valid'=>$rows,'empty'=>[],'duplicate_missing'=>[$rows[0],$rows[0],$rows[1]],'missing'=>array_slice($rows,0,2)];
$bad=$rows;$bad[1]['eligibility_days']=45;$fixtures['wrong_days']=$bad;
$bad=$rows;$bad[1]['basis']='SELLER_DEBIT_AT';$fixtures['wrong_basis']=$bad;
$data=['sql'=>SvAmazonReturnPolicyMatrix::sql(1),'fixtures'=>$fixtures];
$code=<<<'PY'
import json, sqlite3, sys
j=json.load(sys.stdin)
for label, rows in j['fixtures'].items():
    con=sqlite3.connect(':memory:')
    con.execute('CREATE TABLE amazon_return_policies (tenant_id INTEGER, policy_key TEXT,marketplace_id TEXT,program TEXT,effective_from TEXT,effective_to TEXT,eligibility_days INTEGER,basis TEXT,source_url TEXT,source_hash TEXT,status TEXT)')
    for tenant, values in [(1,rows),(2,j['fixtures']['valid'])]:
        for row in values:
            con.execute('INSERT INTO amazon_return_policies VALUES (?,?,?,?,?,?,?,?,?,?,?)',[tenant]+[row[x] for x in ['policy_key','marketplace_id','program','effective_from','effective_to','eligibility_days','basis','source_url','source_hash','status']])
    actual=con.execute(j['sql']).fetchone()[0]
    expected=0 if label=='valid' else 1
    if actual!=expected: print(label, 'expected',expected,'actual',actual); sys.exit(1)
print('actual SQL matrix fixtures: OK')
PY;
$proc=proc_open(['python3','-c',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc))throw new RuntimeException('Could not run isolated SQL test.');
fwrite($pipes[0],json_encode($data,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($proc);
if($status!==0)throw new RuntimeException('SQL validator regression: '.$out.$err);echo "policy-matrix-sql-test: OK\n";
