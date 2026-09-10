<?php
declare(strict_types=1);

final class SvAmazonCockpitHistory
{
    /** @return array{total_events:int,visible_items:int,grouped_events:int,items:list<array<string,mixed>>} */
    public static function summarize(array $timeline):array
    {
        $result=[];$groups=[];
        foreach($timeline as $item){
            if(!is_array($item))continue;
            if(!self::repeatable($item)){
                $item['repeat_count']=1;$item['first_at']=$item['occurred_at']??null;$item['last_at']=$item['occurred_at']??null;
                $result[]=$item;continue;
            }
            $key=self::groupKey($item);
            if(isset($groups[$key])){
                $index=$groups[$key];$result[$index]['repeat_count']++;
                $at=(string)($item['occurred_at']??'');
                if($at>(string)($result[$index]['last_at']??'')){$result[$index]['last_at']=$at;$result[$index]['occurred_at']=$at;}
                continue;
            }
            $item['repeat_count']=1;$item['first_at']=$item['occurred_at']??null;$item['last_at']=$item['occurred_at']??null;
            $groups[$key]=count($result);$result[]=$item;
        }
        usort($result,static fn(array $a,array $b):int=>strcmp((string)($a['occurred_at']??''),(string)($b['occurred_at']??'')));
        $total=count($timeline);$visible=count($result);
        return ['total_events'=>$total,'visible_items'=>$visible,'grouped_events'=>max(0,$total-$visible),'items'=>array_values($result)];
    }

    private static function repeatable(array $item):bool
    {
        $category=strtoupper(trim((string)($item['category']??'')));
        return in_array($category,['OBSERVATION','FINANCIAL','RULE'],true);
    }

    private static function groupKey(array $item):string
    {
        $day=substr((string)($item['occurred_at']??''),0,10);
        $title=mb_strtolower(trim((string)($item['title']??$item['category']??'')),'UTF-8');
        $source=strtoupper(trim((string)($item['source']??'')));
        $status=strtoupper(trim((string)($item['status']??'')));
        return implode('|',[$day,$title,$source,$status]);
    }
}
