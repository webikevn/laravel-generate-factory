<?php

namespace Webike\Factory\Commands;

use Webike\Factory\Connections\DatabaseContract;
use Webike\Factory\Connections\MySql;
use Webike\Factory\Connections\Sqlite;
use Webike\Factory\Exceptions\UnknownConnectionException;
use Illuminate\Config\Repository;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Class GenerateFactory
 *
 * @package Webike\Factory\Commands
 */
class GenerateFactory extends GeneratorCommand
{

    /**
     * @var string
     */
    protected $signature = 'generate:factory {name?} {--all} {connection?}';

    /**
     * @var \Illuminate\Config\Repository
     */
    protected $config;

    /**
     * @var DatabaseContract
     */
    protected $database;

    /**
     * GenerateFactory constructor.
     *
     * @param \Illuminate\Filesystem\Filesystem $files
     * @param \Illuminate\Config\Repository $config
     */
    public function __construct(Filesystem $files, Repository $config)
    {
        parent::__construct($files);
        $this->config = $config;
    }

    /**
     * @return bool|null|void
     * @throws \Webike\Factory\Exceptions\UnknownConnectionException
     */
    public function handle()
    {
        $connection = $this->argument('connection');
        /** @var Connection $conn */
        $conn = \DB::connection($connection);
        /** @var \PDO $pdo */
        $pdo = \DB::connection($connection)->getPdo();

        if ($conn instanceof MySqlConnection) {
            $this->database = new MySql($pdo);
        } elseif ($conn instanceof SQLiteConnection) {
            $this->database = new Sqlite($pdo);
        } else {
            throw new UnknownConnectionException('Unknown connection is set.');
        }

        if ($this->hasOption('all') && $this->option('all')) {
            $tables = $this->database->fetchTables();
            foreach ($tables as $tableRecord) {
                $this->runFactoryBuilder($tableRecord[0]);
            }
        } else {
            $table = $this->getNameInput();
            $this->runFactoryBuilder($table);
        }
    }

    /**
     * @param $table
     * @return bool
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function runFactoryBuilder($table)
    {
        $model = Str::ucfirst(Str::camel($table));
        $factory = Str::singular($model) . 'Factory';

        $path = $this->getPath($factory);

        if ($this->alreadyExists($path)) {
            $this->error($factory . ' already exists!');
            return false;
        }

        $columns = $this->database->fetchColumns($table);

        $factoryContent = $this->buildFactory(
            $this->config->get('factory-generator.namespace.model'),
            $model,
            $columns
        );

        $this->createFile($path, $factoryContent);

        $this->info($factory . ' created successfully.');
    }

    /**
     * @param string $name
     * @return string
     */
    protected function getPath($name)
    {
        return base_path() . '/database/factories/' . $name . '.php';
    }

    /**
     * @param string $path
     * @return bool
     */
    protected function alreadyExists($path)
    {
        return !!$this->files->exists($path);
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__ . '/stubs/factory.stub';
    }

    /**
     * @param $namespace
     * @param $name
     * @param $columns
     * @return mixed|string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function buildFactory($namespace, $name, $columns)
    {
        $stub = $this->files->get($this->getStub());

        $stub = $this->replaceNamespace($stub, $namespace)->replaceClass($stub, Str::singular($name));

        $stub = $this->replaceColumns($stub, $columns);

        return $stub;
    }

    /**
     * @param $path
     * @param $content
     */
    protected function createFile($path, $content)
    {
        $this->files->put($path, $content);
    }

    /**
     * @param string $stub
     * @param string $name
     * @return $this
     */
    protected function replaceNamespace(&$stub, $name)
    {
        $stub = str_replace('DummyModelNamespace', $name, $stub);

        return $this;
    }

    /**
     * @param $stub
     * @param $columns
     * @return mixed
     */
    protected function replaceColumns($stub, $columns)
    {
        $content = '';

        $ignoredColumns = $this->config->get('factory-generator.ignored_columns', []);

        foreach ($columns as $column) {
            if (in_array($column['field'], $ignoredColumns)) {
                continue;
            }

            // indent spaces
            $content .= "        ";
            $content .= "'{$column['field']}' => {$this->buildValueFactory($column['type'])},";
            $content .= "\n";
        }

        if (Str::length($content) > 0) {
            // Remove newline character of the last line.
            $content = rtrim($content, "\n");
        }

        return str_replace('DummyColumns', $content, $stub);
    }

    /**
     * @param $type
     * @return string
     */
    private function buildValueFactory($type)
    {
        $type = explode(' ', $type);
        $type = explode('(', $type[0]);
        switch ($type[0]) {
            case 'char':
            case 'text':
            case 'varchar':
                return '$faker->word';
            case 'int':
            case 'bigint':
            case 'tinyint':
                return '$faker->numberBetween(0, 10)';
            case 'datetime':
                return '\Illuminate\Support\Carbon::now()';
                break;
        }
    }
}
