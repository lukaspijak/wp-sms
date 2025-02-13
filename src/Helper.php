<?php

namespace WP_SMS;


/**
 * Class WP_SMS
 * @package WP_SMS
 * @description The helper that provides the useful methods for the plugin for development purposes.
 */
class Helper
{
    public static function getPluginAssetUrl($assetName, $plugin = 'wp-sms')
    {
        return plugins_url($plugin) . "/assets/{$assetName}";
    }

    public static function getAssetPath($asset)
    {
        return plugin_dir_path(dirname(__FILE__, 1)) . $asset;
    }

    /**
     * @param $template
     * @param array $parameters
     * @param $isPro
     *
     * @return false|string|void
     */
    public static function loadTemplate($template, $parameters = [], $isPro = false)
    {
        $base_path = WP_SMS_DIR;

        if ($isPro) {
            $base_path = WP_SMS_PRO_DIR;
        }

        $templatePath = $base_path . "includes/templates/{$template}";

        if (file_exists($templatePath)) {
            ob_start();

            extract($parameters);
            require $templatePath;

            return ob_get_clean();
        }
    }

    /**
     * @return mixed|void|null
     */
    public static function getUserMobileFieldName()
    {
        $mobileFieldManager = new \WP_SMS\User\MobileFieldManager();
        return $mobileFieldManager->getHandler()->getUserMobileFieldName();
    }

    /**
     * @return string
     */
    public static function getWooCommerceCheckoutFieldName()
    {
        $mobileFieldHandler = (new \WP_SMS\User\MobileFieldManager())->getHandler();
        return $mobileFieldHandler instanceof \WP_SMS\User\MobileFieldHandler\WooCommerceAddMobileFieldHandler ?
            $mobileFieldHandler->getUserMobileFieldName() :
            'billing_phone';
    }

    /**
     * @param $userId
     *
     * @return mixed
     */
    public static function getUserMobileNumberByUserId($userId)
    {
        $mobileFieldManager = new \WP_SMS\User\MobileFieldManager();
        return $mobileFieldManager->getHandler()->getMobileNumberByUserId($userId);
    }

    /**
     * @param $number
     * @return mixed|void|null
     */
    public static function getUserByPhoneNumber($number)
    {
        if (empty($number)) {
            return;
        }

        $mobileMetaKey = self::getUserMobileFieldName();
        $users         = get_users([
            'meta_key'   => $mobileMetaKey,
            'meta_value' => $number
        ]);

        return !empty($users) ? array_values($users)[0] : null;
    }

