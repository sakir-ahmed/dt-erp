<?php

namespace App\Enums;

enum WorkspacePermission: string
{
    case ManageConverseChannels = 'converse.channels.manage';
    case ManageConverseKnowledge = 'converse.knowledge.manage';
    case ManageConverseInbox = 'converse.inbox.manage';
    case ViewConverseInbox = 'converse.inbox.view';
    case ViewUsers = 'users.view';
    case ManageUsers = 'users.manage';
    case ManageSettings = 'settings.manage';
}
