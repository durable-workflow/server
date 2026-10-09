<?php

namespace App\Database;

use Illuminate\Database\SQLiteConnection;

final class GuardedSqliteConnection extends SQLiteConnection
{
    public function getSchemaBuilder()
    {
        // SQLite db:wipe can truncate the configured file without issuing SQL.
        // Check ownership before handing out that capability, without opening
        // a writable connection or changing journal mode before truncation.
        (new GuardedSqliteConnector)->assertOwnership($this->getConfig());

        return parent::getSchemaBuilder();
    }
}
