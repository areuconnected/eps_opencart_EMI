<?php
class ControllerExtensionPaymentEps extends Controller {
    
    // Abstracted Token Fetcher
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

    public function index() {
        $this->load->language('extension/payment/eps');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading'] = $this->language->get('text_loading');
        
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $data['order_total'] = (float)$order_info['total'];
        
        return $this->load->view('extension/payment/eps', $data);
    }

    public function getBanks() {
        $tokenData = json_decode($this->getToken(true), true);
        
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
        }
    }

    public function getEmiDetails() {
        if (!isset($this->request->post['bank_id'])) return;

        $tokenData = json_decode($this->getToken(true), true);
        
        if (isset($tokenData['token'])) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            $storeId = $this->config->get('payment_eps_store_id');
            $merchantId = $this->config->get('payment_eps_merchant_id');
            $hashKey = $this->config->get('payment_eps_hashkey');
            $xhash = $this->generateHash($hashKey, $storeId);
            
            $amount = (float)$this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

            // Sending BOTH productAmount (PDF) and totalAmount (Postman) to bypass their inconsistencies
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
            
            // Debug catcher
            if (!isset($response['EmiDetails'])) {
                $response['debug_raw'] = $responseRaw;
                $response['debug_payload'] = $payload;
            }

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($response));
        }
    }

    public function confirm() {
               $json = array();
        $is_emi = (isset($this->request->post['payment_type']) && $this->request->post['payment_type'] == 'emi');
        
        $tokenData = json_decode($this->getToken($is_emi), true);

        if (isset($tokenData['token'])) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
            
            $merchantTxnId = uniqid('txn_') . $order_info['order_id'];
            $hashKey = $this->config->get('payment_eps_hashkey');
            $xhash = $this->generateHash($hashKey, $merchantTxnId);

            $this->session->data['eps_merchant_txn_id'] = $merchantTxnId;
            $this->session->data['eps_is_emi'] = $is_emi;

            $total_amount = (float)$this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
            $ip_address = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '127.0.0.1';

            // Pad the Order ID to ensure it is between 5 and 15 characters (e.g. 129 becomes ORD-00129)
            $safe_order_id = "ORD-" . str_pad($order_info['order_id'], 5, "0", STR_PAD_LEFT);
            // Ensure it doesn't accidentally exceed 15 characters
            $safe_order_id = substr($safe_order_id, 0, 15);

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
                "customerName" => $order_info['payment_firstname'] . ' ' . $order_info['payment_lastname'],
                "customerEmail" => $order_info['email'],
                "customerAddress" => $order_info['payment_address_1'],
                "customerAddress2" => $order_info['payment_address_2'] ? $order_info['payment_address_2'] : "string",
                "customerCity" => $order_info['payment_city'],
                "customerState" => $order_info['payment_zone'],
                "customerPostcode" => $order_info['payment_postcode'],
                "customerCountry" => $order_info['payment_iso_code_2'],
                "customerPhone" => $order_info['telephone'],
                "shipmentName" => $order_info['shipping_firstname'] ? $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'] : "string",
                "shipmentAddress" => $order_info['shipping_address_1'] ? $order_info['shipping_address_1'] : "string",
                "shipmentAddress2" => "string",
                "shipmentCity" => $order_info['shipping_city'] ? $order_info['shipping_city'] : "string",
                "shipmentState" => $order_info['shipping_zone'] ? $order_info['shipping_zone'] : "string",
                "shipmentPostcode" => $order_info['shipping_postcode'] ? $order_info['shipping_postcode'] : "string",
                "shipmentCountry" => $order_info['shipping_iso_code_2'] ? $order_info['shipping_iso_code_2'] : "string",
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

            $ch = curl_init();
            $headers = array(
                'Content-Type: application/json',
                'x-hash: ' . $xhash,
                'Authorization: Bearer ' . $tokenData['token']
            );
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadArray));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
            
            $responseRaw = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $response = json_decode($responseRaw, true);

            if (isset($response['RedirectURL']) && !empty($response['RedirectURL'])) {
                $json['redirect'] = $response['RedirectURL'];
            } else {
                $err = isset($response['ErrorMessage']) && !empty($response['ErrorMessage']) ? $response['ErrorMessage'] : 'Unknown API Error';
                $json['error'] = 'EPS API Error: ' . $err;
                
//                $json['debug_endpoint'] = $endpoint;
//                $json['debug_payload'] = json_encode($payloadArray);
//                $json['debug_raw_response'] = $responseRaw ? $responseRaw : 'EMPTY_RESPONSE_FROM_EPS';
//                $json['debug_curl_error'] = $curlError ? $curlError : 'NO_CURL_ERROR';
//                $json['debug_http_code'] = (string)$httpCode;
            }
        } else {
            $json['error'] = 'Failed to authenticate with EPS Gateway.';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback() {
        $this->load->model('checkout/order');
        
        if (!isset($this->session->data['order_id']) || !isset($this->session->data['eps_merchant_txn_id'])) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }

        $order_id = $this->session->data['order_id'];
        $merchantTxnId = $this->session->data['eps_merchant_txn_id'];
        $is_emi = isset($this->session->data['eps_is_emi']) ? $this->session->data['eps_is_emi'] : false;
        
        if (isset($this->request->get['status']) && $this->request->get['status'] == 'fail') {
            $this->model_checkout_order->addOrderHistory($order_id, 10, 'Payment failed or cancelled by user.', true);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }

        $tokenData = json_decode($this->getToken($is_emi), true);

        if (isset($tokenData['token'])) {
            $hashKey = $this->config->get('payment_eps_hashkey');
            $xhash = $this->generateHash($hashKey, $merchantTxnId);

            $is_test = $this->config->get('payment_eps_test');
            
            // Fix: The correct Verify path from Postman for EMI
            if ($is_emi) {
                $endpoint = 'https://emiapi.eps.com.bd/v1/EMI/CheckMerchantTransactionStatus?merchantTransactionId=' . $merchantTxnId;
            } else {
                $baseUrl = $is_test ? 'https://sandboxpgapi.eps.com.bd/v1/' : 'https://pgapi.eps.com.bd/v1/';
                $endpoint = $baseUrl . 'EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . $merchantTxnId;
            }

            $verifyResponse = json_decode($this->curlGet($endpoint, $xhash, $tokenData['token']), true);

            if (isset($verifyResponse['Status']) && strtolower($verifyResponse['Status']) == 'success') {
                $success_status_id = $this->config->get('payment_eps_order_status_id');
                
                // Safely extract the Transaction ID using the Dev Team's recommended key first
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
                $this->model_checkout_order->addOrderHistory($order_id, 10, 'EPS Verification Error: ' . $error_msg, true);
                $this->response->redirect($this->url->link('checkout/failure', '', true));
            }
        } else {
            $this->model_checkout_order->addOrderHistory($order_id, 10, 'EPS Error: Could not generate token for verification.', true);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }
    }

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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}