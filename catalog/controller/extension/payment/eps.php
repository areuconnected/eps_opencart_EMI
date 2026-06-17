<?php
class ControllerExtensionPaymentEps extends Controller {
    
    // -----------------------------------------
    // 1. TOKEN GENERATOR
    // -----------------------------------------
    private function getToken($is_emi = false) {
        $username = $this->config->get('payment_eps_username');
        $password = $this->config->get('payment_eps_password');
        $hashKey = $this->config->get('payment_eps_hashkey');
        $is_test = $this->config->get('payment_eps_test');
        
        if ($is_emi) {
            $baseUrl = 'https://emiapi.eps.com.bd/v1/'; 
        } else {
            $baseUrl = $is_test ? 'https://sandboxpgapi.eps.com.bd/v1/' : 'https://pgapi.eps.com.bd/v1/';
        }
        
        $xhash = $this->generateHash($hashKey, $username);

        $payload = json_encode(array(
            'userName' => $username,
            'password' => $password
        ));

        return $this->curlPost($baseUrl . 'Auth/GetToken', $payload, $xhash);
    }

    // -----------------------------------------
    // 2. CHECKOUT VIEW LOAD
    // -----------------------------------------
    public function index() {
        $this->load->language('extension/payment/eps');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading'] = $this->language->get('text_loading');
        
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $data['order_total'] = (float)$order_info['total'];
        
        return $this->load->view('extension/payment/eps', $data);
    }

    // -----------------------------------------
    // 3. GET EMI BANKS
    // -----------------------------------------
    public function getBanks() {
        $tokenDataRaw = $this->getToken(true);
        $tokenData = json_decode($tokenDataRaw, true);
        
        if (isset($tokenData['token'])) {
            $storeId = $this->config->get('payment_eps_store_id');
            $merchantId = $this->config->get('payment_eps_merchant_id');
            $hashKey = $this->config->get('payment_eps_hashkey');
            $xhash = $this->generateHash($hashKey, $storeId);
            
            $payload = json_encode(array(
                'merchantId' => $merchantId,
                'storeId' => $storeId
            ));

            $response = $this->curlPost('https://emiapi.eps.com.bd/v1/EMI/BankList', $payload, $xhash, $tokenData['token']);
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput($response);
        } else {
            $this->log->write('EPS API Error (getBanks): Token generation failed. Response: ' . $tokenDataRaw);
        }
    }

