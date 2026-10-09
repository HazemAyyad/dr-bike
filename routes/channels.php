<?php

use App\Models\SupportConversation;
use App\Services\Support\SupportAccessService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('support.conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = SupportConversation::query()->find($conversationId);

    return $conversation
        && app(SupportAccessService::class)->canAccess($user, $conversation);
});

Broadcast::channel('support.inbox', function ($user) {
    return app(SupportAccessService::class)->canManageInbox($user);
});
