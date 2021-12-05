<?php
defined('_JEXEC') or die('Restricted access');

require_once(JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php');

class plgJ2StorePayment_sadad extends J2StorePaymentPlugin
{
    var $_element = 'payment_sadad';

    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
//        $this->loadLanguage('com_j2store', JPATH_ADMINISTRATOR);
    }

    function onJ2StoreCalculateFees($order)
    {
        $payment_method = $order->get_payment_method();

        if ($payment_method == $this->_element) {
            $total = $order->order_subtotal + $order->order_shipping + $order->order_shipping_tax;
            $surcharge = 0;
            $surcharge_percent = $this->params->get('surcharge_percent', 0);
            $surcharge_fixed = $this->params->get('surcharge_fixed', 0);
            if (( float )$surcharge_percent > 0 || ( float )$surcharge_fixed > 0) {
                // percentage
                if (( float )$surcharge_percent > 0) {
                    $surcharge += ($total * ( float )$surcharge_percent) / 100;
                }

                if (( float )$surcharge_fixed > 0) {
                    $surcharge += ( float )$surcharge_fixed;
                }

                $name = $this->params->get('surcharge_name', JText::_('J2STORE_CART_SURCHARGE'));
                $tax_class_id = $this->params->get('surcharge_tax_class_id', '');
                $taxable = false;
                if ($tax_class_id && $tax_class_id > 0)
                    $taxable = true;
                if ($surcharge > 0) {
                    $order->add_fee($name, round($surcharge, 2), $taxable, $tax_class_id);
                }
            }
        }
    }

    function _prePayment($data)
    {
        $app = JFactory::getApplication();
        $vars = new JObject();
        $vars->order_id = $data['order_id'];
        $vars->orderpayment_id = $data['orderpayment_id'];
        $vars->orderpayment_amount = $data['orderpayment_amount'];
        $vars->orderpayment_type = $this->_element;
        $vars->button_text = $this->params->get('button_text', 'J2STORE_PLACE_ORDER');
        $vars->display_name = 'پرداخت امن سداد';
        $vars->display_name = 'پرداخت امن سداد';

        $vars->merchant_id = $this->params->get('sadad_merchant_id', '');
        $vars->terminal_id = $this->params->get('sadad_terminal_id', '');
        $vars->terminal_key = $this->params->get('sadad_terminal_key', '');

        if ($vars->merchant_id == NULL || $vars->merchant_id == ''
            || $vars->terminal_id == NULL || $vars->terminal_id == ''
            || $vars->terminal_key == NULL || $vars->terminal_key == '') {
            $link = JRoute::_(JURI::root() . "index.php?option=com_j2store");
            $app->redirect($link, '<h2>لطفا تنظیمات درگاه سداد بانک ملی را بررسی کنید</h2>', $msgType = 'Error');
        } else {
            $Amount = round($vars->orderpayment_amount, 0) / 10;
            $CallbackURL = JRoute::_(JURI::root() . "index.php?option=com_j2store&view=checkout") . '&orderpayment_id=' . $vars->orderpayment_id . '&orderpayment_type=' . $vars->orderpayment_type . '&task=confirmPayment';

            $sign_data = $this->sadad_encrypt($vars->terminal_id . ';' . $data['orderpayment_id'] . ';' . $Amount, $vars->terminal_key);

            $parameters = array(
                'MerchantID' => $vars->merchant_id,
                'TerminalId' => $vars->terminal_id,
                'Amount' => $Amount,
                'OrderId' => $data['orderpayment_id'],
                'LocalDateTime' => date('m/d/Y g:i:s a'),
                'ReturnUrl' => $CallbackURL,
                'SignData' => $sign_data,
            );

            $result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);

            if ($result != false) {
                if ($result->ResCode == 0) {
                    $vars->sadad_token = $result->Token;
                    return $this->_getLayout('prepayment', $vars);
                } else {
                    $link = JRoute::_("index.php?option=com_j2store");
                    $app->redirect($link, '<h2>' . $this->sadad_request_err_msg($result->ResCode) . '</h2>', $msgType = 'Error');
                }
            } else {
                $link = JRoute::_("index.php?option=com_j2store");
                $app->redirect($link, '<h2>خطا در برقراری ارتباط با بانک!</h2>', $msgType = 'Error');
            }
        }
    }

    function _postPayment($data)
    {
        $app = JFactory::getApplication();
        $jinput = $app->input;
        $html = '';
        $orderpayment_id = $jinput->get->get('orderpayment_id', '0', 'INT');
        F0FTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_j2store/tables');
        $orderpayment = F0FTable::getInstance('Order', 'J2StoreTable')->getClone();

        $terminal_key = $this->params->get('sadad_terminal_key', '');

        $token = $_POST['Token'];
        $ResCode = $_POST['ResCode'];

        if (!empty($jinput->post->get('Token')))
            $token = $jinput->post->get('Token');

        if (!empty($jinput->post->get('ResCode')))
            $ResCode = $jinput->post->get('ResCode');


        if ($orderpayment->load($orderpayment_id)) {
            $customer_note = $orderpayment->customer_note;
            if ($orderpayment->j2store_order_id == $orderpayment_id) {

                $parameters = array(
                    'Token' => $token,
                    'SignData' => $this->sadad_encrypt($token, $terminal_key),
                );

                $result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);

                if ($token != NULL && $ResCode != NULL) {
                    if ($result != false) {
                        if ($result->ResCode == 0) {
                            $msg = 'عملیات پرداخت با موفقیت انجام شد !';
                            $this->saveStatus($msg, 1, $customer_note, 'ok', $result->RetrivalRefNo, $orderpayment);
                            $app->enqueueMessage('عملیات پرداخت با موفقیت انجام شد، کد پیگیری شما : ' . $result->RetrivalRefNo, 'message');
                        }
                        else {
                            $msg = $this->sadad_verify_err_msg($result->ResCode);
                            $this->saveStatus($msg, 3, $customer_note, 'nonok', null, $orderpayment);// error
                            $link = JRoute::_("index.php?option=com_j2store");
                            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                        }
                    }
                    else {
                        $msg = 'خطا در برقراری ارتباط با بانک !';
                        $this->saveStatus($msg, 4, $customer_note, 'nonok', null, $orderpayment);
                        $link = JRoute::_("index.php?option=com_j2store");
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                    }
                }
                else {
                    $msg = 'خطا در دریافت اطلاعات از بانک !';
                    $this->saveStatus($msg, 4, $customer_note, 'nonok', null, $orderpayment);
                    $link = JRoute::_("index.php?option=com_j2store");
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }
            }
            else {
                $msg = 'سفارش پیدا نشد !';
                $link = JRoute::_("index.php?option=com_j2store");
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
        }
        else {
            $msg = 'سفارش پیدا نشد !';
            $link = JRoute::_("index.php?option=com_j2store");
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }
    }

    function _renderForm($data)
    {
        $user = JFactory::getUser();
        $vars = new JObject();
        $vars->onselection_text = $this->params->get('onselection', '');
        $html = $this->_getLayout('form', $vars);
        return $html;
    }

    function getPaymentStatus($payment_status)
    {
        $status = '';
        switch ($payment_status) {
            case '1':
                $status = JText::_('J2STORE_CONFIRMED');
                break;
            case '2':
                $status = JText::_('J2STORE_PROCESSED');
                break;
            case '3':
                $status = JText::_('J2STORE_FAILED');
                break;
            case '4':
                $status = JText::_('J2STORE_PENDING');
                break;
            case '5':
                $status = JText::_('J2STORE_INCOMPLETE');
                break;
            default:
                $status = JText::_('J2STORE_PENDING');
                break;
        }
        return $status;
    }

    function saveStatus($msg, $statCode, $customer_note, $emptyCart, $trackingCode, $orderpayment)
    {
        $html = '<br />';
        $html .= '<strong>' . ':SADAD:' . '</strong>';
        $html .= '<br />';
        if (isset($trackingCode)) {
            $html .= '<br />';
            $html .= $trackingCode . 'شماره پیگری ';
            $html .= '<br />';
        }
        $html .= '<br />' . $msg;
        $orderpayment->customer_note = $customer_note . $html;
        $payment_status = $this->getPaymentStatus($statCode);
        $orderpayment->transaction_status = $payment_status;
        $orderpayment->order_state = $payment_status;
        $orderpayment->order_state_id = $this->params->get('payment_status', $statCode);

        if ($orderpayment->store()) {
            if ($emptyCart == 'ok') {
                $orderpayment->payment_complete();
                $orderpayment->empty_cart();
            }
        } else {
            $errors[] = $orderpayment->getError();
        }

        $vars = new JObject();
        $vars->onafterpayment_text = $msg;
        $html = $this->_getLayout('postpayment', $vars);
        $html .= $this->_displayArticle();
        return $html;
    }

    function sadad_request_err_msg($err_code)
    {
        $message = 'در حین پرداخت خطای سیستمی رخ داده است .';
        switch ($err_code) {
            case 3:
                $message = 'پذيرنده کارت فعال نیست لطفا با بخش امورپذيرندگان, تماس حاصل فرمائید.';
                break;
            case 23:
                $message = 'پذيرنده کارت نامعتبر است لطفا با بخش امورذيرندگان, تماس حاصل فرمائید.';
                break;
            case 58:
                $message = 'انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد.';
                break;
            case 61:
                $message = 'مبلغ تراکنش از حد مجاز بالاتر است.';
                break;
            case 1000:
                $message = 'ترتیب پارامترهای ارسالی اشتباه می باشد, لطفا مسئول فنی پذيرنده با بانکماس حاصل فرمايند.';
                break;
            case 1001:
                $message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,پارامترهای پرداختاشتباه می باشد.';
                break;
            case 1002:
                $message = 'خطا در سیستم- تراکنش ناموفق';
                break;
            case 1003:
                $message = 'آی پی پذیرنده اشتباه است. لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند.';
                break;
            case 1004:
                $message = 'لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند,شماره پذيرندهاشتباه است.';
                break;
            case 1005:
                $message = 'خطای دسترسی:لطفا بعدا تلاش فرمايید.';
                break;
            case 1006:
                $message = 'خطا در سیستم';
                break;
            case 1011:
                $message = 'درخواست تکراری- شماره سفارش تکراری می باشد.';
                break;
            case 1012:
                $message = 'اطلاعات پذيرنده صحیح نیست,يکی از موارد تاريخ,زمان يا کلید تراکنش
						اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.';
                break;
            case 1015:
                $message = 'پاسخ خطای نامشخص از سمت مرکز';
                break;
            case 1017:
                $message = 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است';
                break;
            case 1018:
                $message = 'اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید';
                break;
            case 1019:
                $message = 'امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست';
                break;
            case 1020:
                $message = 'پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد';
                break;
            case 1023:
                $message = 'آدرس بازگشت پذيرنده نامعتبر است';
                break;
            case 1024:
                $message = 'مهر زمانی پذيرنده نامعتبر است';
                break;
            case 1025:
                $message = 'امضا تراکنش نامعتبر است';
                break;
            case 1026:
                $message = 'شماره سفارش تراکنش نامعتبر است';
                break;
            case 1027:
                $message = 'شماره پذيرنده نامعتبر است';
                break;
            case 1028:
                $message = 'شماره ترمینال پذيرنده نامعتبر است';
                break;
            case 1029:
                $message = 'آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
                break;
            case 1030:
                $message = 'آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست .لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند';
                break;
            case 1031:
                $message = 'مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید .';
                break;
            case 1032:
                $message = 'پرداخت با اين کارت . برای پذيرنده مورد نظر شما امکان پذير نیست.لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است . استفاده نمايید.';
                break;
            case 1033:
                $message = 'به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده
						است.لطفا مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند.';
                break;
            case 1036:
                $message = 'اطلاعات اضافی ارسال نشده يا دارای اشکال است';
                break;
            case 1037:
                $message = 'شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد';
                break;
            case 1053:
                $message = 'خطا: درخواست معتبر, از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید.';
                break;
            case 1055:
                $message = 'مقدار غیرمجاز در ورود اطلاعات';
                break;
            case 1056:
                $message = 'سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید.';
                break;
            case 1058:
                $message = 'سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمايید.';
                break;
            case 1061:
                $message = 'اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد مرورگر « عملیات پرداخت را انجام دهید )احتمال استفاده از دکمه Back » مرورگر(';
                break;
            case 1064:
                $message = 'لطفا مجددا سعی بفرمايید';
                break;
            case 1065:
                $message = 'ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید';
                break;
            case 1066:
                $message = 'سیستم سرويس دهی پرداخت موقتا غیر فعال شده است';
                break;
            case 1068:
                $message = 'با عرض پوزش به علت بروزرسانی . سیستم موقتا قطع میباشد.';
                break;
            case 1072:
                $message = 'خطا در پردازش پارامترهای اختیاری پذيرنده';
                break;
            case 1101:
                $message = 'مبلغ تراکنش نامعتبر است';
                break;
            case 1103:
                $message = 'توکن ارسالی نامعتبر است';
                break;
            case 1104:
                $message = 'اطلاعات تسهیم صحیح نیست';
                break;
            default:
                $message = 'خطای نامشخص';
        }
        return $message;
    }

    function sadad_verify_err_msg($res_code)
    {
        $error_text = '';
        switch ($res_code) {
            case -1:
            case '-1':
                $error_text = 'پارامترهای ارسالی صحیح نیست و يا تراکنش در سیستم وجود ندارد.';
                break;
            case 101:
            case '101':
                $error_text = 'مهلت ارسال تراکنش به پايان رسیده است.';
                break;
        }
        return $error_text;
    }

    private function mcrypt_encrypt_pkcs7($str, $key)
    {
        $block = mcrypt_get_block_size("tripledes", "ecb");
        $pad = $block - (strlen($str) % $block);
        $str .= str_repeat(chr($pad), $pad);
        $ciphertext = mcrypt_encrypt("tripledes", $key, $str, "ecb");
        return base64_encode($ciphertext);
    }

    private function openssl_encrypt_pkcs7($key, $data)
    {
        $encData = openssl_encrypt($data, 'des-ede3', $key, 0);
        return $encData;
    }


    private function sadad_encrypt($data, $key)
    {
        $key = base64_decode($key);
        if (function_exists('openssl_encrypt')) {
            return $this->openssl_encrypt_pkcs7($key, $data);
        } elseif (function_exists('mcrypt_encrypt')) {
            return $this->mcrypt_encrypt_pkcs7($data, $key);
        } else {
            require_once './TripleDES.php';
            $cipher = new Crypt_TripleDES();
            return $cipher->letsEncrypt($key, $data);
        }
    }

    private function sadad_call_api($url, $data = false)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $result = curl_exec($ch);
            curl_close($ch);
            return !empty($result) ? json_decode($result) : false;
        }
        catch (Exception $ex) {
            return false;
        }
    }

}
