<?php

    require '../common.php';
    $fileTab = $manager->getFileTable();

    $files = $fileTab->tableIns()->select();

    foreach ($files as $k => $videoFileInfo)
    {
        if (str_starts_with($videoFileInfo[$fileTab->getMimeTypeField()], 'video'))
        {
            //转码为m3u8
            $manager->convertM3u8ToQueue($videoFileInfo, function($path) use ($manager) {
                $path = $manager->telegramMediaStorePath . '/' . $path;

                return $path;
            });
        }
    }