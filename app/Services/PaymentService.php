<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = null, $uuid = null)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) {
            throw new ApiException('Payment gateway not found');
        }

        $payment = null;
        if ($id) {
            $payment = Payment::find($id);
        } elseif ($uuid) {
            $payment = Payment::where('uuid', $uuid)->first();
        }

        if (!$payment) {
            throw new ApiException('Payment record not found');
        }

        $this->config = array_merge([
            'enable' => $payment->enable,
            'id' => $payment->id,
            'uuid' => $payment->uuid,
            'notify_domain' => $payment->notify_domain,
        ], $payment->config);

        $this->payment = new $this->class($this->config);
    }

    /**
     * Handle payment notification
     *
     * @param array $params Notification parameters
     * @return mixed
     * @throws ApiException
     */
    public function notify($params)
    {
        if (!$this->config['enable']) {
            throw new ApiException('Payment gateway is not enabled');
        }

        return $this->payment->notify($params);
    }

    /**
     * Process payment
     *
     * @param array $order Order details
     * @return mixed
     */
    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => url('/#/order/' . $order['trade_no']),
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    /**
     * Get payment form
     *
     * @return array Payment form details
     */
    public function form()
    {
        $form = $this->payment->form();
        foreach ($form as $key => $field) {
            if (isset($this->config[$key])) {
                $form[$key]['value'] = $this->config[$key];
            }
        }
        return $form;
    }

    /**
     * Log payment details for debugging
     *
     * @param string $message Log message
     */
    private function logPaymentDetails($message)
    {
        Log::info($message, [
            'method' => $this->method,
            'config' => $this->config
        ]);
    }

    /**
     * Validate payment method
     *
     * @throws ApiException
     */
    private function validateMethod()
    {
        if (!class_exists($this->class)) {
            throw new ApiException('Payment method not found');
        }
    }

    /**
     * Configure payment method
     *
     * @param array $config Configuration parameters
     */
    public function configure(array $config)
    {
        $this->config = array_merge($this->config, $config);
        $this->payment = new $this->class($this->config);
    }

    /**
     * Get payment status
     *
     * @param string $tradeNo Trade number
     * @return mixed Payment status
     */
    public function getStatus($tradeNo)
    {
        return $this->payment->getStatus($tradeNo);
    }
}
