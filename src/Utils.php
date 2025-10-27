<?php

    namespace Coco\tgMedia;

    class Utils
    {
        public static function truncateUtf8String($string, $length): string
        {
            // 使用 mb_substr 来截取字符串，确保是按字符而非字节截取
            return mb_substr($string, 0, $length, 'UTF-8');
        }

        public static function inlineText(string $subject): array|string|null
        {
            $result = preg_replace('#\s+#iu', ' ', $subject);
            $result = static::cleanText($result);

            return $result;
        }

        public static function cleanText(string $subject): array|string|null
        {
            $result = trim($subject);

            //连续两个以上的换行符减少为两个
            $result = preg_replace('/\n{3,}/im', "\n\n", $result);

            //最前面的问号去掉
            $result = preg_replace('/^[\?\s]+/ium', '', $result);

            //最后的问号如果个数超过2个，最多保留一个
            $result = preg_replace('/\?{2,}$/ium', '?', $result);

            //中间的问号，最多保留一个
            $result = preg_replace('/\?{2,}/ium', '?', $result);

            //连续空格最多保留一个
            $result = preg_replace('/[ \t]+/ium', ' ', $result);

            return $result;
        }

        public static function formatBytes($bytes): string
        {
            $units = [
                'B',
                'KB',
                'MB',
                'GB',
                'TB',
            ];

            $unit = 0;
            while ($bytes >= 1024 && $unit < count($units) - 1)
            {
                $bytes /= 1024;
                $unit++;
            }

            return sprintf("%.2f %s", $bytes, $units[$unit]);
        }

        public static function convertToBytes(string $size): float|int
        {
            // 使用正则表达式匹配数值和单位
            if (preg_match('/^(\d+)([KMGT])B?$/i', $size, $matches))
            {
                $value = $matches[1];             // 数值部分
                $unit  = strtoupper($matches[2]); // 单位部分，转为大写以便统一处理

                // 根据单位进行转换
                switch ($unit)
                {
                    case 'K':
                        return $value * 1024; // KB -> 字节
                    case 'M':
                        return $value * 1024 * 1024; // MB -> 字节
                    case 'G':
                        return $value * 1024 * 1024 * 1024; // GB -> 字节
                    case 'T':
                        return $value * 1024 * 1024 * 1024 * 1024; // TB -> 字节
                    default:
                        return 0; // 不支持的单位
                }
            }
            elseif (is_numeric($size))
            {
                return $size;
            }
            else
            {
                // 如果不匹配，返回 0 或抛出异常
                return 0;
            }
        }
    }
