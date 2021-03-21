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
        $fromClause = $this->generateFromClause($tableName, $alias);
        $joinClause = $this->generateJoinClauses($joinTables);


        return [];
    }

    public function count(FoxDbConnection $foxDbConnection, string $entityName, Predicate ...$predicates): int
    {
        // TODO: Implement generateCountQuery() method.
    }

    public function insert(FoxDbConnection $foxDbConnection, string $entityName, Predicate ...$predicates): array
    {
        // TODO: Implement generateInsertQuery() method.
    }

    public function update(FoxDbConnection $foxDbConnection, string $entityName, Predicate ...$predicates): array
    {
        // TODO: Implement generateUpdateQuery() method.
    }

    public function delete(FoxDbConnection $foxDbConnection, string $entityName, Predicate ...$predicates): array
    {
        // TODO: Implement generateDeleteQuery() method.
    }

    private function generateSelectClause(array $columns): string
    {
        $select = 'SELECT ';
        foreach ($columns as $entityColumns) {
            $select .= join(',', $entityColumns);
        }

        return $select;
    }

    private function generateFromClause(string $tableName, $alias): string
    {
        return "FROM `$tableName` AS `$alias`";
    }

    private function generateJoinClauses(array $joinTables): array
    {
        $ret = [];
        foreach ($joinTables as $joinTable) {
            $join = '';
            [$tableName, $alias, $joinOn, $nullable, $parentColumn] = $joinTable;
            if ($nullable) {
                $join = 'LEFT ';
            }
            $join .= "JOIN `$tableName` AS `$alias` ON (`$alias`.`$joinOn` = $parentColumn)";
            $ret[] = $join;
        }

        return $ret;
    }

}