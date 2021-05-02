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

namespace Fox\DB\Sources\Services;

use Fox\Core\Attribute\Autowire;
use Fox\Core\Attribute\Service;
use Fox\DB\Helpers\FoxEntity;
use Fox\DB\Helpers\Predicate;

#[Service]
#[Autowire]
class FoxEntityRepository
{
    public function __construct(private FoxDbConnection $foxDbConnection)
    {
    }

    public function fetch(string $entityName, Predicate...$predicates): ?FoxEntity
    {
        $statement = $this->foxDbConnection->getDbEngine()->select($this->foxDbConnection, $entityName, 1, 0, null, $predicates);
    }

    public function fetchAll(string $entityName, Predicate...$predicates): ?array
    {
        $statement = $this->foxDbConnection->getDbEngine()->select($this->foxDbConnection, $entityName, 1, 0, null, $predicates);
    }

    public function saveOrUpdate(FoxEntity $entity): void
    {

    }
}
