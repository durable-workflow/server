<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;

final class GuardedPostgresConnector extends PostgresConnector
{
    use RefusesRustDatabase;
}