    // -----------------------------------------
    // 4. GET EMI DETAILS
    // -----------------------------------------
    public function getEmiDetails() {
        if (!isset($this->request->post['bank_id'])) return;

        $tokenDataRaw = $this->getToken(true);
        $tokenData = json_decode($tokenDataRaw, true);
        
        if (isset($tokenData['token'])) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            $storeId = $this->config->get('payment_eps_store_id');
            $merchantId = $this->config->get('payment_eps_merchant_id');
            $hashKey = $this->config->get('payment_eps_hashkey');
            $xhash = $this->generateHash($hashKey, $storeId);
            
            $amount = (float)$this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

            $payload = json_encode(array(
                'merchantId' => $merchantId,
                'storeId' => $storeId,
                'bankId' => (int)$this->request->post['bank_id'],
                'productAmount' => $amount,
                'totalAmount' => $amount,
                'productName' => "Order " . $order_info['order_id']
            ));

            $responseRaw = $this->curlPost('https://emiapi.eps.com.bd/v1/EMI/EmiDetails', $payload, $xhash, $tokenData['token']);
            $response = json_decode($responseRaw, true);
            
            if (!isset($response['EmiDetails'])) {
                $response['debug_raw'] = $responseRaw;
                $response['debug_payload'] = $payload;
                $this->log->write('EPS API Error (getEmiDetails): ' . $responseRaw);
            }

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($response));
        } else {
            $this->log->write('EPS API Error (getEmiDetails): Token generation failed. Response: ' . $tokenDataRaw);
        }
    }

    // -----------------------------------------
    // 5. CONFIRM PAYMENT (SEND TO EPS)
    // -----------------------------------------
    public function confirm() {
        $json = array();
        
        try {
            if (!isset($this->session->data['order_id'])) {
                throw new Exception('Your session has expired or the order ID is missing. Please refresh the page and try again.');
            }

            $is_emi = (isset($this->request->post['eps_payment_type']) && $this->request->post['eps_payment_type'] == 'emi');

            $tokenDataRaw = $this->getToken($is_emi);
            $tokenData = json_decode($tokenDataRaw, true);

            if (isset($tokenData['token'])) {
                $this->load->model('checkout/order');
                $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
                
                if (!$order_info) {
                    throw new Exception('Could not retrieve order information from the database.');
                }
                
                $prefix = $is_emi ? 'emi_' : 'txn_';
                $merchantTxnId = uniqid($prefix) . $order_info['order_id'];
                
                $hashKey = $this->config->get('payment_eps_hashkey');
                $xhash = $this->generateHash($hashKey, $merchantTxnId);

                $this->session->data['eps_merchant_txn_id'] = $merchantTxnId;
                $this->session->data['eps_is_emi'] = $is_emi;

                $total_amount = (float)$this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
                $ip_address = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '127.0.0.1';

                $safe_order_id = "ORD-" . str_pad($order_info['order_id'], 5, "0", STR_PAD_LEFT);
                $safe_order_id = substr($safe_order_id, 0, 15);

                // Safe Data Extractors
                $pay_first   = isset($order_info['payment_firstname']) ? $order_info['payment_firstname'] : '';
                $pay_last    = isset($order_info['payment_lastname']) ? $order_info['payment_lastname'] : '';
                $pay_addr1   = isset($order_info['payment_address_1']) ? $order_info['payment_address_1'] : 'N/A';
                $pay_addr2   = !empty($order_info['payment_address_2']) ? $order_info['payment_address_2'] : 'N/A';
                $pay_city    = isset($order_info['payment_city']) ? $order_info['payment_city'] : 'N/A';
                $pay_zone    = isset($order_info['payment_zone']) ? $order_info['payment_zone'] : 'N/A';
                $pay_post    = isset($order_info['payment_postcode']) ? $order_info['payment_postcode'] : '0000';
                $pay_iso     = isset($order_info['payment_iso_code_2']) ? $order_info['payment_iso_code_2'] : 'BD';
                
                $ship_name   = !empty($order_info['shipping_firstname']) ? $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'] : trim($pay_first . ' ' . $pay_last);
                $ship_addr   = !empty($order_info['shipping_address_1']) ? $order_info['shipping_address_1'] : $pay_addr1;
                $ship_city   = !empty($order_info['shipping_city']) ? $order_info['shipping_city'] : $pay_city;
                $ship_zone   = !empty($order_info['shipping_zone']) ? $order_info['shipping_zone'] : $pay_zone;
                $ship_post   = !empty($order_info['shipping_postcode']) ? $order_info['shipping_postcode'] : $pay_post;
                $ship_country= !empty($order_info['shipping_iso_code_2']) ? $order_info['shipping_iso_code_2'] : $pay_iso;

                $payloadArray = array(
                    "storeId" => $this->config->get('payment_eps_store_id'),
                    "merchantTransactionId" => $merchantTxnId,
                    "CustomerOrderId" => $safe_order_id,
                    "transactionTypeId" => 1,
                    "financialEntityId" => 0,
                    "transitionStatusId" => 0,
                    "totalAmount" => $total_amount,
                    "ipAddress" => $ip_address,
                    "version" => "1",
                    "successUrl" => $this->url->link('extension/payment/eps/callback', 'status=success', true),
                    "failUrl" => $this->url->link('extension/payment/eps/callback', 'status=fail', true),
                    "cancelUrl" => $this->url->link('checkout/checkout', '', true),
                    "customerName" => trim($pay_first . ' ' . $pay_last),
                    "customerEmail" => !empty($order_info['email']) ? $order_info['email'] : 'no-email@provided.com',
                    "customerAddress" => $pay_addr1,
                    "customerAddress2" => $pay_addr2,
                    "customerCity" => $pay_city,
                    "customerState" => $pay_zone,
                    "customerPostcode" => $pay_post,
                    "customerCountry" => $pay_iso,
                    "customerPhone" => !empty($order_info['telephone']) ? $order_info['telephone'] : '00000000000',
                    "shipmentName" => trim($ship_name),
                    "shipmentAddress" => $ship_addr,
                    "shipmentAddress2" => "N/A",
                    "shipmentCity" => $ship_city,
                    "shipmentState" => $ship_zone,
                    "shipmentPostcode" => $ship_post,
                    "shipmentCountry" => $ship_country,
                    "valueA" => "string",
                    "valueB" => "string",
                    "valueC" => "string",
                    "valueD" => "string",
                    "shippingMethod" => "string",
                    "noOfItem" => "1",
                    "productName" => "Order " . $order_info['order_id'],
                    "productProfile" => "string",
                    "productCategory" => "string"
                );

                if ($is_emi) {
                    $payloadArray['emiType'] = 1;
                    $payloadArray['bankId'] = (int)$this->request->post['bank_id'];
                    $payloadArray['emiMonthId'] = (int)$this->request->post['emi_month_id'];
                    
                    $endpoint = 'https://emiapi.eps.com.bd/v1/EMI/InitializeEPS';
                } else {
                    $payloadArray = array("merchantId" => $this->config->get('payment_eps_merchant_id')) + $payloadArray;
                    
                    $is_test = $this->config->get('payment_eps_test');
                    $baseUrl = $is_test ? 'https://sandboxpgapi.eps.com.bd/v1/' : 'https://pgapi.eps.com.bd/v1/';
                    $endpoint = $baseUrl . 'EPSEngine/InitializeEPS';
                }

                $responseRaw = $this->curlPost($endpoint, json_encode($payloadArray), $xhash, $tokenData['token']);
                $response = json_decode($responseRaw, true);

                if (isset($response['RedirectURL']) && !empty($response['RedirectURL'])) {
                    $json['redirect'] = $response['RedirectURL'];
                } else {
                    $err = isset($response['ErrorMessage']) && !empty($response['ErrorMessage']) ? $response['ErrorMessage'] : 'Unknown API Error';
                    $json['error'] = 'EPS API Error: ' . $err;
                    $this->log->write('EPS Checkout Initialization Error: ' . $responseRaw);
                }
            } else {
                $this->log->write('EPS Checkout Authentication Error: Token generation failed. Response: ' . $tokenDataRaw);
                $json['error'] = 'Failed to securely authenticate with EPS Gateway. Please try again.';
            }

        } catch (Exception $e) {
            $error_message = 'FATAL PHP CRASH: ' . $e->getMessage() . ' on line ' . $e->getLine();
            $json['error'] = $error_message;
            $this->log->write($error_message);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // -----------------------------------------
    // 6. RETURN CALLBACK (VERIFICATION)
    // -----------------------------------------
    public function callback() {
        $this->load->model('checkout/order');
        
        $merchantTxnId = '';
        if (isset($this->request->get['merchantTransactionId'])) {
            $merchantTxnId = $this->request->get['merchantTransactionId'];
        } elseif (isset($this->request->post['merchantTransactionId'])) {
            $merchantTxnId = $this->request->post['merchantTransactionId'];
        } elseif (isset($this->session->data['eps_merchant_txn_id'])) {
            $merchantTxnId = $this->session->data['eps_merchant_txn_id'];
        }

        if (empty($merchantTxnId)) {
            $this->log->write('EPS Callback Fatal Error: No merchantTransactionId provided by the gateway.');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }

        $order_id = substr($merchantTxnId, 17);
        $is_emi = (strpos($merchantTxnId, 'emi_') === 0);
        
        if (!$order_id) {
            $this->log->write('EPS Callback Error: Could not extract Order ID from string: ' . $merchantTxnId);
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }

        if (isset($this->request->get['status']) && $this->request->get['status'] == 'fail') {
            $this->model_checkout_order->addOrderHistory($order_id, 10, 'Payment failed or cancelled by user.', true);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }

        $tokenDataRaw = $this->getToken($is_emi);
        $tokenData = json_decode($tokenDataRaw, true);

        if (isset($tokenData['token'])) {
            $hashKey = $this->config->get('payment_eps_hashkey');
            $xhash = $this->generateHash($hashKey, $merchantTxnId);

            $is_test = $this->config->get('payment_eps_test');
            
            if ($is_emi) {
                $endpoint = 'https://emiapi.eps.com.bd/v1/EMI/CheckMerchantTransactionStatus?merchantTransactionId=' . $merchantTxnId;
            } else {
                $baseUrl = $is_test ? 'https://sandboxpgapi.eps.com.bd/v1/' : 'https://pgapi.eps.com.bd/v1/';
                $endpoint = $baseUrl . 'EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . $merchantTxnId;
            }

            $verifyResponseRaw = $this->curlGet($endpoint, $xhash, $tokenData['token']);
            $verifyResponse = json_decode($verifyResponseRaw, true);

            if (isset($verifyResponse['Status']) && strtolower($verifyResponse['Status']) == 'success') {
                $success_status_id = $this->config->get('payment_eps_order_status_id');
                
                $eps_trx_id = 'Unknown';
                if (isset($verifyResponse['MerchantTransactionId']) && !empty($verifyResponse['MerchantTransactionId'])) {
                    $eps_trx_id = $verifyResponse['MerchantTransactionId'];
                } elseif (isset($verifyResponse['merchantTransactionId']) && !empty($verifyResponse['merchantTransactionId'])) {
                    $eps_trx_id = $verifyResponse['merchantTransactionId'];
                } elseif (isset($verifyResponse['epsTransactionId']) && !empty($verifyResponse['epsTransactionId'])) {
                    $eps_trx_id = $verifyResponse['epsTransactionId'];
                } elseif (isset($verifyResponse['EpsTransactionId']) && !empty($verifyResponse['EpsTransactionId'])) {
                    $eps_trx_id = $verifyResponse['EpsTransactionId'];
                } elseif (isset($verifyResponse['TransactionId']) && !empty($verifyResponse['TransactionId'])) {
                    $eps_trx_id = $verifyResponse['TransactionId'];
                } elseif (isset($verifyResponse['transactionId']) && !empty($verifyResponse['transactionId'])) {
                    $eps_trx_id = $verifyResponse['transactionId'];
                }

                $message = 'EPS Payment Successful. Transaction ID: ' . $eps_trx_id;
                
                $this->model_checkout_order->addOrderHistory($order_id, $success_status_id, $message, true);
                
                unset($this->session->data['eps_merchant_txn_id']);
                unset($this->session->data['eps_is_emi']);

                $this->response->redirect($this->url->link('checkout/success', '', true));
            } else {
                $error_msg = isset($verifyResponse['ErrorMessage']) && !empty($verifyResponse['ErrorMessage']) ? $verifyResponse['ErrorMessage'] : 'Transaction verification failed.';
                $this->log->write('EPS Verification Failed for Order ' . $order_id . ' | Response: ' . $verifyResponseRaw);
                $this->model_checkout_order->addOrderHistory($order_id, 10, 'EPS Verification Error: ' . $error_msg, true);
                $this->response->redirect($this->url->link('checkout/failure', '', true));
            }
        } else {
            $this->log->write('EPS Verification Auth Error: Token generation failed. Response: ' . $tokenDataRaw);
            $this->model_checkout_order->addOrderHistory($order_id, 10, 'EPS Error: Could not generate token for verification.', true);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }
    }

    // -----------------------------------------
    // 7. UTILITY FUNCTIONS
    // -----------------------------------------
    private function generateHash($key, $data) {
        $utf8Key = mb_convert_encoding($key, 'UTF-8');
        $hmac = hash_hmac('sha512', $data, $utf8Key, true);
        return base64_encode($hmac);
    }

    private function curlPost($url, $payload, $xhash, $bearer = null) {
        $headers = array('Content-Type: application/json', 'x-hash: ' . $xhash);
        if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // HARDENED SSL BYPASS
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $result = curl_exec($ch);
        
        if ($result === false) {
            $this->log->write('EPS FATAL cURL ERROR (POST to ' . $url . '): ' . curl_error($ch));
        }
        
        curl_close($ch);
        return $result;
    }

    private function curlGet($url, $xhash, $bearer = null) {
        $headers = array('Content-Type: application/json', 'x-hash: ' . $xhash);
        if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPGET, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // HARDENED SSL BYPASS
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $result = curl_exec($ch);
        
        if ($result === false) {
            $this->log->write('EPS FATAL cURL ERROR (GET to ' . $url . '): ' . curl_error($ch));
        }
        
        curl_close($ch);
        return $result;
    }
}
