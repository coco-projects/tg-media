<?php

    require '../common.php';
    $fileTab = $manager->getFileTable();

    $files = $fileTab->tableIns()->select();

    foreach ($files as $k => $videoFileInfo)
    {

        if (str_starts_with($videoFileInfo[$fileTab->getMimeTypeField()], 'video'))
        {
            //如果是视频，就抽个封面图
            $manager->makeVideoCoverToQueue($videoFileInfo, function($path) use ($manager) {
                $path = $manager->telegramMediaStorePath . '/' . $path;

                return $path;
            });
        }
    }