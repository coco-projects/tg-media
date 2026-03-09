<?php

    use Coco\tgMedia\tables\Post;

    require '../common.php';

    $manager->updatePostContents(function(array $post, Post $postTable) {
        $origin_contents = $post[$postTable->getContentsField()];
        $contents        = $origin_contents;

        //去除前面部分
        $contents = preg_replace('/^(test-)+/', '', $contents);

        //去除后面部分
        $contents = preg_replace('/\s*(千万视频|☄️)+[\s\S]*$/iu', '', $contents);

        echo $contents;
        echo PHP_EOL . '------------------------------------' . PHP_EOL . PHP_EOL;

        if (str_contains($origin_contents, '@') || str_contains($origin_contents, 't.me'))
        {
        }

        return trim($contents);
    });
