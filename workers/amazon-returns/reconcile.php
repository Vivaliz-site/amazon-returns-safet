<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-returns/FinancialReconciler.php';

final class SvAmazonReturnsReconcileWorker
{
    public function __construct(private ?SvAmazonFinancialReconciler $reconciler = null) { $this->reconciler ??= new SvAmazonFinancialReconciler(); }
    public function reconcileCase(array $case, array $transactions): array { return $this->reconciler->reconcile($case, $transactions); }
}
