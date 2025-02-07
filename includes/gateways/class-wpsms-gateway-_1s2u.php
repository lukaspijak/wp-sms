<?php

namespace WP_SMS\Gateway;

use Exception;
use WP_Error;

class _1s2u extends \WP_SMS\Gateway
{
    private $wsdl_link = "https://api.1s2u.io";
    public $tariff = "https://1s2u.com";
    public $unitrial = false;
    public $unit;
    public $flash = "enable";
    public $isflash = false;

    public function __construct()
    {
        parent::__construct();
        $this->bulk_send      = true;
        $this->has_key        = false;
        $this->validateNumber = "The phone number must contain only digits together with the country code. It should not contain any other symbols such as (+) sign.  Instead  of  plus  sign,  please  put  (00)" . PHP_EOL . "e.g seperate numbers with comma: 12345678900, 11222338844";
        $this->help           = "";
        $this->gatewayFields  = [
            'username' => [
                'id'   => 'gateway_username',
                'name' => 'Registered Username',
                'desc' => 'Enter your username.',
            ],
            'password' => [
                'id'   => 'gateway_password',
                'name' => 'Password',
                'desc' => 'Enter your password.',
            ],
        ];
    }

    public function SendSMS()
    {

        /**
         * Modify sender number
         *
         * @param string $this ->from sender number.
         *
         * @since 3.4
         *
         */
        $this->from = apply_filters('wp_sms_from', $this->from);

        /**
         * Modify Receiver number
         *
         * @param array $this ->to receiver number
         *
         * @since 3.4
         *
         */
        $this->to = apply_filters('wp_sms_to', $this->to);

        /**
         * Modify text message
         *
         * @param string $this ->msg text message.
         *
         * @since 3.4
         *
         */
        $this->msg = apply_filters('wp_sms_msg', $this->msg);

        try {

            $mt = 0;
            if (isset($this->options['send_unicode']) and $this->options['send_unicode']) {
                $mt = 1;
            }

            $fl = 0;
            if ($this->isflash) {
                $fl = 1;
            }

            $numbers = array();

            foreach ($this->to as $number) {
                $numbers[] = $this->clean_number($number);
            }

            $arguments = array(
                'username' => $this->username,
                'password' => $this->password,
                'mno'      => implode(',', $numbers),
                'Sid'      => $this->from,
                'msg'      => urlencode($this->msg),
                'mt'       => $mt,
                'fl'       => $fl
            );

            $response = $this->request('POST', "{$this->wsdl_link}/bulksms", [], $arguments);

            //todo error handler

            //log the result
            $this->log($this->from, $this->msg, $this->to, $response);

            /**
             * Run hook after send sms.
             *
             * @param string $response result output.
             * @since 2.4
             *
             */
            do_action('wp_sms_send', $response);

            return $response;

        } catch (Exception $e) {
            $this->log($this->from, $this->msg, $this->to, $e->getMessage(), 'error');

            return new WP_Error('send-sms', $e->getMessage());
        }

    }

    /**
     * @return string | WP_Error
     * @throws Exception
     */
    public function GetCredit()
    {

        try {

            // Check username and password
            if (!$this->username && !$this->password) {
                throw new Exception(__('Username and password are required.', 'wp-sms'));
            }

            $arguments = [
                'USER' => $this->username,
                'PASS' => $this->password
            ];

            $response = $this->request('POST', "{$this->wsdl_link}/checkbalance", [], $arguments);

            if (!isset($response)) {
                throw new Exception($response);
            }

            return $response;

        } catch (Exception $e) {
            return new WP_Error('account-credit', $e->getMessage());
        }

    }

    /**
     * Clean number
     *
     * @param $number
     *
     * @return string
     */
    private function clean_number($number)
    {
        $number = str_replace('+', '00', $number);

        return trim($number);
    }

}
