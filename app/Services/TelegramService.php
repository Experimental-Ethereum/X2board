<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use \Curl\Curl;

class TelegramService
{
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . admin_setting('telegram_bot_token', $token) . '/';
    }

    /**
     * Send a message to a specific chat.
     *
     * @param int $chatId
     * @param string $text
     * @param string $parseMode
     */
    public function sendMessage(int $chatId, string $text, string $parseMode = '')
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ]);
    }

    /**
     * Approve a chat join request.
     *
     * @param int $chatId
     * @param int $userId
     */
    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    /**
     * Decline a chat join request.
     *
     * @param int $chatId
     * @param int $userId
     */
    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    /**
     * Get bot information.
     *
     * @return mixed
     */
    public function getMe()
    {
        return $this->request('getMe');
    }

    /**
     * Set the webhook URL for Telegram updates.
     *
     * @param string $url
     * @return mixed
     */
    public function setWebhook(string $url)
    {
        return $this->request('setWebhook', ['url' => $url]);
    }

    /**
     * Send a request to the Telegram API.
     *
     * @param string $method
     * @param array $params
     * @return mixed
     */
    private function request(string $method, array $params = [])
    {
        $curl = new Curl();
        $curl->get($this->api . $method . '?' . http_build_query($params));
        $response = $curl->response;
        $curl->close();

        if (!isset($response->ok)) {
            throw new ApiException('请求失败');
        }

        if (!$response->ok) {
            throw new ApiException('来自TG的错误：' . $response->description);
        }

        return $response;
    }

    /**
     * Send a message to all admins or staff members.
     *
     * @param string $message
     * @param bool $isStaff
     */
    public function sendMessageWithAdmin(string $message, bool $isStaff = false)
    {
        if (!admin_setting('telegram_bot_enable', 0)) return;

        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->whereNotNull('telegram_id')
            ->get();

        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }
}
