<?php

    use Coco\tableManager\TableRegistry;

    require '../common.php';

    $method = TableRegistry::makeMethod($manager->getPostTable()->getFieldsSqlMap());

    print_r($method);
