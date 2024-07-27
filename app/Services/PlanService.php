<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanService
{
    public $plan;

    public function __construct(int $planId)
    {
        $this->plan = Plan::lockForUpdate()->find($planId);
        if (!$this->plan) {
            throw new \Exception("Plan not found");
        }
    }

    /**
     * Check if the plan has available capacity
     *
     * @return bool
     */
    public function haveCapacity(): bool
    {
        if ($this->plan->capacity_limit === null) {
            return true;
        }

        $count = self::countActiveUsers();
        $activeUserCount = $count[$this->plan->id]['count'] ?? 0;

        return ($this->plan->capacity_limit - $activeUserCount) > 0;
    }

    /**
     * Count active users for each plan
     *
     * @return \Illuminate\Support\Collection
     */
    public static function countActiveUsers()
    {
        return User::select(
            DB::raw("plan_id"),
            DB::raw("count(*) as count")
        )
            ->where('plan_id', '!=', null)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', null);
            })
            ->groupBy("plan_id")
            ->get()
            ->keyBy('plan_id');
    }

    /**
     * Get plan details
     *
     * @return array
     */
    public function getPlanDetails(): array
    {
        return $this->plan->toArray();
    }

    /**
     * Update plan capacity
     *
     * @param int $newCapacity
     * @return bool
     */
    public function updateCapacity(int $newCapacity): bool
    {
        $this->plan->capacity_limit = $newCapacity;
        return $this->plan->save();
    }

    /**
     * Calculate the remaining capacity of the plan
     *
     * @return int
     */
    public function calculateRemainingCapacity(): int
    {
        if ($this->plan->capacity_limit === null) {
            return PHP_INT_MAX;
        }

        $count = self::countActiveUsers();
        $activeUserCount = $count[$this->plan->id]['count'] ?? 0;

        return $this->plan->capacity_limit - $activeUserCount;
    }

    /**
     * Get all plans
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAllPlans()
    {
        return Plan::all();
    }
}
