<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class StatisticalService
{
    protected $userStats;
    protected $startAt;
    protected $endAt;
    protected $serverStats;
    protected $statServerKey;
    protected $statUserKey;
    protected $redis;

    public function __construct()
    {
        ini_set('memory_limit', -1);
        $this->redis = Redis::connection();
    }

    /**
     * Set the start timestamp for statistics.
     *
     * @param int $timestamp
     */
    public function setStartAt($timestamp)
    {
        $this->startAt = $timestamp;
        $this->statServerKey = "stat_server_{$this->startAt}";
        $this->statUserKey = "stat_user_{$this->startAt}";
    }

    /**
     * Set the end timestamp for statistics.
     *
     * @param int $timestamp
     */
    public function setEndAt($timestamp)
    {
        $this->endAt = $timestamp;
    }

    /**
     * Generate statistical data.
     *
     * @return array
     */
    public function generateStatData(): array
    {
        $startAt = $this->startAt ?? strtotime(date('Y-m-d'));
        $endAt = $this->endAt ?? strtotime('+1 day', $startAt);

        return [
            'order_count' => $this->countOrders($startAt, $endAt),
            'order_total' => $this->sumOrders($startAt, $endAt),
            'paid_count' => $this->countPaidOrders($startAt, $endAt),
            'paid_total' => $this->sumPaidOrders($startAt, $endAt),
            'commission_count' => $this->countCommissions($startAt, $endAt),
            'commission_total' => $this->sumCommissions($startAt, $endAt),
            'register_count' => $this->countRegistrations($startAt, $endAt),
            'invite_count' => $this->countInvites($startAt, $endAt),
            'transfer_used_total' => $this->sumTransferUsed($startAt, $endAt)
        ];
    }

    /**
     * Increment server statistics for traffic usage.
     *
     * @param int $serverId
     * @param string $serverType
     * @param int $u
     * @param int $d
     */
    public function statServer($serverId, $serverType, $u, $d)
    {
        $this->redis->zincrby($this->statServerKey, $u, "{$serverType}_{$serverId}_u");
        $this->redis->zincrby($this->statServerKey, $d, "{$serverType}_{$serverId}_d");
    }

    /**
     * Increment user statistics for traffic usage.
     *
     * @param float $rate
     * @param int $userId
     * @param int $u
     * @param int $d
     */
    public function statUser($rate, $userId, $u, $d)
    {
        $this->redis->zincrby($this->statUserKey, $u, "{$rate}_{$userId}_u");
        $this->redis->zincrby($this->statUserKey, $d, "{$rate}_{$userId}_d");
    }

    /**
     * Get user statistics by user ID.
     *
     * @param int $userId
     * @return array
     */
    public function getStatUserByUserID($userId): array
    {
        $stats = [];
        $statsUser = $this->redis->zrange($this->statUserKey, 0, -1, true);
        foreach ($statsUser as $member => $value) {
            list($rate, $uid, $type) = explode('_', $member);
            if ($uid !== $userId) continue;
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => floatval($rate),
                'u' => 0,
                'd' => 0,
                'user_id' => intval($userId),
            ];
            $stats[$key][$type] += $value;
        }
        return array_values($stats);
    }

    /**
     * Get cached user statistics.
     *
     * @return array
     */
    public function getStatUser(): array
    {
        $stats = [];
        $statsUser = $this->redis->zrange($this->statUserKey, 0, -1, true);
        foreach ($statsUser as $member => $value) {
            list($rate, $uid, $type) = explode('_', $member);
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => intval($uid),
            ];
            $stats[$key][$type] += $value;
        }
        return array_values($stats);
    }

    /**
     * Get cached server statistics.
     *
     * @return array
     */
    public function getStatServer(): array
    {
        $stats = [];
        $statsServer = $this->redis->zrange($this->statServerKey, 0, -1, true);
        foreach ($statsServer as $member => $value) {
            list($serverType, $serverId, $type) = explode('_', $member);
            $key = "{$serverType}_{$serverId}";
            $stats[$key] = $stats[$key] ?? [
                'server_id' => intval($serverId),
                'server_type' => $serverType,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$key][$type] += $value;
        }
        return array_values($stats);
    }

    /**
     * Clear cached user statistics.
     */
    public function clearStatUser()
    {
        $this->redis->del($this->statUserKey);
    }

    /**
     * Clear cached server statistics.
     */
    public function clearStatServer()
    {
        $this->redis->del($this->statServerKey);
    }

    /**
     * Get statistical records for a specific type.
     *
     * @param string $type
     * @return mixed
     */
    public function getStatRecord($type)
    {
        switch ($type) {
            case 'paid_total':
                return Stat::select(['*', DB::raw('paid_total / 100 as paid_total')])
                    ->whereBetween('record_at', [$this->startAt, $this->endAt])
                    ->orderBy('record_at', 'ASC')
                    ->get();
            case 'commission_total':
                return Stat::select(['*', DB::raw('commission_total / 100 as commission_total')])
                    ->whereBetween('record_at', [$this->startAt, $this->endAt])
                    ->orderBy('record_at', 'ASC')
                    ->get();
            case 'register_count':
                return Stat::whereBetween('record_at', [$this->startAt, $this->endAt])
                    ->orderBy('record_at', 'ASC')
                    ->get();
            default:
                return null;
        }
    }

    /**
     * Get ranking data for a specific type.
     *
     * @param string $type
     * @param int $limit
     * @return mixed
     */
    public function getRanking($type, $limit = 20)
    {
        switch ($type) {
            case 'server_traffic_rank':
                return $this->buildServerTrafficRank($limit);
            case 'user_consumption_rank':
                return $this->buildUserConsumptionRank($limit);
            case 'invite_rank':
                return $this->buildInviteRank($limit);
            default:
                return null;
        }
    }

    private function buildInviteRank($limit)
    {
        $stats = User::select(['invite_user_id', DB::raw('count(*) as count')])
            ->whereBetween('created_at', [$this->startAt, $this->endAt])
            ->whereNotNull('invite_user_id')
            ->groupBy('invite_user_id')
            ->orderBy('count', 'DESC')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $stats->pluck('invite_user_id'))->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            $stats[$k]['email'] = $users[$v['invite_user_id']]['email'] ?? null;
        }
        return $stats;
    }

    private function buildUserConsumptionRank($limit)
    {
        $stats = StatUser::select(['user_id', DB::raw('sum(u) as u'), DB::raw('sum(d) as d'), DB::raw('sum(u) + sum(d) as total')])
            ->whereBetween('record_at', [$this->startAt, $this->endAt])
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $stats->pluck('user_id'))->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            $stats[$k]['email'] = $users[$v['user_id']]['email'] ?? null;
        }
        return $stats;
    }

    private function buildServerTrafficRank($limit)
    {
        return StatServer::select(['server_id', 'server_type', DB::raw('sum(u) as u'), DB::raw('sum(d) as d'), DB::raw('sum(u) + sum(d) as total')])
            ->whereBetween('record_at', [$this->startAt, $this->endAt])
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }

    private function countOrders($startAt, $endAt)
    {
        return Order::whereBetween('created_at', [$startAt, $endAt])->count();
    }

    private function sumOrders($startAt, $endAt)
    {
        return Order::whereBetween('created_at', [$startAt, $endAt])->sum('total_amount');
    }

    private function countPaidOrders($startAt, $endAt)
    {
        return Order::whereBetween('paid_at', [$startAt, $endAt])
            ->whereNotIn('status', [0, 2])
            ->count();
    }

    private function sumPaidOrders($startAt, $endAt)
    {
        return Order::whereBetween('paid_at', [$startAt, $endAt])
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');
    }

    private function countCommissions($startAt, $endAt)
    {
        return CommissionLog::whereBetween('created_at', [$startAt, $endAt])->count();
    }

    private function sumCommissions($startAt, $endAt)
    {
        return CommissionLog::whereBetween('created_at', [$startAt, $endAt])->sum('get_amount');
    }

    private function countRegistrations($startAt, $endAt)
    {
        return User::whereBetween('created_at', [$startAt, $endAt])->count();
    }

    private function countInvites($startAt, $endAt)
    {
        return User::whereBetween('created_at', [$startAt, $endAt])->whereNotNull('invite_user_id')->count();
    }

    private function sumTransferUsed($startAt, $endAt)
    {
        return StatServer::whereBetween('created_at', [$startAt, $endAt])
            ->select(DB::raw('SUM(u) + SUM(d) as total'))
            ->value('total') ?? 0;
    }
}
