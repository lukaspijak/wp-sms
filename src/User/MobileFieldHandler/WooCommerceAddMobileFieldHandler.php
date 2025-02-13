<?php

namespace WP_SMS\User\MobileFieldHandler;

use WP_SMS\Helper;

class WooCommerceAddMobileFieldHandler
{
    public function register()
    {
        // billing address in my account
        add_filter('woocommerce_billing_fields', [$this, 'registerFieldInBillingForm']);
        add_action('woocommerce_after_save_address_validation', [$this, 'validateMobileNumberCallback']);

        // billing address in admin profile
        add_filter('woocommerce_customer_meta_fields', [$this, 'registerFieldInAdminUserBillingForm'], 10, 1);

        // checkout billing address
        add_filter('woocommerce_checkout_fields', [$this, 'registerFieldInCheckoutBillingForm']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateMobileNumberInCheckoutCallback'], 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'updateMobileNumberAfterPlaceTheOrder'), 10, 3);

        // admin order billing address
        add_filter('woocommerce_admin_billing_fields', [$this, 'registerFieldInAdminOrderBillingForm']);
        add_action('woocommerce_process_shop_order_meta', array($this, 'updateCustomerMobileNumberAfterUpdateTheOrder'), 10, 2);
    }

    public function getMobileNumberByUserId($userId)
    {
        $mobileNumber = get_user_meta($userId, $this->getUserMobileFieldName(), true);
        return apply_filters('wp_sms_user_mobile_number', $mobileNumber, $userId);
    }

    public function getUserMobileFieldName()
    {
        return apply_filters('wp_sms_user_mobile_field', 'mobile');
    }

    public function registerFieldInBillingForm($fields)
    {
        $fields[$this->getUserMobileFieldName()] = $this->getField();

        return $fields;
    }

    /**
     * @return void
     */
    public function validateMobileNumberCallback()
    {
        $mobile   = Helper::sanitizeMobileNumber($_POST[$this->getUserMobileFieldName()]);
        $validity = Helper::checkMobileNumberValidity($mobile);

        if (is_wp_error($validity)) {
            wc_add_notice($validity->get_error_message(), 'error');
        }
    }

    /**
     * @param $errors \WP_Error
     * @return void
     */
    public function validateMobileNumberInCheckoutCallback($data, $errors)
    {
        $mobile   = Helper::sanitizeMobileNumber($_POST[$this->getUserMobileFieldName()]);
        $validity = Helper::checkMobileNumberValidity($mobile, get_current_user_id());

        if (is_wp_error($validity)) {
            $errors->add($validity->get_error_code(), $validity->get_error_message());
        }
    }

    /**
     * @param $orderId
     * @param $postedData
     * @param $order \WC_Order
     * @return void
     */
    public function updateMobileNumberAfterPlaceTheOrder($orderId, $postedData, $order)
    {
        $userMobile = isset($postedData[$this->getUserMobileFieldName()]) ? sanitize_text_field($postedData[$this->getUserMobileFieldName()]) : '';

        if ($userMobile) {
            $this->updateMobileNumber($orderId, $userMobile);
        }
    }

    public function registerFieldInAdminUserBillingForm($args)
    {
        $args['billing']['fields'][$this->getUserMobileFieldName()] = array(
            'label'       => __('Mobile Number', 'wp-sms'),
            'description' => __('Mobile Number for getting SMS notification', 'wp-sms'),
            'class'       => 'wp-sms-input-mobile regular-text'
        );

        return $args;
    }

    public function registerFieldInCheckoutBillingForm($fields)
    {
        $fields['billing'][$this->getUserMobileFieldName()] = $this->getField();

        return $fields;
    }

    private function getField()
    {
        return [
            'label'       => __('Mobile Number', 'wp-sms'),
            'description' => __('Enter your mobile number for getting SMS notification', 'wp-sms'),
            'required'    => true,
            'clear'       => false,
            'type'        => 'text',
            'input_class' => array('wp-sms-input-mobile')
        ];
    }

    public function registerFieldInAdminOrderBillingForm($billingFields)
    {
        // Backward compatibility
        if (empty($_GET['post'])) {
            return;
        }

        $orderId = sanitize_text_field($_GET['post']);
        $mobile  = get_post_meta($orderId, $this->getUserMobileFieldName(), true);

        $billingFields[$this->getUserMobileFieldName()] = [
            'label' => __('Mobile Number', 'wp-sms'),
            'class' => 'wp-sms-input-mobile',
            'value' => $mobile
        ];

        return $billingFields;
    }

    public function updateCustomerMobileNumberAfterUpdateTheOrder($orderId, $data)
    {
        if (isset($_POST['_billing_mobile'])) {
            $this->updateMobileNumber($orderId, $_POST['_billing_mobile']);
        }
    }

    /**
     * @param $orderId
     * @param $mobileNumber
     * @return void
     */
    private function updateMobileNumber($orderId, $mobileNumber)
    {
        $mobileNumber = sanitize_text_field($mobileNumber);

        // Update in order meta
        update_post_meta($orderId, $this->getUserMobileFieldName(), $mobileNumber);

        // Update in user meta
        $userId = get_post_meta($orderId, '_customer_user', true);

        if ($userId and $userId != 0) {
            update_user_meta($userId, $this->getUserMobileFieldName(), $mobileNumber);
        }
    }
}
