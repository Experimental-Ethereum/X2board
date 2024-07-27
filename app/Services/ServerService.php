<?php

namespace App\Services;

use App\Models\ServerHysteria;
use App\Models\ServerLog;
use App\Models\ServerRoute;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\User;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ServerService
{
    /**
     * Get available VLESS servers
     *
     * @param User $user
     * @return array
     */
    public static function getAvailableVless(User $user): array
    {
        $servers = [];
        $model = ServerVless::orderBy('sort', 'ASC');
        $server = $model->get();
        foreach ($server as $key => $v) {
            if (!$v['show']) continue;
            $serverData = $v->toArray();
            $serverData['type'] = 'vless';
            if (!in_array($user->group_id, $serverData['group_id'])) continue;
            if (strpos($serverData['port'], '-') !== false) {
                $serverData['port'] = Helper::randomPort($serverData['port']);
            }
            if ($serverData['parent_id']) {
                $serverData['last_check_at'] = Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $serverData['parent_id']));
            } else {
                $serverData['last_check_at'] = Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $serverData['id']));
            }
            if (isset($serverData['tls_settings']) && isset($serverData['tls_settings']['private_key'])) {
                unset($serverData['tls_settings']['private_key']);
            }
            $servers[] = $serverData;
        }
        return $servers;
    }

    /**
     * Get available VMESS servers
     *
     * @param User $user
     * @return array
     */
    public static function getAvailableVmess(User $user): array
    {
        $servers = [];
        $model = ServerVmess::orderBy('sort', 'ASC');
        $vmess = $model->get();
        foreach ($vmess as $key => $v) {
            if (!$v['show']) continue;
            $vmess[$key]['type'] = 'vmess';
            if (!in_array($user->group_id, $vmess[$key]['group_id'])) continue;
            if (strpos($vmess[$key]['port'], '-') !== false) {
                $vmess[$key]['port'] = Helper::randomPort($vmess[$key]['port']);
            }
            if ($vmess[$key]['parent_id']) {
                $vmess[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $vmess[$key]['parent_id']));
            } else {
                $vmess[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $vmess[$key]['id']));
            }
            $servers[] = $vmess[$key]->toArray();
        }
        return $servers;
    }

    /**
     * Get available TROJAN servers
     *
     * @param User $user
     * @return array
     */
    public static function getAvailableTrojan(User $user): array
    {
        $servers = [];
        $model = ServerTrojan::orderBy('sort', 'ASC');
        $trojan = $model->get();
        foreach ($trojan as $key => $v) {
            if (!$v['show']) continue;
            $trojan[$key]['type'] = 'trojan';
            if (!in_array($user->group_id, $trojan[$key]['group_id'])) continue;
            if (strpos($trojan[$key]['port'], '-') !== false) {
                $trojan[$key]['port'] = Helper::randomPort($trojan[$key]['port']);
            }
            if ($trojan[$key]['parent_id']) {
                $trojan[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $trojan[$key]['parent_id']));
            } else {
                $trojan[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $trojan[$key]['id']));
            }
            $servers[] = $trojan[$key]->toArray();
        }
        return $servers;
    }

    /**
     * Get available HYSTERIA servers
     *
     * @param User $user
     * @return array
     */
    public static function getAvailableHysteria(User $user): array
    {
        $servers = [];
        $model = ServerHysteria::orderBy('sort', 'ASC');
        $hysteria = $model->get();
        foreach ($hysteria as $key => $v) {
            if (!$v['show']) continue;
            $hysteria[$key]['type'] = 'hysteria';
            $hysteria[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_HYSTERIA_LAST_CHECK_AT', $v['id']));
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $hysteria[$key]['port'] = Helper::randomPort($v['port']);
            }
            $servers[] = $hysteria[$key]->toArray();
        }
        return $servers;
    }

    /**
     * Get available SHADOWSOCKS servers
     *
     * @param User $user
     * @return array
     */
    public static function getAvailableShadowsocks(User $user): array
    {
        $servers = [];
        $model = ServerShadowsocks::orderBy('sort', 'ASC');
        $shadowsocks = $model->get();
        foreach ($shadowsocks as $key => $v) {
            if (!$v['show']) continue;
            $shadowsocks[$key]['type'] = 'shadowsocks';
            $shadowsocks[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_SHADOWSOCKS_LAST_CHECK_AT', $v['id']));
            if (!in_array($user->group_id, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $shadowsocks[$key]['port'] = Helper::randomPort($v['port']);
            }
            // Handle ss2022 password
            $cipherConfiguration = [
                '2022-blake3-aes-128-gcm' => [
                    'serverKeySize' => 16,
                    'userKeySize' => 16,
                ],
                '2022-blake3-aes-256-gcm' => [
                    'serverKeySize' => 32,
                    'userKeySize' => 32,
                ],
                '2022-blake3-chacha20-poly1305' => [
                    'serverKeySize' => 32,
                    'userKeySize' => 32,
                ]
            ];
            $shadowsocks[$key]['password'] = $user['uuid'];
            if (array_key_exists($v['cipher'], $cipherConfiguration)) {
                $config = $cipherConfiguration[$v['cipher']];
                $serverKey = Helper::getServerKey($v['created_at'], $config['serverKeySize']);
                $userKey = Helper::uuidToBase64($user['uuid'], $config['userKeySize']);
                $shadowsocks[$key]['password'] = "{$serverKey}:{$userKey}";
            }
            $servers[] = $shadowsocks[$key]->toArray();
        }
        return $servers;
    }

    /**
     * Get all available servers
     *
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Cache::remember('serversAvailable_' . $user->id, 5, function () use ($user) {
            return array_merge(
                self::getAvailableShadowsocks($user),
                self::getAvailableVmess($user),
                self::getAvailableTrojan($user),
                self::getAvailableHysteria($user),
                self::getAvailableVless($user)
            );
        });
        usort($servers, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        return array_map(function ($server) {
            $server['port'] = (int)$server['port'];
            $server['is_online'] = (time() - 300 > $server['last_check_at']) ? 0 : 1;
            $server['cache_key'] = "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}";
            return $server;
        }, $servers);
    }

    /**
     * Get available users by group ID
     *
     * @param array $groupId
     * @return Collection
     */
    public static function getAvailableUsers(array $groupId): Collection
    {
        return \DB::table('v2_user')
            ->whereIn('group_id', $groupId)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', null);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit'
            ])
            ->get();
    }

    /**
     * Log server traffic
     *
     * @param int $userId
     * @param int $serverId
     * @param int $u
     * @param int $d
     * @param float $rate
     * @param string $method
     * @return bool
     */
    public static function log(int $userId, int $serverId, int $u, int $d, float $rate, string $method): bool
    {
        if (($u + $d) < 10240) return true;
        $timestamp = strtotime(date('Y-m-d'));
        $serverLog = ServerLog::where('log_at', '>=', $timestamp)
            ->where('log_at', '<', $timestamp + 3600)
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('rate', $rate)
            ->where('method', $method)
            ->first();
        if ($serverLog) {
            try {
                $serverLog->increment('u', $u);
                $serverLog->increment('d', $d);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $serverLog = new ServerLog();
            $serverLog->user_id = $userId;
            $serverLog->server_id = $serverId;
            $serverLog->u = $u;
            $serverLog->d = $d;
            $serverLog->rate = $rate;
            $serverLog->log_at = $timestamp;
            $serverLog->method = $method;
            return $serverLog->save();
        }
    }

    /**
     * Get all SHADOWSOCKS servers
     *
     * @return array
     */
    public static function getAllShadowsocks(): array
    {
        $servers = ServerShadowsocks::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'shadowsocks';
        }
        return $servers;
    }

    /**
     * Get all VMESS servers
     *
     * @return array
     */
    public static function getAllVMess(): array
    {
        $servers = ServerVmess::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vmess';
        }
        return $servers;
    }

    /**
     * Get all VLESS servers
     *
     * @return array
     */
    public static function getAllVLess(): array
    {
        $servers = ServerVless::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vless';
        }
        return $servers;
    }

    /**
     * Get all TROJAN servers
     *
     * @return array
     */
    public static function getAllTrojan(): array
    {
        $servers = ServerTrojan::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'trojan';
        }
        return $servers;
    }

    /**
     * Get all HYSTERIA servers
     *
     * @return array
     */
    public static function getAllHysteria(): array
    {
        $servers = ServerHysteria::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'hysteria';
        }
        return $servers;
    }

    /**
     * Merge server data with additional information
     *
     * @param array $servers
     */
    private static function mergeData(array &$servers)
    {
        foreach ($servers as $k => $v) {
            $serverType = strtoupper($v['type']);
            $servers[$k]['online'] = Cache::get(CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $v['parent_id'] ?? $v['id'])) ?? 0;

            if ($pid = $v['parent_id']) {
                $onlineUsers = Cache::get(CacheKey::get('MULTI_SERVER_' . $serverType . '_ONLINE_USER', $pid)) ?? [];
                $servers[$k]['online'] = (collect($onlineUsers)->whereIn('ip', $v['ips'])->sum('online_user')) . "|{$servers[$k]['online']}";
            }

            $servers[$k]['last_check_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_push_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $v['parent_id'] ?? $v['id']));

            if ((time() - 300) >= $servers[$k]['last_check_at']) {
                $servers[$k]['available_status'] = 0;
            } elseif ((time() - 300) >= $servers[$k]['last_push_at']) {
                $servers[$k]['available_status'] = 1;
            } else {
                $servers[$k]['available_status'] = 2;
            }
        }
    }

    /**
     * Get all servers
     *
     * @return array
     */
    public static function getAllServers(): array
    {
        $servers = array_merge(
            self::getAllShadowsocks(),
            self::getAllVMess(),
            self::getAllTrojan(),
            self::getAllHysteria(),
            self::getAllVLess()
        );
        self::mergeData($servers);
        usort($servers, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        return $servers;
    }

    /**
     * Get server routes
     *
     * @param array $routeIds
     * @return array
     */
    public static function getRoutes(array $routeIds): array
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->get()
            ->toArray();

        foreach ($routes as $k => $route) {
            $array = json_decode($route['match'], true);
            if (is_array($array)) {
                $routes[$k]['match'] = $array;
            }
        }
        return $routes;
    }

    /**
     * Get server by ID and type
     *
     * @param int $serverId
     * @param string $serverType
     * @return mixed
     */
    public static function getServer(int $serverId, string $serverType)
    {
        switch ($serverType) {
            case 'vmess':
                return ServerVmess::find($serverId);
            case 'shadowsocks':
                return ServerShadowsocks::find($serverId);
            case 'trojan':
                return ServerTrojan::find($serverId);
            case 'hysteria':
                return ServerHysteria::find($serverId);
            case 'vless':
                return ServerVless::find($serverId);
            default:
                return false;
        }
    }

    /**
     * Get child server by node IP and parent server ID
     *
     * @param int $serverId
     * @param string $serverType
     * @param string $nodeIp
     * @return mixed
     */
    public static function getChildServer(int $serverId, string $serverType, string $nodeIp)
    {
        switch ($serverType) {
            case 'vmess':
                return ServerVmess::where('parent_id', $serverId)
                    ->where('ips', 'like', "%\"$nodeIp\"%")
                    ->first();
            case 'shadowsocks':
                return ServerShadowsocks::where('parent_id', $serverId)
                    ->where('ips', 'like', "%\"$nodeIp\"%")
                    ->first();
            case 'trojan':
                return ServerTrojan::where('parent_id', $serverId)
                    ->where('ips', 'like', "%\"$nodeIp\"%")
                    ->first();
            case 'hysteria':
                return ServerHysteria::where('parent_id', $serverId)
                    ->where('ips', 'like', "%\"$nodeIp\"%")
                    ->first();
            case 'vless':
                return ServerVless::where('parent_id', $serverId)
                    ->where('ips', 'like', "%\"$nodeIp\"%")
                    ->first();
            default:
                return null;
        }
    }
}
