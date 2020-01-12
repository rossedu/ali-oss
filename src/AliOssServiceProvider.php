<?php

namespace Ross\AliOss;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use OSS\OssClient;

class AliOssServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('ross-oss', function ($app, $config) {

            $accessId = $config['access_id'];
            $accessKey = $config['access_key'];

            $cdnDomain = $config['cdnDomain'] ?? '';
            $bucket = $config['bucket'];
            $ssl = $config['ssl'] ?? FALSE;
            $isCName = $config['isCName'] ?? FALSE;
            $debug = $config['debug'] ?? FALSE;
            $endpoint = $config['endpoint'];

            return new Filesystem(new AliOSSAdapter($accessId, $accessKey, $endpoint, $bucket, $isCName));
        });

    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    }

}
