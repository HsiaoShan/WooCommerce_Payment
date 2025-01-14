<?php

class WC_Gateway_Ecpay extends WC_Payment_Gateway
{
    public $ecpay_test_mode;
    public $ecpay_merchant_id;
    public $ecpay_hash_key;
    public $ecpay_hash_iv;
    public $ecpay_choose_payment;
    public $ecpay_payment_methods;

    public function __construct()
    {

        $this->id = 'ecpay';
        $this->method_title = __('ECPay', 'ecpay');
        $this->method_description   =  __('ECPay is the most popular payment gateway for online shopping in Taiwan', 'ecpay');
        $this->has_fields = true;
        $this->icon = apply_filters('woocommerce_ecpay_icon', plugins_url('images/icon.png', dirname( __FILE__ )));

        # Load the form fields
        $this->init_form_fields();

        # Load the administrator settings
        $this->init_settings();

        $this->title                 = $this->get_option('title');
        $this->description           = $this->get_option('description');
        $this->ecpay_test_mode       = $this->get_option('ecpay_test_mode');
        $this->ecpay_merchant_id     = $this->get_option('ecpay_merchant_id');
        $this->ecpay_hash_key        = $this->get_option('ecpay_hash_key');
        $this->ecpay_hash_iv         = $this->get_option('ecpay_hash_iv');
        $this->ecpay_payment_methods = $this->get_option('ecpay_payment_methods');

        //$this->ecpay_apple_pay = 'yes' === $this->get_option( 'apple_pay', 'yes' );
        $this->ecpay_apple_pay_ca_path = 'yes' === $this->get_option( 'apple_pay_domain_set', 'no' );
        $this->ecpay_apple_pay_button  = $this->get_option( 'apple_pay_button', 'black' );

        # Register a action to save administrator settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        # Register a action to redirect to ECPay payment center
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        # Register a action to process the callback
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));

        add_action( 'woocommerce_thankyou_ecpay', array( $this, 'thankyou_page' ) );

        add_filter('woocommerce_available_payment_gateways', array( $this, 'woocs_filter_gateways' ) );

    }

    /**
    * 過濾重複付款
    */
    public function woocs_filter_gateways($gateway_list)
    {
       if(isset($_GET['pay_for_order']))
       {
            $url_arr = explode('/', $_SERVER['REQUEST_URI']);
            $order_id = intval($url_arr[3]);
            $order = new WC_Order( $order_id );
            $nEcpay_Payment_Tag = get_post_meta($order->get_id(), 'ecpay_payment_tag', true);
            if ($order->get_payment_method() === 'ecpay') {
                if ($nEcpay_Payment_Tag === 1) {
                    unset($gateway_list['ecpay']);
                    unset($gateway_list['ecpay_dca']);
                } else {
                    unset($gateway_list['bacs']);
                }
            }
       }
       return $gateway_list;
    }

    /**
    * 載入參數設定欄位
    */
    public function init_form_fields ()
    {
        $this->form_fields = include( untrailingslashit( plugin_dir_path( WC_ECPAY_MAIN_FILE ) ) . '/includes/settings-ecpay.php' );
    }

    /**
     * Display the form when chooses ECPay payment
     */
    public function payment_fields()
    {
        if (!empty($this->description)) {
            echo $this->add_next_line($this->description . '<br /><br />');
        }
        echo __('Payment Method', 'ecpay') . ' : ';
        echo $this->add_next_line('<select name="ecpay_choose_payment">');
        foreach ($this->ecpay_payment_methods as $payment_method) {
            echo $this->add_next_line('  <option value="' . $payment_method . '">');
            echo $this->add_next_line('    ' . $this->get_payment_desc($payment_method));
            echo $this->add_next_line('  </option>');
        }
        echo $this->add_next_line('</select>');
    }

    /**
     * Check the payment method and the chosen payment
     */
    public function validate_fields()
    {
        $choose_payment = $_POST['ecpay_choose_payment'];
        $payment_desc = $this->get_payment_desc($choose_payment);
        if ($_POST['payment_method'] == $this->id && !empty($payment_desc)) {
            $this->ecpay_choose_payment = $choose_payment;
            return true;
        } else {
            wc_add_notice( __( 'Invalid payment method.' ).$payment_desc, 'error' );
            return false;
        }
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id)
    {
        # Update order status
        $order = new WC_Order($order_id);
        $order->update_status('pending', __('Awaiting ECPay payment', 'ecpay'));

        # Set the ECPay payment type to the order note
        $order->add_order_note($this->get_payment_desc($this->ecpay_choose_payment), true);
        add_post_meta( $order->id, 'choose_ecpay_payment', $this->ecpay_choose_payment, true);

        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }

    /**
     * Redirect to ECPay
     */
    public function receipt_page($order_id)
    {
        # Clean the cart
        global $woocommerce;
        $woocommerce->cart->empty_cart();

        //找出訂單資訊
        $order = new WC_Order($order_id);
        $choose_payment = get_post_meta($order->get_id(), 'choose_ecpay_payment', true);
        if ($choose_payment == 'ApplePay') {
            $gateway_settings = get_option( 'woocommerce_ecpay_settings', '' );

            // 載入CSS
            wp_enqueue_style( 'ecpay_apple_pay', plugins_url( 'assets/css/ecpay-apple-pay.css', WC_ECPAY_MAIN_FILE ), array());

            // 載入JS
            wp_enqueue_script( 'ecpay_apple_pay', plugins_url( 'assets/js/ecpay-apple-pay.js', WC_ECPAY_MAIN_FILE ), array(), null, true);

            // 參數往前端送
            $ecpay_params = array(
                'lable'         => get_option('blogname'),
                'ajaxurl'       => admin_url().'admin-ajax.php',
                'total'         => $order->get_total(),
                'display_name'  => $gateway_settings['ecpay_apple_display_name'],
                'order_id'      => $order_id,
                'site_url'      => get_site_url(),
                'server_https'  => $_SERVER['HTTPS'],
                'test_mode'     => $this->ecpay_test_mode
            );

            wp_localize_script( 'ecpay_apple_pay', 'wc_ecpay_apple_pay_params', $ecpay_params );

            ?>
            <div class="apple-pay-button-wrapper">
                <button class="apple-pay-button" id="apple-pay-button" style="display: none;" lang="<?php echo esc_attr( $this->apple_pay_button_lang ); ?>" style="-webkit-appearance: -apple-pay-button; -apple-pay-button-type: buy; -apple-pay-button-style: <?php echo esc_attr( $this->apple_pay_button ); ?>;" ></button>
            </div>
            <?php
        } else {
            try {
                $aio = new ECPay_AllInOne();
                $aio->Send['MerchantTradeNo'] = '';
                $service_url = '';
                if ($this->ecpay_test_mode == 'yes') {
                    $service_url = 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut';
                    $aio->Send['MerchantTradeNo'] = date('YmdHis');
                } else {
                    $service_url = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut';
                }
                $aio->MerchantID        = $this->ecpay_merchant_id;
                $aio->HashKey           = $this->ecpay_hash_key;
                $aio->HashIV            = $this->ecpay_hash_iv;
                $aio->ServiceURL        = $service_url;
                $aio->Send['ReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_ECPay', home_url('/'));
                $aio->Send['ClientBackURL'] = $this->get_return_url($order);
                $aio->Send['MerchantTradeNo'] .= $order->get_id();
                $aio->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');

                // 接收額外回傳參數 提供電子發票使用 v1.1.0911
                $aio->Send['NeedExtraPaidInfo'] = 'Y';

                # Set the product info
                $aio->Send['TotalAmount'] = $order->get_total();
                array_push(
                    $aio->Send['Items'],
                    array(
                        'Name'      => '網路商品一批',
                        'Price'     => $aio->Send['TotalAmount'],
                        'Currency'  => $order->get_currency(),
                        'Quantity'  => 1,
                        'URL'       => '',
                    )
                );

                $aio->Send['TradeDesc'] = 'ecpay_module_woocommerce';

                # Get the chosen payment and installment
                $choose_installment = '';
                if ($choose_payment != '') {
                    $chooseParam = explode('_', $choose_payment);
                    $choose_payment =isset($chooseParam[0]) ? $chooseParam[0] : '';
                    $choose_installment = isset($chooseParam[1]) ? $chooseParam[1] : '';
                }
                $aio->Send['ChoosePayment'] = $choose_payment;

                # Set the extend information
                switch ($aio->Send['ChoosePayment']) {
                    case 'Credit':
                        # Do not support UnionPay
                        $aio->SendExtend['UnionPay'] = false;

                        # Credit installment parameters
                        if (!empty($choose_installment)) {
                            $aio->SendExtend['CreditInstallment'] = $choose_installment;
                            $aio->SendExtend['InstallmentAmount'] = $aio->Send['TotalAmount'];
                            $aio->SendExtend['Redeem'] = false;
                        }
                        break;
                    case 'WebATM':
                        break;
                    case 'ATM':
                        $aio->SendExtend['ExpireDate'] = 3;
                        $aio->SendExtend['PaymentInfoURL'] = $aio->Send['ReturnURL'];
                        break;
                    case 'CVS':
                    case 'BARCODE':
                        $aio->SendExtend['Desc_1'] = '';
                        $aio->SendExtend['Desc_2'] = '';
                        $aio->SendExtend['Desc_3'] = '';
                        $aio->SendExtend['Desc_4'] = '';
                        $aio->SendExtend['PaymentInfoURL'] = $aio->Send['ReturnURL'];
                        break;
                    default:
                        throw new Exception(__('Invalid payment method.', 'ecpay'));
                        break;
                }
                $aio->CheckOut();
                exit;
            } catch(Exception $e) {
                wc_add_notice($e->getMessage(), 'error');
            }
        }
    }

    /**
     * Process the callback
     */
    public function receive_response()
    {
        $result_msg = '1|OK';
        $order = null;
        try {
            # Retrieve the check out result

            $aio = new ECPay_AllInOne();
            $aio->HashKey = $this->ecpay_hash_key;
            $aio->HashIV = $this->ecpay_hash_iv;
            $aio->MerchantID = $this->ecpay_merchant_id;
            $ecpay_feedback = $aio->CheckOutFeedback();
            unset($aio);
            if (count($ecpay_feedback) < 1) {
                throw new Exception('Get ECPay feedback failed.');
            } else {
                # Get the cart order id
                $cart_order_id = $ecpay_feedback['MerchantTradeNo'];
                if ($this->ecpay_test_mode == 'yes') {
                    $cart_order_id = substr($ecpay_feedback['MerchantTradeNo'], 14);
                }

                # Get the cart order amount
                $order = new WC_Order($cart_order_id);
                $cart_amount = $order->get_total();

                # Check the amounts
                $ecpay_amount = $ecpay_feedback['TradeAmt'];
                if (round($cart_amount) != $ecpay_amount) {
                    throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                } else {
                    # Set the common comments
                    $comments = sprintf(
                        __('Payment Method : %s<br />Trade Time : %s<br />', 'ecpay'),
                        $ecpay_feedback['PaymentType'],
                        $ecpay_feedback['TradeDate']
                    );

                    # Set the getting code comments
                    $return_code = $ecpay_feedback['RtnCode'];
                    $return_message = $ecpay_feedback['RtnMsg'];
                    $get_code_result_comments = sprintf(
                        __('Getting Code Result : (%s)%s', 'ecpay'),
                        $return_code,
                        $return_message
                    );

                    # Set the payment result comments
                    $payment_result_comments = sprintf(
                        __('ECPay Payment Result : (%s)%s', 'ecpay'),
                        $return_code,
                        $return_message
                    );

                    # Set the fail message
                    $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);

                    # Get ECPay payment method
                    $ecpay_payment_method = $this->get_payment_method($ecpay_feedback['PaymentType']);

                    # Set the order comments

                    // 20170920
                    switch($ecpay_payment_method) {
                        case ECPay_PaymentMethod::Credit:
                            if ($return_code != 1 and $return_code != 800)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if (!$this->is_order_complete($order))
                                {
                                    $this->confirm_order($order, $payment_result_comments, $ecpay_feedback);

                                    // 增加ECPAY付款狀態
                                    add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);

                                }
                                else
                                {
                                    # The order already paid or not in the standard procedure, do nothing
                                    //throw new Exception('The order already paid or not in the standard procedure ' . $cart_order_id . '.');
                                    $nEcpay_Payment_Tag = get_post_meta($order->id, 'ecpay_payment_tag', true);
                                    if($nEcpay_Payment_Tag == 0)
                                    {
                                        $order->add_order_note($payment_result_comments, 1);
                                        add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);
                                    }
                                }
                            }
                            break;
                        case ECPay_PaymentMethod::WebATM:
                            if ($return_code != 1 and $return_code != 800) {
                                throw new Exception($fail_msg);
                            } else {
                                if (!$this->is_order_complete($order))
                                {
                                    $this->confirm_order($order, $payment_result_comments, $ecpay_feedback);

                                    // 增加ECPAY付款狀態
                                    add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);
                                }
                                else
                                {
                                    # The order already paid or not in the standard procedure, do nothing
                                   // throw new Exception('The order already paid or not in the standard procedure ' . $cart_order_id . '.');
                                    $nEcpay_Payment_Tag = get_post_meta($order->id, 'ecpay_payment_tag', true);
                                    if($nEcpay_Payment_Tag == 0)
                                    {
                                        $order->add_order_note($payment_result_comments, 1);
                                        add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);
                                    }
                                }
                            }
                            break;
                        case ECPay_PaymentMethod::ATM:
                            if ($return_code != 1 and $return_code != 2 and $return_code != 800) {
                                throw new Exception($fail_msg);
                            } else {
                                if ($return_code == 2) {
                                    # Set the getting code result
                                    $comments .= $this->get_order_comments($ecpay_feedback);
                                    $comments .= $get_code_result_comments;
                                    $order->add_order_note($comments);

                                    // 紀錄付款資訊提供感謝頁面使用
                                    add_post_meta( $order->id, 'payment_method', 'ATM', true);
                                    add_post_meta( $order->id, 'BankCode', $ecpay_feedback['BankCode'], true);
                                    add_post_meta( $order->id, 'vAccount', $ecpay_feedback['vAccount'], true);
                                    add_post_meta( $order->id, 'ExpireDate', $ecpay_feedback['ExpireDate'], true);
                                }
                                else
                                {
                                    if (!$this->is_order_complete($order))
                                    {
                                        $this->confirm_order($order, $payment_result_comments, $ecpay_feedback);

                                        // 增加ECPAY付款狀態
                                        add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);

                                    } else {
                                        # The order already paid or not in the standard procedure, do nothing
                                        // throw new Exception('The order already paid or not in the standard procedure ' . $cart_order_id . '.');
                                        $nEcpay_Payment_Tag = get_post_meta($order->id, 'ecpay_payment_tag', true);
                                        if($nEcpay_Payment_Tag == 0)
                                        {
                                            $order->add_order_note($payment_result_comments, 1);
                                            add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);
                                        }
                                    }
                                }
                            }
                            break;
                        case ECPay_PaymentMethod::CVS:
                        case ECPay_PaymentMethod::BARCODE:
                            if ($return_code != 1 and $return_code != 800 and $return_code != 10100073) {
                                throw new Exception($fail_msg);
                            } else {
                                if ($return_code == 10100073) {
                                    # Set the getting code result
                                    $comments .= $this->get_order_comments($ecpay_feedback);
                                    $comments .= $get_code_result_comments;
                                    $order->add_order_note($comments);

                                    if($ecpay_payment_method == CVS )
                                    {
                                        // 紀錄付款資訊提供感謝頁面使用
                                        add_post_meta( $order->id, 'payment_method', 'CVS', true);
                                        add_post_meta( $order->id, 'PaymentNo', $ecpay_feedback['PaymentNo'], true);
                                        add_post_meta( $order->id, 'ExpireDate', $ecpay_feedback['ExpireDate'], true);
                                    }

                                } else {
                                    if (!$this->is_order_complete($order))
                                    {
                                        $this->confirm_order($order, $payment_result_comments, $ecpay_feedback);

                                        // 增加ECPAY付款狀態
                                        add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);
                                    }
                                    else
                                    {
                                        # The order already paid or not in the standard procedure, do nothing
                                        // throw new Exception('The order already paid or not in the standard procedure ' . $cart_order_id . '.');
                                        $nEcpay_Payment_Tag = get_post_meta($order->id, 'ecpay_payment_tag', true);
                                        if($nEcpay_Payment_Tag == 0)
                                        {
                                            $order->add_order_note($payment_result_comments, 1);
                                            add_post_meta( $order->id, 'ecpay_payment_tag', 1, true);
                                        }
                                    }
                                }
                            }
                            break;
                        default:
                            throw new Exception('Invalid payment method of the order ' . $cart_order_id . '.');
                            break;
                    }
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (!empty($order)) {
                $comments .= sprintf(__('Failed To Pay<br />Error : %s<br />', 'ecpay'), $error);
                $order->add_order_note($comments);
            }

            # Set the failure result
            $result_msg = '0|' . $error;
        }
        echo $result_msg;
        exit;
    }


    # Custom function

    /**
     * Get the payment method description
     * @param  string   payment name
     * @return string   payment method description
     */
    private function get_payment_desc($payment_name)
    {
        $payment_desc = array(
            'Credit'    => __('Credit', 'ecpay'),
            'Credit_3'  => __('Credit(3 Installments)', 'ecpay'),
            'Credit_6'  => __('Credit(6 Installments)', 'ecpay'),
            'Credit_12' => __('Credit(12 Installments)', 'ecpay'),
            'Credit_18' => __('Credit(18 Installments)', 'ecpay'),
            'Credit_24' => __('Credit(24 Installments)', 'ecpay'),
            'WebATM'    => __('WEB-ATM', 'ecpay'),
            'ATM'       => __('ATM', 'ecpay'),
            'CVS'       => __('CVS', 'ecpay'),
            'BARCODE'   => __('BARCODE', 'ecpay'),
            'ApplePay'  => __('ApplePay', 'ecpay')
        );

        return $payment_desc[$payment_name];
    }

    /**
     * Add a next line character
     * @param  string   content
     * @return string   content with next line character
     */
    private function add_next_line($content)
    {
        return $content . "\n";
    }

    /**
     * Format the version description
     * @param  string   version string
     * @return string   version description
     */
    private function format_version_desc($version)
    {
        return str_replace('.', '_', $version);
    }

    /**
     * Check if the order status is complete
     * @param  object   order
     * @return boolean  is the order complete
     */
    private function is_order_complete($order)
    {
        $status = '';
        $status = (method_exists($order,'get_status') == true ) ? $order->get_status() : $order->status;

        if ($status == 'pending') {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get the payment method from the payment_type
     * @param  string   payment type
     * @return string   payment method
     */
    private function get_payment_method($payment_type)
    {
        $info_pieces = explode('_', $payment_type);

        return $info_pieces[0];
    }

    /**
     * Get the order comments
     * @param  array    ECPay feedback
     * @return string   order comments
     */
    function get_order_comments($ecpay_feedback)
    {
        $comments = array(
            'ATM' =>
                sprintf(
                      __('Bank Code : %s<br />Virtual Account : %s<br />Payment Deadline : %s<br />', 'ecpay'),
                    $ecpay_feedback['BankCode'],
                    $ecpay_feedback['vAccount'],
                    $ecpay_feedback['ExpireDate']
                ),
            'CVS' =>
                sprintf(
                    __('Trade Code : %s<br />Payment Deadline : %s<br />', 'ecpay'),
                    $ecpay_feedback['PaymentNo'],
                    $ecpay_feedback['ExpireDate']
                ),
            'BARCODE' =>
                sprintf(
                    __('Payment Deadline : %s<br />BARCODE 1 : %s<br />BARCODE 2 : %s<br />BARCODE 3 : %s<br />', 'ecpay'),
                    $ecpay_feedback['ExpireDate'],
                    $ecpay_feedback['Barcode1'],
                    $ecpay_feedback['Barcode2'],
                    $ecpay_feedback['Barcode3']
                )
        );
        $payment_method = $this->get_payment_method($ecpay_feedback['PaymentType']);

        return $comments[$payment_method];
    }

    /**
     * Complete the order and add the comments
     * @param  object   order
     */
    function confirm_order($order, $comments, $ecpay_feedback)
    {
        $order->add_order_note($comments, true);

        $order->payment_complete();

        // 加入信用卡後四碼，提供電子發票開立使用 v1.1.0911
        if(isset($ecpay_feedback['card4no']) && !empty($ecpay_feedback['card4no']))
        {
            add_post_meta( $order->get_id(), 'card4no', $ecpay_feedback['card4no'], true);
        }

        // call invoice model
        $invoice_active_ecpay = 0 ;
        $invoice_active_opay = 0 ;

        $active_plugins = (array) get_option( 'active_plugins', array() );

        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

        foreach ($active_plugins as $key => $value) {
            if ((strpos($value,'/woocommerce-ecpayinvoice.php') !== false)) {
                $invoice_active_ecpay = 1;
            }

            if ((strpos($value,'/woocommerce-opayinvoice.php') !== false)) {
                $invoice_active_opay = 1;
            }
        }

        if ($invoice_active_ecpay == 0 && $invoice_active_opay == 1) { // opay

            $aConfig_Invoice = get_option('wc_opayinvoice_active_model');

            // 記錄目前成功付款到第幾次
            $nTotalSuccessTimes = ( isset($ecpay_feedback['TotalSuccessTimes']) && ( empty($ecpay_feedback['TotalSuccessTimes']) || $ecpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $ecpay_feedback['TotalSuccessTimes'] ;
            update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

            if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_opay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_opay_invoice_auto'] == 'auto' ) {
                do_action('opay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
            }

        } elseif ($invoice_active_ecpay == 1 && $invoice_active_opay == 0) { // ecpay

            $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

            // 記錄目前成功付款到第幾次
            $nTotalSuccessTimes = ( isset($ecpay_feedback['TotalSuccessTimes']) && ( empty($ecpay_feedback['TotalSuccessTimes']) || $ecpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $ecpay_feedback['TotalSuccessTimes'] ;
            update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

            if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto' ) {
                do_action('ecpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
            }
        }
    }

    /**
    * Output for the order received page.
    *
    * @param int $order_id
    */
    public function thankyou_page( $order_id ) {

        $this->payment_details( $order_id );

    }


    /**
     * Get payment details and place into a list format.
     *
     * @param int $order_id
     */
    private function payment_details( $order_id = '' ) {

        $account_html = '';
        $has_details = false ;
        $a_has_details = array();

        $payment_method = get_post_meta($order_id, 'payment_method', true);

        switch($payment_method) {
            case ECPay_PaymentMethod::CVS:
                $PaymentNo = get_post_meta($order_id, 'PaymentNo', true);
                $ExpireDate = get_post_meta($order_id, 'ExpireDate', true);

                $a_has_details = array(
                    'PaymentNo' => array(
                                'label' => __( 'PaymentNo', 'ecpay' ),
                                'value' => $PaymentNo
                            ),
                    'ExpireDate' => array(
                                'label' => __( 'ExpireDate', 'ecpay' ),
                                'value' => $ExpireDate
                            )
                );

                $has_details = true ;
            break;

            case ECPay_PaymentMethod::ATM:
                $BankCode = get_post_meta($order_id, 'BankCode', true);
                $vAccount = get_post_meta($order_id, 'vAccount', true);
                $ExpireDate = get_post_meta($order_id, 'ExpireDate', true);

                $a_has_details = array(
                    'BankCode' => array(
                                'label' => __( 'BankCode', 'ecpay' ),
                                'value' => $BankCode
                            ),
                    'vAccount' => array(
                                'label' => __( 'vAccount', 'ecpay' ),
                                'value' => $vAccount
                            ),
                    'ExpireDate' => array(
                                'label' => __( 'ExpireDate', 'ecpay' ),
                                'value' => $ExpireDate
                            )
                );


                $has_details = true ;
            break;
        }

        $account_html .= '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">' . PHP_EOL;

        foreach($a_has_details as $field_key => $field ) {
            $account_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL ;
        }

        $account_html .= '</ul>';


        if ( $has_details ) {
            echo '<section><h2>' . __( 'Payment details', 'ecpay' ) . '</h2>' . PHP_EOL . $account_html . '</section>';
        }
    }
}

class WC_Gateway_Ecpay_DCA extends WC_Payment_Gateway
{
    public $ecpay_test_mode;
    public $ecpay_merchant_id;
    public $ecpay_hash_key;
    public $ecpay_hash_iv;
    public $ecpay_choose_payment;
    public $ecpay_domain;
    public $ecpay_dca_payment;

    public function __construct()
    {
        # Load the translation
        $this->ecpay_domain = 'ecpay_dca';

        # Initialize construct properties
        $this->id = 'ecpay_dca';

        # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
        $this->icon = '';

        # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
        $this->has_fields = true;

        # Title of the payment method shown on the admin page
        $this->method_title = __('ECPay Paid Automatically', 'ecpay');
        $this->method_description = __('Enable to use ECPay Paid Automatically', 'ecpay');

        # Load the form fields
        $this->init_form_fields();

        # Load the administrator settings
        $this->init_settings();
        $this->title = $this->get_option( 'title' );

        $admin_options = get_option('woocommerce_ecpay_settings');
        $this->ecpay_test_mode = $admin_options['ecpay_test_mode'];
        $this->ecpay_merchant_id = $admin_options['ecpay_merchant_id'];
        $this->ecpay_hash_key = $admin_options['ecpay_hash_key'];
        $this->ecpay_hash_iv = $admin_options['ecpay_hash_iv'];
        $this->ecpay_dca_payment = $this->getEcpayDcaPayment();

        $this->ecpay_dca = get_option( 'woocommerce_ecpay_dca',
            array(
                array(
                    'periodType' => $this->get_option( 'periodType' ),
                    'frequency' => $this->get_option( 'frequency' ),
                    'execTimes' => $this->get_option( 'execTimes' ),
                ),
            )
        );

        # Register a action to save administrator settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_dca_details' ) );

        # Register a action to redirect to ECPay payment center
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        # Register a action to process the callback
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));

        add_action( 'woocommerce_thankyou_ecpay', array( $this, 'thankyou_page' ) );

         add_filter('woocommerce_available_payment_gateways', array( $this, 'woocs_filter_gateways' ) );
    }

    /**
    * 過濾重複付款
    */
    public function woocs_filter_gateways($gateway_list)
    {
       if(isset($_GET['pay_for_order']))
       {
            $url_arr = explode('/', $_SERVER['REQUEST_URI']);
            $order_id = intval($url_arr[3]);
            $order = new WC_Order( $order_id );
            $nEcpay_Payment_Tag = get_post_meta($order->get_id(), 'ecpay_payment_tag', true);
            if ($order->get_payment_method() === 'ecpay') {
                if ($nEcpay_Payment_Tag === 1) {
                    unset($gateway_list['ecpay']);
                    unset($gateway_list['ecpay_dca']);
                } else {
                    unset($gateway_list['bacs']);
                }
            }
       }
       return $gateway_list;
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __('Enable ECPay Paid Automatically', 'ecpay'),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'default'     => __('ECPay Paid Automatically', 'ecpay'),
                'desc_tip'    => true,
            ),
            'ecpay_dca' => array(
                'type'        => 'ecpay_dca'
            ),
        );
    }

    public function generate_ecpay_dca_html()
    {
        ob_start();

        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo __('ECPay Paid Automatically Details', 'ecpay'); ?></th>
            <td class="forminp" id="ecpay_dca">
                <table class="widefat wc_input_table sortable" cellspacing="0" style="width: 600px;">
                    <thead>
                        <tr>
                            <th class="sort">&nbsp;</th>
                            <th><?php echo __('Peroid Type', 'ecpay'); ?></th>
                            <th><?php echo __('Frequency', 'ecpay'); ?></th>
                            <th><?php echo __('Execute Times', 'ecpay'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="accounts">
                        <?php
                            if (
                                sizeof($this->ecpay_dca) === 1
                                && $this->ecpay_dca[0]["periodType"] === ''
                                && $this->ecpay_dca[0]["frequency"] === ''
                                && $this->ecpay_dca[0]["execTimes"] === ''
                            ) {
                                // 初始預設定期定額方式
                                $this->ecpay_dca = [
                                    [
                                        'periodType' => "Y",
                                        'frequency' => "1",
                                        'execTimes' => "6",
                                    ],
                                    [
                                        'periodType' => "M",
                                        'frequency' => "1",
                                        'execTimes' => "12",
                                    ],
                                ];
                            }

                            $i = -1;
                            if ( is_array($this->ecpay_dca) ) {
                                foreach ( $this->ecpay_dca as $dca ) {
                                    $i++;
                                    echo '<tr class="account">
                                        <td class="sort"></td>
                                        <td><input type="text" class="fieldPeriodType" value="' . esc_attr( $dca['periodType'] ) . '" name="periodType[' . $i . ']" maxlength="1" required /></td>
                                        <td><input type="number" class="fieldFrequency" value="' . esc_attr( $dca['frequency'] ) . '" name="frequency[' . $i . ']"  min="1" max="365" required /></td>
                                        <td><input type="number" class="fieldExecTimes" value="' . esc_attr( $dca['execTimes'] ) . '" name="execTimes[' . $i . ']"  min="2" max="999" required /></td>
                                    </tr>';
                                }
                            }
                        ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4">
                                <a href="#" class="add button"><?php echo __('add', 'ecpay'); ?></a>
                                <a href="#" class="remove_rows button"><?php echo __('remove', 'ecpay'); ?></a>
                            </th>
                        </tr>
                    </tfoot>
                </table>
                <p class="description"><?php echo __('Don\'t forget to save modify.', 'ecpay'); ?></p>
                <p id="fieldsNotification" style="display: none;"><?php echo __('ECPay paid automatically details has been repeatedly, please confirm again.', 'ecpay'); ?></p>
                <script>
                    jQuery(function() {
                        jQuery('#ecpay_dca').on( 'click', 'a.add', function() {
                            var size = jQuery('#ecpay_dca').find('tbody .account').length;

                            jQuery('<tr class="account">\
                                    <td class="sort"></td>\
                                    <td><input type="text" class="fieldPeriodType" name="periodType[' + size + ']" maxlength="1" required /></td>\
                                    <td><input type="number" class="fieldFrequency" name="frequency[' + size + ']" min="1" max="365" required /></td>\
                                    <td><input type="number" class="fieldExecTimes" name="execTimes[' + size + ']" min="2" max="999" required /></td>\
                                </tr>').appendTo('#ecpay_dca table tbody');

                            return false;
                        });

                        jQuery('#ecpay_dca').on( 'blur', 'input', function() {
                            let field = this.value.trim();
                            let indexStart = this.name.search(/[[]/g);
                            let indexEnd = this.name.search(/[\]]/g);
                            let fieldIndex = this.name.substring(indexStart + 1, indexEnd);
                            let fieldPeriodType = document.getElementsByName('periodType[' + fieldIndex + ']')[0].value;

                            if (
                                (validateFields.periodType(field) === false && this.className === 'fieldPeriodType') ||
                                (validateFields.frequency(fieldPeriodType, field) === false && this.className === 'fieldFrequency') ||
                                (validateFields.execTimes(fieldPeriodType, field) === false && this.className === 'fieldExecTimes')
                            ){
                                this.value = '';
                            }
                        });

                        jQuery('#ecpay_dca').on( 'blur', 'tbody', function() {
                            fields.process();
                        });

                        jQuery('body').on( 'click', '#mainform', function() {
                            fields.process();
                        });
                    });

                    var data = {
                        periodType: ['D', 'M', 'Y'],
                        frequency: ['365', '12', '1'],
                        execTimes: ['999', '99', '9'],
                    };

                    var fields = {
                        get: function() {
                            var field = jQuery('#ecpay_dca').find('tbody .account td input');
                            var fieldsInput = [];
                            var fieldsTmp = [];
                            var i = 0;
                            Object.keys(field).forEach(function(key) {
                                if (field[key].value != null) {
                                    i++;
                                    if (i % 3 == 0) {
                                        fieldsTmp.push(field[key].value);
                                        fieldsInput.push(fieldsTmp);
                                        fieldsTmp = [];
                                    } else {
                                        fieldsTmp.push(field[key].value);
                                    }
                                }
                            });

                            return fieldsInput;
                        },
                        check: function(inputs) {
                            var errorFlag = 0;
                            inputs.forEach(function(key1, index1) {
                                inputs.forEach(function(key2, index2) {
                                    if (index1 !== index2) {
                                        if (key1[0] === key2[0] && key1[1] === key2[1] && key1[2] === key2[2]) {
                                            errorFlag++;
                                        }
                                    }
                                })
                            });

                            return errorFlag;
                        },
                        process: function() {
                            if (fields.check(fields.get()) > 0) {
                                document.getElementById('fieldsNotification').style = 'color: #ff0000;';
                                document.querySelector('input[name="save"]').disabled = true;
                            } else {
                                document.getElementById('fieldsNotification').style = 'display: none;';
                                document.querySelector('input[name="save"]').disabled = false;
                            }
                        }
                    }

                    var validateFields = {
                        periodType: function(field) {
                            return (data.periodType.indexOf(field) !== -1);
                        },
                        frequency: function(periodType, field) {
                            let maxFrequency = parseInt(data.frequency[data.periodType.indexOf(periodType)], 10);
                            return ((field > 0) && ((maxFrequency + 1) > field));
                        },
                        execTimes: function(periodType, field) {
                            let maxExecTimes = parseInt(data.execTimes[data.periodType.indexOf(periodType)], 10);
                            return ((field > 1) && ((maxExecTimes + 1) > field));
                        }
                    };
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Save account details table.
     */
    public function save_dca_details()
    {
        $ecpayDca = array();

        if ( isset( $_POST['periodType'] ) ) {

            $periodTypes = array_map( 'wc_clean', $_POST['periodType'] );
            $frequencys = array_map( 'wc_clean', $_POST['frequency'] );
            $execTimes = array_map( 'wc_clean', $_POST['execTimes'] );

            foreach ( $periodTypes as $i => $name ) {
                if ( ! isset( $periodTypes[ $i ] ) ) {
                    continue;
                }

                $ecpayDca[] = array(
                    'periodType' => $periodTypes[ $i ],
                    'frequency' => $frequencys[ $i ],
                    'execTimes' => $execTimes[ $i ],
                );
            }
        }

        update_option( 'woocommerce_ecpay_dca', $ecpayDca );
    }

    /**
     * Display the form when chooses ECPay payment
     */
    public function payment_fields()
    {
        global $woocommerce;
        $ecpayDCA = get_option('woocommerce_ecpay_dca');
        $periodTypeMethod = [
            'Y' => ' ' . __('year', 'ecpay'),
            'M' => ' ' . __('month', 'ecpay'),
            'D' => ' ' . __('day', 'ecpay')
        ];
        $ecpay = '';
        foreach ($ecpayDCA as $dca) {
            $option = sprintf(
                    __('NT$ %d / %s %s, up to a maximun of %s', 'ecpay'),
                    (int)$woocommerce->cart->total,
                    $dca['frequency'],
                    $periodTypeMethod[$dca['periodType']],
                    $dca['execTimes']
                );
            $ecpay .= '
                <option value="' . $dca['periodType'] . '_' . $dca['frequency'] . '_' . $dca['execTimes'] . '">
                    ' . $option . '
                </option>';
        }
        echo '
            <select id="ecpay_dca_payment" name="ecpay_dca_payment">
                <option>------</option>
                ' . $ecpay . '
            </select>
            <div id="ecpay_dca_show"></div>
            <hr style="margin: 12px 0px;background-color: #eeeeee;">
            <p style="font-size: 0.8em;color: #c9302c;">
                你將使用<strong>綠界科技定期定額信用卡付款</strong>，請留意你所購買的商品為<strong>非單次扣款</strong>商品。
            </p>
        ';
    }

    public function getEcpayDcaPayment()
    {
        global $woocommerce;
        $ecpayDCA = get_option('woocommerce_ecpay_dca');
        $ecpay = [];
        if (is_array($ecpayDCA)) {
            foreach ($ecpayDCA as $dca) {
                array_push($ecpay, $dca['periodType'] . '_' . $dca['frequency'] . '_' . $dca['execTimes']);
            }
        }

        return $ecpay;
    }

    /**
     * Translate the content
     * @param  string   translate target
     * @return string   translate result
     */
    private function tran($content)
    {
        return __($content, $this->ecpay_domain);
    }

    /**
     * Invoke ECPay module
     */
    private function invoke_ecpay_module()
    {
        if (!class_exists('ECPay_AllInOne')) {
            if (!require(plugin_dir_path(__FILE__) . '/lib/ECPay.Payment.Integration.php')) {
                throw new Exception($this->tran('ECPay module missed.'));
            }
        }
    }

    /**
     * Check the payment method and the chosen payment
     */
    public function validate_fields()
    {
        $choose_payment = $_POST['ecpay_dca_payment'];

        if ($_POST['payment_method'] == $this->id && in_array($choose_payment, $this->ecpay_dca_payment)) {
            $this->ecpay_choose_payment = $choose_payment;
            return true;
        } else {
            $this->ECPay_add_error($this->tran('Invalid payment method.'));
            return false;
        }
    }

    /**
     * Add a WooCommerce error message
     * @param  string   error message
     */
    private function ECPay_add_error($error_message)
    {
        wc_add_notice($error_message, 'error');
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id)
    {
        # Update order status
        $order = new WC_Order($order_id);
        $order->update_status('pending', $this->tran('Awaiting ECPay payment'));

        # Set the ECPay payment type to the order note
        $order->add_order_note(__('Credit_' . $this->ecpay_choose_payment, 'ecpay'), true);

        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }

    /**
     * Redirect to ECPay
     */
    public function receipt_page($order_id)
    {
        # Clean the cart
        global $woocommerce;
        $woocommerce->cart->empty_cart();
        $order = new WC_Order($order_id);

        try {
            $this->invoke_ecpay_module();
            $aio = new ECPay_AllInOne();
            $aio->Send['MerchantTradeNo'] = '';
            $service_url = '';
            if ($this->ecpay_test_mode == 'yes') {
                $service_url = 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut';
                $aio->Send['MerchantTradeNo'] = date('YmdHis');
            } else {
                $service_url = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut';
            }
            $aio->MerchantID = $this->ecpay_merchant_id;
            $aio->HashKey = $this->ecpay_hash_key;
            $aio->HashIV = $this->ecpay_hash_iv;
            $aio->ServiceURL = $service_url;
            $aio->Send['ReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_ECPay', home_url('/'));
            $aio->Send['ClientBackURL'] = $this->get_return_url($order);
            $aio->Send['MerchantTradeNo'] .= $order_id;
            $aio->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');

            // 接收額外回傳參數 提供電子發票使用 v1.1.0911
            $aio->Send['NeedExtraPaidInfo'] = 'Y';

            # Set the product info
            $aio->Send['TotalAmount'] = $order->get_total();
            array_push(
                $aio->Send['Items'],
                array(
                    'Name'     => '網路商品一批',
                    'Price'    => $aio->Send['TotalAmount'],
                    'Currency' => $order->get_currency(),
                    'Quantity' => 1,
                    'URL'      => '',
                )
            );

            $aio->Send['TradeDesc'] = 'ecpay_module_woocommerce_v1.1.1207';
            $notes = $order->get_customer_order_notes();
            $PeriodType = '';
            $Frequency = '';
            $ExecTimes = '';
            if (isset($notes[0])) {
                list($ChoosePayment, $PeriodType, $Frequency, $ExecTimes) = explode('_', $notes[0]->comment_content);
            }
            $aio->Send['ChoosePayment'] = 'Credit';
            $aio->SendExtend['UnionPay'] = false;
            $aio->SendExtend['PeriodAmount'] = $aio->Send['TotalAmount'];
            $aio->SendExtend['PeriodType'] = $PeriodType;
            $aio->SendExtend['Frequency'] = $Frequency;
            $aio->SendExtend['ExecTimes'] = $ExecTimes;
            $aio->SendExtend['PeriodReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Ecpay_DCA', home_url('/'));
            $aio->CheckOut();
            exit;
        } catch(Exception $e) {
            $this->ECPay_add_error($e->getMessage());
        }
    }

    /**
     * Process the callback
     */

    /**
     * Process the callback
     */
    public function receive_response()
    {
        $result_msg = '1|OK';
        $order = null;
        try {
            # Retrieve the check out result

            $aio = new ECPay_AllInOne();
            $aio->HashKey = $this->ecpay_hash_key;
            $aio->HashIV = $this->ecpay_hash_iv;
            $aio->MerchantID = $this->ecpay_merchant_id;
            $ecpay_feedback = $aio->CheckOutFeedback();
            unset($aio);
            if (count($ecpay_feedback) < 1) {
                throw new Exception('Get ECPay feedback failed.');
            } else {
                # Get the cart order id
                $cart_order_id = $ecpay_feedback['MerchantTradeNo'];
                if ($this->ecpay_test_mode == 'yes') {
                    $cart_order_id = substr($ecpay_feedback['MerchantTradeNo'], 14);
                }

                # Get the cart order amount
                $order = new WC_Order($cart_order_id);
                $cart_amount = $order->get_total();

                # Check the amounts
                $ecpay_amount = $ecpay_feedback['Amount'];
                if (round($cart_amount) != $ecpay_amount) {
                    throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                } else {
                    # Set the common comments
                    $comments = sprintf(
                        __('Payment Method : %s<br />Trade Time : %s<br />', 'ecpay'),
                        $ecpay_feedback['PaymentType'],
                        $ecpay_feedback['TradeDate']
                    );

                    # Set the getting code comments
                    $return_code = $ecpay_feedback['RtnCode'];
                    $return_message = $ecpay_feedback['RtnMsg'];
                    $get_code_result_comments = sprintf(
                        __('Getting Code Result : (%s)%s', 'ecpay'),
                        $return_code,
                        $return_message
                    );

                    # Set the payment result comments
                    $payment_result_comments = sprintf(
                        __( 'ECPay Paid Automatically Payment Result : (%s)%s', 'ecpay'),
                        $return_code,
                        $return_message
                    );

                    # Set the fail message
                    $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);

                    # Set the order comments
                    if ($return_code != 1 and $return_code != 800) {
                        throw new Exception($fail_msg);
                    } else {
                        if (!$this->is_order_complete($order) || ( isset($ecpay_feedback['TotalSuccessTimes']) && !empty($ecpay_feedback['TotalSuccessTimes']) ) ) {
                            $this->confirm_order($order, $payment_result_comments, $ecpay_feedback);
                        } else {
                            # The order already paid or not in the standard procedure, do nothing
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (!empty($order)) {
                $comments .= sprintf(__('Failed To Pay<br />Error : %s<br />', 'ecpay'), $error);
                $order->add_order_note($comments);
            }

            # Set the failure result
            $result_msg = '0|' . $error;
        }
        echo $result_msg;
        exit;
    }

    /**
     * Check if the order status is complete
     * @param  object   order
     * @return boolean  is the order complete
     */
    private function is_order_complete($order)
    {
        $status = '';
        $status = (method_exists($order,'get_status') == true ) ? $order->get_status() : $order->status;

        if ($status == 'pending') {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Complete the order and add the comments
     * @param  object   order
     */
    function confirm_order($order, $comments, $ecpay_feedback)
    {
        $order->add_order_note($comments, true);

        $order->payment_complete();

        // 加入信用卡後四碼，提供電子發票開立使用 v1.1.0911
        if(isset($ecpay_feedback['card4no']) && !empty($ecpay_feedback['card4no']))
        {
            add_post_meta( $order->get_id(), 'card4no', $ecpay_feedback['card4no'], true);
        }

        // call invoice model
        $invoice_active_ecpay = 0 ;
        $invoice_active_opay = 0 ;

        $active_plugins = (array) get_option( 'active_plugins', array() );

        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

        foreach ($active_plugins as $key => $value) {
            if ((strpos($value,'/woocommerce-ecpayinvoice.php') !== false)) {
                $invoice_active_ecpay = 1;
            }

            if ((strpos($value,'/woocommerce-opayinvoice.php') !== false)) {
                $invoice_active_opay = 1;
            }
        }

        if ($invoice_active_ecpay == 0 && $invoice_active_opay == 1) { // opay

            $aConfig_Invoice = get_option('wc_opayinvoice_active_model');

            // 記錄目前成功付款到第幾次
            $nTotalSuccessTimes = ( isset($ecpay_feedback['TotalSuccessTimes']) && ( empty($ecpay_feedback['TotalSuccessTimes']) || $ecpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $ecpay_feedback['TotalSuccessTimes'] ;
            update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

            if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_opay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_opay_invoice_auto'] == 'auto' ) {
                do_action('opay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
            }

        } elseif ($invoice_active_ecpay == 1 && $invoice_active_opay == 0) { // ecpay

            $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

            // 記錄目前成功付款到第幾次
            $nTotalSuccessTimes = ( isset($ecpay_feedback['TotalSuccessTimes']) && ( empty($ecpay_feedback['TotalSuccessTimes']) || $ecpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $ecpay_feedback['TotalSuccessTimes'] ;
            update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

            if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto' ) {
                do_action('ecpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
            }

        }
    }

    /**
    * Output for the order received page.
    *
    * @param int $order_id
    */
    public function thankyou_page( $order_id ) {

        $this->payment_details( $order_id );

    }


    /**
     * Get payment details and place into a list format.
     *
     * @param int $order_id
     */
    private function payment_details( $order_id = '' ) {

    }
}

?>
