<?php

namespace Tests\Feature\OnlineStore;

use App\Models\Store\StoreUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StoreSessionSecurityTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshDisposableDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();
            return;
        }
        $this->refreshDisposableDatabase();
    }

    public function test_account_check_requires_a_token_and_never_discloses_another_account(): void
    {
        $owner = OnlineStoreFixtureFactory::createStoreActor(['fcm_token' => 'private-device-token']);
        $other = OnlineStoreFixtureFactory::createStoreActor();
        $token = StoreUser::findOrFail($owner->id)->createToken('owner')->plainTextToken;
        $this->postJson('/Auth/CheckUser?UserId='.$owner->id)->assertUnauthorized();
        $this->withToken($token)->postJson('/Auth/CheckUser?UserId='.$other->id)->assertNotFound();
        $this->withToken($token)->postJson('/Auth/CheckUser')->assertOk()
            ->assertJsonPath('id', (string) $owner->id)->assertJsonPath('userToken', '');
    }

    public function test_expired_and_blocked_sessions_are_rejected(): void
    {
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $user = StoreUser::findOrFail($actor->id);
        $expired = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->postJson('/Auth/CheckUser')->assertUnauthorized();
        $active = $user->createToken('active')->plainTextToken;
        $user->forceFill(['is_blocked' => true])->save();
        $this->withToken($active)->postJson('/Auth/CheckUser')->assertUnauthorized();
    }

    public function test_logout_revokes_only_this_session_and_matching_device(): void
    {
        $actor = OnlineStoreFixtureFactory::createStoreActor(['fcm_token' => 'this-device']);
        $user = StoreUser::findOrFail($actor->id);
        $first = $user->createToken('first')->plainTextToken;
        $second = $user->createToken('second')->plainTextToken;
        $this->withToken($first)->postJson('/Auth/Logout', ['fcm_token' => 'different-device'])->assertNoContent();
        $this->assertSame('this-device', $user->fresh()->fcm_token);
        $this->withToken($first)->postJson('/Auth/CheckUser')->assertUnauthorized();
        $this->withToken($second)->postJson('/Auth/CheckUser')->assertOk();
        $this->withToken($second)->postJson('/Auth/Logout', ['fcm_token' => 'this-device'])->assertNoContent();
        $this->assertNull($user->fresh()->fcm_token);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_new_passwords_are_validated_on_the_server(): void
    {
        $this->postJson('/Users/Register', [
            'email' => 'short@example.test', 'phoneNumber' => '000000000',
            'password' => 'short', 'confirmPassword' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $token = StoreUser::findOrFail($actor->id)->createToken('owner')->plainTextToken;
        $this->withToken($token)->postJson('/Auth/ChangePassword', [
            'oldPassword' => 'password', 'newPassword' => 'short', 'confirmPassword' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('newPassword');
    }
}
