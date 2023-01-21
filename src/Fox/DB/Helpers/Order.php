<?php

namespace Fox\DB\Helpers;

class Order
{
    const ASC = 'ASC';
    const DESC = 'DESC';
    const ALLOWED_DIRECTIONS = [self::ASC, self::DESC];
    
    private array $orderColumns = [];

    public function add(string $className, string $variable, string $direction = self::ASC): Order
    {
        if (!in_array($direction, self::ALLOWED_DIRECTIONS)) {
            throw new NotAllowedOrderException("Direction '$direction' is not supported!");
        }

        $this->orderColumns[] = [$className, $variable, $direction];
        return $this;
    }

    public function getOrderColumns(): array
    {
        return $this->orderColumns;
    }

}