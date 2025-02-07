<?php

namespace WP_SMS\Gateway;


use Exception;
use WP_Error;

class prosms extends \WP_SMS\Gateway
{
    private $wsdl_link = "https://api.prosms.se/v1";
    public $tariff = "https://prosms.se/";
    public $flash = "false";
    public $isflash = false;
    public $unitrial = true;
    public $unit;

    public function __construct()
    {
        parent::__construct();
        $this->validateNumber = '46*********';
        $this->has_key        = true;
        $this->help           = "Note: that you need to get every sendername approved before you can use it as sendername.You can do it by going to your gateway account then go to this path : Account setting > SENDER NAME > Add";
        $this->gatewayFields  = [
            'from'    => [
                'id'   => 'gateway_sender_name',
                'name' => 'Sender name',
                'desc' => 'Sender name',
            ],
            'has_key' => [
                'id'   => 'gateway_key',
                'name' => 'API key',
                'desc' => 'Enter API key of gateway'
            ]
        ];
    }

    public function SendSMS()
    {
        /**
         * Modify sender id
         */
        $this->from = apply_filters('wp_sms_from', $this->from);

        /**
         * Modify Receiver number
         */
        $this->to = apply_filters('wp_sms_to', $this->to);

        /**
         * Modify text message
         */
        $this->msg = apply_filters('wp_sms_msg', $this->msg);

        try {
            $arguments = array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer $this->has_key",
                ),
                'body'    => json_encode(array(
                    'receiver'   => implode(',', $this->to),
                    'senderName' => $this->from,
                    'message'    => $this->msg
                ))
            );

            $response = $this->request('POST', "{$this->wsdl_link}/sms/send", [], $arguments, false);

            //check sender name
            if ($response->messageCode == '1017') {
                throw new Exception($response->errorResult);
            }

            if ($response->status == 'error') {
                if (isset($response->errorResult)) {
                    throw new Exception($this->buildErrorReportResult($response->errorResult));
                } else {
                    throw new Exception($response->message);
                }
            }


            $responseLog = [];
            foreach ($response->result->report->accepted as $item) {
                $responseLog[] = "{$response->status} Result <br>From {$item->receiver} - {$item->country}";
            }
            // Log the result
            $this->log($this->from, $this->msg, $this->to, implode(', ', $responseLog));

            /*
             * Run hook after send sms.
             *
             */
            do_action('wp_sms_send', $response);

            return $response;
        } catch (Exception $e) {
            $this->log($this->from, $this->msg, $this->to, $e->getMessage(), 'error');
            return new WP_Error('send-sms', $e->getMessage());
        }
    }

    public function GetCredit()
    {
        try {
            // Check Api key
            if (!$this->has_key or !isset($this->has_key)) {
                throw new Exception(__('Api key for this gateway is required.', 'wp-sms-pro'));
            }

            $arguments = [
                'headers' => array(
                    'Authorization' => "Bearer $this->has_key",
                )
            ];

            $response = $this->request('GET', "{$this->wsdl_link}/user/getcreditvalue", [], $arguments, false);

            if ($response->status == 'error') {
                throw new Exception($response->message);
            }


            return $response->result;

        } catch (\Throwable $e) {
            return new \WP_Error('get-credit', $e->getMessage());
        }
    }

    private function buildErrorReportResult($response)
    {
        $errors = [];
        foreach ($response->report->rejected as $item) {
            $errors[] = "Number {$item->receiver} - {$item->message}";
        }

        return implode(', ', $errors);
    }
}

