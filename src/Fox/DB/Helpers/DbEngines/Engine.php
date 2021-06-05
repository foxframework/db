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
use Fox\DB\Attribute\AutoIncrement;
use Fox\DB\Attribute\Column;
use Fox\DB\Attribute\Lazy;
use Fox\DB\Attribute\OneToMany;
use Fox\DB\Attribute\OneToOne;
use Fox\DB\Attribute\PrimaryKey;
use Fox\DB\Attribute\Table;
use Fox\DB\Helpers\DbValueException;
use Fox\DB\Helpers\FoxEntity;
use Fox\DB\Helpers\IncorrectMappingException;
use Fox\DB\Helpers\Predicate;
use Fox\DB\Helpers\StringUtils;
use Fox\DB\Sources\Services\FoxDbConnection;
use JetBrains\PhpStorm\ArrayShape;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionMethod;
use function Webmozart\Assert\Tests\StaticAnalysis\contains;
use function Webmozart\Assert\Tests\StaticAnalysis\true;

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

                    if (!empty($parentEntity)) {
                        $parentReflection = new ReflectionClass($parentEntity);
                        $parentReflectionProperty = $parentReflection->getProperty($propertyName);
                        if ($parentReflectionProperty->isPublic()) {
                            $parentReflectionProperty->setAccessible(true);
                        }

                        $parentReflectionProperty->setValue($parentEntity, $joinResult);
                    }
                }
            }
            return $res;
        }

        return null;
    }

    protected function createSelectFromEntity(string $entityName, int $index = 0, ?string $joinType = null, ?string $joinedEntity = null): array
    {
        /** @var ReflectionClass $reflectionClass */
        [$tableName, $reflectionClass] = $this->createReflection($entityName);
        $alias = substr($tableName, 0, 1) . $index;
        $columns[$entityName] = $this->getColumnsWithAliases($alias, $this->getColumns($reflectionClass));
        $primaryKey = self::getPrimaryKey($reflectionClass)[0];
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

    protected function getColumns(ReflectionClass $reflectionClass, bool $includeFK = false): array
    {
        $res = [];
        foreach ($reflectionClass->getProperties() as $property) {
            $columnAttributes = $property->getAttributes(Column::class);
            if (empty($columnAttributes)) {
                continue;
            }

            $oneToOne = $property->getAttributes(OneToOne::class);
            $oneToMany = $property->getAttributes(OneToMany::class);

            if (!$includeFK && (!empty($oneToOne) || !empty($oneToMany))) {
                continue;
            }

            /** @var Column $column */
            $column = $columnAttributes[0]->newInstance();
            $res[$property->getName()] = $column->name ?? StringUtils::toSnakeCase($property->getName());
        }
        return $res;
    }

    protected function getChildren(ReflectionClass $reflectionClass): array
    {
        $res = [];
        foreach ($reflectionClass->getProperties() as $property) {
            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
                continue;
            }

            $oneToOne = $property->getAttributes(OneToOne::class);
            $oneToMany = $property->getAttributes(OneToMany::class);

            if (!empty($oneToOne) || !empty($oneToMany)) {
                $res[] = $property->getName();
            }
        }
        return $res;
    }

    public static function getPrimaryKey(ReflectionClass $reflectionClass): array
    {
        foreach ($reflectionClass->getProperties() as $property) {
            $primaryKeyAttribute = $property->getAttributes(PrimaryKey::class);
            if (empty($primaryKeyAttribute)) {
                continue;
            }
            $autoIncrementAttributes = $property->getAttributes(AutoIncrement::class);

            return [$property->getName(), !empty($autoIncrementAttributes)];
        }
        throw new IncorrectMappingException("Missing primary key for $reflectionClass->name");
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

    protected function generateInsertClause(string $tableName, array $columns): string
    {
        $questionMarks = [];
        $columns = array_map(function ($v) use (&$questionMarks) {
            $questionMarks[] = '?';
            return "`$v`";
        }, $columns);

        $columnsString = implode(',', $columns);
        $qmString = implode(',', $questionMarks);
        return "INSERT INTO `$tableName` ($columnsString) VALUES ($qmString)";
    }

    protected function generateDeleteClause(string $tableName, string $primaryKey)
    {
        return "DELETE FROM `$tableName` WHERE `$primaryKey` = ?";
    }

    protected function getQuestionMarks(ReflectionClass $reflectionClass, FoxEntity $entity, array $columns): array
    {
        $questionMarks = [];
        $getters = $this->getGetters($reflectionClass);

        foreach ($columns as $var => $column) {
            $lowerVar = strtolower($var);
            if (array_key_exists($lowerVar, $getters)) {
                $val = call_user_func([$entity, $getters[$lowerVar]]);
            } else if ($reflectionClass->getProperty($var)->isPublic()) {
                $val = $entity->{$var};
            } else {
                $clName = $entity::class;
                throw new IncorrectMappingException("Entity $clName has not public access or getter to variable $var");
            }

            if ($val instanceof FoxEntity) {
                $rfPk = new ReflectionClass($val);
                [$pk, $ai] = self::getPrimaryKey($rfPk);
                $pkProp = $rfPk->getProperty($pk);
                $pkProp->setAccessible(true);
                $val = $pkProp->getValue($val);
                if (empty($val)) {
                    $cls = $entity::class;
                    throw new DbValueException("Can not get parent id for $cls. Property $var -> $pkProp");
                }
            }

            $questionMarks[$var] = $val;
        }

        return $questionMarks;
    }

    public function count(FoxDbConnection $foxDbConnection, string $entityName, array $predicates): int
    {
        [[$tableName, $alias], $columns, $joinTables, $eagerJoinManyToOne] = $this->createSelectFromEntity($entityName);
        $selectClause = 'SELECT COUNT(*)';
        $stmt = $this->doSelect($tableName, $alias, $joinTables, $foxDbConnection, $columns, $predicates, $selectClause, null, null);
        return $stmt->fetch(PDO::FETCH_COLUMN);
    }

    public function insert(FoxDbConnection $foxDbConnection, FoxEntity $entity, bool $parent = true): void
    {
        /** @var ReflectionClass $reflectionClass */
        [$tableName, $reflectionClass] = $this->createReflection($entity::class);
        $columns = $this->getColumns($reflectionClass, true);
        [$primaryKey, $autoIncrement] = self::getPrimaryKey($reflectionClass);
        if ($autoIncrement) {
            unset($columns[$primaryKey]);
        }
        $insertClause = $this->generateInsertClause($tableName, $columns);
        if ($parent) {
            $foxDbConnection->getPdoConnection()->beginTransaction();
        }
        $stmt = $foxDbConnection->getPdoConnection()->prepare($insertClause);
        $questionMarks = $this->getQuestionMarks($reflectionClass, $entity, $columns);
        $stmt->execute(array_values($questionMarks));
        $id = $autoIncrement ? $foxDbConnection->getPdoConnection()->lastInsertId() : $questionMarks[$primaryKey];
        $propertyPk = $reflectionClass->getProperty($primaryKey);
        $propertyPk->setAccessible(true);
        $propertyPk->setValue($entity, $id);
        $children = $this->getChildren($reflectionClass);
        $getters = $this->getGetters($reflectionClass);
        foreach ($children as $child) {
            $lowerVar = strtolower($child);
            if (array_key_exists($lowerVar, $getters)) {
                $foxEntity = call_user_func([$entity, $getters[$lowerVar]]);
            } else if ($reflectionClass->getProperty($child)->isPublic()) {
                $foxEntity = $entity->{$child};
            } else {
                $cls = $entity::class;
                throw new IncorrectMappingException("Class $cls has not publicly accessible getter for field $child");
            }

            if (is_array($foxEntity)) {
                foreach ($foxEntity as $item) {
                    $this->insert($foxDbConnection, $item, false);
                }
                continue;
            }

            if ($foxEntity instanceof FoxEntity) {
                $this->insert($foxDbConnection, $foxEntity, false);
            }
        }

        $entity->setAsNotVirgin();
        if ($parent) {
            $foxDbConnection->getPdoConnection()->commit();
        }
    }

    protected function generateUpdateClause(string $tableName, array $updatedColumns, string $primaryKeyColumn): string
    {
        $updateClause = "UPDATE `$tableName` SET ";
        $first = true;
        foreach ($updatedColumns as $column) {
            if (!$first) {
                $updateClause .= ',';
            } else {
                $first = false;
            }
            $updateClause .= "`$column` = ?";
        }

        $updateClause .= " WHERE `$primaryKeyColumn` = ?";

        return $updateClause;
    }


    public function update(FoxDbConnection $foxDbConnection, FoxEntity $entity, bool $parent = true)
    {
        /** @var ReflectionClass $reflectionClass */
        [$tableName, $reflectionClass] = $this->createReflection($entity::class);
        [$primaryKey, $autoIncrement] = self::getPrimaryKey($reflectionClass);
        $children = $this->getChildren($reflectionClass);
        if ($parent) {
            $foxDbConnection->getPdoConnection()->beginTransaction();
        }
        foreach ($children as $child) {
            $childProperty = $reflectionClass->getProperty($child);
            $childProperty->setAccessible(true);
            if (!$childProperty->isInitialized($entity)) { // Lazy loaded fields
                continue;
            }
            $val = $childProperty->getValue($entity);
            if ($val instanceof FoxEntity) {
                $this->update($foxDbConnection, $val, false);
            } else if (is_array($val)) {
                foreach ($val as $childVal) {
                    $this->update($foxDbConnection, $childVal, false);
                }
            }
        }

        $doUpdate = !$entity->canUseDiff() || ($entity->canUseDiff() && !empty($entity->getChangedFields()));
        if ($doUpdate) {
            $columns = $this->getColumns($reflectionClass);
            $pkColumn = $columns[$primaryKey];
            unset($columns[$primaryKey]); // Prevent unwanted breaking changes

            if ($entity->canUseDiff()) {
                $changedFields = $entity->getChangedFields();
                $columns = array_filter($columns, function ($propertyName) use ($changedFields) {
                    return in_array($propertyName, $changedFields);
                }, ARRAY_FILTER_USE_KEY);

            }

            $updateClause = $this->generateUpdateClause($tableName, $columns, $pkColumn);
            $propertyPk = $reflectionClass->getProperty($primaryKey);
            $propertyPk->setAccessible(true);
            $questionMarks = $this->getQuestionMarks($reflectionClass, $entity, $columns);
            $questionMarks[] = $propertyPk->getValue($entity);

            $stmt = $foxDbConnection->getPdoConnection()->prepare($updateClause);
            $stmt->execute(array_values($questionMarks));
        }

        if ($parent) {
            $foxDbConnection->getPdoConnection()->commit();
        }
    }

    public function delete(FoxDbConnection $foxDbConnection, FoxEntity $entity)
    {
        /** @var ReflectionClass $reflectionClass */
        [$tableName, $reflectionClass] = $this->createReflection($entity::class);
        [$primaryKey, $autoIncrement] = self::getPrimaryKey($reflectionClass);
        $columns = $this->getColumns($reflectionClass);
        $deleteClause = $this->generateDeleteClause($tableName, $columns[$primaryKey]);
        $propertyPk = $reflectionClass->getProperty($primaryKey);
        $propertyPk->setAccessible(true);
        $foxDbConnection->getPdoConnection()->beginTransaction();
        $stmt = $foxDbConnection->getPdoConnection()->prepare($deleteClause);
        $stmt->execute([$propertyPk->getValue($entity)]);
        $foxDbConnection->getPdoConnection()->commit();
        unset($entity);
    }


    protected function getGetters(ReflectionClass $reflectionClass): array
    {
        $getters = [];
        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if (str_starts_with($reflectionMethod->getName(), 'get')) {
                $lowerVar = strtolower(str_replace('get', '', $reflectionMethod->getName()));
                $getters[$lowerVar] = $reflectionMethod->getName();
            }
        }
        return $getters;
    }


}
