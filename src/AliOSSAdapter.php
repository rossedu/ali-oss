<?php

namespace Ross\AliOss;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;

class AliOSSAdapter extends AbstractAdapter
{

    use NotSupportingVisibilityTrait;

    protected $client;

    protected $accessId;

    protected $accessKey;

    protected $endpoint;

    protected $bucket;

    protected $isCName;

    protected $prefix;

    protected $options;

    public function __construct($accessId, $accessKey, $endpoint, $bucket, $isCName = FALSE, $prefix = '', ...$options)
    {
        $this->accessId = $accessId;
        $this->accessKey = $accessKey;
        $this->endpoint = $endpoint;
        $this->bucket = $bucket;
        $this->isCName = $isCName;
        $this->options = $options;
        $this->initClient();
        $this->setPathPrefix($prefix);

    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        $resource = stream_get_contents($resource);

        return $this->upload($path, $resource);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newPath)
    : bool {
        if (!$this->copy($path, $newpath)) {
            return FALSE;
        }

        return $this->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    : bool {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        try {
            $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
        } catch (OssException $exception) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    : bool {
        $path = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject($this->bucket, $path);
        } catch (OssException $ossException) {
            return FALSE;
        }

        return !$this->has($path);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    : bool {
        return $this->delete($dirname);
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $path = $this->applyPathPrefix($dirname);
        $options = [];
        try {
            $this->client->createObjectDir($this->bucket, $path, $options);
        } catch (OssException $e) {

            return FALSE;
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        try {
            $contents = $this->getObject($path);
        } catch (OssException $exception) {
            return FALSE;
        }

        return compact('contents');
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        try {
            $stream = $this->getObject($path);
        } catch (OssException $exception) {
            return FALSE;
        }

        return compact('stream');
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = FALSE)
    : array {
        $list = [];

        $result = $this->listDirObjects($directory, TRUE);

        if (!empty($result['objects'])) {
            foreach ($result['objects'] as $files) {
                if (!$fileInfo = $this->normalizeFileInfo($files)) {
                    continue;
                }

                $list[] = $fileInfo;
            }
        }

        return $list;
    }

    /**
     * File list core method.
     *
     * @param string $dirname
     * @param bool   $recursive
     *
     * @return array
     *
     * @throws OssException
     */
    public function listDirObjects($dirname = '', $recursive = FALSE)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;

        $result = [];

        while (TRUE) {
            $options = [
                'delimiter' => $delimiter,
                'prefix'    => $dirname,
                'max-keys'  => $maxkeys,
                'marker'    => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, $options);
            } catch (OssException $exception) {
                throw $exception;
            }

            $nextMarker = $listObjectInfo->getNextMarker();
            $objectList = $listObjectInfo->getObjectList();
            $prefixList = $listObjectInfo->getPrefixList();

            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix'] = $dirname;
                    $object['Key'] = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag'] = $objectInfo->getETag();
                    $object['Type'] = $objectInfo->getType();
                    $object['Size'] = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();
                    $result['objects'][] = $object;
                }
            } else {
                $result['objects'] = [];
            }

            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            } else {
                $result['prefix'] = [];
            }

            // Recursive directory
            if ($recursive) {
                foreach ($result['prefix'] as $prefix) {
                    $next = $this->listDirObjects($prefix, $recursive);
                    $result['objects'] = array_merge($result['objects'], $next['objects']);
                }
            }

            if ('' === $nextMarker) {
                break;
            }
        }

        return $result;
    }

    /**
     * normalize file info.
     *
     * @param array $stats
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats)
    {
        $filePath = ltrim($stats['Key'], '/');

        $meta = $this->getMetadata($filePath) ?? [];

        if (empty($meta)) {
            return [];
        }

        return [
            'type'      => 'file',
            'mimetype'  => $meta['content-type'],
            'path'      => $filePath,
            'timestamp' => $meta['info']['filetime'],
            'size'      => $meta['content-length'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $metadata = $this->client->getObjectMeta($this->bucket, $path);
        } catch (OssException $exception) {
            return FALSE;
        }

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string          $path
     * @param resource|string $contents
     * @param string          $mode
     *
     * @return array|false file metadata
     */
    protected function upload(string $path, $contents)
    {
        $path = $this->applyPathPrefix($path);
        $options = [];
        try {
            $this->client->putObject($this->bucket, $path, $contents, $options);
        } catch (OssException $e) {
            info(__FUNCTION__ . $e->getMessage());

            return FALSE;
        }

        return TRUE;
    }

    private function initClient()
    {
        try {
            $this->client = $this->client ?: new OssClient($this->accessId, $this->accessKey, $this->endpoint,
                $this->isCName);
        } catch (OssException $e) {
            info('OSS:', $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl = ($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        $this->client->putObjectAcl($this->bucket, $object, $acl);

        return compact('visibility');
    }
}
