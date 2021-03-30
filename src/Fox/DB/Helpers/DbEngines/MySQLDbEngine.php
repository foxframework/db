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

use Fox\DB\Helpers\Predicate;
use Fox\DB\Sources\Services\FoxDbConnection;
use PDO;
use PDOStatement;
use function Webmozart\Assert\Tests\StaticAnalysis\true;

class MySQLDbEngine extends Engine implements DbEngine
{
    public function handles(): string
    {
        return 'mysql';
    }

    public function select(FoxDbConnection $foxDbConnection, string $entityName, int $limit, int $offset, Predicate ...$predicates): ?array
    {
        [[$tableName, $alias], $columns, $joinTables, $eagerJoinManyToOne] = $this->createSelectFromEntity($entityName);
        $selectClause = $this->generateSelectClause($columns);
        $stmt = $this->doSelect($tableName, $alias, $joinTables, $foxDbConnection, $columns, $predicates, $selectClause);
        $res = $stmt->fetchAll();
        foreach ($columns as $entity => &$aliases){
            $aliases = $this->getRealAliases($aliases);
        }

        return [];
    }

    public function count(FoxDbConnection $foxDbConnection, string $entityName, Predicate ...$predicates): int
    {
        [[$tableName, $alias], $columns, $joinTables, $eagerJoinManyToOne] = $this->createSelectFromEntity($entityName);
        $selectClause = 'SELECT COUNT(*)';
        $stmt = $this->doSelect($tableName, $alias, $joinTables, $foxDbConnection, $columns, $predicates, $selectClause);
        return $stmt->fetch(PDO::FETCH_COLUMN);
    }

    private function generateSelectClause(array $columns): string
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

    private function generateFromClause(string $tableName, $alias): string
    {
        return "FROM `$tableName` AS `$alias`";
    }

    private function generateJoinClauses(array $joinTables, FoxDbConnection $foxDbConnection): array
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

    private function generateWhereClause(array $columns, array $predicates): array
    {
        $clause = 'WHERE ';
        $bindings = [];
        $pIndex = 0;
        $predCount = count($predicates) - 1;
        $i = 0;
        $andNeeded = false;

        foreach ($predicates as $orPredicate) {
            $clause .= '(';
            foreach ($orPredicate->getPredicates() as $andPredicate) {
                [$className, $variable, $operation, $value] = $andPredicate;

                if ($andNeeded) {
                    $clause .= ' AND ';
                }

                $andNeeded = true;

                if (in_array($operation, [Predicate::IN, Predicate::NOT_IN])) {
                    $bindPar = '(';
                    $firstRun = true;
                    foreach ($value as $item) {
                        $bindings[":p$pIndex"] = [$item, is_string($value) ? PDO::PARAM_STR : PDO::PARAM_INT];
                        if (!$firstRun) {
                            $bindPar .= ',';
                        }
                        $bindPar .= ":p$pIndex";
                        $pIndex++;
                        $firstRun = false;
                    }
                    $bindPar .= ')';
                } else {
                    $bindPar = ":p$pIndex";
                    $bindings[$bindPar] = [$value, is_string($value) ? PDO::PARAM_STR : PDO::PARAM_INT];
                    $pIndex++;
                }
                $clause .= explode(' as', $columns[$className][$variable])[0] . " $operation $bindPar";
            }

            $clause .= ')';

            if ($predCount !== $i) {
                $clause .= ' OR ';
                $andNeeded = false;
            }
            $i++;
        }
        return [$clause, $bindings];
    }

    private function getPdoStatement(string $query, array $bindings, FoxDbConnection $connection): PDOStatement
    {
        $stmt = $connection->getPdoConnection()->prepare($query);
        foreach ($bindings as $parameter => [$value, $type]) {
            $stmt->bindParam($parameter, $value, $type);
        }
        return $stmt;
    }

    public function doSelect($tableName,
                             $alias,
                             mixed $joinTables,
                             FoxDbConnection $foxDbConnection,
                             mixed $columns,
                             array $predicates,
                             string $selectClause): PDOStatement
    {
        $fromClause = $this->generateFromClause($tableName, $alias);
        $joinClause = $this->generateJoinClauses($joinTables, $foxDbConnection);
        [$whereClause, $bindings] = $this->generateWhereClause($columns, $predicates);
        $join = implode(' ', $joinClause);
        $query = "$selectClause $fromClause $join $whereClause";
        $stmt = $this->getPdoStatement($query, $bindings, $foxDbConnection);
        $stmt->execute();
        return $stmt;
    }

}
