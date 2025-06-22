<?php

    declare(strict_types = 1);

    namespace Coco\tgMedia;

    use Coco\queue\missionProcessors\CallableMissionProcessor;
    use Coco\queue\missionProcessors\GuzzleMissionProcessor;
    use Coco\queue\missions\CallableMission;
    use Coco\queue\missions\HttpMission;
    use Coco\queue\resultProcessor\CustomResultProcessor;
    use DI\Container;
    use GuzzleHttp\Client;

    use Symfony\Component\Cache\Adapter\RedisAdapter;
    use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
    use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
    use Symfony\Component\Finder\Finder;

    use Coco\commandBuilder\command\Curl;
    use Coco\commandRunner\DaemonLauncher;
    use Coco\commandRunner\Launcher;

    use Coco\scanner\abstract\MakerAbastact;
    use Coco\scanner\LoopScanner;
    use Coco\scanner\LoopTool;
    use Coco\scanner\maker\CallbackMaker;
    use Coco\scanner\maker\FilesystemMaker;
    use Coco\scanner\processor\CallbackProcessor;

    use Coco\queue\MissionManager;
    use Coco\queue\Queue;

    use Coco\tableManager\TableRegistry;

    use Coco\tgMedia\tables\Message;
    use Coco\tgMedia\tables\Type;
    use Coco\tgMedia\tables\File;
    use Coco\tgMedia\tables\Post;

    class Manager
    {
        public MissionManager $queueMissionManager;
        protected ?Container  $container       = null;
        protected bool       $debug           = false;
        protected bool       $enableRedisLog  = false;
        protected bool       $enableEchoLog   = false;
        protected array      $tables          = [];
        protected array      $contentsFilters = [];

        //如果开启，一个消息中所有媒体都下载完成才会写入file，关闭的话，下载一个写入一个
        protected bool   $strictMode    = true;
        protected string $redisHost     = '127.0.0.1';
        protected string $redisPassword = '';
        protected int    $redisPort     = 6379;
        protected int    $redisDb       = 9;

        protected string $mysqlDb;
        protected string $mysqlHost                = '127.0.0.1';
        protected string $mysqlUsername            = 'root';
        protected string $mysqlPassword            = 'root';
        protected int    $mysqlPort                = 3306;
        protected int    $telegramMediaMaxFileSize = 1024 * 1024 * 200;

        protected ?string $mediaOwner            = 'www';
        protected         $beforePostFilesInsert = null;

        protected ?string $messageTableName = null;
        protected ?string $postTableName    = null;
        protected ?string $fileTableName    = null;
        protected ?string $typeTableName    = null;

        protected ?string $logNamespace;
        protected ?string $cacheNamespace;

        protected ?string $telegramTempJsonPath               = null;
        public ?string    $telegramMediaStorePath             = null;
        protected ?string $telegramBotApiPath                 = null;
        protected int     $telegramMediaMaxDownloading        = 10;
        protected int     $telegramMediaMaxDownloadTimeout    = 360000;
        protected int     $telegramMediaDownloadDelayInSecond = 1;
        protected ?string $telegramWebHookUrl                 = null;

        protected int     $scanDelayMs    = 3000;

        protected int $localServerPort = 8081;
        protected int $statisticsPort  = 8082;

        const FILE_STATUS_0_WAITING_DOWNLOAD = 0;
        const FILE_STATUS_1_DOWNLOADING      = 1;
        const FILE_STATUS_2_MOVED            = 2;
        const FILE_STATUS_3_IN_POSTED        = 3;

        const CDN_PREFETCH_QUEUE     = 'CDN_PREFETCH';
        const MAKE_VIDEO_COVER_QUEUE = 'MAKE_VIDEO_COVER';
        const CONVERT_M3U8_QUEUE     = 'CONVERT_M3U8';

        public Queue $cdnPrefetchQueue;
        public Queue $makeVideoCoverQueue;
        public Queue $convertM3u8Queue;

        protected array $whereFileStatus0WaitingDownload;
        protected array $whereFileStatus1Downloading;
        protected array $whereFileStatus2FileMoved;
        protected array $whereFileStatus3InPosted;

        protected string $cacheTypes;
        protected string $scannerDownloadMedia;
        protected string $scannerFileMove;
        protected string $scannerMigration;
        protected string $downloadLockKey;

        public function __construct(protected string $bootToken, protected string $apiId, protected string $apiHash, protected string $basePath, protected string $redisNamespace, ?Container $container = null)
        {
            static::envCheck();

            if (!is_null($container))
            {
                $this->container = $container;
            }
            else
            {
                $this->container = new Container();
            }

            $this->logNamespace   = $this->redisNamespace . '-log:';
            $this->cacheNamespace = $this->redisNamespace . '-cache';

            $this->basePath = rtrim($this->basePath, '/');
            is_dir($this->basePath) or mkdir($this->basePath, 0777, true);
            $this->basePath = realpath($this->basePath) . '/';

            $this->telegramTempJsonPath   = $this->basePath . 'json';
            $this->telegramMediaStorePath = $this->basePath . 'media';
            $this->telegramBotApiPath     = $this->basePath . 'telegramBotApi';

            $this->scannerDownloadMedia = $this->redisNamespace . ':scanner:' . 'download_media';
            $this->scannerFileMove      = $this->redisNamespace . ':scanner:' . 'file_move';
            $this->scannerMigration     = $this->redisNamespace . ':scanner:' . 'migration';

            $this->cacheTypes      = 'telegraph_types';
            $this->downloadLockKey = $this->redisNamespace . '-download_lock_key';
        }

        /*
         *
         * ------------------------------------------------------
         * telegram 资源下载
         * ------------------------------------------------------
         *
         */

        public function isDebug(): bool
        {
            return $this->debug;
        }

        public function initServer(): static
        {
            $this->addContentsFilters(static::baseContentsFilter());
            $this->initMissionManager();
            $this->initRedis();
            $this->initMysql();
            $this->initTheDownloadMediaScanner();
            $this->initTheFileMoveScanner();
            $this->initTheMigrationScanner();
            $this->initTelegramBotApi();
            $this->initTelegramApiGuzzle();

            return $this;
        }

        public function initCommonProperty(): static
        {
            $msgTable = $this->getMessageTable();

            $this->whereFileStatus0WaitingDownload = [
                [
                    $msgTable->getFileStatusField(),
                    '=',
                    static::FILE_STATUS_0_WAITING_DOWNLOAD,
                ],
            ];
            $this->whereFileStatus1Downloading     = [
                [
                    $msgTable->getFileStatusField(),
                    '=',
                    static::FILE_STATUS_1_DOWNLOADING,
                ],
            ];
            $this->whereFileStatus2FileMoved       = [
                [
                    $msgTable->getFileStatusField(),
                    '=',
                    static::FILE_STATUS_2_MOVED,
                ],
            ];
            $this->whereFileStatus3InPosted        = [
                [
                    $msgTable->getFileStatusField(),
                    '=',
                    static::FILE_STATUS_3_IN_POSTED,
                ],
            ];

            return $this;
        }

        public function setDebug(bool $debug): void
        {
            $this->debug = $debug;
        }

        public function setEnableEchoLog(bool $enableEchoLog): static
        {
            $this->enableEchoLog = $enableEchoLog;

            return $this;
        }

        public function setEnableRedisLog(bool $enableRedisLog): static
        {
            $this->enableRedisLog = $enableRedisLog;

            return $this;
        }

        public function setRedisConfig(string $host = '127.0.0.1', string $password = '', int $port = 6379, int $db = 9): static
        {
            $this->redisHost     = $host;
            $this->redisPassword = $password;
            $this->redisPort     = $port;
            $this->redisDb       = $db;

            return $this;
        }

        public function setMysqlConfig($db, $host = '127.0.0.1', $username = 'root', $password = 'root', $port = 3306): static
        {
            $this->mysqlHost     = $host;
            $this->mysqlPassword = $password;
            $this->mysqlUsername = $username;
            $this->mysqlPort     = $port;
            $this->mysqlDb       = $db;

            return $this;
        }

        public function getTelegramTempJsonPath(): ?string
        {
            return $this->telegramTempJsonPath;
        }

        public function setTelegramMediaStorePath(?string $telegramMediaStorePath): static
        {
            $this->telegramMediaStorePath = $telegramMediaStorePath;

            return $this;
        }

        public function getTelegramMediaStorePath(): ?string
        {
            return $this->telegramMediaStorePath;
        }

        public function getBootToken(): string
        {
            return $this->bootToken;
        }

        public function getBootId(): int
        {
            [
                $id,
                $_,
            ] = explode(':', $this->bootToken);

            return (int)$id;
        }

        public function setTelegramMediaMaxDownloading(int $telegramMediaMaxDownloading): static
        {
            $this->telegramMediaMaxDownloading = $telegramMediaMaxDownloading;

            return $this;
        }

        public function setTelegramMediaDownloadDelayInSecond(int $telegramMediaDownloadDelayInSecond): static
        {
            $this->telegramMediaDownloadDelayInSecond = $telegramMediaDownloadDelayInSecond;

            return $this;
        }

        public function setTelegramMediaMaxDownloadTimeout(int $telegramMediaMaxDownloadTimeout): static
        {
            $this->telegramMediaMaxDownloadTimeout = $telegramMediaMaxDownloadTimeout;

            return $this;
        }

        public function setTelegramWebHookUrl(?string $telegramWebHookUrl): static
        {
            $this->telegramWebHookUrl = $telegramWebHookUrl;

            return $this;
        }

        public function setMediaOwner(?string $mediaOwner): static
        {
            $this->mediaOwner = $mediaOwner;

            return $this;
        }

        public function getTypeIdBySender($sender): int
        {
            $typeMap = $this->getTypes();

            $map = [];
            foreach ($typeMap as $k => $v)
            {
                $map[$v[$this->getTypeTable()->getGroupIdField()]] = $v[$this->getTypeTable()->getPkField()];
            }

            if (isset($map[$sender]))
            {
                return $map[$sender];
            }

            return -1;
        }

        public function setLocalServerPort(int $localServerPort): static
        {
            $this->localServerPort = $localServerPort;

            return $this;
        }

        public function setStatisticsPort(int $statisticsPort): static
        {
            $this->statisticsPort = $statisticsPort;

            return $this;
        }

        public function setTelegramMediaMaxFileSize(int $telegramMediaMaxFileSize): static
        {
            $this->telegramMediaMaxFileSize = $telegramMediaMaxFileSize;

            return $this;
        }

        public function setBeforePostFilesInsert(callable $beforePostFilesInsert): static
        {
            $this->beforePostFilesInsert = $beforePostFilesInsert;

            return $this;
        }

        public function setStrictMode(bool $strictMode): static
        {
            $this->strictMode = $strictMode;

            return $this;
        }

        public function addContentsFilters(callable $contentsFilters): static
        {
            $this->contentsFilters[] = $contentsFilters;

            return $this;
        }

        public function getContainer(): Container
        {
            return $this->container;
        }

        /*
         * ---------------------------------------------------------
         * */

        protected function initTheDownloadMediaScanner(): static
        {
            is_dir($this->telegramTempJsonPath) or mkdir($this->telegramTempJsonPath, 0777, true);
            if (!is_dir($this->telegramTempJsonPath))
            {
                throw new \Exception('文件夹不存在：' . $this->telegramTempJsonPath);
            }

            $this->initDownloadMediaMaker();
            $this->initDownloadMediaScanner();

            return $this;
        }

        protected function initTheFileMoveScanner(): static
        {
            is_dir($this->telegramMediaStorePath) or mkdir($this->telegramMediaStorePath, 0777, true);
            if (!is_dir($this->telegramMediaStorePath))
            {
                throw new \Exception('文件夹不存在：' . $this->telegramMediaStorePath);
            }

            $this->initToFileMoveMaker($this->telegramTempJsonPath);
            $this->initToFileMoveScanner();

            return $this;
        }

        protected function initTheMigrationScanner(): static
        {
            $this->initMigrationMaker();
            $this->initMigrationScanner();

            return $this;
        }

        /*
         * ---------------------------------------------------------
         * */

        /**
         * 扫描库中的 updates, 获取 getFileStatusField 为 0 的记录，然后推入队列，再把 getFileStatusField 改为 1
         * 下载一次就把 getDownloadTimesField 加 1,开始下载时更新 download_time 时间
         * getFileIdField 可能会有重复的，即多个 updates 指向同一个文件
         *
         * 0:未下载文件，1:下载中，2:下载完成移动到指定位置中，3:移动完成
         *
         * @return $this
         */
        protected function initDownloadMediaMaker(): static
        {
            $this->container->set('downloadMediaMaker', function(Container $container) {

                /*-------------------------------------------*/
                $maker = new CallbackMaker(function() {

                    if ($this->isTelegramBotApiStarted())
                    {
                        $this->getDownloadMediaScanner()->logInfo('API服务正常');
                    }
                    else
                    {
                        $this->getDownloadMediaScanner()->logInfo('【------- API服务熄火 -------】');

                        return [];
                    }

                    while ($this->getRedisClient()->get($this->downloadLockKey))
                    {
                        $this->getDownloadMediaScanner()->logInfo('锁定中，等待...');
                        usleep(1000 * 250);
                    }

                    if ($this->telegramMediaMaxDownloading < 1)
                    {
                        $this->telegramMediaMaxDownloading = 1;
                    }

                    $msgTable = $this->getMessageTable();

                    //没有文件的信息直接设置状态为2，不用处理文件
                    //getFileIdField 为空，并且getFileStatusField为0的
                    $msgTable->tableIns()->where($msgTable->getFileIdField(), '=', '')
                        ->where($this->whereFileStatus0WaitingDownload)->update([
                            $msgTable->getFileStatusField() => static::FILE_STATUS_2_MOVED,
                        ]);

                    //下载超时失败的任务，重置状态后继续下载
                    $msgTable->tableIns()
                        ->where($msgTable->getDownloadTimeField(), '<', time() - $this->telegramMediaMaxDownloadTimeout)
                        ->where($this->whereFileStatus1Downloading)->update([
                            $msgTable->getFileStatusField()   => static::FILE_STATUS_0_WAITING_DOWNLOAD,
                            $msgTable->getDownloadTimeField() => 0,
                        ]);

                    /**
                     * ---------------------------------------
                     * 先获取正在下载的任务个数,限制最多只能同时下载多少个任务
                     * ---------------------------------------
                     */

                    $downloadingFiles = $this->getInDownloadingFiles();

                    $downloading = count($downloadingFiles);

                    $downloadingMessageIds = [];

                    foreach ($downloadingFiles as $k => $v)
                    {
                        $downloadingMessageIds[] = $v[$msgTable->getPkField()];
                    }

                    /**
                     * ---------------------------------------
                     * 剩余待下载任务数
                     * ---------------------------------------
                     */
                    $downloadingRemain = $this->getFileStatus0Count();

                    $this->getDownloadMediaScanner()
                        ->logInfo('正在下载任务数：' . $downloading . '，剩余：' . $downloadingRemain);

                    $this->getDownloadMediaScanner()
                        ->logInfo('正在下载任务ids：' . PHP_EOL . implode(PHP_EOL, $downloadingMessageIds) . PHP_EOL);

                    //如果正在下载的任务大于等于最大限制
                    if ($downloading >= $this->telegramMediaMaxDownloading)
                    {
                        $this->getDownloadMediaScanner()->logInfo('正在下载任务数大于等于设定最大值，暂停下载');

                        return [];
                    }

                    /**
                     * ---------------------------------------
                     * 获取需要下载文件的 file_id
                     * ---------------------------------------
                     */

                    /*
                     * getFileStatusField 为 0,并且 path 为空，每次获取 maxDownloading 个
                     * 文件超过 telegramMediaMaxFileSize 的不下载
                     *
                     * --------------*/
                    $data = $msgTable->tableIns()->field(implode(',', [
                        $msgTable->getPkField(),
                        $msgTable->getFileIdField(),
                        $msgTable->getDownloadTimeField(),
                        $msgTable->getFileSizeField(),
                    ]))->where($this->whereFileStatus0WaitingDownload)
                        ->where($msgTable->getFileSizeField(), '<', $this->telegramMediaMaxFileSize)
                        ->limit(0, $this->telegramMediaMaxDownloading - $downloading)->order($msgTable->getPkField())
                        ->select();

                    $data = $data->toArray();

                    /**
                     * ---------------------------------------
                     * 最终需要下载的文件
                     * ---------------------------------------
                     */

                    $ids = [];

                    foreach ($data as $k => $v)
                    {
                        $ids[] = $v[$msgTable->getPkField()];
                    }

                    $this->getDownloadMediaScanner()->logInfo(count($ids) . '个文件写入下载');

                    //更新下载状态和开始下载时间
                    $timeNow = time();
                    $msgTable->tableIns()->where($msgTable->getPkField(), 'in', $ids)->update([
                        $msgTable->getFileStatusField()   => static::FILE_STATUS_1_DOWNLOADING,
                        $msgTable->getDownloadTimeField() => $timeNow,
                    ]);

                    //更新下载次数
                    $msgTable->tableIns()->where($msgTable->getPkField(), 'in', $ids)
                        ->inc($msgTable->getDownloadTimesField(), 1)->update();

                    return $data;
                });

                /*-------------------------------------------*/
                $maker->init(function(CallbackMaker $maker_) {
                });

                /*-------------------------------------------*/

                $processorCallback = function($data, CallbackMaker $maker_) {

                    foreach ($data as $k => $item)
                    {
                        $file_id    = $item['file_id'];
                        $apiUrl     = $this->resolveEndponit('getFile', [
                            "file_id" => $file_id,
                        ]);
                        $outputPath = $this->telegramTempJsonPath . DIRECTORY_SEPARATOR . $item['id'] . '.json';

                        $command = Curl::getIns();
                        $command->silent();
                        $command->outputToFile(escapeshellarg($outputPath));
                        $command->setMaxTime($this->telegramMediaMaxDownloadTimeout);
                        $command->url(escapeshellarg($apiUrl));

                        $launcher = new Launcher((string)$command);

                        $logName = 'curl-launcher';
                        $launcher->setStandardLogger($logName);
                        if ($this->enableRedisLog)
                        {
                            $launcher->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
                        }

                        if ($this->enableEchoLog)
                        {
                            $launcher->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
                        }

                        $launcher->launch();
                    }
                };

                $maker->addProcessor(new CallbackProcessor($processorCallback));

                return $maker;
            });

            return $this;
        }

        protected function getDownloadMediaMaker()
        {
            return $this->container->get('downloadMediaMaker');
        }

        protected function initDownloadMediaScanner(): static
        {
            $this->container->set('downloadMediaScanner', function(Container $container) {
                $scanner = new LoopScanner(host: $this->redisHost, password: $this->redisPassword, port: $this->redisPort, db: $this->redisDb);
                $scanner->setDelayMs($this->scanDelayMs);
                $scanner->setName($this->scannerDownloadMedia);

                $logName = 'te-loopScanner-download-media';
                $scanner->setStandardLogger($logName);
                if ($this->enableRedisLog)
                {
                    $scanner->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
                }

                if ($this->enableEchoLog)
                {
                    $scanner->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
                }

                return $scanner;
            });

            return $this;
        }

        public function getDownloadMediaScanner(): LoopScanner
        {
            return $this->container->get('downloadMediaScanner');
        }

        public function scanAndDownload(): void
        {
            $this->getDownloadMediaScanner()->setMaker($this->getDownloadMediaMaker())->listen();
        }

        public function stopDownloadMedia(): void
        {
            LoopTool::getIns(host: $this->redisHost, password: $this->redisPassword, port: $this->redisPort, db: $this->redisDb)
                ->stop($this->scannerDownloadMedia);
        }



        /**
         * 扫描curl请求完成的结果 json，读取文件中的内容，将文件移动到指定文件夹，更新 path，file_status
         * file_id 可能重复
         *
         * @param string $destPath
         *
         * @return $this
         */
        protected function initToFileMoveMaker(string $destPath): static
        {
            $this->container->set('toFileMoveMaker', function(Container $container) use ($destPath) {

                $maker = new FilesystemMaker($destPath);
                $maker->init(function(string $path, Finder $finder) {

                    is_dir($path) or mkdir($path, 777, true);

                    $finder->depth('< 1')->in($path)->files();
                });

                $maker->addProcessor(new CallbackProcessor(function(Finder $finder, FilesystemMaker $maker_) {
                    $msgTable = $this->getMessageTable();

                    $this->getToFileMoveScanner()->logInfo('json 文件数量：' . count($finder));

                    foreach ($finder as $k => $pathName)
                    {
                        $fullSourcePath = $pathName->getRealPath();

                        $id = pathinfo($fullSourcePath, PATHINFO_FILENAME);

                        $json = file_get_contents($fullSourcePath);

                        $jsonInfo = json_decode($json, true);

                        //有时候json文件是空的,删除json文件，更新状态为0，这个id重新下载
                        if (!$jsonInfo)
                        {
                            $this->getToFileMoveScanner()->logInfo('文件为空，删除：' . $fullSourcePath);
                            $this->getToFileMoveScanner()
                                ->logInfo('暂停' . $this->telegramMediaDownloadDelayInSecond . '秒');

                            $this->getRedisClient()
                                ->setex($this->downloadLockKey, $this->telegramMediaDownloadDelayInSecond, 1);

                            unlink($fullSourcePath);

                            $msgTable->tableIns()->where($msgTable->getPkField(), '=', $id)->update([
                                $msgTable->getFileStatusField() => static::FILE_STATUS_0_WAITING_DOWNLOAD,
                            ]);

                            return;
                        }

                        if ($jsonInfo['ok'] !== true)
                        {
                            $this->getToFileMoveScanner()->logInfo('json 出错：' . $json);

                            unlink($fullSourcePath);

                            $msgTable->tableIns()->where($msgTable->getPkField(), '=', $id)->update([
                                $msgTable->getFileStatusField() => static::FILE_STATUS_0_WAITING_DOWNLOAD,
                            ]);

                            continue;
                        }

                        // /www/wwwroot/tg-bot-server/data/6026303590:AAGvMcaxTRBbcPxs_ShGu-G4CffyCyI_6Ek/videos/file_8
                        $source = $jsonInfo['result']['file_path'];

                        $updateInfo = $msgTable->tableIns()->where($msgTable->getPkField(), $id)->find();

                        // videos
                        $fileType =  explode('/',  $updateInfo[$msgTable->getMimeTypeField()])[0];

                        $targetPath = static::makePath($jsonInfo['result']['file_id'], $fileType) . '.' . $updateInfo[$msgTable->getExtField()];

                        // /var/www/6025/new/coco-tgDownloader/examples/data/mediaStore/2024-10/photos/A/AQADdrcxGxbnIFR-.jpg
                        $target = rtrim($this->telegramMediaStorePath) . '/' . ltrim($targetPath);

                        is_dir(dirname($target)) or mkdir(dirname($target), 0777, true);

                        $this->getToFileMoveScanner()->logInfo('移动：' . $source . ' -> ' . $target);

                        //下载的媒体文件不存在
                        if (!is_file($source))
                        {
                            //如果文件不存在，查看是否有相同 file_id 文件在数据库中，有直接指向
                            //由于file_id 可能重复，可能文件之前被移走，被更新到数据库中
                            //这种情况直接把数据库中同file_id 的path 更新过来就行

                            //查找同 file_id 记录的有没有已经存在的path
                            $path = $msgTable->tableIns()
                                ->where($msgTable->getFileIdField(), '=', $jsonInfo['result']['file_id'])
                                ->where($msgTable->getPathField(), '<>', '')->value($msgTable->getPathField());

                            if ($path)
                            {
                                $this->getToFileMoveScanner()->logInfo('源文件重复：' . $source);

                                //如果有的话，把所有同 file_id 的 path 都更新
                                $data = [
                                    $msgTable->getFileStatusField() => static::FILE_STATUS_2_MOVED,
                                    $msgTable->getPathField()       => $path,
                                ];

                                $res = $msgTable->tableIns()
                                    ->where($msgTable->getFileIdField(), '=', $jsonInfo['result']['file_id'])
                                    ->where($msgTable->getPathField(), '=', '')
                                    ->update($data);

                                $this->getToFileMoveScanner()->logInfo('更新重复文件 path：' . $res);
                                $this->getToFileMoveScanner()->logInfo('删除：' . $fullSourcePath);
                            }

                            unlink($fullSourcePath);
                        }
                        else
                        {
                            if (rename($source, $target))
                            {
                                @chmod($target, 0777);
                                @chown($target, $this->mediaOwner);

                                //移动成功
                                $this->getToFileMoveScanner()
                                    ->logInfo('更新：' . $updateInfo[$msgTable->getPkField()] . '->' . $target);

                                $data = [
                                    $msgTable->getPathField()       => $targetPath,
                                    $msgTable->getFileStatusField() => static::FILE_STATUS_2_MOVED,
                                ];

                                $res = $msgTable->tableIns()
                                    ->where($msgTable->getPkField(), $updateInfo[$msgTable->getPkField()])
                                    ->update($data);

                                if ($res)
                                {
                                    $this->getToFileMoveScanner()->logInfo('删除：' . $fullSourcePath);

                                    unlink($fullSourcePath);
                                }
                                else
                                {
                                    $this->getToFileMoveScanner()
                                        ->logError('更新失败：' . $updateInfo[$msgTable->getPkField()] . '->' . $target);
                                }
                            }
                            else
                            {
                                $this->getToFileMoveScanner()->logError('文件 rename 失败：' . $source . '->' . $target);

                                unlink($fullSourcePath);

                                $msgTable->tableIns()->where($msgTable->getPkField(), '=', $id)->update([
                                    $msgTable->getFileStatusField() => static::FILE_STATUS_0_WAITING_DOWNLOAD,
                                ]);

                            }
                        }
                    }

                }));

                return $maker;
            });

            return $this;
        }

        protected function getToFileMoveMaker(): MakerAbastact
        {
            return $this->container->get('toFileMoveMaker');
        }

        protected function initToFileMoveScanner(): static
        {
            $this->container->set('toFileMove', function(Container $container) {

                $scanner = new LoopScanner(host: $this->redisHost, password: $this->redisPassword, port: $this->redisPort, db: $this->redisDb);
                $scanner->setDelayMs($this->scanDelayMs);
                $scanner->setName($this->scannerFileMove);

                $logName = 'te-loopScanner-to-file-move';
                $scanner->setStandardLogger($logName);
                if ($this->enableRedisLog)
                {
                    $scanner->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
                }

                if ($this->enableEchoLog)
                {
                    $scanner->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
                }

                return $scanner;
            });

            return $this;
        }

        public function getToFileMoveScanner(): LoopScanner
        {
            return $this->container->get('toFileMove');
        }

        public function scanAndMoveFile(): void
        {
            $this->getToFileMoveScanner()->setMaker($this->getToFileMoveMaker())->listen();
        }

        public function stopFileMove(): void
        {
            LoopTool::getIns(host: $this->redisHost, password: $this->redisPassword, port: $this->redisPort, db: $this->redisDb)
                ->stop($this->scannerFileMove);
        }



        /**
         * 扫描状态为 2 的记录，根据media_group_id分组，media的个数等于redis里保存的个数说明文件都处理完了，
         * 先把文件 path 写入 file 表
         * 再把 caption 写入post表
         *
         * @return $this
         */
        public function initMigrationMaker(): static
        {
            $this->container->set('migrationMaker', function(Container $container) {
                $msgTable  = $this->getMessageTable();
                $postTable = $this->getPostTable();
                $typeTable = $this->getTypeTable();
                $fileTable = $this->getFileTable();

                /*-------------------------------------------*/
                $maker = new CallbackMaker(function() use (
                    $msgTable, $postTable, $typeTable, $fileTable
                ) {

                    /**
                     * ---------------------------------------
                     * 获取message
                     * ---------------------------------------
                     */

                    /*
                     * getFileStatusField 为 2
                     *
                     * --------------*/

                    $data = $msgTable->tableIns()->where($this->whereFileStatus2FileMoved)
                        //->limit(0, 500)
                        ->order($msgTable->getPkField())->select();
                    $data = $data->toArray();

                    $message_group = [];
                    $result        = [];

                    //根据media_group_id 分组
                    foreach ($data as $k => $v)
                    {
                        if (!isset($message_group[$v[$msgTable->getMediaGroupIdField()]]))
                        {
                            $message_group[$v[$msgTable->getMediaGroupIdField()]] = [];
                        }
                        $message_group[$v[$msgTable->getMediaGroupIdField()]][] = $v;
                    }

                    $this->getMigrationScanner()
                        ->logInfo(count($message_group) . '组 message_group，严格模式' . ($this->strictMode ? '【开启】' : '【关闭】'));

                    foreach ($message_group as $group_id => $messages)
                    {
                        //如果开启严格模式，三流程必须一个一个来，不能同时进行
                        if ($this->strictMode)
                        {
                            //这个消息一共应该有几个媒体
                            $totalMediaCount = $this->getMediaGroupCount($group_id);

                            //当前已经写到下载完写入库几个有媒体
                            $currentHasMediaCount = 0;
                            foreach ($messages as $k => $message)
                            {
                                if ($message[$msgTable->getFileUniqueIdField()])
                                {
                                    $currentHasMediaCount++;
                                }
                            }

                            $this->getMigrationScanner()
                                ->logInfo($group_id . ' - 此消息共有媒体:' . $totalMediaCount . ',已经下载媒体:' . $currentHasMediaCount);

                            //如果相等说明一条消息中所有媒体已经下载完
                            if ($currentHasMediaCount >= $totalMediaCount)
                            {
                                $result[$group_id] = $messages;
                            }
                        }
                        else
                        {
                            //文件已经下载好写入了数据库，但是redis被清除，就关闭严格模式，将下载的文件写入post表
                            //在没写入完时，不要更新页面，否则文章可能会少媒体文件
                            $result[$group_id] = $messages;
                        }
                    }

                    $this->getMigrationScanner()->logInfo(count($result) . '个 message_group 信息待写入');

                    return $result;
                });

                /*-------------------------------------------*/
                $maker->init(function(CallbackMaker $maker_) {

                });

                /*-------------------------------------------*/
                $maker->addProcessor(new CallbackProcessor(function($data, CallbackMaker $maker_) use (
                    $msgTable, $postTable, $typeTable, $fileTable
                ) {

                    foreach ($data as $mediaGroupId => $item)
                    {
                        $ids   = [];
                        $files = [];

                        $baseMessageInfo = $item[0];

                        //计算出文本信息
                        $content = '';
                        foreach ($item as $k => $messageInfo)
                        {
                            if ($messageInfo[$msgTable->getCaptionField()])
                            {
                                $content = $messageInfo[$msgTable->getCaptionField()];
                                break;
                            }

                            if ($messageInfo[$msgTable->getTextField()])
                            {
                                $content = $messageInfo[$msgTable->getTextField()];
                                break;
                            }
                        }

                        $this->getMigrationScanner()->logInfo('原始信息:' . static::inlineText($content));

                        foreach ($this->contentsFilters as $k => $filter)
                        {
                            $content = $filter($content);
                        }

                        $this->getMigrationScanner()->logInfo('过滤后信息:' . static::inlineText($content));

                        $postId = $postTable->calcPk();
                        //构造文件数组，写入文件表
                        foreach ($item as $k => $messageInfo)
                        {
                            if ($messageInfo[$msgTable->getFileIdField()])
                            {
                                $files[] = [
                                    $fileTable->getPkField()             => $fileTable->calcPk(),
                                    $fileTable->getPostIdField()         => $postId,
                                    $fileTable->getPathField()           => $messageInfo[$msgTable->getPathField()],
                                    $fileTable->getFileSizeField()       => $messageInfo[$msgTable->getFileSizeField()],
                                    $fileTable->getFileNameField()       => $messageInfo[$msgTable->getFileNameField()],
                                    $fileTable->getExtField()            => $messageInfo[$msgTable->getExtField()],
                                    $fileTable->getMimeTypeField()       => $messageInfo[$msgTable->getMimeTypeField()],
                                    $fileTable->getOriginExtField()      => $messageInfo[$msgTable->getExtField()],
                                    $fileTable->getOriginMimeTypeField() => $messageInfo[$msgTable->getMimeTypeField()],
                                    $fileTable->getBotIdField()          => $messageInfo[$msgTable->getBotIdField()],
                                    $fileTable->getFileIdField()         => $messageInfo[$msgTable->getFileIdField()],
                                    $fileTable->getFileUniqueIdField()   => $messageInfo[$msgTable->getFileUniqueIdField()],
                                    $fileTable->getMediaGroupIdField()   => $mediaGroupId,
                                    $fileTable->getTimeField()           => time(),
                                ];
                            }

                            $ids[] = $messageInfo[$msgTable->getPkField()];
                        }

                        $this->getMigrationScanner()
                            ->logInfo("mediaGroupId: $mediaGroupId: " . count($files) . "个文件，内容: $content");

                        if (count($files))
                        {
                            $fileTable->tableIns()->insertAll($files);

                            $this->getMigrationScanner()->logInfo("mediaGroupId: $mediaGroupId: 写入 file 表:" . count($files) . '个文件');

                            if (is_callable($this->beforePostFilesInsert))
                            {
                                call_user_func_array($this->beforePostFilesInsert, [
                                    $files,
                                ]);
                            }
                        }

                        //向 post 插入一个记录
                        //有可能当前这个 message 不是消息组中第一个带有 caption 的消息
                        $postTable->tableIns()->insert([
                            $postTable->getPkField()           => $postId,
                            $postTable->getTypeIdField()       => $baseMessageInfo[$msgTable->getTypeIdField()],
                            $postTable->getContentsField()     => $content,
                            $postTable->getMediaGroupIdField() => $mediaGroupId,
                            $postTable->getDateField()         => $baseMessageInfo[$msgTable->getDateField()],
                            $postTable->getTimeField()         => time(),
                        ]);

                        //删除redis记录的条数
                        $this->deleteMediaGroupCount($mediaGroupId);

                        //更新状态为数据已经迁移
                        $msgTable->tableIns()->where($msgTable->getPkField(), 'in', $ids)->update([
                            $msgTable->getFileStatusField() => static::FILE_STATUS_3_IN_POSTED,
                        ]);
                    }
                }));

                return $maker;
            });

            return $this;
        }

        protected function getMigrationMaker(): MakerAbastact
        {
            return $this->container->get('migrationMaker');
        }

        protected function initMigrationScanner(): static
        {
            $this->container->set('migration', function(Container $container) {

                $scanner = new LoopScanner(host: $this->redisHost, password: $this->redisPassword, port: $this->redisPort, db: $this->redisDb);
                $scanner->setDelayMs($this->scanDelayMs);
                $scanner->setName($this->scannerMigration);

                $logName = 'te-loopScanner-migration';
                $scanner->setStandardLogger($logName);
                if ($this->enableRedisLog)
                {
                    $scanner->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
                }

                if ($this->enableEchoLog)
                {
                    $scanner->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
                }

                return $scanner;
            });

            return $this;
        }

        public function getMigrationScanner(): LoopScanner
        {
            return $this->container->get('migration');
        }

        public function scanAndMirgrateMediaToDb(): void
        {
            $this->getMigrationScanner()->setMaker($this->getMigrationMaker())->listen();
        }

        public function stopMigration(): void
        {
            LoopTool::getIns(host: $this->redisHost, password: $this->redisPassword, port: $this->redisPort, db: $this->redisDb)
                ->stop($this->scannerMigration);
        }



        /*
         * ---------------------------------------------------------
         * */

        public function getFileStatus0Count(): int
        {
            $msgTable = $this->getMessageTable();

            if (!$msgTable->isTableCerated())
            {
                return 0;
            }

            return $msgTable->tableIns()->where($msgTable->getFileSizeField(), '<', $this->telegramMediaMaxFileSize)
                ->where($this->whereFileStatus0WaitingDownload)->count();
        }

        public function getFileStatus1Count(): int
        {
            $msgTable = $this->getMessageTable();

            if (!$msgTable->isTableCerated())
            {
                return 0;
            }

            return $msgTable->tableIns()->where($this->whereFileStatus1Downloading)->count();
        }

        public function getFileStatus2Count(): int
        {
            $msgTable = $this->getMessageTable();

            if (!$msgTable->isTableCerated())
            {
                return 0;
            }

            return $msgTable->tableIns()->where($this->whereFileStatus2FileMoved)->count();
        }

        public function getFileStatus3Count(): int
        {
            $msgTable = $this->getMessageTable();

            if (!$msgTable->isTableCerated())
            {
                return 0;
            }

            return $msgTable->tableIns()->where($this->whereFileStatus3InPosted)->count();
        }

        public function getInDownloadingFiles()
        {
            $msgTable = $this->getMessageTable();

            if (!$msgTable->isTableCerated())
            {
                return [];
            }

            return $msgTable->tableIns()->where($this->whereFileStatus1Downloading)->select();
        }

        /*
         * ---------------------------------------------------------
         * */

        protected function initTelegramBotApi(): static
        {
            is_dir($this->telegramBotApiPath) or mkdir($this->telegramBotApiPath, 0777, true);
            if (!is_dir($this->telegramBotApiPath))
            {
                throw new \Exception('文件夹不存在：' . $this->telegramBotApiPath);
            }

            $this->container->set('telegramBotApi', function(Container $container) {

                $binPath = dirname(__DIR__) . '/tg-bot-server/bin/telegram-bot-api';

                $telegramApiCommand = TelegramBotAPI::getIns($binPath);
                $telegramApiCommand->setApiId((string)$this->apiId);
                $telegramApiCommand->setApiHash($this->apiHash);
                $telegramApiCommand->allowLocalRequests();
                $telegramApiCommand->setHttpPort($this->localServerPort);
                $telegramApiCommand->setHttpStatPort($this->statisticsPort);
                $telegramApiCommand->setWorkingDirectory($this->telegramBotApiPath);
                $telegramApiCommand->setTempDirectory('temp');
                $telegramApiCommand->setLogFilePath('log.log');
                $telegramApiCommand->setLogVerbosity(1);

                $launcher = new DaemonLauncher((string)$telegramApiCommand);
                $logName  = 'telegram-api-launcher';
                $launcher->setStandardLogger($logName);
                if ($this->enableRedisLog)
                {
                    $launcher->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
                }

                if ($this->enableEchoLog)
                {
                    $launcher->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
                }

                return $launcher;
            });

            return $this;
        }

        public function getTelegramBotApi(): Launcher
        {
            return $this->container->get('telegramBotApi');
        }

        public function startTelegramBotApi(): void
        {
            $this->getTelegramBotApi()->launch();
        }

        public function restartTelegramBotApi(): void
        {
            if ($this->isTelegramBotApiStarted())
            {
                $this->stopTelegramBotApi();
            }

            $this->startTelegramBotApi();
        }

        public function stopTelegramBotApi(): void
        {
            $this->getTelegramBotApi()->killByKeyword('telegram-bot-api');
        }

        public function isTelegramBotApiStarted(): bool
        {
            $binPath = dirname(__DIR__) . '/tg-bot-server/bin/telegram-bot-api';

            $processes = $this->getTelegramBotApi()->getProcessListByKeyword($binPath);

            return count($processes) > 0;
        }

        public function getTelegramApiInfo(): array
        {
            $apiUrl   = 'http://127.0.0.1:' . $this->statisticsPort;
            $contents = $this->getTelegramApiGuzzle()->get($apiUrl);
            $body     = $contents->getBody()->getContents();

            $result = [];

            $data        = preg_split('#[\r\n]{2}#', $body);
            $performance = array_shift($data);
            $bots        = $data;

            $performanceLines = static::parseLine($performance);

            foreach ($performanceLines as $k => $v)
            {
                $t = static::parseField($v);
                if (count($t['value']) == 1)
                {
                    $result['performance'][$t['key']] = $t['value'][0];
                }
                else
                {
                    $result['performance'][$t['key']] = $t['value'];
                }
            }

            foreach ($bots as $k => $v)
            {
                $botLines = static::parseLine($v);

                $botInfo = [];
                foreach ($botLines as $k1 => $v1)
                {
                    $t = static::parseField($v1);
                    if (count($t['value']) == 1)
                    {
                        $botInfo[$t['key']] = $t['value'][0];
                    }
                    else
                    {
                        $botInfo[$t['key']] = $t['value'];
                    }
                }

                $result['bots'][$botInfo['id']] = $botInfo;
            }

            return $result;
        }

        /*
         * ---------------------------------------------------------
         * */

        protected function initMysql(): static
        {
            $this->container->set('mysqlClient', function(Container $container) {

                $registry = new TableRegistry($this->mysqlDb, $this->mysqlHost, $this->mysqlUsername, $this->mysqlPassword, $this->mysqlPort,);

                $logName = 'te-mysql';
                $registry->setStandardLogger($logName);

                if ($this->enableRedisLog)
                {
                    $registry->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
                }

                if ($this->enableEchoLog)
                {
                    $registry->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
                }

                return $registry;
            });

            return $this;
        }

        public function getMysqlClient(): TableRegistry
        {
            return $this->container->get('mysqlClient');
        }

        /*
         * ---------------------------------------------------------
         * */

        protected function initRedis(): static
        {
            $this->container->set('redisClient', function(Container $container) {
                return (new \Redis());
            });

            $this->queueMissionManager->initRedisClient(function(MissionManager $missionManager) {
                /**
                 * @var \Redis $redis
                 */
                $redis = $missionManager->getContainer()->get('redisClient');
                $redis->connect($this->redisHost, $this->redisPort);
                $this->redisPassword && $redis->auth($this->redisPassword);
                $redis->select($this->redisDb);

                return $redis;
            });

            $this->initCacheManager();

            $this->initQueue();

            return $this;
        }

        public function getRedisClient(): \Redis
        {
            return $this->container->get('redisClient');
        }

        /*
         * ---------------------------------------------------------
         * */

        protected function getTelegramApiGuzzle(): Client
        {
            return $this->container->get('telegramApiGuzzle');
        }

        protected function initTelegramApiGuzzle(): static
        {
            $this->container->set('telegramApiGuzzle', function(Container $container) {
                return new Client([
                    'timeout' => 50,
                    'debug'   => $this->debug,
                ]);
            });

            return $this;
        }

        /*
         * ---------------------------------------------------------
         * */

        public function getCacheManager(): RedisAdapter
        {
            return $this->container->get('cacheManager');
        }

        protected function initCacheManager(): static
        {
            $this->container->set('cacheManager', function(Container $container) {
                $marshaller   = new DeflateMarshaller(new DefaultMarshaller());
                $cacheManager = new RedisAdapter($container->get('redisClient'), $this->cacheNamespace, 0, $marshaller);

                return $cacheManager;
            });

            return $this;
        }

        /*
         *
         * ---------------------------------------------------------
         *
         * */

        /**********************/
        public function initMessageTable(string $name, callable $callback): static
        {
            $this->messageTableName = $name;

            $this->getMysqlClient()->initTable($name, Message::class, $callback);

            return $this;
        }

        public function getMessageTable(): Message
        {
            return $this->getMysqlClient()->getTable($this->messageTableName);
        }

        /**********************/
        public function initTypeTable(string $name, callable $callback): static
        {
            $this->typeTableName = $name;

            $this->getMysqlClient()->initTable($name, Type::class, $callback);

            return $this;
        }

        public function getTypeTable(): Type
        {
            return $this->getMysqlClient()->getTable($this->typeTableName);
        }

        /**********************/
        public function initFileTable(string $name, callable $callback): static
        {
            $this->fileTableName = $name;

            $this->getMysqlClient()->initTable($name, File::class, $callback);

            return $this;
        }

        public function getFileTable(): File
        {
            return $this->getMysqlClient()->getTable($this->fileTableName);
        }


        /**********************/
        public function initPostTable(string $name, callable $callback): static
        {
            $this->postTableName = $name;

            $this->getMysqlClient()->initTable($name, Post::class, $callback);

            return $this;
        }

        public function getPostTable(): Post
        {
            return $this->getMysqlClient()->getTable($this->postTableName);
        }


        /**********************/

        /*
         * ---------------------------------------------------------
         * */

        public function getMe()
        {
            $guzzle = $this->getTelegramApiGuzzle();

            $apiUrl = $this->resolveEndponit('getMe');

            $config = [];

            $response = $guzzle->get($apiUrl, $config);

            $contents = $response->getBody()->getContents();

            $json = json_decode($contents, true);

            return $json;
        }

        public function isWebHookSeted(): bool
        {
            $info = $this->getTelegramApiInfo();

            $bots = $info['bots'];

            return isset($bots[$this->getBootId()]);
        }

        public function updateWebHook()
        {
            $guzzle = $this->getTelegramApiGuzzle();

            $apiUrl = $this->resolveEndponit('setWebhook', [
                "url" => $this->telegramWebHookUrl,
            ]);

            $config = [];

            $response = $guzzle->get($apiUrl, $config);

            $contents = $response->getBody()->getContents();

            $json = json_decode($contents, true);

            return $json;
        }

        public function deleteWebHook()
        {
            $guzzle = $this->getTelegramApiGuzzle();
            $apiUrl = $this->resolveEndponit('deleteWebHook');

            $config   = [];
            $response = $guzzle->get($apiUrl, $config);
            $contents = $response->getBody()->getContents();
            $json     = json_decode($contents, true);

            return $json;
        }

        public function webHookEndPoint(string $message): void
        {
            $msg = UpdateMessage::parse($message, $this->getBootId());

            $typeId = $this->getTypeIdBySender($msg->senderId);

            if ($msg->isNeededType() && $typeId > 0)
            {
                $msgTable = $this->getMessageTable();

                $data = [
                    $msgTable->getPkField()                 => $msgTable->calcPk(),
                    $msgTable->getBotIdField()              => $msg->bootId,
                    $msgTable->getUpdateIdField()           => $msg->updateId,
                    $msgTable->getSenderIdField()           => $msg->senderId,
                    $msgTable->getMediaGroupIdField()       => $msg->mediaGroupId,
                    $msgTable->getMessageLoadTypeField()    => $msg->messageLoadType,
                    $msgTable->getMessageFromTypeField()    => $msg->messageFromType,
                    $msgTable->getFileIdField()             => $msg->fileId,
                    $msgTable->getFileUniqueIdField()       => $msg->fileUniqueId,
                    $msgTable->getFileSizeField()           => $msg->fileSize,
                    $msgTable->getFileNameField()           => $msg->fileName,
                    $msgTable->getCaptionField()            => $msg->caption,
                    $msgTable->getChatTypeField()           => $msg->chatType,
                    $msgTable->getChatSourceTypeField()     => $msg->chatSourceType,
                    $msgTable->getChatSourceUsernameField() => $msg->chatSourceUsername,
                    $msgTable->getTextField()               => $msg->text,
                    $msgTable->getRawField()                => $msg->message,
                    $msgTable->getDateField()               => $msg->date,
                    $msgTable->getExtField()                => $msg->ext,
                    $msgTable->getMimeTypeField()           => $msg->mimeType,
                    $msgTable->getTypeIdField()             => $typeId,
                    $msgTable->getTimeField()               => time(),
                ];

                $msgTable->tableIns()->insert($data);

                //更新每个信息有几个media图文
                if (//如果有文件要下载
                    $data[$msgTable->getFileUniqueIdField()] && //并且文件小于200M
                    ($msg->fileSize < $this->telegramMediaMaxFileSize))
                {
                    $this->incMediaGroupCount($msg->mediaGroupId);
                }
            }
        }

        protected function resolveEndponit($endpoint, array $query = []): ?string
        {
            $queryStr = '';
            if (count($query))
            {
                $queryStr = '?' . http_build_query($query);
            }

            return implode('/', [
                rtrim('http://127.0.0.1:' . $this->localServerPort, '/'),
                'bot' . $this->bootToken,
                trim($endpoint, '/') . $queryStr,
            ]);
        }

        /*
         *
         * ---------------------------------------------------------
         *
         * */

        protected function makeMediaGroupCountName($mediaGroupId): string
        {
            return $this->redisNamespace . ':media_group_count:' . $mediaGroupId;
        }

        protected function incMediaGroupCount($mediaGroupId): static
        {
            $this->getRedisClient()->incr($this->makeMediaGroupCountName($mediaGroupId));

            return $this;
        }

        protected function getMediaGroupCount($mediaGroupId): int
        {
            return (int)$this->getRedisClient()->get($this->makeMediaGroupCountName($mediaGroupId));
        }

        protected function deleteMediaGroupCount($mediaGroupId): int
        {
            return (int)$this->getRedisClient()->del($this->makeMediaGroupCountName($mediaGroupId));
        }

        /*-------------------------------------------------------------------*/
        public function makeVideoCoverToQueue(array $videoFileInfo, callable $callback): void
        {
            $fileTab = $this->getFileTable();

            $fileId       = $videoFileInfo[$fileTab->getPkField()];
            $postId       = $videoFileInfo[$fileTab->getPostIdField()];
            $mediaGroupId = $videoFileInfo[$fileTab->getMediaGroupIdField()];
            $videoPath    = $videoFileInfo[$fileTab->getPathField()];

            $videoFullPath = call_user_func_array($callback, [$videoPath]);
            if (!is_file($videoFullPath))
            {
                $this->queueMissionManager->logInfo(implode([
                    'makeVideoCoverQueue，视频文件不存在，被忽略: ' . $videoFullPath,
                ]));

                return;
            }

            $fullCoverPath = strtr($videoFullPath, ["videos" => "photos"]);
            $fullCoverPath = preg_replace('/[^.]+$/im', 'jpg', $fullCoverPath);

            $saveCoverPath = strtr($videoPath, ["videos" => "photos"]);
            $saveCoverPath = preg_replace('/[^.]+$/im', 'jpg', $saveCoverPath);

            preg_match('%/(\d+)\.%im', $saveCoverPath, $match);

            $videoFileNameId = $match[1];

            is_dir(dirname($fullCoverPath)) or mkdir(dirname($fullCoverPath), 0777, true);
            @chmod(dirname($fullCoverPath), 0777);
            @chown(dirname($fullCoverPath), $this->mediaOwner);

            $ffmpegConfig = [
                'ffmpeg.binaries'  => dirname(__DIR__) . '/tg-bot-server/bin/ffmpeg',
                'ffprobe.binaries' => dirname(__DIR__) . '/tg-bot-server/bin/ffprobe',
                'timeout'          => 36000,
                'ffmpeg.threads'   => 12,
            ];

            $ffprobe  = \FFMpeg\FFProbe::create($ffmpegConfig);
            $info     = $ffprobe->format($videoFullPath);
            $duration = $info->get('duration');

            if (!$duration)
            {
                $duration = 1;
            }

            if ($duration <= 30)
            {
                $picCount = 3;
            }

            if ($duration > 30 && $duration <= 120)
            {
                $picCount = 6;
            }

            if ($duration > 120)
            {
                $picCount = 9;
            }

            $picPositions = static::splitVideo($picCount, $duration);

            $this->queueMissionManager->logInfo(implode([
                "makeVideoCoverQueue - 时长：$duration - $videoFullPath"
            ]));

            foreach ($picPositions as $positionSecondKey => $positionSecond)
            {
                $mission = new CallableMission();

                // /var/www/6025/new/coco-tgMedia/data/media/2025-06/16/videos/E/9110579685264727.mp4
                $mission->videoFullPath = $videoFullPath;

                // /var/www/6025/new/coco-tgMedia/data/media/2025-06/16/photos/E/9110579685264727-1.jpg
                $mission->fullCoverPath = preg_replace('#(\.[^.]+)$#', "-" . ($positionSecondKey + 1) . "$1", $fullCoverPath);;

                // 2025-06/16/photos/E/9110579685264727-1.jpg
                $mission->saveCoverPath = preg_replace('#(\.[^.]+)$#', "-" . ($positionSecondKey + 1) . "$1", $saveCoverPath);

                $mission->postId            = $postId;
                $mission->mediaGroupId      = $mediaGroupId;
                $mission->videoFileNameId   = $videoFileNameId;
                $mission->positionSecondKey = $positionSecondKey + 1;

                $mission->setParameters([
                    $videoFullPath,
                    $mission->fullCoverPath,
                ]);

                $mission->setCallback(function($fileFullPath, $coverPath) use ($ffmpegConfig, $positionSecond) {
                    $ffmpeg = \FFMpeg\FFMpeg::create($ffmpegConfig);

                    $video = $ffmpeg->open($fileFullPath);
                    $video->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds($positionSecond))->save($coverPath,true);
                });

                $this->queueMissionManager->logInfo(implode([
                    "封面[$positionSecondKey] - $mission->fullCoverPath",
                ]));

                $this->makeVideoCoverQueue->addNewMission($mission);
            }
        }

        public function listenMakeVideoCover(): void
        {
            $queue = $this->makeVideoCoverQueue;

            $queue->setContinuousRetry(true);
            $queue->setDelayMs($this->telegraphQueueDelayMs);
            $queue->setEnable(true);
            $queue->setMaxTimes(5);
            $queue->setIsRetryOnError(true);
            $queue->setMissionProcessor(new CallableMissionProcessor());

            $success = function(CallableMission $mission) {

//                $mission->videoFullPath   = $videoFullPath;
//                $mission->fullCoverPath   = $fullCoverPath;
//                $mission->saveCoverPath   = $saveCoverPath;
//                $mission->postId          = $postId;
//                $mission->mediaGroupId    = $mediaGroupId;
//                $mission->videoFileNameId = $videoFileNameId;
//                $mission->positionSecondKey  = $positionSecondKey+1;

                //怕文件还没写好
//                usleep(1000 * 50);

                $fileSize = (is_file($mission->fullCoverPath) && is_readable($mission->fullCoverPath)) ? filesize($mission->fullCoverPath) : 0;

                //封面图片信息写入数据库
                $fileTable = $this->getFileTable();
                $result = $fileTable->tableIns()->insert([
                    $fileTable->getPkField()     => $fileTable->calcPk(),

                    // 1120080870326144974
                    $fileTable->getPostIdField() => $mission->postId,

                    // 2025-06/16/photos/E/9110579685264727-1.jpg
                    $fileTable->getPathField()   => $mission->saveCoverPath,

                    $fileTable->getFileSizeField()       => $fileSize,

                    // cover-9110579685264727-1
                    $fileTable->getFileNameField()       => "cover-{$mission->videoFileNameId}-{$mission->positionSecondKey}",

                    $fileTable->getExtField()            => 'jpg',
                    $fileTable->getMimeTypeField()       => 'image/jpeg',
                    $fileTable->getOriginExtField()      => 'jpg',
                    $fileTable->getOriginMimeTypeField() => 'image/jpeg',

                    $fileTable->getMediaGroupIdField()   => $mission->mediaGroupId,
                    $fileTable->getTimeField()           => time(),
                ]);

                if ($result == 1)
                {
                    $msg = "makeVideoCover success：【{$mission->fullCoverPath}】";
                }
                else
                {
                    $msg = "makeVideoCover error：【{$mission->fullCoverPath}】";
                }

                $this->queueMissionManager->logInfo("{$msg}");
            };

            $catch = function(CallableMission $mission, \Exception $exception) {
                $this->queueMissionManager->logError("error【{$exception->getMessage()}】【{$mission->videoFullPath}】");
            };

            $queue->addResultProcessor(new CustomResultProcessor($success, $catch));
            $queue->listen();
        }

        /*-------------------------------------------------------------------*/
        public function convertM3u8ToQueue(array $videoFileInfo, callable $callback, $sectionSeconds = 15, $threads = 12, $streamFormat = 'x264', $representations = [
            //            144,
            //            240,
            //            360,
            //            480,
            //            720,
            1080,
            //            1440,
            //            2160,
        ],bool $deleteSource = false): void
        {
            $fileTab = $this->getFileTable();

            $fileId       = $videoFileInfo[$fileTab->getPkField()];
            $postId       = $videoFileInfo[$fileTab->getPostIdField()];
            $mediaGroupId = $videoFileInfo[$fileTab->getMediaGroupIdField()];
            $videoPath    = $videoFileInfo[$fileTab->getPathField()];
            $videoSize    = $videoFileInfo[$fileTab->getFileSizeField()];

            $videoFullPath = call_user_func_array($callback, [$videoPath]);
            if (!is_file($videoFullPath))
            {
                $this->queueMissionManager->logInfo(implode([
                    'convertM3u8Queue，视频文件不存在，被忽略: ' . $videoFullPath,
                ]));

                return;
            }

            // key/393644444504750.txt
            $keyUri = 'key/' . hrtime(true) . '.txt';

            // /var/www/6025/new/coco-tgDownloader/data/media/2025-01/04/videos/D/1/hls.m3u8
            $tsFullPath = preg_replace('/\.[^.]+$/im', '/hls.m3u8', $videoFullPath);

            // 2025-01/04/videos/D/1/hls.m3u8
            $tsPath = preg_replace('/\.[^.]+$/im', '/hls.m3u8', $videoPath);

            // /var/www/6025/new/coco-tgDownloader/data/media/2025-01/04/videos/D/1/key/393644444504750.txt
            $keyFullPath = preg_replace('/\.[^.]+$/im', '/' . $keyUri, $videoFullPath);

            is_dir(dirname($keyFullPath)) or mkdir(dirname($keyFullPath), 0777, true);
            @chmod(dirname($keyFullPath), 0777);
            @chown(dirname($keyFullPath), $this->mediaOwner);

            $ffmpegConfig           = [
                'ffmpeg.binaries'  => dirname(__DIR__) . '/tg-bot-server/bin/ffmpeg',
                'ffprobe.binaries' => dirname(__DIR__) . '/tg-bot-server/bin/ffprobe',
                'timeout'          => 36000,
                'ffmpeg.threads'   => 12,
            ];

            $mission                = new CallableMission();
            $mission->videoFullPath = $videoFullPath;
            $mission->videoPath     = $videoPath;
            $mission->keyUri        = $keyUri;
            $mission->keyFullPath   = $keyFullPath;
            $mission->tsFullPath    = $tsFullPath;
            $mission->tsPath        = $tsPath;
            $mission->postId        = $postId;
            $mission->fileId        = $fileId;
            $mission->mediaGroupId  = $mediaGroupId;
            $mission->videoSize     = $videoSize;
            $mission->deleteSource  = $deleteSource;
            $mission->ext           = $videoFileInfo[$fileTab->getExtField()];
            $mission->mime          = $videoFileInfo[$fileTab->getMimeTypeField()];

            $mission->setParameters([
                $videoFullPath,
                $tsFullPath,
                $keyFullPath,
                $keyUri,
            ]);

            $mission->setCallback(function($videoFullPath, $tsFullPath, $keyFullPath, $keyUri) use ($ffmpegConfig, $sectionSeconds, $threads, $streamFormat,$representations) {
                $ffmpeg = \Streaming\FFMpeg::create($ffmpegConfig);

                $video = $ffmpeg->open($videoFullPath);

                if ($streamFormat == 'x264')
                {
                    $v = $video->hls()->x264();
                }
                else
                {
                    $v = $video->hls()->hevc();
                }

                $v->encryption($keyFullPath, $keyUri, 5)
                    ->setHlsTime((string)$sectionSeconds)
                    ->autoGenerateRepresentations($representations)->save($tsFullPath);
            });

            $this->queueMissionManager->logInfo(implode([
                "convertM3u8Queue，视频大小: " . static::formatBytes($videoSize) . "，m3u8 : {$tsFullPath}",
            ]));

            $this->convertM3u8Queue->addNewMission($mission);
        }

        public function listenConvertM3u8(): void
        {
            $queue = $this->convertM3u8Queue;

            $queue->setContinuousRetry(true);
            $queue->setDelayMs($this->telegraphQueueDelayMs);
            $queue->setEnable(true);
            $queue->setMaxTimes(5);
            $queue->setIsRetryOnError(true);
            $queue->setMissionProcessor(new CallableMissionProcessor());

            $queue->setOnEachMissionStartExec(function(CallableMission $mission) {
                $msg = "转换：【" . static::formatBytes($mission->videoSize) . "】【{$mission->videoFullPath}】";
                $this->queueMissionManager->logInfo("{$msg}");
            });

            $success = function(CallableMission $mission) {

                /*
                $mission->videoFullPath = $videoFullPath;
                $mission->videoPath     = $videoPath;
                $mission->keyUri        = $keyUri;
                $mission->keyFullPath   = $keyFullPath;
                $mission->tsFullPath    = $tsFullPath;
                $mission->tsPath        = $tsPath;
                $mission->postId        = $postId;
                $mission->fileId        = $fileId;
                $mission->mediaGroupId  = $mediaGroupId;
                $mission->videoSize     = $videoSize;

                */

                $fileTable = $this->getFileTable();
                $fileTable->tableIns()->where($fileTable->getPkField(), '=', $mission->fileId)->update([
                    $fileTable->getPathField()           => $mission->tsPath,
                    $fileTable->getMimeTypeField()       => 'application/x-mpegURL',
                    $fileTable->getExtField()            => 'm3u8',
                    $fileTable->getOriginExtField()      => $mission->ext,
                    $fileTable->getOriginMimeTypeField() => $mission->mime,
                ]);

                $msg = "convertM3u8 success：【{$mission->tsFullPath}】";
                $this->queueMissionManager->logInfo("{$msg}");

                if ($mission->deleteSource)
                {
                    //删除原 mp4
                    @unlink($mission->videoFullPath);
                }
            };

            $catch = function(CallableMission $mission, \Exception $exception) {
                $this->queueMissionManager->logError("error【{$exception->getMessage()}】【{$mission->videoFullPath}】");
            };

            $queue->addResultProcessor(new CustomResultProcessor($success, $catch));
            $queue->listen();
        }

        /*-------------------------------------------------------------------*/
        public function cdnPrefetchToQueue(array $videoFileInfo, callable $callback, $referer = ''): void
        {
            $fileTab = $this->getFileTable();
            $path    = $videoFileInfo[$fileTab->getPathField()];

            $mission = new HttpMission();

            $url = call_user_func_array($callback, [$path]);
            $mission->setTimeout(30000);
            $mission->setUrl($url);
            $mission->addClientOptions('verify', false);
            $mission->addClientOptions('debug', $this->debug);
            $mission->addClientOptions('headers', ['referer' => $referer]);

            $mission->url = $url;

            $this->queueMissionManager->logInfo(implode([
                'cdnPrefetchQueue，url: ' . $url,
            ]));

            $this->cdnPrefetchQueue->addNewMission($mission);
        }

        public function listenCdnPrefetch(): void
        {
            $queue = $this->cdnPrefetchQueue;

            $queue->setContinuousRetry(true);
            $queue->setDelayMs($this->telegraphQueueDelayMs);
            $queue->setEnable(true);
            $queue->setMaxTimes(5);
            $queue->setIsRetryOnError(true);
            $queue->setMissionProcessor(new GuzzleMissionProcessor());

            $success = function(HttpMission $mission) {
//                只需要请求一次，不需要使用结果
//                $response = $mission->getResult();
//                $contents = $response->getBody()->getContents();

                $this->queueMissionManager->logInfo("cdnPrefetch success【{$mission->url}】");
            };

            $catch = function(HttpMission $mission, \Exception $exception) {
                $this->queueMissionManager->logError("error【{$exception->getMessage()}】");
            };

            $queue->addResultProcessor(new CustomResultProcessor($success, $catch));

            $queue->listen();
        }

        /*-------------------------------------------------------------------*/

        public function queueMonitor(): void
        {
            $this->queueMissionManager->getAllQueueInfoTable();
        }

        public function getQueueStatus(): array
        {
            return $this->queueMissionManager->getAllQueueInfo();
        }

        public function setTelegraphQueueDelayMs(int $telegraphQueueDelayMs): static
        {
            $this->telegraphQueueDelayMs = $telegraphQueueDelayMs;

            return $this;
        }

        public function setTelegraphTimeout(int $telegraphTimeout): static
        {
            $this->telegraphTimeout = $telegraphTimeout;

            return $this;
        }

        public function setTelegraphPageBrandTitle(?string $telegraphPageBrandTitle): static
        {
            $this->telegraphPageBrandTitle = $telegraphPageBrandTitle;

            return $this;
        }

        public function setTelegraphQueueMaxTimes(int $telegraphQueueMaxTimes): static
        {
            $this->telegraphQueueMaxTimes = $telegraphQueueMaxTimes;

            return $this;
        }

        /*
        *
        * ------------------------------------------------------
        * 公共
        * ------------------------------------------------------
        *
        */


        /**
         * -2 表都没创建
         * -1 已经存在
         * 0 写入失败
         * 1 写入成功
         *
         * @param string $name
         * @param int    $groupId
         *
         * @return int
         */
        public function addType(string $name, int $groupId): int
        {
            $typeTab = $this->getTypeTable();

            if (!$typeTab->isTableCerated())
            {
                return -2;
            }

            if ($this->isTypeGroupIdExists($groupId))
            {
                return -1;
            }

            return (int)!!$typeTab->tableIns()->insert([
                $typeTab->getGroupIdField() => $groupId,
                $typeTab->getNameField()    => $name,
            ]);
        }

        public function delType(int $groupId): bool
        {
            $typeTab = $this->getTypeTable();

            if (!$typeTab->isTableCerated())
            {
                return false;
            }
            $typeTab->tableIns()->where($typeTab->getGroupIdField(), '=', $groupId)->delete();

            return true;
        }

        public function isTypeGroupIdExists(int $groupId): bool
        {
            $typeTab = $this->getTypeTable();

            if (!$typeTab->isTableCerated())
            {
                return false;
            }

            return !!$typeTab->tableIns()->where($typeTab->getGroupIdField(), '=', $groupId)->findOrEmpty();
        }

        public function getTypeList()
        {
            $typeTab = $this->getTypeTable();

            if (!$typeTab->isTableCerated())
            {
                return [];
            }

            return $typeTab->tableIns()->field(implode(',', [
                $typeTab->getPkField(),
                $typeTab->getNameField(),
                $typeTab->getGroupIdField(),
            ]))->select();
        }

        public function getTypes()
        {
            $types = $this->getCacheManager()->get($this->cacheTypes, function($item) {
                $item->expiresAfter(30);

                $typeTab = $this->getTypeTable();

                return $typeTab->tableIns()->field(implode(',', [
                    $typeTab->getPkField(),
                    $typeTab->getNameField(),
                    $typeTab->getGroupIdField(),
                ]))->select();
            });

            return $types;
        }

        public function getAllTableStatus(): array
        {
            $data = [];

            $a                                         = $this->getMessageTable()->isTableCerated();
            $data[$this->getMessageTable()->getName()] = [
                'is_created' => (int)$a,
                'count'      => $a ? (int)$this->getMessageTable()->getCount() : 0,
            ];

            $b                                      = $this->getTypeTable()->isTableCerated();
            $data[$this->getTypeTable()->getName()] = [
                'is_created' => (int)$b,
                'count'      => $b ? (int)$this->getTypeTable()->getCount() : 0,
            ];

            $c                                      = $this->getFileTable()->isTableCerated();
            $data[$this->getFileTable()->getName()] = [
                'is_created' => (int)$c,
                'count'      => $c ? (int)$this->getFileTable()->getCount() : 0,
            ];

            $d                                      = $this->getPostTable()->isTableCerated();
            $data[$this->getPostTable()->getName()] = [
                'is_created' => (int)$d,
                'count'      => $d ? (int)$this->getPostTable()->getCount() : 0,
            ];

            return $data;
        }

        public function getAllPosts($continuePostId = null,$row = 100): array
        {
            $postTable = $this->getPostTable();
            $fileTable = $this->getFileTable();
            $msgTable  = $this->getMessageTable();

            $postWhere = [];

            if ($continuePostId)
            {
                $postWhere[] = [
                    $postTable->getPkField(),
                    '>',
                    $continuePostId,
                ];
            }

            $posts = $postTable->tableIns()->where($postWhere)->limit(0, $row)->select();

            $postsIds = $posts->column($postTable->getPkField());

            $files = $fileTable->tableIns()->where([
                [
                    $fileTable->getPostIdField(),
                    'in',
                    $postsIds,
                ],
            ])->select();

            $media = [];

            $videos    = [];
            $covers    = [];
            $images    = [];
            $audios    = [];
            $documents = [];

            foreach ($posts as $k => $post)
            {
                $postId                 = $post[$postTable->getPkField()];
                $media[$postId]['post'] = $post;
            }

            foreach ($files as $k => $fileInfo)
            {
                // cover-9110579685264727-1
                $fileNameInfo = static::parseFileNameId($fileInfo[$fileTable->getFileNameField()]);

                // 2025-06/16/photos/E/9110579685264727-1.jpg
                $pathId = static::parsePathId($fileInfo[$fileTable->getPathField()]);

                if (str_starts_with($fileInfo[$fileTable->getMimeTypeField()], 'image'))
                {
                    if (count($fileNameInfo))
                    {
                        $fileNameInfoId    = $fileNameInfo[1];
                        $fileNameInfoOrder = $fileNameInfo[2];

                        $covers[$fileInfo[$fileTable->getPostIdField()]][$fileNameInfoId][] = $fileInfo;
                    }
                    else
                    {
                        $images[$fileInfo[$fileTable->getPostIdField()]][] = $fileInfo;
                    }
                }
                elseif (str_starts_with($fileInfo[$fileTable->getMimeTypeField()], 'video') || ($fileInfo[$fileTable->getMimeTypeField()] == 'application/x-mpegURL'))
                {
                    $videos[$fileInfo[$fileTable->getPostIdField()]][$pathId] = [
                        "source" => $fileInfo,
                        "cover"  => [],
                    ];
                }
                elseif (str_starts_with($fileInfo[$fileTable->getMimeTypeField()], 'audio'))
                {
                    $audios[$fileInfo[$fileTable->getPostIdField()]][] = $fileInfo;
                }
                else
                {
                    $documents[$fileInfo[$fileTable->getPostIdField()]][] = $fileInfo;
                }
            }

            foreach ($images as $postId_ => $image)
            {
                $media[$postId_]['image'] = $image;
            }

            foreach ($videos as $postId_ => &$video)
            {
                foreach ($video as $pathId_ => &$v)
                {
                    if (isset($covers[$postId_]))
                    {
                        $v['cover'] = $covers[$postId_][$pathId_];
                    }
                }

                $media[$postId_]['video'] = $video;
            }

            foreach ($audios as $postId_ => $audio)
            {
                $media[$postId_]['music'] = $audio;
            }

            foreach ($documents as $postId_ => $document)
            {
                $media[$postId_]['document'] = $document;
            }


            return $media;
        }

        //根据mediaGroupId，删除 post 表中的文章
        public function deletePostByMediaGroupId($mediaGroupIds): void
        {
            $msgTable  = $this->getMessageTable();
            $postTable = $this->getPostTable();
            $fileTable = $this->getFileTable();

            $msgTable->tableIns()->where([
                [
                    $msgTable->getMediaGroupIdField(),
                    'in',
                    $mediaGroupIds,
                ],
            ])->delete();

            $postTable->tableIns()->where([
                [
                    $postTable->getMediaGroupIdField(),
                    'in',
                    $mediaGroupIds,
                ],
            ])->delete();

            $fileTable->tableIns()->where([
                [
                    $fileTable->getMediaGroupIdField(),
                    'in',
                    $mediaGroupIds,
                ],
            ])->delete();
        }

        //根据关键词，删除 post 表中的文章
        public function deletePostByKeyword(string $keyword, bool $isFullMatch = false): void
        {
            $msgTable  = $this->getMessageTable();
            $postTable = $this->getPostTable();
            $fileTable = $this->getFileTable();

            if ($isFullMatch)
            {
                $where = [
                    [
                        $postTable->getContentsField(),
                        '=',
                        $keyword,
                    ],
                ];
            }
            else
            {
                $where = [
                    [
                        $postTable->getContentsField(),
                        'like',
                        "%{$keyword}%",
                    ],
                ];
            }

            $mediaGroupIds = $postTable->tableIns()->where($where)->column($postTable->getMediaGroupIdField());

            $this->deletePostByMediaGroupId($mediaGroupIds);
        }

        //删除file表中，文件大小超过指定值的视频文件
        public function deleteOverSizeFile(int $sizeInByte, callable $callback): void
        {
            $msgTable  = $this->getMessageTable();
            $fileTable = $this->getFileTable();

            //查询文件表大于指定大小的记录
            $files = $fileTable->tableIns()->where($fileTable->getFileSizeField(), '>=', $sizeInByte)->select();

            $this->queueMissionManager->logInfo('待删除文件: ' . count($files));

            //遍历文件，构造文件路径，删除文件
            $ids = [];
            foreach ($files as $k => $file)
            {
                $path  = call_user_func_array($callback, [$file[$fileTable->getPathField()]]);
                $ids[] = $id = $file[$fileTable->getPkField()];

                if (is_file($path) && is_writeable($path))
                {
                    $res = @!!unlink($path);
                    if ($res)
                    {
                        $this->queueMissionManager->logInfo('删除文件成功: ' . $id . '----' . $path);
                    }
                    else
                    {
                        $this->queueMissionManager->logInfo('删除文件失败: ' . $id . '----' . $path);
                    }
                }
                else
                {
                    $this->queueMissionManager->logInfo('文件不存在: ' . $id . '----' . $path);
                }
            }

            $this->queueMissionManager->logInfo(implode('', [
                '查出文件: ' . count($files),
                ',成功删除: ' . count($ids),
                ',失败个数: ' . count($files) - count($ids),
            ]));

            //删除文件表记录
            if (count($ids))
            {
                $num = $fileTable->tableIns()->where([
                    [
                        $fileTable->getPkField(),
                        'in',
                        $ids,
                    ],
                ])->delete();
                $this->queueMissionManager->logInfo('删除 file 表记录: ' . $num);
            }

            //删除 msg 表记录
            $num = $msgTable->tableIns()->where($msgTable->getFileSizeField(), '>=', $sizeInByte)->delete();
            $this->queueMissionManager->logInfo('删除 msg 表记录: ' . $num);
        }

        //删除post表中，一个文件都没有，内容也没有的文章
        public function deleteEmptyPost(): void
        {
            $postTable = $this->getPostTable();
            $fileTable = $this->getFileTable();
            $msgTable  = $this->getMessageTable();

            $posts = $postTable->tableIns()->field(implode(',', [
                $postTable->getMediaGroupIdField(),
                $postTable->getContentsField(),
            ]))->select();

            $this->queueMissionManager->logInfo('一共文章数量: ' . count($posts));
            foreach ($posts as $k => $post)
            {
                $gropId  = $post[$postTable->getMediaGroupIdField()];
                $content = $post[$postTable->getContentsField()];

                $fileCount = $fileTable->tableIns()->where([
                    [
                        $fileTable->getMediaGroupIdField(),
                        '=',
                        $gropId,
                    ],
                ])->count();

                $this->queueMissionManager->logInfo( $gropId . ',文件个数:[' . $fileCount . ']--' . static::inlineText($content));

                if (!$fileCount && !$content)
                {
                    $this->queueMissionManager->logInfo('删除文章: ' . $gropId);

                    $this->deletePostByMediaGroupId($gropId);
                }
            }
        }

        //修改post表中的contents字段
        public function updatePostContents(callable $callback): void
        {
            $postTable = $this->getPostTable();

            $posts = $postTable->tableIns()->cursor();

            foreach ($posts as $k => $post)
            {
                $where = [
                    [
                        $postTable->getPkField(),
                        '=',
                        $post[$postTable->getPkField()],
                    ],
                ];

                $postTable->tableIns()->where($where)->update([
                    $postTable->getContentsField() => call_user_func_array($callback, [
                        $post,
                        $postTable,
                    ]),
                ]);
            }
        }

        public function deleteRedisLog(): void
        {
            $redis = $this->getRedisClient();

            $pattern = $this->logNamespace . '*';

            $keysToDelete = $redis->keys($pattern);

            foreach ($keysToDelete as $key)
            {
                $redis->del($key);
            }
        }

        public function deleteCache(): void
        {
            $redis = $this->getRedisClient();

            $pattern = $this->cacheNamespace . '*';

            $keysToDelete = $redis->keys($pattern);

            foreach ($keysToDelete as $key)
            {
                $redis->del($key);
            }
        }

        public function deleteVideoSourceFile():void{

        }
        /*-------------------------------------------------------------------*/

        protected function initQueue(): static
        {
            $this->cdnPrefetchQueue    = $this->queueMissionManager->initQueue(static::CDN_PREFETCH_QUEUE);
            $this->makeVideoCoverQueue = $this->queueMissionManager->initQueue(static::MAKE_VIDEO_COVER_QUEUE);
            $this->convertM3u8Queue    = $this->queueMissionManager->initQueue(static::CONVERT_M3U8_QUEUE);

            return $this;
        }

        protected function initMissionManager(): static
        {
            $this->queueMissionManager = new MissionManager($this->container);
            $this->queueMissionManager->setPrefix($this->redisNamespace);

            $logName = 'te-queue-manager';
            $this->queueMissionManager->setStandardLogger($logName);
            if ($this->enableRedisLog)
            {
                $this->queueMissionManager->addRedisHandler(redisHost: $this->redisHost, redisPort: $this->redisPort, password: $this->redisPassword, db: $this->redisDb, logName: $this->logNamespace . $logName, callback: $this->queueMissionManager::getStandardFormatter());
            }

            if ($this->enableEchoLog)
            {
                $this->queueMissionManager->addStdoutHandler($this->queueMissionManager::getStandardFormatter());
            }

            return $this;
        }

        /*-------------------------------------------------------------------*/

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
            $subject = trim($subject);

            //最前面的问号去掉
            $result = preg_replace('/^[\?\s]+/ium', '', $subject);

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

        // cover-9110579685264727-1
        protected static function parseFileNameId($fileName): array
        {
            preg_match('/cover-(\d+)-(\d+)/im', $fileName, $match);

            return $match;
        }

        // 2025-06/16/videos/5/9110574637673867/hls.m3u8
        // 2025-06/16/videos/5/9110574637673867.mp4
        protected static function parsePathId($path): ?string
        {
            preg_match('%/[a-z\d]/(\d+)%im', $path, $match);

            if (isset($match[1]))
            {
                return $match[1];
            }

            return null;
        }

        protected static function baseContentsFilter(): callable
        {
            return function($content) {
                $content = trim($content);
                $content = preg_replace('#[\r\n]+#iu', "\r\n", $content);
                $lines   = preg_split('#[\r\n]+#iu', $content, -1, PREG_SPLIT_NO_EMPTY);
                $lines   = array_map('trim', $lines);
                $content = implode(PHP_EOL, $lines);

                return $content;
            };
        }

        protected static function envCheck(): void
        {
            // 检查 PHP 版本
            if (version_compare(PHP_VERSION, '8.1.0', '<'))
            {
                throw new \Exception('PHP version must be 8.1 or higher.');
            }

            // 检查 exec 函数是否可用
            if (!function_exists('exec'))
            {
                throw new \Exception('The exec function is disabled.');
            }

            // 检查操作系统
            // 使用 exec 函数检查 /etc/os-release
            $output    = [];
            $returnVar = 0;
            exec('cat /etc/os-release', $output, $returnVar);

            if ($returnVar !== 0 || empty($output))
            {
                throw new \Exception('Unable to read /etc/os-release.');
            }

            $osRelease = implode("\n", $output);
            if (!str_contains($osRelease, 'fedora'))
            {
                throw new \Exception('This application must run on a system compatible with CentOS (like Fedora).');
            }

        }

        protected static function splitVideo($parts, $duration): array
        {
            $duration = (int)$duration;
            // 如果传入的参数不合法，返回空数组
            if ($parts <= 0 || $duration <= 0)
            {
                return [];
            }

            $interval = floor($duration / $parts);  // 每个部分的时长
            $result   = [];

            for ($i = 0; $i < $parts; $i++)
            {
                $result[] = (int)($i * $interval);  // 计算每部分的起始秒数
            }

            // 如果视频时长不能整除份数，最后一部分调整为视频的总时长
            $result[$parts - 1] = $duration;

            $result[0]++;
            $result[count($result) - 1]--;

            return $result;
        }

        protected static function makePath(string $input, string $fileType): string
        {
            $year        = date('Y');
            $month       = date('m');
            $day         = date('d');
            $firstLetter = strtoupper(substr(md5($input), 0, 1));

            return "$year-$month/$day/$fileType/$firstLetter/" . hrtime(true);
        }

        protected static function parseField(string $line): array
        {
            $res = explode("\t", $line);

            $key   = array_shift($res);
            $value = $res;

            return [
                "key"   => $key,
                "value" => $value,
            ];
        }

        protected static function parseLine(string $data): array
        {
            return preg_split('#[\r\n]+#', $data, -1, PREG_SPLIT_NO_EMPTY);
        }
    }
