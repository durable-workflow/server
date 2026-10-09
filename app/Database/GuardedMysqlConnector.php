<?php

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;

final class GuardedMysqlConnector extends MySqlConnector
{
    use RefusesRustDatabase;
}
