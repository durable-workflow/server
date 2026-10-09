<?php

namespace App\Database;

use Illuminate\Database\Connectors\MariaDbConnector;

final class GuardedMariaDbConnector extends MariaDbConnector
{
    use RefusesRustDatabase;
}
