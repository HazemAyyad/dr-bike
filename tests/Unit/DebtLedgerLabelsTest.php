<?php

namespace Tests\Unit;

use App\Http\Controllers\API\AdminUiPreferencesController;
use App\Models\User;
use App\Support\DebtLedgerLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DebtLedgerLabelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::shouldReceive('connection')->never();
    }

    public function test_legacy_words_are_inherited_without_losing_customization(): void
    {
        $labels = ['taken_label' => 'استلمت', 'by_type' => [
            'sellers' => ['taken_label' => 'اشتريت', 'given_label' => '  '],
        ]];
        $this->assertSame('استلمت', DebtLedgerLabels::forScope($labels, 'customers')['taken_label']);
        $this->assertSame('اشتريت', DebtLedgerLabels::forScope($labels, 'sellers')['taken_label']);
        $this->assertSame('أعطيت', DebtLedgerLabels::forScope($labels, 'sellers')['given_label']);
    }

    public function test_saving_one_type_preserves_other_types_and_dashboard(): void
    {
        $preferences = [
            'admin_dashboard' => ['quick_access_count' => 5],
            'debt_ledger' => ['taken_label' => 'عام', 'by_type' => [
                'customers' => ['taken_label' => 'قبضت'],
                'private' => ['given_label' => 'خاص'],
            ]],
        ];
        $response = $this->updatePreferences($preferences, ['by_type' => [
            'sellers' => ['taken_label' => 'اشتريت', 'given_label' => 'دفعت'],
        ]]);
        $this->assertSame('success', $response['status']);
        $this->assertSame(5, $response['data']['admin_dashboard']['quick_access_count']);
        $labels = $response['data']['debt_ledger'];
        $this->assertSame('عام', $labels['taken_label']);
        $this->assertSame('قبضت', $labels['by_type']['customers']['taken_label']);
        $this->assertSame('خاص', $labels['by_type']['private']['given_label']);
        $this->assertSame('دفعت', $labels['by_type']['sellers']['given_label']);
    }

    public function test_removing_override_follows_subsequent_general_changes(): void
    {
        $preferences = ['debt_ledger' => ['taken_label' => 'عام', 'by_type' => [
            'sellers' => ['taken_label' => 'اشتريت'],
            'customers' => ['taken_label' => 'قبضت'],
        ]]];
        $response = $this->updatePreferences($preferences, [
            'taken_label' => 'عام جديد', 'by_type' => ['sellers' => null],
        ]);
        $labels = $response['data']['debt_ledger'];
        $this->assertArrayNotHasKey('sellers', $labels['by_type']);
        $this->assertSame('عام جديد', DebtLedgerLabels::forScope($labels, 'sellers')['taken_label']);
        $this->assertSame('قبضت', $labels['by_type']['customers']['taken_label']);
    }

    private function updatePreferences(array $preferences, array $labels): array
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['ui_preferences' => $preferences]);
        $user->shouldReceive('save')->once()->andReturn(true);
        $request = Request::create('/api/admin/ui-preferences', 'PUT', ['debt_ledger' => $labels]);
        $request->setUserResolver(fn () => $user);

        return (new AdminUiPreferencesController)->update($request)->getData(true);
    }
}
