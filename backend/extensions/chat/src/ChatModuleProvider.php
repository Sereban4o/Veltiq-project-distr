<?php

namespace App\Extensions\Chat;

use App\Extensions\ExtensionServiceProvider;

/**
 * Chat module: chats, messages, search, pins, push notifications, and the client
 * downloads page (it belongs to the chat module). Data models and broadcast
 * events stay in app/ because broadcasting and notifications reference them.
 */
class ChatModuleProvider extends ExtensionServiceProvider
{
}
