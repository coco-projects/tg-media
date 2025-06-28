<?php

    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../config.php';

    /*
     * 基础配置
     * -------------------------------------------------------------------------------
     * */

    $manager = new \Coco\tgMedia\Manager(
        $bootToken,
        $apiId,
        $apiHash,
        __DIR__ . '/../data',
        'te_10100'
    );

    $manager->setDebug(true);
    $manager->setTelegramMediaMaxDownloading(10);
    $manager->setTelegramMediaDownloadDelayInSecond(2);
    $manager->setTelegramMediaMaxDownloadTimeout(120);
    $manager->setMediaOwner('www');
//    $manager->setTelegramMediaStorePath(__DIR__ . '/medias');
    $manager->setTelegramMediaMaxFileSize(3000 * 1024 * 1024);

    $url = 'http://127.0.0.1:8101/tg/scripts/endpoint/type1.php';
    $manager->setTelegramWebHookUrl($url);
    $manager->setRedisConfig(db: 12);

    $manager->setMysqlConfig(db: 'tg_media', username: 'root', password: 'root');
    $manager->setLocalServerPort(8081);
    $manager->setStatisticsPort(8082);

    $manager->setEnableRedisLog(true);
    $manager->setEnableEchoLog(true);

    $manager->setBeforePostFilesInsert(function($files) use ($manager) {

        $fileTab = $manager->getFileTable();

        foreach ($files as $k => $videoFileInfo)
        {
            if (str_starts_with($videoFileInfo[$fileTab->getMimeTypeField()], 'video'))
            {
                //如果是视频，就抽个封面图
                //如果是视频，就抽个封面图
                $manager->makeVideoCoverToQueue($videoFileInfo, function($path) use ($manager) {
                    $path = $manager->telegramMediaStorePath . '/' . $path;

                    return $path;
                });

                //转码为m3u8
                $manager->convertM3u8ToQueue($videoFileInfo, function($path) use ($manager) {
                    $path = $manager->telegramMediaStorePath . '/' . $path;

                    return $path;
                });
            }

            /*
            //所有文件cdn预热
            $manager->cdnPrefetchToQueue($videoFileInfo, function($path) {
                return implode('', [
                    $this->cdnUrl,
                    $path,
                ]);

            }, $this->referer);
            */
        }
    });

    $manager->initServer();

    /*
     * 初始化公用表
     * -------------------------------------------------------------------------------
     * */

    $manager->initMessageTable('te_message', function(\Coco\tgMedia\tables\Message $table) {
        $registry = $table->getTableRegistry();

        $table->setPkField('id');
        $table->setIsPkAutoInc(false);
        $table->setPkValueCallable($registry::snowflakePKCallback());
    });

    $manager->initPostTable('te_post', function(\Coco\tgMedia\tables\Post $table) {
        $registry = $table->getTableRegistry();

        $table->setPkField('id');
        $table->setIsPkAutoInc(false);
        $table->setPkValueCallable($registry::snowflakePKCallback());
    });

    $manager->initFileTable('te_file', function(\Coco\tgMedia\tables\File $table) {
        $registry = $table->getTableRegistry();

        $table->setPkField('id');
        $table->setIsPkAutoInc(false);
        $table->setPkValueCallable($registry::snowflakePKCallback());
    });

    $manager->initTypeTable('te_type', function(\Coco\tgMedia\tables\Type $table) {
        $registry = $table->getTableRegistry();

        $table->setPkField('id');
        $table->setIsPkAutoInc(true);
    });

    $manager->initCommonProperty();


