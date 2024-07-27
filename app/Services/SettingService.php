<?php

namespace App\Services;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    /**
     * Get a specific setting value by name.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get($name, $default = null)
    {
        return Cache::remember("setting_{$name}", 3600, function () use ($name, $default) {
            $setting = SettingModel::where('name', $name)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Get all settings as an associative array.
     *
     * @return array
     */
    public function getAll()
    {
        return Cache::remember('settings_all', 3600, function () {
            return SettingModel::all()->pluck('value', 'name')->toArray();
        });
    }

    /**
     * Set a specific setting value by name.
     *
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public function set($name, $value)
    {
        $setting = SettingModel::updateOrCreate(['name' => $name], ['value' => $value]);
        Cache::forget("setting_{$name}");
        Cache::forget('settings_all');
        return $setting->wasRecentlyCreated || $setting->wasChanged();
    }

    /**
     * Delete a specific setting by name.
     *
     * @param string $name
     * @return bool
     */
    public function delete($name)
    {
        $deleted = SettingModel::where('name', $name)->delete();
        if ($deleted) {
            Cache::forget("setting_{$name}");
            Cache::forget('settings_all');
        }
        return (bool) $deleted;
    }

    /**
     * Clear all cached settings.
     */
    public function clearCache()
    {
        Cache::forget('settings_all');
        $settings = SettingModel::all()->pluck('name');
        foreach ($settings as $name) {
            Cache::forget("setting_{$name}");
        }
    }
}