    /**
     * @param $roleId
     *
     * @return array
     */
    public static function getUsersMobileNumbers($roleId = false)
    {
        $mobileFieldKey = self::getUserMobileFieldName();
        $args           = array(
            'meta_query'  => array(
                array(
                    'key'     => $mobileFieldKey,
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
            'count_total' => 'false'
        );

        if ($roleId) {
            $args['role'] = $roleId;
        }

        $users         = get_users($args);
        $mobileNumbers = [];

        foreach ($users as $user) {
            if (isset($user->$mobileFieldKey)) {
                $mobileNumbers[] = $user->$mobileFieldKey;
            }
        }

        return $mobileNumbers;
    }

    /**
     * Get WooCommerce customers
     *
     * @return array|int
     */
    public static function getWooCommerceCustomersNumbers($roles = [])
    {
        $fieldKey = self::getUserMobileFieldName();
        $args     = array(
            'meta_query' => array(
                array(
                    'key'     => $fieldKey,
                    'compare' => '>',
                ),
                array(
                    'key'     => 'billing_phone',
                    'compare' => '>',
                ),
            ),
            'fields'     => 'all_with_meta'
        );

        if ($roles) {
            $args['role__in'] = $roles;
        }

        $customers = get_users($args);
        $numbers   = array();

        foreach ($customers as $customer) {
            $numbers[] = $customer->$fieldKey;
        }

        return $numbers;
    }

    /**
     * Get customer mobile number by order id
     *
     * @param $orderId
     * @return string|void
     * @throws Exception
     */
    public static function getWooCommerceCustomerNumberByOrderId($orderId)
    {
        $userId = get_post_meta($orderId, '_customer_user', true);

        if ($userId) {
            $customerMobileNumber = self::getUserMobileNumberByUserId($orderId);

            if ($customerMobileNumber) {
                return $customerMobileNumber;
            }
        }

        return get_post_meta($orderId, self::getUserMobileFieldName(), true);
    }

    /**
     * Prepare a list of WP roles
     *
     * @return array
     */
    public static function getListOfRoles()
    {
        $wpsms_list_of_role = array();
        foreach (wp_roles()->role_names as $key_item => $val_item) {
            $wpsms_list_of_role[$key_item] = array(
                "name"  => $val_item,
                "count" => count(self::getUsersMobileNumbers($key_item))
            );
        }

        return $wpsms_list_of_role;
    }

    /**
     * @param $message
     *
     * @return array|string|string[]|null
     */
    public static function makeUrlsShorter($message)
    {
        $regex = "/(http|https)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";

        return preg_replace_callback($regex, function ($url) {
            return wp_sms_shorturl($url[0]);
        }, $message);
    }

    public static function isJson($string)
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * return current admin page url
     *
     * @return string
     */

    public static function getCurrentAdminPageUrl()
    {
        global $wp;

        return add_query_arg($_SERVER['QUERY_STRING'], '', home_url($wp->request) . '/wp-admin/admin.php');
    }

    /**
     * This function check the validity of users' phone numbers. If the number is not available, raise an error
     *
     * @param $mobileNumber
     * @param bool $userID
     * @param bool $isSubscriber
     * @param $groupID
     * @param $subscribeId
     *
     * @return bool|\WP_Error
     */
    public static function checkMobileNumberValidity($mobileNumber, $userID = false, $isSubscriber = false, $groupID = false, $subscribeId = false)
    {
        global $wpdb;

        if (!is_numeric($mobileNumber)) {
            return new \WP_Error('invalid_number', __('Invalid Mobile Number', 'wp-sms'));
        }

        // check whether international mode is enabled
        $international_mode = Option::getOption('international_mobile') ? true : false;

        // check whether the first character of mobile number is +
        $country_code = substr($mobileNumber, 0, 1) == '+' ? true : false;

        /**
         * 1. Check whether international mode is on and the number is NOT started with +
         */
        if ($international_mode and !$country_code) {
            return new \WP_Error('invalid_number', __("The mobile number doesn't contain the country code.", 'wp-sms'));
        }

        /**
         * 2. Check whether the min and max length of the number comply
         */
        if (!$international_mode) {
            // get min length of the number if it is set
            $min_length = Option::getOption('mobile_terms_minimum');

            // get max length of the number if it is set
            $max_length = Option::getOption('mobile_terms_maximum');

            if ($max_length and strlen($mobileNumber) > $max_length) {
                return new \WP_Error('invalid_number', __("Your mobile number must have up to {$max_length} characters.", 'wp-sms'));
            }

            if ($min_length and strlen($mobileNumber) < $min_length) {
                return new \WP_Error('invalid_number', __("Your mobile number must have at least {$min_length} characters.", 'wp-sms'));
            }
        }

        /**
         * 3. Check whether number is exists in usermeta or sms_subscriber table
         */
        if ($isSubscriber) {
            $sql = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}sms_subscribes` WHERE mobile = %s", $mobileNumber);

            if ($groupID) {
                $sql .= $wpdb->prepare(" AND group_id = '%s'", $groupID);
            }

            // While updating we should query except the current one.
            if ($subscribeId) {
                $sql .= $wpdb->prepare(" AND id != '%s'", $subscribeId);
            }

            $result = $wpdb->get_row($sql);
        } else {
            $where       = '';
            $mobileField = self::getUserMobileFieldName();

            if ($userID) {
                $where = $wpdb->prepare('AND user_id != %s', $userID);
            }

            $sql    = $wpdb->prepare("SELECT * from {$wpdb->prefix}usermeta WHERE meta_key = %s AND meta_value = %s {$where};", $mobileField, $mobileNumber);
            $result = $wpdb->get_results($sql);
        }

        // if any result found, raise an error
        if ($result) {
            return new \WP_Error('is_duplicate', __('This mobile is already registered, please choose another one.', 'wp-sms'));
        }

        return apply_filters('wp_sms_mobile_number_validity', true, $mobileNumber);
    }

    /**
     * @param $mobile
     *
     * @return string
     */
    public static function sanitizeMobileNumber($mobile)
    {
        return apply_filters('wp_sms_sanitize_mobile_number', sanitize_text_field(trim($mobile)));
    }

    /**
     * @return void
     */
    public static function maybeStartSession($readAndClose = true)
    {
        if (empty(session_id()) && !headers_sent()) {
            session_start(array('read_and_close' => $readAndClose));
        }
    }

    /**
     * This function adds mobile country code to the mobile number if the mobile country code option is enabled.
     *
     * @param $mobileNumber
     * @return mixed|string
     */
    public static function prepareMobileNumber($mobileNumber)
    {
        $international_mode = Option::getOption('international_mobile') ? true : false;
        $country_code       = substr($mobileNumber, 0, 1) == '+' ? true : false;

        if ($international_mode and !$country_code) {
            $mobileNumber = '+' . $mobileNumber;
        }

        return $mobileNumber;
    }
}
