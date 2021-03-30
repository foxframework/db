<?php
/*
 * MIT License
 *
 * Copyright (c) 2021 Petr Ploner <petr@ploner.cz>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *
 */

namespace Fox\DB\Helpers\DbEngines;


use Fox\DB\Attribute\Column;
use Fox\DB\Attribute\Lazy;
use Fox\DB\Attribute\OneToMany;
use Fox\DB\Attribute\OneToOne;
use Fox\DB\Attribute\PrimaryKey;
use Fox\DB\Attribute\Table;
use Fox\DB\Helpers\FoxEntity;
use Fox\DB\Helpers\IncorrectMappingException;
use Fox\DB\Helpers\StringUtils;
use JetBrains\PhpStorm\ArrayShape;
use ReflectionClass;

abstract class Engine implements DbEngine
{
    protected const ONE_TO_ONE = 0;
    protected const ONE_TO_MANY = 2;

    protected function createReflection(string $entityName): array
    {
        $class = new ReflectionClass($entityName);
        $tableAttributes = $class->getAttributes(Table::class);
        if (empty($tableAttributes)) {
            throw new IncorrectMappingException("Missing required attribute Table in $entityName");
        }

        /** @var  $table Table */
        $table = $tableAttributes[0]->newInstance();
        return [$table->name, $class];
    }

    protected function getColumns(ReflectionClass $reflectionClass): array
    {
        $res = [];
        foreach ($reflectionClass->getProperties() as $property) {
            $columnAttributes = $property->getAttributes(Column::class);
            if (empty($columnAttributes)) {
                continue;
            }

            $oneToOne = $property->getAttributes(OneToOne::class);
            $oneToMany = $property->getAttributes(OneToMany::class);

            if (!empty($oneToOne) || !empty($oneToMany)) {
                continue;
            }

            /** @var Column $column */
            $column = $columnAttributes[0]->newInstance();
            $res[$property->getName()] = $column->name ?? StringUtils::toSnakeCase($property->getName());
        }
        return $res;
    }

    protected function getPrimaryKey(ReflectionClass $reflectionClass): string
    {
        foreach ($reflectionClass->getProperties() as $property) {
            $columnAttributes = $property->getAttributes(PrimaryKey::class);
            if (empty($columnAttributes)) {
                continue;
            }

            return $property->getName();
        }
        throw new IncorrectMappingException("Missing primary key for $reflectionClass->name");
    }

    #[ArrayShape([self::ONE_TO_ONE => "array", self::ONE_TO_MANY => "array"])]
    protected function getEagerJoins(ReflectionClass $reflectionClass): array
    {
        $res = [
            self::ONE_TO_ONE => [],
            self::ONE_TO_MANY => [],
        ];

        foreach ($reflectionClass->getProperties() as $property) {
            $lazy = $property->getAttributes(Lazy::class);
            $oneToOne = $property->getAttributes(OneToOne::class);
            $oneToMany = $property->getAttributes(OneToMany::class);
            if (!empty($lazy) || (empty($oneToOne) && empty($oneToMany))) {
                continue;
            }
            /** @var OneToOne $oneToOne */
            $oneToOne = $oneToOne[0]?->newInstance();
            if ($oneToOne instanceof OneToOne) {
                $res[self::ONE_TO_ONE][$property->getName()] = [$oneToOne->column, $oneToOne->cascadeDelete, $property->getType()->allowsNull()];
                continue;
            }

            /** @var OneToMany $oneToMany */
            $oneToMany = $oneToMany[0]?->newInstance();
            if ($oneToMany instanceof OneToOne) {
                $res[self::ONE_TO_MANY][$property->getName()] = [$oneToMany->entityName, $oneToMany->column, $oneToMany->cascadeDelete, $property->getType()->allowsNull()];
                continue;
            }
        }

        return $res;
    }

    protected function createSelectFromEntity(string $entityName, int $index = 0, ?string $joinType = null): array
    {
        /** @var ReflectionClass $reflectionClass */
        [$tableName, $reflectionClass] = $this->createReflection($entityName);
        $alias = substr($tableName, 0, 1) . $index;
        $columns[$entityName] = $this->getColumnsWithAliases($alias, $this->getColumns($reflectionClass));
        $eagerJoins = $this->getEagerJoins($reflectionClass);
        $joinTables = [];
        $primaryKey = $this->getPrimaryKey($reflectionClass);
        foreach ($eagerJoins[Engine::ONE_TO_ONE] as $property => $joinColumnDefinition) {
            [$joinColumn, $cascade, $nullable] = $joinColumnDefinition;
            $entityType = $reflectionClass->getProperty($property)->getType()->getName();
            if ($entityType === $joinType) {
                continue;
            }
            [[$joinTableName, $joinAlias], $joinColumns, $nestedJoinTables] = $this->createSelectFromEntity($entityType, $index + 1, $entityName);
            $joinTables = array_merge([[$joinTableName, $joinAlias, $joinColumn, $nullable, $columns[$entityName][$primaryKey], $entityType]], $nestedJoinTables);
            $columns = array_merge($columns, $joinColumns);
        }

        return [[$tableName, $alias], $columns, $joinTables, $eagerJoins[Engine::ONE_TO_MANY]];
    }

    protected function getColumnsWithAliases(string $alias, array $columns)
    {
        return array_map(function ($v) use ($alias) {
            return "`$alias`.`$v` as `$alias$v`";
        }, $columns);
    }

    protected function getRealAliases(array $columns)
    {
        return array_map(function ($v) {
            return explode('as ', $v)[1];
        }, $columns);
    }

    public static function hydratePdoResultToEntity(string $className, array $pdoResult, array $columnMapping): FoxEntity
    {
        $mappedArray =[];


    }
}
