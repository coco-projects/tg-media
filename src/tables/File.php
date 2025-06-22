<?php

    declare(strict_types = 1);

    namespace Coco\tgMedia\tables;

    use Coco\tableManager\TableAbstract;

    class File extends TableAbstract
    {
        public string $comment = '文件信息';

        public array $fieldsSqlMap = [
            "post_id"          => "`__FIELD__NAME__` BIGINT (11) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'post_id',",
            "bot_id"           => "`__FIELD__NAME__` bigint(11) NOT NULL DEFAULT '0' COMMENT '机器人 telegramid',",
            "file_id"          => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'file_id',",
            "file_unique_id"   => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'file_unique_id',",
            "file_size"        => "`__FIELD__NAME__` BIGINT (11) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'file_size',",
            "file_name"        => "`__FIELD__NAME__` TEXT COLLATE utf8mb4_unicode_ci COMMENT '文件名',",
            "path"             => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '本地地址',",
            "media_group_id"   => "`__FIELD__NAME__` bigint(11) unsigned NOT NULL DEFAULT '0' COMMENT 'media_group_id',",
            "ext"              => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '当前格式后缀，视频文件转m3u8后的格式',",
            "mime_type"        => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '当前格式后缀，视频文件转m3u8后的mime_type',",
            "origin_ext"       => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '源文件后缀，转m3u8之前的视频格式',",
            "origin_mime_type" => "`__FIELD__NAME__` char(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '源文件后缀，转m3u8之前的视频mime_type',",
            "time"             => "`__FIELD__NAME__` INT (10) UNSIGNED NOT NULL DEFAULT '0',",
        ];

        protected array $indexSentence = [
            "post_id"        => "KEY `__INDEX__NAME___index` ( __FIELD__NAME__ ),",
            "file_id"        => "KEY `__INDEX__NAME___index` ( __FIELD__NAME__ ),",
            "file_unique_id" => "KEY `__INDEX__NAME___index` ( __FIELD__NAME__ ),",
            "media_group_id" => "KEY `__INDEX__NAME___index` ( __FIELD__NAME__ ),",
        ];

        public function setPostIdField(string $value): static
        {
            $this->setFeildName('post_id', $value);

            return $this;
        }

        public function getPostIdField(): string
        {
            return $this->getFieldName('post_id');
        }

        public function setBotIdField(string $value): static
        {
            $this->setFeildName('bot_id', $value);

            return $this;
        }

        public function getBotIdField(): string
        {
            return $this->getFieldName('bot_id');
        }

        public function setFileIdField(string $value): static
        {
            $this->setFeildName('file_id', $value);

            return $this;
        }

        public function getFileIdField(): string
        {
            return $this->getFieldName('file_id');
        }

        public function setFileUniqueIdField(string $value): static
        {
            $this->setFeildName('file_unique_id', $value);

            return $this;
        }

        public function getFileUniqueIdField(): string
        {
            return $this->getFieldName('file_unique_id');
        }

        public function setFileSizeField(string $value): static
        {
            $this->setFeildName('file_size', $value);

            return $this;
        }

        public function getFileSizeField(): string
        {
            return $this->getFieldName('file_size');
        }

        public function setFileNameField(string $value): static
        {
            $this->setFeildName('file_name', $value);

            return $this;
        }

        public function getFileNameField(): string
        {
            return $this->getFieldName('file_name');
        }

        public function setPathField(string $value): static
        {
            $this->setFeildName('path', $value);

            return $this;
        }

        public function getPathField(): string
        {
            return $this->getFieldName('path');
        }

        public function setMediaGroupIdField(string $value): static
        {
            $this->setFeildName('media_group_id', $value);

            return $this;
        }

        public function getMediaGroupIdField(): string
        {
            return $this->getFieldName('media_group_id');
        }

        public function setExtField(string $value): static
        {
            $this->setFeildName('ext', $value);

            return $this;
        }

        public function getExtField(): string
        {
            return $this->getFieldName('ext');
        }

        public function setMimeTypeField(string $value): static
        {
            $this->setFeildName('mime_type', $value);

            return $this;
        }

        public function getMimeTypeField(): string
        {
            return $this->getFieldName('mime_type');
        }

        public function setOriginExtField(string $value): static
        {
            $this->setFeildName('origin_ext', $value);

            return $this;
        }

        public function getOriginExtField(): string
        {
            return $this->getFieldName('origin_ext');
        }

        public function setOriginMimeTypeField(string $value): static
        {
            $this->setFeildName('origin_mime_type', $value);

            return $this;
        }

        public function getOriginMimeTypeField(): string
        {
            return $this->getFieldName('origin_mime_type');
        }

        public function setTimeField(string $value): static
        {
            $this->setFeildName('time', $value);

            return $this;
        }

        public function getTimeField(): string
        {
            return $this->getFieldName('time');
        }
    }
