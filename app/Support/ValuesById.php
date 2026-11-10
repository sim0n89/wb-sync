<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

class ValuesById
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<int, int>  $valuesById
     */
    public static function toCase(string $model, array $valuesById): Expression
    {
        if ($valuesById === []) {
            throw new InvalidArgumentException('At least one value is required.');
        }

        $sql = 'CASE '.(new $model)->getQualifiedKeyName();

        foreach ($valuesById as $id => $value) {
            $sql .= ' WHEN '.(int) $id.' THEN '.(int) $value;
        }

        return new Expression($sql.' END');
    }
}
