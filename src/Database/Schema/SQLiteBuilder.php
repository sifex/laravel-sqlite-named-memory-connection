<?php

declare(strict_types=1);


namespace Crhg\SQLiteNamedMemoryConnection\Database\Schema;

use Illuminate\Support\Str;

class SQLiteBuilder extends \Illuminate\Database\Schema\SQLiteBuilder
{
    public function dropAllTables()
    {
        if (Str::startsWith($this->connection->getDatabaseName(), ':named-memory:')) {
            // Laravel 12+ uses pragma() method instead of grammar compile methods
            if (method_exists($this, 'pragma')) {
                // Laravel 12+ approach
                $this->pragma('writable_schema', 1);

                // Check if compileDropAllTables accepts a schema parameter (Laravel 12+)
                $reflection = new \ReflectionMethod($this->grammar, 'compileDropAllTables');
                if ($reflection->getNumberOfParameters() > 0) {
                    $this->connection->statement($this->grammar->compileDropAllTables('main'));
                } else {
                    $this->connection->statement($this->grammar->compileDropAllTables());
                }

                $this->pragma('writable_schema', 0);

                // Check if compileRebuild accepts a schema parameter (Laravel 12+)
                $reflection = new \ReflectionMethod($this->grammar, 'compileRebuild');
                if ($reflection->getNumberOfParameters() > 0) {
                    $this->connection->statement($this->grammar->compileRebuild('main'));
                } else {
                    $this->connection->statement($this->grammar->compileRebuild());
                }
            } else {
                // Laravel 5-11 approach using grammar methods
                $this->connection->select($this->grammar->compileEnableWriteableSchema());
                $this->connection->select($this->grammar->compileDropAllTables());
                $this->connection->select($this->grammar->compileDisableWriteableSchema());
                $this->connection->select($this->grammar->compileRebuild());
            }
            return;
        }

        parent::dropAllTables();
    }
}