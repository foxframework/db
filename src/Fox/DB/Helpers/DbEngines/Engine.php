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


use Fox\Core\Helpers\RequestBody;
use Fox\DB\Attribute\Column;
use Fox\DB\Attribute\Lazy;
use Fox\DB\Attribute\OneToMany;
use Fox\DB\Attribute\OneToOne;
use Fox\DB\Attribute\PrimaryKey;
use Fox\DB\Attribute\Table;
use Fox\DB\Helpers\FoxEntity;
use Fox\DB\Helpers\IncorrectMappingException;
use Fox\DB\Helpers\Predicate;
use Fox\DB\Helpers\StringUtils;
use Fox\DB\Sources\Services\FoxDbConnection;
use JetBrains\PhpStorm\ArrayShape;
use PDOStatement;
use ReflectionClass;

abstract class Engine implements DbEngine
{
    protected const ONE_TO_ONE = 0;
    protected const ONE_TO_MANY = 2;

    public function select(FoxDbConnection $foxDbConnection, string $entityName, ?int $limit, ?int $offset, ?string $limitedJoin, array $predicates): ?array
    {
        [[$tableName, $alias], $columns, $joinTables, $eagerJoinManyToOne, $parent] = $this->createSelectFromEntity($entityName, joinedEntity: $limitedJoin);
        $selectClause = $this->generateSelectClause($columns);
        $stmt = $this->doSelect($tableName, $alias, $joinTables, $foxDbConnection, $columns, $predicates, $selectClause, $limit, $offset);
        if ($stmt instanceof PDOStatement) {
            $res = $stmt->fetchAll();
            foreach ($columns as $entity => &$aliases) {
                $aliases = $this->getRealAliases($aliases);
            }

            foreach ($res as &$row) {
                [$body, $fullMap] = $this->prepareBody($row, $columns, $entityName, $joinTables);
                /**
                 * @var FoxEntity $row
                 */
                $row = RequestBody::instanceDAOFromBody($entityName, $body);
                $this->fillParentFromResult($row, $parent);
                $row->setAsNotVirgin();
                foreach ($eagerJoinManyToOne as $propertyName => $joinData) {
                    [$className, $primaryKey, $joinEntity, $joinColumn] = $joinData;
                    $predicate = (new Predicate())->add($joinEntity, $joinColumn, $fullMap[$className][$primaryKey], exactPredicate: true);
                    $joinResult = $this->select($foxDbConnection, $joinEntity, null, null, $className, [$predicate]);
                    $parentProperty = null;
                    $parentEntity = null;
                    foreach ($joinResult as $result) {
                        if (empty($parentProperty)) {
                            $parentProperty = $this->getParentFromJoinResult($result, $className);
                            $parentEntity = $this->getParentEntity($row, $className);
                        }
                        $reflection = new ReflectionClass($result);
                        $property = $reflection->getProperty($parentProperty);
                        if (!$property->isPublic()) {
                            $property->setAccessible(true);
                        }

                        $property->setValue($result, $parentEntity);
                    }
                    $parentReflection = new ReflectionClass($parentEntity);
                    $parentReflectionProperty = $parentReflection->getProperty($propertyName);
                    if ($parentReflectionProperty->isPublic()) {
                        $parentReflectionProperty->setAccessible(true);
                    }

                    $parentReflectionProperty->setValue($parentEntity, $joinResult);
                }
            }
            return $res;
        }

        return null;
    }

    private function getParentEntity(FoxEntity $row, string $parentClass): ?FoxEntity
    {
        $reflectionEntity = new ReflectionClass($row);
        foreach ($reflectionEntity->getProperties() as $property) {
            if ($property->getType()->getName() === $parentClass) {
                if (!$property->isPublic()) {
                    $property->setAccessible(true);
                }
                return $property->getValue($row);
            }

            if (str_contains($property->getType()->getName(), 'Entity')) {
                if (!$property->isPublic()) {
                    $property->setAccessible(true);
                }
                $parentEntity = $this->getParentEntity($property->getValue($row), $parentClass);
                if ($parentEntity instanceof $parentClass) {
                    return $parentEntity;
                }
            }
        }
        return null;
    }

    private function getParentFromJoinResult(FoxEntity $row, string $parentClass): string
    {
        $reflection = new ReflectionClass($row);
        foreach ($reflection->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->getType()->getName() === $parentClass) {
                return $reflectionProperty->getName();
            }
        }

