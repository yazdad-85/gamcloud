<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Pusher extends BaseConfig
{
    public string $appId = '';
    public string $key = '';
    public string $secret = '';
    public string $cluster = 'ap1';
    public bool $useTLS = true;

    public function __construct()
    {
        parent::__construct();

        $this->appId = (string) env('PUSHER_APP_ID', '');
        $this->key = (string) env('PUSHER_APP_KEY', '');
        $this->secret = (string) env('PUSHER_APP_SECRET', '');
        $this->cluster = (string) env('PUSHER_APP_CLUSTER', 'ap1');
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->key !== '' && $this->secret !== '';
    }
}
