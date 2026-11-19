<?php

namespace App\Enums;

enum WorkspacePermission: string
{
    case ViewUsers = 'users.view';
    case ViewProducts = 'products.view';
    case ManageProducts = 'products.manage';
    case ManageStock = 'stock.manage';
    case ManageUsers = 'users.manage';
}
