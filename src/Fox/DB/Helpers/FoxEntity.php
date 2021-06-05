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

use Fox\Core\Helpers\Globals;
use Fox\DB\Attribute\Column;
use Fox\DB\Attribute\Lazy;
use Fox\DB\Helpers\DbEngines\Engine;
use Fox\DB\Sources\Services\FoxDbConnection;
use Psr\Container\ContainerInterface;
use ReflectionClass;

abstract class FoxEntity
{
    private array $_changedFields = [];
    private array $_lazyInitFields = [];
    private array $_queryConditions = [];
    private bool $_virginEntity = true;
    private bool $_useDiff = false;

    public function __construct()
    {
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            $lazy = $property->getAttributes(Lazy::class);
            if (!empty($lazy)) {
                $this->_lazyInitFields[] = $property->getName();
            }
        }

    }

    public function getLazy(string $name)
    {
        if (in_array($name, $this->_lazyInitFields)) {
            /** @var ContainerInterface $container */
            $container = Globals::get('foxContainer');
            /** @var FoxDbConnection $dbConnection */
            $dbConnection = $container->get(FoxDbConnection::class);
            $dbEngine = $dbConnection->getDbEngine();
            $reflection = new ReflectionClass($this);
            $primaryKey = Engine::getPrimaryKey($reflection)[0];
            $property = $reflection->getProperty($name);
            $targetReflection = new ReflectionClass($property->getType()->getName());
            $targetProperty = null;
            foreach ($targetReflection->getProperties() as $targetLoopProperty) {
                if ($targetLoopProperty->getType()->getName() === $this::class) {
                    $targetProperty = $targetLoopProperty;
                    break;
                }
            }

            if (empty($targetProperty)) {
                throw new IncorrectMappingException('Target entity not found');
            }

            $joinColumnName = $targetProperty->getAttributes(Column::class)[0]->newInstance()->name;

            $predicate = (new Predicate())->add($this::class, $joinColumnName, $this->{$primaryKey}, exactPredicate: true);
            $type = $reflection->getProperty($name)->getType()->getName();
            $isArray = $type === 'array';
            $limit = $isArray ? null : 1;
            $offset = $isArray ? null : 0;
            $result = $dbEngine->select($dbConnection, $type, $limit, $offset, $this::class, [$predicate]);
            $parentPropertyName = null;
            foreach ($result as $res) {
                $resultReflection = new ReflectionClass($res);
                if (empty($parentPropertyName)) {
                    foreach ($resultReflection->getProperties() as $property) {
                        if ($property->getType()->getName() === $this::class) {
                            $parentPropertyName = $property->getName();
                            break;
                        }
                    }
                }
                $resultReflection->getProperty($parentPropertyName)->setAccessible(true);
                $resultReflection->getProperty($parentPropertyName)->setValue($res, $this);
            }
            $value = $isArray ? $result : ($result[0] ?? null);
            $this->{$name} = $value;
            $this->changeValue($name, $value);
            return $value;
        }
        return $this->{$name};
    }

    public function changeValue(mixed $name, mixed $value): void
    {
        $this->_useDiff = true;

        if (!isset($this->{$name}) || $value === $this->{$name}) {
            return;
        }

        if (!str_starts_with($name, '_') && !in_array($name, $this->_lazyInitFields)) {
            $this->_changedFields[] = $name;
        }

        if (in_array($name, $this->_lazyInitFields) && !empty($value)) {
            $this->_lazyInitFields = array_diff($this->_lazyInitFields, [$name]);
        }
    }

    public function setQueryConditions(array $queryConditions): void
    {
        $this->_queryConditions = $queryConditions;
    }

    public function setAsNotVirgin(): void
    {
        $this->_changedFields = [];
        $this->_virginEntity = false;
    }

    public function isVirgin(): bool
    {
        return $this->_virginEntity;
    }

    public function canUseDiff(): bool
    {
        return $this->_useDiff;
    }

    public function getChangedFields(): array
    {
        return $this->_changedFields;
    }

}
