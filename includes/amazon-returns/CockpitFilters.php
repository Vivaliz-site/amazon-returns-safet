<?php
declare(strict_types=1);

final class SvAmazonCockpitFilters
{
    private const ALLOWED=[
        'q','state','action','review_status','program','physical_status','deadline',
        'learned_rule','min_outstanding','max_outstanding','page','per_page',
    ];
    private function __construct(
        private array $filters,
        private int $page,
        private int $perPage,
        private ?string $action
    ){}

    public static function fromQuery(array $query):self
    {
        foreach(array_keys($query) as $key){
            if(!is_string($key) || !in_array($key,self::ALLOWED,true)){
                throw new InvalidArgumentException('Unsupported cockpit filter: '.(string)$key);
            }
        }
        $page=max(1,self::integer($query['page']??1,1,100000));
        $perPage=self::integer($query['per_page']??50,25,100);
        $filters=[];
        foreach(['q','state','program','physical_status','review_status'] as $field){
            if(!array_key_exists($field,$query))continue;
            $value=self::text($query[$field],$field,96);
            if(in_array($field,['state','program','physical_status','review_status'],true))$value=strtoupper($value);
            $filters[$field]=$value;
        }
        $action=null;
        if(array_key_exists('action',$query))$action=strtoupper(self::text($query['action'],'action',64));
        if(array_key_exists('deadline',$query)){
            $value=strtolower(self::text($query['deadline'],'deadline',16));
            if(!in_array($value,['overdue','today','7d'],true))throw new InvalidArgumentException('Invalid deadline filter.');
            $filters['deadline']=$value;
        }
        if(array_key_exists('learned_rule',$query)){
            $value=strtolower(self::text($query['learned_rule'],'learned_rule',16));
            if(!in_array($value,['applied','none','conflict'],true))throw new InvalidArgumentException('Invalid learned_rule filter.');
            $filters['learned_rule']=$value;
        }
        foreach(['min_outstanding','max_outstanding'] as $field){
            if(!array_key_exists($field,$query))continue;
            $raw=filter_var($query[$field],FILTER_VALIDATE_FLOAT);
            if($raw===false || $raw<0 || $raw>100000000)throw new InvalidArgumentException('Invalid amount filter.');
            $filters[$field]=(string)$raw;
        }
        if(isset($filters['min_outstanding'],$filters['max_outstanding']) && (float)$filters['min_outstanding']>(float)$filters['max_outstanding']){
            throw new InvalidArgumentException('Outstanding amount range is inverted.');
        }
        return new self($filters,$page,$perPage,$action);
    }
    public function sqlFilters():array{return $this->filters;}
    public function filters():array{return $this->filters+($this->action!==null?['action'=>$this->action]:[]);}
    public function requiresDecisionFilter():bool{return $this->action!==null;}
    public function action():?string{return $this->action;}
    public function perPage():int{return $this->perPage;}
    public function page():int{return $this->page;}

    private static function text(mixed $value,string $label,int $max):string
    {
        if(!is_scalar($value))throw new InvalidArgumentException('Invalid '.$label.' filter.');
        $value=trim((string)$value);
        if($value==='' || strlen($value)>$max || str_contains($value,"\0"))throw new InvalidArgumentException('Invalid '.$label.' filter.');
        return $value;
    }

    private static function integer(mixed $value,int $min,int $max):int
    {
        $parsed=filter_var($value,FILTER_VALIDATE_INT);
        if($parsed===false)$parsed=$min;
        return max($min,min($max,(int)$parsed));
    }
}
