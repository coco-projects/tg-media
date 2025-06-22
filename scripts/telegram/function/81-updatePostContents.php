<?php

    require '../common.php';

    $tgDownloaderManager->updatePostContents(function(array $post, \Coco\tgDownloader\tables\Post $postTable) {
        $origin_contents = $post[$postTable->getContentsField()];
        $contents        = $origin_contents;

        //去除前面部分
        //    $contents = preg_replace('/^(test-)+/', '', $origin_contents);

        //去除后面部分
        $contents = preg_replace('/\s*(千万视频|☄️)+[\s\S]*$/iu', '', $contents);

        echo PHP_EOL . '------------------------------------' . PHP_EOL;
        echo $contents;
        if (str_contains($origin_contents, '@') || str_contains($origin_contents, 't.me'))
        {
        }

//        return $origin_contents;

        return trim($contents);
    });
