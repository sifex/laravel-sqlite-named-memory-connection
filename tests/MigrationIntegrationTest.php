<?php

namespace Crhg\SQLiteNamedMemoryConnection\Tests;

use Crhg\SQLiteNamedMemoryConnection\Providers\SQLiteNamedMemoryConnectionServiceProvider;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class MigrationIntegrationTest extends TestCase
{
    protected $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new Capsule instance for database operations
        $this->capsule = new Capsule;

        // Register the custom connector before adding connection
        $connector = new \Crhg\SQLiteNamedMemoryConnection\Database\Connectors\SQLiteConnector();
        $this->capsule->getDatabaseManager()->extend('sqlite-named', function ($config, $name) use ($connector) {
            $config['name'] = $name;
            $connection = $connector->connect($config);

            return new \Crhg\SQLiteNamedMemoryConnection\Database\SQLiteConnection(
                $connection,
                $config['database'],
                $config['prefix'] ?? '',
                $config
            );
        });

        // Add connection using the custom driver
        $this->capsule->addConnection([
            'driver'    => 'sqlite-named',
            'database'  => ':named-memory:test-db',
            'prefix'    => '',
        ], 'default');

        // Make this Capsule instance available globally
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    protected function tearDown(): void
    {
        if ($this->capsule->getConnection()->getSchemaBuilder()->hasTable('users')) {
            $this->capsule->schema()->drop('users');
        }

        parent::tearDown();
    }

    public function testCanRunMigration()
    {
        $schema = $this->capsule->schema();

        // Create a test table (simulating a migration)
        $schema->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        // Verify table was created
        $this->assertTrue($schema->hasTable('users'));
        $this->assertTrue($schema->hasColumn('users', 'name'));
        $this->assertTrue($schema->hasColumn('users', 'email'));
    }

    public function testCanInsertAndQueryData()
    {
        $schema = $this->capsule->schema();

        // Create table
        $schema->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
        });

        // Insert data
        $this->capsule->table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Query data
        $user = $this->capsule->table('users')->where('email', 'john@example.com')->first();

        $this->assertNotNull($user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testNamedMemoryConnectionPersistsAcrossConnections()
    {
        $schema = $this->capsule->schema();

        // Create table and insert data
        $schema->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        $this->capsule->table('users')->insert(['name' => 'Test User']);

        // Get a new connection to the same named memory database
        $this->capsule->addConnection([
            'driver'    => 'sqlite-named',
            'database'  => ':named-memory:test-db',
            'prefix'    => '',
        ], 'second');

        // Verify data persists in the named memory database
        $count = $this->capsule->connection('second')->table('users')->count();
        $this->assertEquals(1, $count);

        $user = $this->capsule->connection('second')->table('users')->first();
        $this->assertEquals('Test User', $user->name);
    }

    public function testDifferentNamedDatabasesAreIsolated()
    {
        $schema = $this->capsule->schema();

        // Create table in first database
        $schema->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        $this->capsule->table('users')->insert(['name' => 'User 1']);

        // Create a connection to a different named memory database
        $this->capsule->addConnection([
            'driver'    => 'sqlite-named',
            'database'  => ':named-memory:different-db',
            'prefix'    => '',
        ], 'different');

        // This database should not have the users table
        $hasTables = $this->capsule->connection('different')->getSchemaBuilder()->hasTable('users');
        $this->assertFalse($hasTables);
    }

    public function testDropAllTablesCallsGrammarMethods()
    {
        $schema = $this->capsule->schema();

        // Create multiple tables to test drop all functionality
        $schema->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        $schema->create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });

        // Verify tables exist
        $this->assertTrue($schema->hasTable('users'));
        $this->assertTrue($schema->hasTable('posts'));

        // This will call dropAllTables() which internally calls:
        // - $this->grammar->compileEnableWriteableSchema()
        // - $this->grammar->compileDropAllTables()
        // - $this->grammar->compileDisableWriteableSchema()
        // - $this->grammar->compileRebuild()
        // These methods were removed in Laravel 12, so this test will fail
        $schema->dropAllTables();

        // Verify all tables were dropped
        $this->assertFalse($schema->hasTable('users'));
        $this->assertFalse($schema->hasTable('posts'));
    }
}