        throw new IncorrectMappingException("Unknown property for parent $parentClass");
    }

    private function fillParentFromResult(FoxEntity $row, array &$parent, ?FoxEntity $parentEntity = null): void
    {
        $reflectionEntity = new ReflectionClass($row);
        foreach ($reflectionEntity->getProperties() as $property) {
            foreach ($parent as $entityName => $parentData) {
                foreach ($parentData as $propertyName => $propertyType) {
                    if ($property->getType()->getName() === $entityName) {
                        if (!$property->isPublic()) {
                            $property->setAccessible(true);
                        }
                        $this->fillParentFromResult($property->getValue($row), $parent, $row);
                    }
                    if ($row instanceof $entityName && $property->getName() === $propertyName && $parentEntity instanceof $propertyType) {
                        if (!$property->isPublic()) {
                            $property->setAccessible(true);
                        }
                        $property->setValue($row, $parentEntity);
                        unset($parent[$entityName][$propertyName]);
                    }
                }
            }
        }
    }

    protected function createSelectFromEntity(string $entityName, int $index = 0, ?string $joinType = null, ?string $joinedEntity = null): array
    {
        /** @var ReflectionClass $reflectionClass */
        [$tableName, $reflectionClass] = $this->createReflection($entityName);
        $alias = substr($tableName, 0, 1) . $index;
        $columns[$entityName] = $this->getColumnsWithAliases($alias, $this->getColumns($reflectionClass));
        $primaryKey = self::getPrimaryKey($reflectionClass);
        $eagerJoins = $this->getEagerJoins($reflectionClass, $primaryKey);
        $joinTables = [];
        $parent = [];
        foreach ($eagerJoins[Engine::ONE_TO_ONE] as $property => $joinColumnDefinition) {
            [$joinColumn, $cascade, $nullable] = $joinColumnDefinition;
            $entityType = $reflectionClass->getProperty($property)->getType()->getName();
            if ($entityType === $joinType || $entityType === $joinedEntity) {
                $parent[$entityName][$property] = $entityType;
                continue;
            }
            [[$joinTableName, $joinAlias], $joinColumns, $nestedJoinTables, $nestedEagerJoinsManyToOne, $nestedParent] = $this->createSelectFromEntity($entityType, $index + 1, $entityName);
            $joinTables = array_merge([[$joinTableName, $joinAlias, $joinColumn, $nullable, $columns[$entityName][$primaryKey], $entityType]], $nestedJoinTables, $joinTables);
            $columns = array_merge($columns, $joinColumns);
            $eagerJoins[Engine::ONE_TO_MANY] = array_merge($eagerJoins[Engine::ONE_TO_MANY], $nestedEagerJoinsManyToOne);
            $parent = array_merge($parent, $nestedParent);
            $index++;
        }

        return [[$tableName, $alias], $columns, $joinTables, $eagerJoins[Engine::ONE_TO_MANY], $parent];
    }

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

    protected function getColumnsWithAliases(string $alias, array $columns)
    {
        return array_map(function ($v) use ($alias) {
            return "`$alias`.`$v` as `$alias$v`";
        }, $columns);
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

    #[ArrayShape([self::ONE_TO_ONE => "array", self::ONE_TO_MANY => "array"])]
    protected function getEagerJoins(ReflectionClass $reflectionClass, string $primaryKey): array
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
            if (!empty($oneToOne)) {
                $oneToOne = $oneToOne[0]?->newInstance();
                if ($oneToOne instanceof OneToOne) {
                    $res[self::ONE_TO_ONE][$property->getName()] = [$oneToOne->column, $oneToOne->cascadeDelete, $property->getType()->allowsNull()];
                    continue;
                }
            }

            if (!empty($oneToMany)) {
                /** @var OneToMany $oneToMany */
                $oneToMany = $oneToMany[0]?->newInstance();
                if ($oneToMany instanceof OneToMany) {
                    $res[self::ONE_TO_MANY][$property->getName()] = [$reflectionClass->getName(), $primaryKey, $oneToMany->entityName, $oneToMany->column, $oneToMany->cascadeDelete, $property->getType()->allowsNull()];
                    continue;
                }
            }
        }

        return $res;
    }

    public static function getPrimaryKey(ReflectionClass $reflectionClass): string
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

    protected function generateSelectClause(array $columns): string
    {
        $select = 'SELECT ';
        $firstRun = true;
        foreach ($columns as $entityColumns) {
            if (!$firstRun) {
                $select .= ', ';
            }
            $select .= join(',', $entityColumns);
            $firstRun = false;
        }

        return $select;
    }

    public function doSelect($tableName,
                             $alias,
                             mixed $joinTables,
                             FoxDbConnection $foxDbConnection,
                             mixed $columns,
                             array $predicates,
                             string $selectClause,
                             ?int $limit,
                             ?int $offset): bool|PDOStatement
    {
        $fromClause = $this->generateFromClause($tableName, $alias);
        $joinClause = $this->generateJoinClauses($joinTables, $foxDbConnection);
        $limitClause = $this->generateLimitClause($limit, $offset);
        [$whereClause, $questionMarks] = $this->generateWhereClause($columns, $predicates, $alias);
        $join = implode(' ', $joinClause);
        $query = "$selectClause $fromClause $join $whereClause $limitClause";
        $stmt = $foxDbConnection->getPdoConnection()->prepare($query);
        $stmt->execute($questionMarks);
        return $stmt;
    }

    protected function generateLimitClause(?int $limit, ?int $offset): string
    {
        $limitClause = '';
        if ($limit !== null) {
            $limitClause = "LIMIT $limit";
        }

        if ($offset !== null) {
            $limitClause .= " OFFSET $offset";
        }

        return $limitClause;
    }

    protected function generateFromClause(string $tableName, $alias): string
    {
        return "FROM `$tableName` AS `$alias`";
    }

    protected function generateJoinClauses(array $joinTables, FoxDbConnection $foxDbConnection): array
    {
        $ret = [];
        foreach ($joinTables as $joinTable) {
            $join = '';
            [$tableName, $alias, $joinOn, $nullable, $parentColumn, $entityType] = $joinTable;
            if ($nullable) {
                $join = 'LEFT ';
            }
            $parentColumn = explode(' as', $parentColumn)[0];
            $join .= "JOIN `$tableName` AS `$alias` ON (`$alias`.`$joinOn` = $parentColumn)";
            $ret[] = $join;
        }

        return $ret;
    }

    protected function generateWhereClause(array $columns, array $predicates, string $alias): array
    {
        $clause = '';
        $questionMarks = [];
        $pIndex = 0;
        $predCount = count($predicates) - 1;
        $i = 0;
        $andNeeded = false;

        foreach ($predicates as $orPredicate) {
            if ($i == 0) {
                $clause = 'WHERE ';
            }
            $clause .= '(';
            foreach ($orPredicate->getPredicates() as $andPredicate) {
                [$className, $variable, $operation, $value, $exactPredicate] = $andPredicate;

                if ($andNeeded) {
                    $clause .= ' AND ';
                }

                $andNeeded = true;

                if (in_array($operation, [Predicate::IN, Predicate::NOT_IN])) {
                    $bindPar = '(';
                    $firstRun = true;
                    foreach ($value as $item) {
                        $questionMarks[] = $item;
                        if (!$firstRun) {
                            $bindPar .= ',';
                        }
                        $bindPar .= "?";
                        $pIndex++;
                        $firstRun = false;
                    }
                    $bindPar .= ')';
                } else {
                    $bindPar = '?';
                    $questionMarks[] = $value;
                    $pIndex++;
                }

                if ($exactPredicate) {
                    $clause .= "`$alias`.`$variable` $operation $bindPar";
                } else {
                    $clause .= explode(' as', $columns[$className][$variable])[0] . " $operation $bindPar";
                }
            }

            $clause .= ')';

            if ($predCount !== $i) {
                $clause .= ' OR ';
                $andNeeded = false;
            }
            $i++;
        }
        return [$clause, $questionMarks];
    }

    protected function getRealAliases(array $columns)
    {
        return array_map(function ($v) {
            return explode('as ', $v)[1];
        }, $columns);
    }

    protected function prepareBody(array $row, array $columns, string $mainEntityName, array $joinTables): array
    {
        $mappedResult = [];
        foreach ($columns as $entityName => $mapping) {
            foreach ($mapping as $realName => $alias) {
                $alias = trim($alias, '`');
                $mappedResult[$entityName][$realName] = $row[$alias];
            }
        }

        foreach ($columns as $entityName => $mapping) {
            $reflection = new ReflectionClass($entityName);
            $properties = $reflection->getProperties();
            $i = 0;
            foreach ($joinTables as $joinTable) {
                $entityType = $joinTable[5];
                foreach ($properties as $property) {
                    $type = $property->getType()->getName();
                    if ($type === $entityType) {
                        $mappedResult[$entityName][$property->getName()] = &$mappedResult[$type];
                        unset($joinTables[$i]);
                    }
                }
                $i++;
            }
        }

        return [$mappedResult[$mainEntityName], $mappedResult];
    }

    public function count(FoxDbConnection $foxDbConnection, string $entityName, array $predicates): int
    {
        [[$tableName, $alias], $columns, $joinTables, $eagerJoinManyToOne] = $this->createSelectFromEntity($entityName);
        $selectClause = 'SELECT COUNT(*)';
        $stmt = $this->doSelect($tableName, $alias, $joinTables, $foxDbConnection, $columns, $predicates, $selectClause, 1, 0);
        return $stmt->fetch(PDO::FETCH_COLUMN);
    }
}
