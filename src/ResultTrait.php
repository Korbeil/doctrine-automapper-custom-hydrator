<?php

namespace Korbeil\DoctrineAutomapperHydrator;

use Doctrine\ORM\Query;

trait ResultTrait
{
    public static function getResult(Query $query): mixed
    {
        $query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        return $query->getResult('automapper');
    }

    public static function getOneOrNullResult(Query $query): mixed
    {
        $query->setHint(Query::HINT_INCLUDE_META_COLUMNS, true);

        return $query->getOneOrNullResult('automapper');
    }
}