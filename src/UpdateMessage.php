<?php

    declare(strict_types = 1);

    namespace Coco\tgMedia;

    use Coco\snowflake\Snowflake;

    class UpdateMessage
    {
        const MSG_SOURCE_TYPE_PRIVATE    = 1;
        const MSG_SOURCE_TYPE_GROUP      = 2;
        const MSG_SOURCE_TYPE_SUPERGROUP = 3;
        const MSG_SOURCE_TYPE_CHANNEL    = 4;

        const MSG_CARRIER_TYPE_TEXT      = 1;
        const MSG_CARRIER_TYPE_VIDEO     = 2;
        const MSG_CARRIER_TYPE_PHOTO     = 3;
        const MSG_CARRIER_TYPE_AUDIO     = 4;
        const MSG_CARRIER_TYPE_DOCUMENT  = 5;
        const MSG_CARRIER_TYPE_ANIMATION = 6;
        const MSG_CARRIER_TYPE_STICKER   = 7;
        const MSG_CARRIER_TYPE_LOCATION  = 8;
        const MSG_CARRIER_TYPE_CONTACT   = 9;
        const MSG_CARRIER_TYPE_NEWS      = 10;
        const MSG_CARRIER_TYPE_POLL      = 11;

        const MSG_FROM_TYPE_MESSAGE             = 1;
        const MSG_FROM_TYPE_EDITED_MESSAGE      = 2;
        const MSG_FROM_TYPE_CHANNEL_POST        = 3;
        const MSG_FROM_TYPE_EDITED_CHANNEL_POST = 4;

        protected static Snowflake|null $snowflake   = null;
        protected array                 $messageRow;
        protected array                 $messageBody = [];
        public array                    $tags        = [];

        public int    $messageFromType    = 0;
        public int    $messageLoadType    = 0;
        public int    $chatType           = 0;
        public string $mediaGroupId       = '';
        public string $caption            = '';
        public string $text               = '';
        public string $fileId             = '';
        public string $fileUniqueId       = '';
        public string $chatSourceType     = '';
        public string $chatSourceUsername = '';
        public string $mimeType           = '';
        public string $ext                = '';
        public string $fileName           = '';
        public int    $messageId          = 0;
        public int    $senderId           = 0;
        public int    $date               = 0;
        public int    $fileSize           = 0;
        public int    $updateId           = 0;


        protected static array $mimeTypesMap = [

            // 文档
            'application/pdf'                                                           => 'pdf',
            'application/msword'                                                        => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
            'application/vnd.ms-excel'                                                  => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
            'application/vnd.ms-powerpoint'                                             => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/rtf'                                                           => 'rtf',
            'application/xml'                                                           => 'xml',
            'application/json'                                                          => 'json',
            'application/vnd.oasis.opendocument.text'                                   => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet'                            => 'ods',
            'application/vnd.oasis.opendocument.presentation'                           => 'odp',

            // 压缩文档
            'application/zip'                                                           => 'zip',
            'application/x-zip-compressed'                                              => 'zip',
            'application/x-gzip'                                                        => 'gz',
            'application/x-tar'                                                         => 'tar',
            'application/x-bzip2'                                                       => 'bz2',
            'application/x-rar-compressed'                                              => 'rar',
            'application/x-7z-compressed'                                               => '7z',

            // 视频
            'video/mp4'                                                                 => 'mp4',
            'video/x-msvideo'                                                           => 'avi',
            'video/x-flv'                                                               => 'flv',
            'video/ogg'                                                                 => 'ogv',
            'video/webm'                                                                => 'webm',
            'video/mpeg'                                                                => 'mpg',
            'video/quicktime'                                                           => 'mov',
            'video/x-ms-wmv'                                                            => 'wmv',
            'video/x-matroska'                                                          => 'mkv',
            'video/x-rmvb'                                                              => 'rmvb',
            'video/3gpp'                                                                => '3gp',
            'video/3gpp2'                                                               => '3g2',
            'video/x-ms-asf'                                                            => 'asf',

            // 图像
            'image/jpeg'                                                                => 'jpg',
            'image/pjpeg'                                                               => 'jpg',
            'image/png'                                                                 => 'png',
            'image/gif'                                                                 => 'gif',
            'image/svg+xml'                                                             => 'svg',
            'image/webp'                                                                => 'webp',
            'image/bmp'                                                                 => 'bmp',
            'image/tiff'                                                                => 'tiff',
            'image/heif'                                                                => 'heif',
            'image/heic'                                                                => 'heic',
            'image/jfif'                                                                => 'jfif',
            'image/vnd.adobe.photoshop'                                                 => 'psd',
            'image/x-icon'                                                              => 'ico',
            'image/x-cmu-raster'                                                        => 'ras',
            'image/x-portable-pixmap'                                                   => 'ppm',

            // 音频
            'audio/mpeg'                                                                => 'mp3',
            'audio/wav'                                                                 => 'wav',
            'audio/ogg'                                                                 => 'ogg',
            'audio/x-midi'                                                              => 'mid',
            'audio/aac'                                                                 => 'aac',
            'audio/x-ms-wma'                                                            => 'wma',
            'audio/flac'                                                                => 'flac',
            'audio/x-realaudio'                                                         => 'ra',
            'audio/opus'                                                                => 'opus',
            'audio/x-aac'                                                               => 'aac',
            'audio/aiff'                                                                => 'aiff',
            'audio/x-m4a'                                                               => 'm4a',

            // 文本和网页
            'text/html'                                                                 => 'html',
            'text/css'                                                                  => 'css',
            'text/javascript'                                                           => 'js',
            'text/xml'                                                                  => 'xml',
            'text/markdown'                                                             => 'md',
            'text/x-shellscript'                                                        => 'sh',
            'text/plain'                                                                => 'txt',
            'text/csv'                                                                  => 'csv',

            // 应用程序
            'application/octet-stream'                                                  => 'bin',
            'application/x-shockwave-flash'                                             => 'swf',
            'application/vnd.android.package-archive'                                   => 'apk',
            'application/epub+zip'                                                      => 'epub',
            'application/x-font-ttf'                                                    => 'ttf',
            'application/x-font-opentype'                                               => 'otf',
            'application/font-woff'                                                     => 'woff',
            'application/font-woff2'                                                    => 'woff2',
            'application/x-font-woff'                                                   => 'woff',
            'application/vnd.ms-cab-compressed'                                         => 'cab',
            'application/x-quicktimeplayer'                                             => 'qtl',
            'application/x-msdownload'                                                  => 'exe',
            'application/x-msdos-program'                                               => 'exe',
            'application/x-ms-dos-executable'                                           => 'exe',

        ];

        public static function parse(string $message, int $bootId): UpdateMessage
        {
            $massageObj = new static($message, $bootId);

            return $massageObj->parseMassage();
        }

        public function __construct(public string $message, public int $bootId)
        {
            if (is_null(static::$snowflake))
            {
                static::$snowflake = new Snowflake();
            }
        }

        public function parseMassage(): static
        {
            $this->messageRow = json_decode($this->message, true);

            if (isset($this->messageRow['message']))
            {
                $this->messageFromType = static::MSG_FROM_TYPE_MESSAGE;
                $this->messageBody     = $this->messageRow['message'];
            }

            if (isset($this->messageRow['edited_message']))
            {
                $this->messageFromType = static::MSG_FROM_TYPE_EDITED_MESSAGE;
                $this->messageBody     = $this->messageRow['edited_message'];
            }

            if (isset($this->messageRow['channel_post']))
            {
                $this->messageFromType = static::MSG_FROM_TYPE_CHANNEL_POST;
                $this->messageBody     = $this->messageRow['channel_post'];
            }

            if (isset($this->messageRow['edited_channel_post']))
            {
                $this->messageFromType = static::MSG_FROM_TYPE_EDITED_CHANNEL_POST;
                $this->messageBody     = $this->messageRow['edited_channel_post'];
            }

            /**
             * ********************************************************
             * ********************************************************
             */

            if (isset($this->messageBody['text']))
            {
                $this->messageLoadType = static::MSG_CARRIER_TYPE_TEXT;

                if (isset($this->messageBody['text']) && isset($this->messageBody['entities']))
                {
                    $this->text = static::parseCaptionWithEntities($this->messageBody['text'], $this->messageBody['entities']);
                    $this->tags = static::extractHashtags($this->messageBody['text'], $this->messageBody['entities']);
                }
                else
                {
                    $this->text = $this->messageBody['text'];
                }

                $this->mimeType = '';
                $this->ext      = '';
            }

            if (isset($this->messageBody['video']))
            {
                $this->messageLoadType = static::MSG_CARRIER_TYPE_VIDEO;
                $this->fileId          = $this->messageBody['video']['file_id'];
                $this->fileUniqueId    = $this->messageBody['video']['file_unique_id'];
                $this->fileSize        = $this->messageBody['video']['file_size'];
                $this->fileName        = $this->messageBody['video']['file_name'] ?? '';

                $this->mimeType = static::getMimeType($this->messageBody['video']);
                $this->ext      = static::getExt($this->messageBody['video']);
            }

            if (isset($this->messageBody['photo']))
            {
                $phonoList = array_reverse($this->messageBody['photo']);

                $this->messageLoadType = static::MSG_CARRIER_TYPE_PHOTO;
                $this->fileId          = $phonoList[0]['file_id'];
                $this->fileUniqueId    = $phonoList[0]['file_unique_id'];
                $this->fileSize        = $phonoList[0]['file_size'];

                $this->mimeType = static::getMimeType($phonoList[0], 'image/jpeg');
                $this->ext      = static::getExt($phonoList[0], 'jpg');
            }

            if (isset($this->messageBody['audio']))
            {
                $this->messageLoadType = static::MSG_CARRIER_TYPE_AUDIO;
                $this->fileId          = $this->messageBody['audio']['file_id'];
                $this->fileUniqueId    = $this->messageBody['audio']['file_unique_id'];
                $this->fileSize        = $this->messageBody['audio']['file_size'];
                $this->fileName        = $this->messageBody['audio']['file_name'] ?? '';

                $this->mimeType = static::getMimeType($this->messageBody['audio']);
                $this->ext      = static::getExt($this->messageBody['audio']);
            }

            if (isset($this->messageBody['document']))
            {
                $this->messageLoadType = static::MSG_CARRIER_TYPE_DOCUMENT;
                $this->fileId          = $this->messageBody['document']['file_id'];
                $this->fileUniqueId    = $this->messageBody['document']['file_unique_id'];
                $this->fileSize        = $this->messageBody['document']['file_size'];
                $this->fileName        = $this->messageBody['document']['file_name'] ?? '';

                $this->mimeType = static::getMimeType($this->messageBody['document']);
                $this->ext      = static::getExt($this->messageBody['document']);
            }

            /**
             * ********************************************************
             * ********************************************************
             */

            if ($this->messageBody['chat']['type'] == 'private')
            {
                $this->chatType = static::MSG_SOURCE_TYPE_PRIVATE;
            }

            if ($this->messageBody['chat']['type'] == 'group')
            {
                $this->chatType = static::MSG_SOURCE_TYPE_GROUP;
            }

            if ($this->messageBody['chat']['type'] == 'supergroup')
            {
                $this->chatType = static::MSG_SOURCE_TYPE_SUPERGROUP;
            }

            if ($this->messageBody['chat']['type'] == 'channel')
            {
                $this->chatType = static::MSG_SOURCE_TYPE_CHANNEL;
            }

            /**
             * ********************************************************
             * ********************************************************
             */

            if (isset($this->messageBody['caption']))
            {
                if (isset($this->messageBody['caption_entities']) && isset($this->messageBody['caption_entities']))
                {
                    $this->caption = static::parseCaptionWithEntities($this->messageBody['caption'], $this->messageBody['caption_entities']);
                    $this->tags    = static::extractHashtags($this->messageBody['caption'], $this->messageBody['caption_entities']);
                }
                else
                {
                    $this->caption = $this->messageBody['caption'];
                }
            }

            if (isset($this->messageBody['media_group_id']))
            {
                $this->mediaGroupId = $this->messageBody['media_group_id'];
            }

            if (!$this->mediaGroupId)
            {
                $this->mediaGroupId = static::$snowflake->id();
            }

            $this->senderId           = $this->messageBody['chat']['id'];
            $this->chatSourceType     = $this->messageBody['chat']['type'];
            $this->chatSourceUsername = $this->messageBody['chat']['username'];
            $this->date               = $this->messageBody['date'];
            $this->messageId          = $this->messageBody['message_id'];

            $this->updateId = $this->messageRow['update_id'];

            return $this;
        }

        public function isNeededType(): bool
        {
            return in_array($this->messageLoadType, [
                    static::MSG_CARRIER_TYPE_TEXT,
                    static::MSG_CARRIER_TYPE_VIDEO,
                    static::MSG_CARRIER_TYPE_PHOTO,
                    static::MSG_CARRIER_TYPE_AUDIO,
                    static::MSG_CARRIER_TYPE_DOCUMENT,
                ]) and in_array($this->messageFromType, [
                    static::MSG_FROM_TYPE_MESSAGE,
                    static::MSG_FROM_TYPE_EDITED_MESSAGE,
                    static::MSG_FROM_TYPE_CHANNEL_POST,
                    static::MSG_FROM_TYPE_EDITED_CHANNEL_POST,
                ]);
        }

        protected static function getExt(array $msgBody, string $default = '-'): string
        {
            if (isset($msgBody['mime_type']) && isset(static::$mimeTypesMap[$msgBody['mime_type']]))
            {
                $ext = static::$mimeTypesMap[$msgBody['mime_type']];
            }
            elseif (isset($msgBody['file_name']))
            {
                $ext = pathinfo($msgBody['file_name'], PATHINFO_EXTENSION);
            }
            else
            {
                $ext = $default;
            }

            return strtolower($ext);
        }

        protected static function getMimeType(array $msgBody, string $default = '-'): string
        {
            return $msgBody['mime_type'] ?? $default;
        }

        // Function to calculate the length of a string in UTF-16 code points.
        protected static function mbStrlen(string $text): int
        {
            $length     = 0;
            $textlength = \strlen($text);
            for ($x = 0; $x < $textlength; $x++)
            {
                $char = \ord($text[$x]);
                if (($char & 0xc0) != 0x80)
                {
                    $length += 1 + ($char >= 0xf0 ? 1 : 0);
                }
            }

            return $length;
        }

        // Function to substring a string based on UTF-16 encoding.
        protected static function mbSubstr(string $text, int $offset, ?int $length = null): string
        {
            /** @var string */
            $converted = \mb_convert_encoding($text, 'UTF-16');

            /** @var string */
            return \mb_convert_encoding(\substr($converted, $offset << 1, $length === null ? null : ($length << 1),), 'UTF-8', 'UTF-16',);
        }

        // Main function to parse text with entities.
        protected static function parseCaptionWithEntities($text, $caption_entities): string
        {
            $result     = [];
            $lastOffset = 0;

            foreach ($caption_entities as $entity)
            {
                if ($entity['type'] === 'text_link')
                {
                    // Append text before this entity
                    $start    = $entity['offset'];
                    $length   = $entity['length'];
                    $result[] = self::mbSubstr($text, $lastOffset, $start - $lastOffset);

                    // Append the link text
                    $linkText = self::mbSubstr($text, $start, $length);
                    $result[] = "{<$linkText><{$entity['url']}>}";

                    // Update last offset
                    $lastOffset = $start + $length;
                }
            }

            // Append any remaining text after the last entity
            if ($lastOffset < self::mbStrlen($text))
            {
                $result[] = self::mbSubstr($text, $lastOffset);
            }

            return implode('', $result);
        }

        protected function extractHashtags($caption, $caption_entities): array
        {
            // 初始化保存hashtag文本的数组
            $hashtags = [];

            // 遍历所有实体
            foreach ($caption_entities as $entity)
            {
                // 检查实体类型是否为hashtag
                if ($entity['type'] === 'hashtag')
                {
                    // 使用mbSubstr方法获取hashtag的文本
                    $hashtagLength = $entity['length'];
                    $hashtagOffset = $entity['offset'];

                    // 获取hashtag文本
                    $hashtag    = self::mbSubstr($caption, $hashtagOffset, $hashtagLength);
                    $hashtags[] = $hashtag;
                }
            }

            return $hashtags;
        }
    }




