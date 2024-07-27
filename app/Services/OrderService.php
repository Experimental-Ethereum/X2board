<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    const STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];

    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Open an order, process payment, and update user information
     */
    public function open()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        if ($order->refund_amount) {
            $this->user->balance += $order->refund_amount;
        }

        try {
            DB::beginTransaction();

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => Order::STATUS_DISCOUNTED
                ]);
            }

            switch ($order->period) {
                case 'onetime_price':
                    $this->buyByOneTime($plan);
                    break;
                case 'reset_price':
                    $this->buyByResetTraffic();
                    break;
                default:
                    $this->buyByPeriod($order, $plan);
            }

            switch ($order->type) {
                case Order::TYPE_NEW_PURCHASE:
                    $this->openEvent(admin_setting('new_order_event_id', 0));
                    break;
                case Order::TYPE_RENEWAL:
                    $this->openEvent(admin_setting('renew_order_event_id', 0));
                    break;
                case Order::TYPE_UPGRADE:
                    $this->openEvent(admin_setting('change_order_event_id', 0));
                    break;
            }

            $this->setSpeedLimit($plan->speed_limit);

            if (!$this->user->save()) {
                throw new \Exception('Failed to save user information');
            }

            $order->status = Order::STATUS_COMPLETED;

            if (!$order->save()) {
                throw new \Exception('Failed to save order information');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            throw new ApiException('Order activation failed');
        }
    }

    /**
     * Determine the type of order (new purchase, renewal, or upgrade)
     */
    public function setOrderType(User $user)
    {
        $order = $this->order;

        if ($order->period === 'reset_price') {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } elseif ($user->plan_id !== null && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === null)) {
            if (!(int)admin_setting('plan_change_enable', 1)) {
                throw new ApiException('Plan changes are not allowed at this time. Please contact support.');
            }

            $order->type = Order::TYPE_UPGRADE;

            if ((int)admin_setting('surplus_enable', 1)) {
                $this->getSurplusValue($user, $order);
            }

            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount -= $order->surplus_amount;
            }
        } elseif ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
            $order->type = Order::TYPE_RENEWAL;
        } else {
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    /**
     * Apply VIP discount to the order
     */
    public function setVipDiscount(User $user)
    {
        $order = $this->order;

        if ($user->discount) {
            $order->discount_amount += $order->total_amount * ($user->discount / 100);
        }

        $order->total_amount -= $order->discount_amount;
    }

    /**
     * Set invite details for the order and calculate commission balance
     */
    public function setInvite(User $user): void
    {
        $order = $this->order;

        if ($user->invite_user_id && $order->total_amount <= 0) {
            return;
        }

        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);

        if (!$inviter) {
            return;
        }

        $isCommission = false;

        switch ($inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)admin_setting('commission_first_time_enable', 1);
                $isCommission = !$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) {
            return;
        }

        $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
    }

    /**
     * Check if user has a valid order
     */
    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->exists();
    }

    /**
     * Calculate surplus value for the order
     */
    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === null) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }

    /**
     * Calculate surplus value for one-time purchase
     */
    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', Order::STATUS_COMPLETED)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$lastOneTimeOrder) {
            return;
        }

        $nowUserTraffic = $user->transfer_enable / 1073741824;

        if (!$nowUserTraffic) {
            return;
        }

        $paidTotalAmount = $lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount;

        if (!$paidTotalAmount) {
            return;
        }

        $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $result = $trafficUnitPrice * $notUsedTraffic;

        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', 'reset_price')->where('status', Order::STATUS_COMPLETED);
        $order->surplus_amount = $result > 0 ? $result : 0;
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    /**
     * Calculate surplus value for period purchases
     */
    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->whereNotIn('period', ['reset_price', 'onetime_price'])
            ->where('status', Order::STATUS_COMPLETED)
            ->get()
            ->toArray();

        if (!$orders) {
            return;
        }

        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = 0;

        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            if (strtotime("+{$period} month", $item['created_at']) < time()) {
                continue;
            }
            $lastValidateAt = $item['created_at'];
            $orderMonthSum += $period;
            $orderAmountSum += ($item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount']);
        }

        if (!$lastValidateAt) {
            return;
        }

        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        if ($expiredAtByOrder < time()) {
            return;
        }

        $orderSurplusSecond = $expiredAtByOrder - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidate
