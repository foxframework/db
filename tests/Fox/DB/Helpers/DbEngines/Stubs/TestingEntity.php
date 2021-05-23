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

namespace Fox\DB\Sources\Services\Stubs;


use Fox\DB\Attribute\AutoIncrement;
use Fox\DB\Attribute\Column;
use Fox\DB\Attribute\Lazy;
use Fox\DB\Attribute\OneToOne;
use Fox\DB\Attribute\PrimaryKey;
use Fox\DB\Attribute\Table;
use Fox\DB\Helpers\FoxEntity;

#[Table('testing')]
class TestingEntity extends FoxEntity
{
    #[Column]
    #[PrimaryKey]
    #[AutoIncrement]
    protected int $id;

    #[Column]
    protected string $firstColumn;

    #[Column('custom_second_column')]
    protected string $secondColumn;

    #[OneToOne('testing_entity_id')]
    protected TestingJoinedEntity $testingJoinedOneToOne;

    #[OneToOne('testing_entity_id')]
    #[Lazy]
    protected ?TestingLazyJoinedEntity $testingLazyJoinedEntity;


    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->changeValue('id', $id);
        $this->id = $id;
    }

    public function getFirstColumn(): string
    {
        return $this->firstColumn;
    }

    public function setFirstColumn(string $firstColumn): void
    {
        $this->changeValue('firstColumn', $firstColumn);
        $this->firstColumn = $firstColumn;
    }

    public function getSecondColumn(): string
    {
        return $this->secondColumn;
    }

    public function setSecondColumn(string $secondColumn): void
    {
        $this->changeValue('secondColumn', $secondColumn);
        $this->secondColumn = $secondColumn;
    }

    public function getTestingJoinedOneToOne(): TestingJoinedEntity
    {
        return $this->testingJoinedOneToOne;
    }

    public function setTestingJoinedOneToOne(TestingJoinedEntity $testingJoinedOneToOne): void
    {
        $this->changeValue('testingJoinedOneToOne', $testingJoinedOneToOne);
        $this->testingJoinedOneToOne = $testingJoinedOneToOne;
        $testingJoinedOneToOne->testingEntity = $this;
    }

    public function getTestingLazyJoinedEntity(): ?TestingLazyJoinedEntity
    {
        return $this->getLazy('testingLazyJoinedEntity');
    }

    public function setTestingLazyJoinedEntity(?TestingLazyJoinedEntity $testingLazyJoinedEntity): void
    {
        $this->changeValue('testingLazyJoinedEntity', $testingLazyJoinedEntity);
        $this->testingLazyJoinedEntity = $testingLazyJoinedEntity;
    }
}
