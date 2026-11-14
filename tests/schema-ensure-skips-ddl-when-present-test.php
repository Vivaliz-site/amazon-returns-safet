<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Schema.php';

function sesAssert(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);} }

/** Fake PDO: reports which tables exist and records every DDL statement. */
final class SchemaFakePdo extends PDO
{
    /** @var list<string> */ public array $ddl=[];
    /** @var list<string> */ public array $queries=[];
    public function __construct(public int $existingTables,public bool $columnExists=true){}
    public function exec(string $statement): int|false { $this->ddl[]=$statement; return 0; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { $this->queries[]=$query; return new SchemaFakeStmt($this,$query); }
}
final class SchemaFakeStmt extends PDOStatement
{
    private mixed $value=0;
    public function __construct(private SchemaFakePdo $db,private string $query){}
    public function execute(?array $params=null): bool
    {
        $q=strtolower($this->query);
        if(str_contains($q,'information_schema.tables'))$this->value=$this->db->existingTables;
        elseif(str_contains($q,'information_schema.columns'))$this->value=$this->db->columnExists?1:0;
        else $this->value=0;
        return true;
    }
    public function fetchColumn(int $column=0): mixed { return $this->value; }
}

$total=count(SvAmazonReturnsSchema::statements());
sesAssert($total===16,'sanity: the schema declares 16 tables');

// Schema already complete: a request must not replay any CREATE TABLE.
$present=new SchemaFakePdo($total);
SvAmazonReturnsSchema::ensure($present);
sesAssert($present->ddl===[],'ensure() must not run DDL when every table already exists (got '.count($present->ddl).' statements).');
sesAssert(count($present->queries)<=2,'ensure() must cost at most one table-existence query plus the column check (got '.count($present->queries).').');

// One table missing: the full idempotent DDL still runs, so nothing is skipped by mistake.
$partial=new SchemaFakePdo($total-1);
SvAmazonReturnsSchema::ensure($partial);
sesAssert(count($partial->ddl)===$total,'ensure() must replay all CREATE TABLE IF NOT EXISTS when any table is missing.');

// Column migration still applies when the column is missing, even with every table present.
$noColumn=new SchemaFakePdo($total,false);
SvAmazonReturnsSchema::ensure($noColumn);
sesAssert(count($noColumn->ddl)===1&&str_contains($noColumn->ddl[0],'ADD COLUMN `reconciled_quantity_refunded`'),'ensure() must still add the missing column.');
echo "schema-ensure-skips-ddl-when-present-test OK\n";
