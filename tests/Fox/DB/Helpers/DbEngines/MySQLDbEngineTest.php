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

use Fox\DB\Helpers\IncorrectMappingException;
use Fox\DB\Helpers\Predicate;
use Fox\DB\Sources\Services\FoxDbConnection;
use Fox\DB\Sources\Services\Stubs\SomeEntity;
use Fox\DB\Sources\Services\Stubs\TestingEntity;
use Fox\DB\Sources\Services\Stubs\TestingJoinedEntity;
use Fox\DB\Sources\Services\Stubs\TestingPDO;
use Fox\DB\Sources\Services\Stubs\TestingSecondJoinedEntity;
use PHPUnit\Framework\TestCase;

require 'Stubs/SomeEntity.php';
require 'Stubs/TestingEntity.php';
require 'Stubs/TestingJoinedEntity.php';
require 'Stubs/TestingSecondJoinedEntity.php';
require 'Stubs/TestingPDO.php';

class MySQLDbEngineTest extends TestCase
{
    private FoxDbConnection $foxDbConnection;
    private TestingPDO $testingPDO;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foxDbConnection = $this->createMock(FoxDbConnection::class);
        $this->testingPDO = new TestingPDO('sqlite::memory:');
        $this->testingPDO->query('
            CREATE TABLE `testing` (
                id INTEGER  PRIMARY KEY AUTOINCREMENT,
                first_column TEXT NOT NULL,
                custom_second_column TEXT NOT NULL
                )
        ');
        $this->testingPDO->query('
            CREATE TABLE `testing_joined` (
                id INTEGER  PRIMARY KEY AUTOINCREMENT,
                some_column TEXT,
                testing_entity_id INTEGER,
                FOREIGN KEY(testing_entity_id) REFERENCES testing(id)
                )
        ');
        $this->testingPDO->query('
            CREATE TABLE `testing_joined_second` (
                id INTEGER  PRIMARY KEY AUTOINCREMENT,
                some_column TEXT,
                testing_joined_entity_id INTEGER,
                FOREIGN KEY(testing_joined_entity_id) REFERENCES testing_joined(id)
                )
        ');
        $this->foxDbConnection->method("getPdoConnection")->willReturn($this->testingPDO);
    }


    public function testCreateSelectQueryFailIncorrectTable(): void
    {
        $engine = new MySQLDbEngine();
        $this->expectException(IncorrectMappingException::class);
        $engine->select($this->foxDbConnection, SomeEntity::class, 1, 0);
    }

    public function testCreateSelectQuery(): void
    {
        $engine = new MySQLDbEngine();
        $predicate1 = (new Predicate())
            ->add(TestingEntity::class, 'firstColumn', 'test')
            ->add(TestingJoinedEntity::class, 'someColumn', ['1abcd', '2def'], Predicate::NOT_IN);
        $predicate2 = (new Predicate())
            ->add(TestingSecondJoinedEntity::class, 'someColumn', 'test1234');
        $engine->select($this->foxDbConnection, TestingEntity::class, 1, 0, $predicate1, $predicate2);
        $this->assertEquals(1, count($this->testingPDO->queries));
        $this->assertEquals(
            'SELECT `t0`.`id`,`t0`.`first_column`,`t0`.`custom_second_column`, `t1`.`id`,`t1`.`some_column`, `t2`.`id`,`t2`.`some_column` FROM `testing` AS `t0` JOIN `testing_joined` AS `t1` ON (`t1`.`testing_entity_id` = `t0`.`id`) LEFT JOIN `testing_joined_second` AS `t2` ON (`t2`.`testing_joined_entity_id` = `t1`.`id`) WHERE `t0`.`first_column` = :p0 AND `t1`.`some_column` NOT IN (:p1,:p2) AND `t2`.`some_column` = :p3', $this->testingPDO->queries[0][0]);
    }

}
