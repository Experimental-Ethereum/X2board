<?php

namespace App\Services;

use App\Jobs\BatchTrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserService
{
    private function calcResetDayByMonthFirstDay()
    {
        $today = date('d');
        $lastDay = date('d', strtotime('last day of +0 months'));
        return $lastDay - $today;
    }

    private function calcResetDayByExpireDay(int $expiredAt)
    {
        $day = date('d', $expiredAt);
        $today = date('d');
        $lastDay = date('d', strtotime('last day of +0 months'));

        if ((int) $day >= (int) $today && (int) $day >= (int) $lastDay) {
            return $lastDay - $today;
        }

        if ((int) $day >= (int) $today) {
            return $day - $today;
        }

        return $lastDay - $today + $day;
    }

    private function calcResetDayByYearFirstDay(): int
    {
        $nextYear = strtotime(date("Y-01-01", strtotime('+1 year')));
        return (int) (($nextYear - time()) / 86400);
    }

    private function calcResetDayByYearExpiredAt(int $expiredAt): int
    {
        $md = date('m-d', $expiredAt);
        $nowYear = strtotime(date("Y-{$md}"));
        $nextYear = strtotime('+1 year', $nowYear);

        if ($nowYear > time()) {
            return (int) (($nowYear - time()) / 86400);
        }

        return (int) (($nextYear - time()) / 86400);
    }

    public function getResetDay(User $user)
    {
        if (!isset($user->plan)) {
            $user->plan = Plan::find($user->plan_id);
        }

        if ($user->expired_at <= time() || $user->expired_at === null) {
            return null;
        }

        if ($user->plan->reset_traffic_method === 2) {
            return null;
        }

        switch ($user->plan->reset_traffic_method) {
            case 0:
                return $this->calcResetDayByMonthFirstDay();
            case 1:
                return $this->calcResetDayByExpireDay($user->expired_at);
            case 3:
                return $this->calcResetDayByYearFirstDay();
            case 4:
                return $this->calcResetDayByYearExpiredAt($user->expired_at);
            default:
                $resetTrafficMethod = admin_setting('reset_traffic_method', 0);

                switch ((int) $resetTrafficMethod) {
                    case 0:
                        return $this->calcResetDayByMonthFirstDay();
                    case 1:
                        return $this->calcResetDayByExpireDay($user->expired_at);
                    case 3:
                        return $this->calcResetDayByYearFirstDay();
                    case 4:
                        return $this->calcResetDayByYearExpiredAt($user->expired_at);
                    case 2:
                    default:
                        return null;
                }
        }
    }

    public function isAvailable(User $user)
    {
        return !$user->banned && $user->transfer_enable && ($user->expired_at > time() || $user->expired_at === null);
    }

    public function getAvailableUsers()
    {
        return Cache::remember('available_users', 60, function () {
            return User::whereRaw('u + d < transfer_enable')
                ->where(function ($query) {
                    $query->where('expired_at', '>=', time())
                        ->orWhere('expired_at', null);
                })
                ->where('banned', 0)
                ->get();
        });
    }

    public function getUnAvailableUsers()
    {
        return Cache::remember('unavailable_users', 60, function () {
            return User::where(function ($query) {
                $query->where('expired_at', '<', time())
                    ->orWhere('expired_at', 0);
            })
                ->where(function ($query) {
                    $query->where('plan_id', null)
                        ->orWhere('transfer_enable', 0);
                })
                ->get();
        });
    }

    public function getUsersByIds($ids)
    {
        return Cache::remember('users_by_ids_' . implode('_', $ids), 60, function () use ($ids) {
            return User::whereIn('id', $ids)->get();
        });
    }

    public function getAllUsers()
    {
        return Cache::remember('all_users', 60, function () {
            return User::all();
        });
    }

    public function addBalance(int $userId, int $balance): bool
    {
        $user = DB::transaction(function () use ($userId, $balance) {
            $user = User::lockForUpdate()->find($userId);

            if (!$user) {
                return null;
            }

            $user->balance += $balance;

            if ($user->balance < 0 || !$user->save()) {
                return null;
            }

            return $user;
        });

        return $user !== null;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        return Cache::remember("not_complete_order_{$userId}", 60, function () use ($userId) {
            return Order::whereIn('status', [0, 1])
                ->where('user_id', $userId)
                ->exists();
        });
    }

    public function trafficFetch(array $server, string $protocol, array $data, string $nodeIp = null)
    {
        $timestamp = strtotime(date('Y-m-d'));
        $statService = new StatisticalService();
        $statService->setStartAt($timestamp);

        $childServer = ($server['parent_id'] === null && $nodeIp) ? ServerService::getChildServer($server['id'], $protocol, $nodeIp) : null;

        foreach ($data as $uid => $v) {
            $u = $v[0];
            $d = $v[1];
            $targetServer = $childServer ?? $server;
            $statService->statUser($targetServer['rate'], $uid, $u, $d);

            if ($childServer) {
                $statService->statServer($childServer['id'], $protocol, $u, $d);
            }

            $statService->statServer($server['id'], $protocol, $u, $d);
        }

        collect($data)->chunk(1000)->each(function ($chunk) use ($timestamp, $server, $protocol, $childServer) {
            BatchTrafficFetchJob::dispatch($server, $chunk->toArray(), $protocol, $timestamp, $childServer);
        });
    }
}
