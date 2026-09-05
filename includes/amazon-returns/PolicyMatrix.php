<?php
declare(strict_types=1);
require_once __DIR__.'/PolicySeeder.php';

/** One exact contract for code, shadow audit and live deployment validation. */
final class SvAmazonReturnPolicyMatrix
{
    public static function violations(array $rows): array
    {
        $expected=SvAmazonReturnPolicySeeder::definitions();$errors=[];$seen=[];
        foreach($rows as $row){
            if(!is_array($row) || ($row['status']??'')!=='ACTIVE')continue;
            $key=(string)($row['program']??'');$match=null;
            foreach($expected as $definition){if($definition['program']===$key){$match=$definition;break;}}
            if($match===null){$errors[]='UNEXPECTED_ACTIVE_PROGRAM:'.$key;continue;}
            if(isset($seen[$key]))$errors[]='DUPLICATE_ACTIVE_PROGRAM:'.$key;
            $seen[$key]=true;
            foreach($match as $field=>$value){
                $actual=$row[$field]??null;
                if($field==='eligibility_days')$actual=(int)$actual;
                if($actual!==$value)$errors[]=$key.':'.$field;
            }
        }
        foreach($expected as $definition){if(!isset($seen[$definition['program']]))$errors[]='MISSING_PROGRAM:'.$definition['program'];}
        return array_values(array_unique($errors));
    }

    public static function sql(int $tenantId): string
    {
        if($tenantId<1)throw new InvalidArgumentException('Positive tenant ID required.');
        $terms=[];
        foreach(SvAmazonReturnPolicySeeder::definitions() as $definition){
            $parts=[];
            foreach($definition as $field=>$value){
                if($value===null)$parts[]=$field.' IS NULL';
                elseif(is_int($value))$parts[]=$field.'='.$value;
                else $parts[]=$field."='".str_replace("'","''",$value)."'";
            }
            $terms[]='('.implode(' AND ',$parts).')';
        }
        return 'SELECT CASE WHEN COUNT(*)='.count($terms)
            .' AND COALESCE(SUM(CASE WHEN '.implode(' OR ',$terms)
            ." THEN 0 ELSE 1 END),0)=0 THEN 0 ELSE 1 END FROM amazon_return_policies WHERE tenant_id="
            .$tenantId." AND status='ACTIVE'";
    }
}
