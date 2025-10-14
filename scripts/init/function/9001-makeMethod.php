<?php

    use Coco\tableManager\TableRegistry;

    require '../common.php';

    $method = TableRegistry::makeMethod($manager->getMessageTable()->getFieldsSqlMap());

    print_r($method);
