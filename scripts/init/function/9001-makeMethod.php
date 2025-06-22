<?php

    use Coco\tableManager\TableRegistry;

    require '../common.php';

    $method = TableRegistry::makeMethod($manager->getFileTable()->getFieldsSqlMap());

    print_r($method);
