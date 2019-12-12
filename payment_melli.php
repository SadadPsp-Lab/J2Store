<?php
	defined('_JEXEC') or die('Restricted access');

	require_once(JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php');
	require_once(JPATH_ADMINISTRATOR . '/components/com_j2store/helpers/j2store.php');

	class plgJ2StorePayment_melli extends J2StorePaymentPlugin {
		/**
		 * @var $_element  string  Should always correspond with the plugin's filename,
		 *                         forcing it to be unique
		 */
		var $_element = 'payment_melli';
		private
				$merchantCode = '',
				$merchantId = '',
				$terminalId = '',
				$terminalKey = '',
				$callBackUrl = '',
				$redirectToMelli = '';

		public function __construct(& $subject, $config) {
			parent::__construct($subject, $config);
			$this->loadLanguage('', JPATH_ADMINISTRATOR);
			$this->merchantCode = trim($this->params->get('merchant_id'));
			$this->merchantId = trim($this->params->get('merchant_id'));
			$this->terminalId = trim($this->params->get('terminal_id'));
			$this->terminalKey = trim($this->params->get('terminal_key'));
			$this->callBackUrl = JUri::root() . '/index.php?option=com_j2store&view=checkout&task=confirmPayment&orderpayment_type=payment_melli&paction=callback';
			$this->redirectToMelli = 'https://www.melli.com/pg/StartPay/';
		}

		public function _renderForm($data) {
			$vars = new JObject();
			$vars->message = JText::_("J2STORE_MELLI_PAYMENT_MESSAGE");
			$html = $this->_getLayout('form', $vars);
			return $html;
		}

		public function _prePayment($data) {
			$vars = new StdClass();
			$vars->display_name = $this->params->get('display_name', '');
			$vars->onbeforepayment_text = JText::_("J2STORE_MELLI_PAYMENT_PREPARATION_MESSAGE");


			$amount = (int)$data['orderpayment_amount'];


			$redirect = $this->callBackUrl;

			$terminal_id = trim($this->params->get('terminal_id'));
			$merchant_id = trim($this->params->get('merchant_id'));
			$terminal_key = trim($this->params->get('terminal_key'));
			// todo: order_id need to be retrieved
			$order_id = rand(100000, 999999);
			$sign_data = $this->sadad_encrypt($terminal_id . ';' . $order_id . ';' . $amount, $terminal_key);


			$parameters = array(
					'MerchantID' => $merchant_id,
					'TerminalId' => $terminal_id,
					'Amount' => $amount,
					'OrderId' => $order_id,
					'LocalDateTime' => date('Ymdhis'),
					'ReturnUrl' => $redirect,
					'SignData' => $sign_data,
			);

			$error_flag = false;
			$error_msg = '';

			$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);
			if ($result != false) {
				if ($result->ResCode == 0) {
					$vars->token = $result->Token;
					$vars->redirectToMelli = 'https://sadad.shaparak.ir/VPG/Purchase';
					$html = $this->_getLayout('prepayment', $vars);
					return $html;
				} else {
					//bank returned an error
					$error_flag = true;
					$error_msg = JText::_("J2STORE_MELLI_PAYMENT_REDIRECT_ERROR") . $this->sadad_request_err_msg($result->ResCode);
				}
			} else {
				// couldn't connect to bank
				$error_flag = true;
				$error_msg = JText::_("J2STORE_MELLI_PAYMENT_CONNECTION_ERROR");
			}

			if ($error_flag) {
				$vars->error = $error_msg;
			}
			$html = $this->_getLayout('prepayment', $vars);
			return $html;
		}

		public function _postPayment($data) {
			$vars = new JObject();
			//get order id
			$orderId = $data['order_id'];
			// get instatnce of j2store table
			F0FTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_j2store/tables');
			$order = F0FTable::getInstance('Order', 'J2StoreTable')->getClone();
			$order->load(array('order_id' => $orderId));

			if ($order->load(array('order_id' => $orderId))) {

				$currency = J2Store::currency();
				$currencyValues = $this->getCurrency($order);
				$orderPaymentAmount = $currency->format($order->order_total, $currencyValues['currency_code'], $currencyValues['currency_value'], false);
				$orderPaymentAmount = (int)$orderPaymentAmount;

				$order->add_history(JText::_('J2STORE_CALLBACK_RESPONSE_RECEIVED'));



				if ($orderId && isset($_POST['token']) && isset($_POST['OrderId']) && isset($_POST['ResCode'])) {
					$token = $_POST['token'];

					//verify payment
					$parameters = array(
							'Token' => $token,
							'SignData' => $this->sadad_encrypt($token, trim($this->params->get('terminal_key')))
					);

					$error_flag = false;
					$error_msg = '';

					$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);
					if ($result != false) {
						if ($result->ResCode == 0) {
							//successfully verified
							$order->payment_complete();
							$order->empty_cart();
							$message = JText::_("J2STORE_MELLI_PAYMENT_SUCCESS") . PHP_EOL;
							$message .= JText::_("J2STORE_MELLI_PAYMENT_REF") . $result->RetrivalRefNo;
							$vars->message = $message;
							$html = $this->_getLayout('postpayment', $vars);
							return $html;
						} else {
							//couldn't verify the payment due to a back error
							$error_flag = true;
							$error_msg = JText::_("J2STORE_MELLI_PAYMENT_PROCESS_ERROR") . $this->sadad_verify_err_msg($result->ResCode);
						}
					} else {
						//couldn't verify the payment due to a connection failure to bank
						$error_flag = true;
						$error_msg = JText::_("J2STORE_MELLI_PAYMENT_NO_VERVIFY_ERROR");
					}

					$message = JText::_("J2STORE_MELLI_PAYMENT_FAILED") . PHP_EOL;
					$message .= JText::_("J2STORE_MELLI_PAYMENT_ERROR");
					$message .= $error_msg . PHP_EOL;
					$message .= JText::_("J2STORE_MELLI_PAYMENT_CONTACT") . PHP_EOL;
					$vars->message = $message;
					$html = $this->_getLayout('postpayment', $vars);
					return $html;

				}


			}

			$vars->message = JText::_("J2STORE_MELLI_PAYMENT_PAGE_ERROR");
			$html = $this->_getLayout('postpayment', $vars);
			return $html;
		}
		


        //Create sign data(Tripledes(ECB,PKCS7)) using mcrypt
        private function mcrypt_encrypt_pkcs7($str, $key) {
            $block = mcrypt_get_block_size("tripledes", "ecb");
            $pad = $block - (strlen($str) % $block);
            $str .= str_repeat(chr($pad), $pad);
            $ciphertext = mcrypt_encrypt("tripledes", $key, $str,"ecb");
            return base64_encode($ciphertext);
        }

        //Create sign data(Tripledes(ECB,PKCS7)) using openssl
        private function openssl_encrypt_pkcs7($key, $data) {
            $ivlen = openssl_cipher_iv_length('des-ede3');
            $iv = openssl_random_pseudo_bytes($ivlen);
            $encData = openssl_encrypt($data, 'des-ede3', $key, 0, $iv);
            return $encData;
        }


        private function sadad_encrypt($data, $key) {
            $key = base64_decode($key);
            if( function_exists('openssl_encrypt') ) {
                return $this->openssl_encrypt_pkcs7($key, $data);
            } elseif( function_exists('mcrypt_encrypt') ) {
                return $this->mcrypt_encrypt_pkcs7($data, $key);
            } else {
                require_once 'TripleDES.php';
                $cipher = new Crypt_TripleDES();
                return $cipher->letsEncrypt($key, $data);
            }

        }


		private function sadad_call_api($url, $data = false) {
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

		private function sadad_request_err_msg($err_code) {
			return JText::_("J2STORE_MELLI_PAYMENT_REQ_" . $err_code);
		}

		private function sadad_verify_err_msg($res_code) {
			return JText::_("J2STORE_MELLI_PAYMENT_VER_" . $res_code);
		}


	}