<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CouponService
{
    public $coupon;
    public $planId;
    public $userId;
    public $period;

    public function __construct($code)
    {
        $this->coupon = Coupon::where('code', $code)
            ->lockForUpdate()
            ->first();
    }

    public function use(Order $order): bool
    {
        $this->setPlanId($order->plan_id);
        $this->setUserId($order->user_id);
        $this->setPeriod($order->period);
        $this->check();

        $order->discount_amount = match($this->coupon->type) {
            1 => $this->coupon->value,
            2 => $order->total_amount * ($this->coupon->value / 100),
            default => $order->discount_amount
        };

        $order->discount_amount = min($order->discount_amount, $order->total_amount);

        if ($this->coupon->limit_use !== null && $this->coupon->limit_use <= 0) {
            return false;
        }

        if ($this->coupon->limit_use !== null) {
            $this->coupon->limit_use -= 1;
            if (!$this->coupon->save()) {
                return false;
            }
        }
        return true;
    }

    public function getId()
    {
        return $this->coupon->id;
    }

    public function getCoupon()
    {
        return $this->coupon;
    }

    public function setPlanId($planId)
    {
        $this->planId = $planId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    public function setPeriod($period)
    {
        $this->period = $period;
    }

    public function checkLimitUseWithUser(): bool
    {
        $usedCount = Order::where('coupon_id', $this->coupon->id)
            ->where('user_id', $this->userId)
            ->whereNotIn('status', [0, 2])
            ->count();
        return $usedCount < $this->coupon->limit_use_with_user;
    }

    public function check()
    {
        if (!$this->coupon || !$this->coupon->show) {
            throw new ApiException(__('Invalid coupon'));
        }
        if ($this->coupon->limit_use <= 0 && $this->coupon->limit_use !== null) {
            throw new ApiException(__('This coupon is no longer available'));
        }
        if (time() < $this->coupon->started_at) {
            throw new ApiException(__('This coupon has not yet started'));
        }
        if (time() > $this->coupon->ended_at) {
            throw new ApiException(__('This coupon has expired'));
        }
        if ($this->coupon->limit_plan_ids && $this->planId && !in_array($this->planId, $this->coupon->limit_plan_ids)) {
            throw new ApiException(__('The coupon code cannot be used for this subscription'));
        }
        if ($this->coupon->limit_period && $this->period && !in_array($this->period, $this->coupon->limit_period)) {
            throw new ApiException(__('The coupon code cannot be used for this period'));
        }
        if ($this->coupon->limit_use_with_user !== null && $this->userId && !$this->checkLimitUseWithUser()) {
            throw new ApiException(__('The coupon can only be used :limit_use_with_user per person', [
                'limit_use_with_user' => $this->coupon->limit_use_with_user
            ]));
        }
    }
}
