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

use Fox\Core\DI\FoxContainer;
use Fox\Core\Helpers\Globals;
use Fox\DB\Helpers\IncorrectMappingException;
use Fox\DB\Helpers\Predicate;
use Fox\DB\Sources\Services\FoxDbConnection;
use Fox\DB\Sources\Services\Stubs\FakeContainer;
use Fox\DB\Sources\Services\Stubs\SomeEntity;
use Fox\DB\Sources\Services\Stubs\TestingEntity;
use Fox\DB\Sources\Services\Stubs\TestingJoinedEntity;
use Fox\DB\Sources\Services\Stubs\TestingLazyJoinedEntity;
use Fox\DB\Sources\Services\Stubs\TestingPDO;
use Fox\DB\Sources\Services\Stubs\TestingSecondJoinedEntity;
use PHPUnit\Framework\TestCase;

require 'Stubs/SomeEntity.php';
require 'Stubs/TestingEntity.php';
require 'Stubs/TestingJoinedEntity.php';
require 'Stubs/TestingSecondJoinedEntity.php';
require 'Stubs/TestingLazyJoinedEntity.php';
require 'Stubs/TestingPDO.php';
require 'Stubs/FakeContainer.php';

class DbEngineTest extends TestCase
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
        $this->testingPDO->query('
            CREATE TABLE `testing_lazy_joined` (
                id INTEGER  PRIMARY KEY AUTOINCREMENT,
                some_lazy_column TEXT,
                testing_entity_id INTEGER,
                FOREIGN KEY(testing_entity_id) REFERENCES testing(id)
                )
        ');

        $this->testingPDO->query("INSERT INTO `testing` VALUES (1, 'test', 'custom data')");
        $this->testingPDO->query("INSERT INTO `testing` VALUES (2, 'test1', 'custom data')");
        $this->testingPDO->query("INSERT INTO `testing_joined` VALUES (1, '3ghi', 1)");
        $this->testingPDO->query("INSERT INTO `testing_joined` VALUES (2, '2def', 2)");
        $this->testingPDO->query("INSERT INTO `testing_joined_second` VALUES (1, 'test1234', 1)");
        $this->testingPDO->query("INSERT INTO `testing_lazy_joined` VALUES (1, 'some lazy text', 1)");

        $this->foxDbConnection->method("getPdoConnection")->willReturn($this->testingPDO);
    }


    public function testCreateSelectQueryFailIncorrectTable(): void
    {
        $engine = new SQLiteDbEngine();
        $this->expectException(IncorrectMappingException::class);
        $engine->select($this->foxDbConnection, SomeEntity::class, 1, 0, null, []);
    }

    public function testCreateSelectQuery(): void
    {
        $engine = new MySQLDbEngine();
        $predicate1 = (new Predicate())
            ->add(TestingEntity::class, 'firstColumn', 'test')
            ->add(TestingJoinedEntity::class, 'someColumn', ['1abcd', '2def'], Predicate::NOT_IN);
        $predicate2 = (new Predicate())
            ->add(TestingJoinedEntity::class, 'someColumn', 'test1234');
        $result = $engine->select($this->foxDbConnection, TestingEntity::class, 1, 0, null, [$predicate1, $predicate2]);
        $this->assertCount(1, $result);
        $this->assertTrue($result[0] instanceof TestingEntity);
        $this->assertTrue($result[0]->getTestingJoinedOneToOne() instanceof TestingJoinedEntity);
        $this->assertCount(1, $result[0]->getTestingJoinedOneToOne()->testingSecondJoinedEntities);
        $this->assertTrue($result[0]->getTestingJoinedOneToOne()->testingSecondJoinedEntities[0] instanceof TestingSecondJoinedEntity);
        $this->assertCount(2, $this->testingPDO->queries);
        $this->assertEquals(
            preg_replace('~[\r\n]+~', '',
                '
SELECT `t0`.`id` as `t0id`,`t0`.`first_column` as `t0first_column`,`t0`.`custom_second_column` as `t0custom_second_column`, `t1`.`id` as `t1id`,`t1`.`some_column` as `t1some_column` 
FROM `testing` AS `t0` JOIN `testing_joined` AS `t1` ON (`t1`.`testing_entity_id` = `t0`.`id`) 
WHERE (`t0`.`first_column` = ? AND `t1`.`some_column` NOT IN (?,?)) OR (`t1`.`some_column` = ?) 
LIMIT 1 OFFSET 0'), $this->testingPDO->queries[0][0]);

        $this->assertEquals(
            preg_replace('~[\r\n]+~', '',
                '
SELECT `t0`.`id` as `t0id`,`t0`.`some_column` as `t0some_column` 
FROM `testing_joined_second` AS `t0`  
WHERE (`t0`.`testing_joined_entity_id` = ?) '), $this->testingPDO->queries[1][0]);

        $container = new FakeContainer();
        $this->foxDbConnection->method("getDbEngine")->willReturn($engine);

        $container->set(FoxDbConnection::class, $this->foxDbConnection);
        Globals::set('foxContainer', $container);
        $lazy = $result[0]->getTestingLazyJoinedEntity();
        $this->assertTrue($lazy instanceof TestingLazyJoinedEntity);
        $this->assertEquals('some lazy text', $lazy->someLazyColumn);
        $this->assertCount(3, $this->testingPDO->queries);
        $this->assertEquals('SELECT `t0`.`id` as `t0id`,`t0`.`some_lazy_column` as `t0some_lazy_column` FROM `testing_lazy_joined` AS `t0`  WHERE (`t0`.`testing_entity_id` = ?) LIMIT 1 OFFSET 0', $this->testingPDO->queries[2][0]);
    }

    public function testCount()
    {
        $this->testingPDO->queries = [];
        $engine = new MySQLDbEngine();
        $predicate = (new Predicate())
            ->add(TestingEntity::class, 'firstColumn', 'test1');
        $result = $engine->count($this->foxDbConnection, TestingEntity::class, [$predicate]);
        $this->assertEquals(1, $result);
        $this->assertCount(1, $this->testingPDO->queries);
        $this->assertEquals('SELECT COUNT(*) FROM `testing` AS `t0` JOIN `testing_joined` AS `t1` ON (`t1`.`testing_entity_id` = `t0`.`id`) WHERE (`t0`.`first_column` = ?)', trim($this->testingPDO->queries[0][0]));
    }

    public function testInsert()
    {
        $this->testingPDO->queries = [];
        $engine = new MySQLDbEngine();
        $container = new FakeContainer();
        $this->foxDbConnection->method("getDbEngine")->willReturn($engine);

        $container->set(FoxDbConnection::class, $this->foxDbConnection);
        Globals::set('foxContainer', $container);

        $test = new TestingEntity();
        $test->setFirstColumn('my set first column');
        $test->setSecondColumn('my second column');

        $testJoined = new TestingJoinedEntity();
        $testJoined->someColumn = 'Some joned column';

        $testSecondJoined = new TestingSecondJoinedEntity();
        $testSecondJoined->someColumn = 'Some first second joined';
        $testSecondJoined->testingJoinedEntity = $testJoined;

        $testSecondJoined2 = new TestingSecondJoinedEntity();
        $testSecondJoined2->someColumn = 'Some second second joined';
        $testSecondJoined2->testingJoinedEntity = $testJoined;

        $testJoined->testingSecondJoinedEntities = [$testSecondJoined, $testSecondJoined2];
        $test->setTestingJoinedOneToOne($testJoined);

        $engine->insert($this->foxDbConnection, $test);
        $this->assertEquals(3, $test->getId());
        $this->assertEquals(3, $test->getTestingJoinedOneToOne()->id);
        $this->assertCount(2, $test->getTestingJoinedOneToOne()->testingSecondJoinedEntities);
        $this->assertEquals(2,$test->getTestingJoinedOneToOne()->testingSecondJoinedEntities[0]->id);
        $this->assertEquals(3,$test->getTestingJoinedOneToOne()->testingSecondJoinedEntities[1]->id);
    }

    public function testDelete()
    {
        $engine = new MySQLDbEngine();
        $predicate = (new Predicate())
            ->add(TestingJoinedEntity::class, 'id', 2);
        $result = $engine->select($this->foxDbConnection, TestingJoinedEntity::class, 1, 0, null, [$predicate]);
        $this->assertTrue($result[0] instanceof TestingJoinedEntity);
        $engine->delete($this->foxDbConnection, $result[0]);
        $result2 = $engine->select($this->foxDbConnection, TestingJoinedEntity::class, 1, 0, null, [$predicate]);
        $this->assertEmpty($result2);
        $this->assertEquals('DELETE FROM `testing_joined` WHERE `id` = ?', $this->testingPDO->queries[2][0]);
    }

    public function testUpdate()
    {
        $this->testingPDO->queries = [];
        $engine = new MySQLDbEngine();
        $container = new FakeContainer();
        $this->foxDbConnection->method("getDbEngine")->willReturn($engine);

        $container->set(FoxDbConnection::class, $this->foxDbConnection);
        Globals::set('foxContainer', $container);
        $engine = new MySQLDbEngine();
        $predicate = (new Predicate())
            ->add(TestingEntity::class, 'id', 1);

        /** @var TestingEntity $result */
        $result = $engine->select($this->foxDbConnection, TestingEntity::class, 1, 0, null, [$predicate])[0];
        $this->assertTrue($result instanceof TestingEntity);
        $result->setFirstColumn('some changed text');
        $engine->update($this->foxDbConnection, $result);
        /** @var TestingEntity $result */
        $result = $engine->select($this->foxDbConnection, TestingEntity::class, 1, 0, null, [$predicate])[0];
        $this->assertTrue($result instanceof TestingEntity);
        $this->assertEquals('some changed text', $result->getFirstColumn());
        $result->getTestingLazyJoinedEntity()->someLazyColumn = 'Lazy child changed';
        $engine->update($this->foxDbConnection, $result);

        /** @var TestingEntity $result */
        $result = $engine->select($this->foxDbConnection, TestingEntity::class, 1, 0, null, [$predicate])[0];
        $this->assertTrue($result instanceof TestingEntity);
        $this->assertEquals('some changed text', $result->getFirstColumn());
        $this->assertEquals('Lazy child changed', $result->getTestingLazyJoinedEntity()->someLazyColumn);

        $this->assertEquals('UPDATE `testing_joined_second` SET `some_column` = ? WHERE `id` = ?', $this->testingPDO->queries[2][0]);
        $this->assertEquals('UPDATE `testing_joined` SET `some_column` = ? WHERE `id` = ?', $this->testingPDO->queries[3][0]);
        $this->assertEquals('UPDATE `testing` SET `first_column` = ? WHERE `id` = ?', $this->testingPDO->queries[4][0]);
        $this->assertEquals('UPDATE `testing_lazy_joined` SET `some_lazy_column` = ? WHERE `id` = ?', $this->testingPDO->queries[10][0]);
    }

}
