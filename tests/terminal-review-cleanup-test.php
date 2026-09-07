<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';

final class TerminalReviewFakeReviews {
    public array $resolved=[];
    public function openQueue(int $limit=200): array {
        return [
            ['case_id'=>491,'status'=>'OPEN'],
            ['case_id'=>501,'status'=>'OPEN'],
            ['case_id'=>999,'status'=>'OPEN'],
        ];
    }
    public function resolveOpenForCase(int $caseId): void {$this->resolved[]=$caseId;}
}
final class TerminalReviewFakeCases {
    public function find(int $caseId): ?array {
        return match($caseId){
            491=>['id'=>491,'state'=>'RECOVERED','closed_at'=>'2026-09-07 17:00:00'],
            501=>['id'=>501,'state'=>'POLICY_REVIEW_REQUIRED','closed_at'=>null],
            999=>['id'=>999,'state'=>'CLOSED_LOSS','closed_at'=>'2026-09-07 17:05:00'],
            default=>null,
        };
    }
}
$reviews=new TerminalReviewFakeReviews();
$persistence=(object)['reviews'=>$reviews,'cases'=>new TerminalReviewFakeCases()];
new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$persistence);
sort($reviews->resolved);
$expected=[491,999];
if($reviews->resolved!==$expected){
    fwrite(STDERR,'Terminal/closed reviews must be resolved automatically. expected='.json_encode($expected).' actual='.json_encode($reviews->resolved)."\n");
    exit(1);
}
echo "terminal-review-cleanup-test: OK\n";
