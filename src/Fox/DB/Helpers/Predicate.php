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

namespace Fox\DB\Helpers;


class Predicate
{
    const EQUALS = '=';
    const NOT_EQUALS = '!=';
    const GREATER_THAN = '>';
    const LESS_THAN = '<';
    const GREATER_OR_EQUAL = '>=';
    const LESS_OR_EQUAL = '<=';
    const BETWEEN_AND = 'BETWEEN AND';
    const IN = 'IN';
    const NOT_IN = 'NOT IN';

    const ALLOWED_OPERATIONS = [
        self::EQUALS,
        self::NOT_EQUALS,
        self::GREATER_THAN,
        self::LESS_THAN,
        self::GREATER_OR_EQUAL,
        self::LESS_OR_EQUAL,
        self::BETWEEN_AND,
        self::IN,
        self::NOT_IN
    ];

    private array $predicates = [];

    public function getPredicates(): array
    {
        return $this->predicates;
    }

    public function add(string $className, string $variable, mixed $value, string $operation = self::EQUALS, bool $exactPredicate = false): Predicate
    {
        if (!in_array($operation, self::ALLOWED_OPERATIONS)) {
            throw new NotAllowedPredicateException("Operation '$operation' is not supported!");
        }

        $this->predicates[] = [$className, $variable, $operation, $value, $exactPredicate];
        return $this;
    }
}
