<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class ThemeService
{
    private $path;
    private $theme;

    public function __construct($theme)
    {
        $this->theme = $theme;
        $this->path = public_path('theme/');
    }

    public function init()
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) {
            abort(500, "{$this->theme}主题不存在");
        }

        $themeConfig = Cache::remember("theme_config_{$this->theme}", 60, function () use ($themeConfigFile) {
            return json_decode(File::get($themeConfigFile), true);
        });

        if (!isset($themeConfig['configs']) || !is_array($themeConfig)) {
            abort(500, "{$this->theme}主题配置文件有误");
        }

        $configs = $themeConfig['configs'];
        $data = [];

        foreach ($configs as $config) {
            $data[$config['field_name']] = $config['default_value'] ?? '';
        }

        try {
            admin_setting(["theme_{$this->theme}" => $data]);
        } catch (\Exception $e) {
            abort(500, "{$this->theme}初始化失败");
        }
    }
}
