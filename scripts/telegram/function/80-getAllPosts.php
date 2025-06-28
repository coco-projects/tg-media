<?php

    require '../common.php';
//    $info = $manager->getAllPosts(1120081060969840981, 5);
    $info = $manager->getAllPosts();

    print_r($info);  exit;;

    foreach ($info as $k => $v)
    {
        echo '----------------------------';
        echo PHP_EOL;
        echo $k . ' -> ' . $v['post']['contents'];
        echo PHP_EOL;
        echo PHP_EOL;

    }

