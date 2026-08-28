<?php

namespace Tests\Unit;

use App\Models\SuspendedInstantSale;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\SalesDailySessionService;
use App\Services\SuspendedInstantSaleService;
use Mockery;
use Tests\TestCase;

class SuspendedInstantSaleServiceTest extends TestCase
{
    private function service(): SuspendedInstantSaleService
    {
        return new SuspendedInstantSaleService(
            Mockery::mock(SalesDailySessionService::class),
            Mockery::mock(AdminNotificationService::class)
        );
    }

    private function user(int $id, string $type = 'admin'): User
    {
        $user = new User;
        $user->forceFill(['id' => $id, 'type' => $type]);

        return $user;
    }

    private function suspended(int $ownerId, string $saveType): SuspendedInstantSale
    {
        $sale = new SuspendedInstantSale;
        $sale->forceFill([
            'created_by_user_id' => $ownerId,
            'save_type' => $saveType,
            'status' => SuspendedInstantSale::STATUS_SUSPENDED,
        ]);

        return $sale;
    }

    public function test_auto_saved_draft_is_visible_to_its_owner(): void
    {
        $this->assertTrue($this->service()->canView(
            $this->user(7),
            $this->suspended(7, SuspendedInstantSale::SAVE_TYPE_AUTO)
        ));
    }

    public function test_auto_saved_draft_is_hidden_from_other_admins(): void
    {
        $this->assertFalse($this->service()->canView(
            $this->user(9),
            $this->suspended(7, SuspendedInstantSale::SAVE_TYPE_AUTO)
        ));
    }

    public function test_manual_suspended_invoice_remains_visible_to_admins(): void
    {
        $this->assertTrue($this->service()->canView(
            $this->user(9),
            $this->suspended(7, SuspendedInstantSale::SAVE_TYPE_MANUAL)
        ));
    }
}
