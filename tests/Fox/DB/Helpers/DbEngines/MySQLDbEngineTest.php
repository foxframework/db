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
use Fox\DB\Sources\Services\Stubs\TestingSecondJoinedEntity;
use PHPUnit\Framework\TestCase;

require 'Stubs/SomeEntity.php';
require 'Stubs/TestingEntity.php';
require 'Stubs/TestingJoinedEntity.php';
require 'Stubs/TestingSecondJoinedEntity.php';

class MySQLDbEngineTest extends TestCase
{
    private FoxDbConnection $foxDbConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foxDbConnection = $this->createMock(FoxDbConnection::class);
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
    }

}
