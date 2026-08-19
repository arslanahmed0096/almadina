<?php

namespace Tests\Unit;

use App\Http\Controllers\TransferController;
use ReflectionMethod;
use Tests\TestCase;

class TransferControllerCompatibilityTest extends TestCase
{
    public function test_workflow_dispatch_endpoint_does_not_override_laravel_controller_dispatch(): void
    {
        $this->assertTrue(class_exists(TransferController::class));

        $workflowMethod = new ReflectionMethod(TransferController::class, 'dispatchTransfer');
        $frameworkMethod = new ReflectionMethod(TransferController::class, 'dispatch');

        $this->assertSame(TransferController::class, $workflowMethod->getDeclaringClass()->getName());
        $this->assertNotSame(TransferController::class, $frameworkMethod->getDeclaringClass()->getName());
    }
}
