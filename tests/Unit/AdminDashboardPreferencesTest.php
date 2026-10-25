<?php

namespace Tests\Unit;

use App\Http\Controllers\API\AdminUiPreferencesController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AdminDashboardPreferencesTest extends TestCase
{
    public function test_default_layout_only_enables_quick_access_and_all_sections(): void
    {
        DB::shouldReceive('connection')->never();
        $request = Request::create('/api/admin/ui-preferences');
        $request->setUserResolver(fn () => (new User)->forceFill(['ui_preferences' => []]));
        $response = (new AdminUiPreferencesController)->show($request)->getData(true);
        $this->assertFalse($response['data']['admin_dashboard']['show_attention_section']);
        $this->assertSame(['overview'], $response['data']['admin_dashboard']['hidden_section_keys']);
    }

    public function test_section_visibility_is_saved_without_overwriting_other_preferences(): void
    {
        DB::shouldReceive('connection')->never();
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['ui_preferences' => [
            'debt_ledger' => ['taken_label' => 'قيمة مخصصة'],
            'admin_dashboard' => ['hidden_button_keys' => ['/StockScreen'], 'quick_access_count' => 5],
        ]]);
        $user->shouldReceive('save')->once()->andReturn(true);
        $request = Request::create('/api/admin/ui-preferences', 'PUT', [
            'admin_dashboard' => ['hidden_section_keys' => ['overview', 'quick_access'], 'show_attention_section' => false],
        ]);
        $request->setUserResolver(fn () => $user);
        $response = (new AdminUiPreferencesController)->update($request)->getData(true);
        $dashboard = $response['data']['admin_dashboard'];
        $this->assertSame(['overview', 'quick_access'], $dashboard['hidden_section_keys']);
        $this->assertSame(['/StockScreen'], $dashboard['hidden_button_keys']);
        $this->assertSame(5, $dashboard['quick_access_count']);
        $this->assertSame('قيمة مخصصة', $response['data']['debt_ledger']['taken_label']);
    }

    public function test_legacy_defaults_do_not_enable_attention_in_the_new_layout(): void
    {
        $request = Request::create('/api/admin/ui-preferences');
        $request->setUserResolver(fn () => (new User)->forceFill(['ui_preferences' => [
            'admin_dashboard' => ['show_attention_section' => true],
        ]]));
        $response = (new AdminUiPreferencesController)->show($request)->getData(true);
        $this->assertFalse($response['data']['admin_dashboard']['show_attention_section']);
    }
}
