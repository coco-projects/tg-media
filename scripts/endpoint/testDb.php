<?php

    require __DIR__ . '/../common.php';

    $data = $manager->getMessageTable()->tableIns()->select()->toArray();
    print_r($data);exit;;

