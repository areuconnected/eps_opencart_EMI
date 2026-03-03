<?php
class ControllerExtensionPaymentEps extends Controller {

    public function index() {
        $this->load->language('extension/payment/eps');
        $data['button_confirm'] = $this->language->get('button_confirm');
        return $this->load->view('extension/payment/eps', $data);
    }

    public function confirm() {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/eps');
        $this->load->language('extension/payment/eps');

        $json = ['error' => ''];

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id'] ?? 0);

        if (!$order_info) {
            $json['error'] = 'Order not found!';
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        // Auto switch between sandbox and live (V5 compliant)
        $sandbox   = $this->config->get('payment_eps_sandbox');
        $base_url  = $sandbox ? 'https://sandboxpgapi.eps.com.bd' : 'https://pgapi.eps.com.bd';

        $hash_key  = $this->config->get('payment_eps_hashkey');
        $username  = $this->config->get('payment_eps_username');
        $password  = $this->config->get('payment_eps_password');
        $store_id  = $this->config->get('payment_eps_storeid');

        if (empty($hash_key) || empty($username) || empty($password) || empty($store_id)) {
            $json['error'] = 'EPS credentials missing!';
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $token = $this->getEpsToken($base_url, $username, $password, $hash_key);
        if (!$token) {
            $json['error'] = 'EPS is busy right now. Please try again in 1-2 minutes.';
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $merchant_tx_id = 'OC-' . $order_info['order_id'] . '-' . time() . '-' . substr(uniqid(), -8);

        $this->session->data['eps_merchant_tx_id'] = $merchant_tx_id;

        $product_list = [];
        foreach ($this->cart->getProducts() as $product) {
            $product_list[] = [
                'ProductName'     => $product['name'],
                'NoOfItem'        => (string)$product['quantity'],
                'ProductProfile'  => 'general',
                'ProductCategory' => 'ecommerce',
                'ProductPrice'    => (string)round($product['price'], 2)
            ];
        }

        $body = [
            'storeId'                => $store_id,
            'merchantTransactionId'  => $merchant_tx_id,
            'CustomerOrderId'        => $merchant_tx_id,
            'transactionTypeId'      => 1,
            'financialEntityId'      => 0,
            'transitionStatusId'     => 0,
            'totalAmount'            => round($order_info['total'], 2),
            'ipAddress'              => $this->request->server['REMOTE_ADDR'] ?? '127.0.0.1',
            'version'                => '1',

            'successUrl'             => $this->url->link('extension/payment/eps/callback', 'status=success', true),
            'failUrl'                => $this->url->link('extension/payment/eps/callback', 'status=fail', true),
            'cancelUrl'              => $this->url->link('extension/payment/eps/callback', 'status=cancel', true),

            'customerName'           => trim($order_info['payment_firstname'] . ' ' . $order_info['payment_lastname']),
            'customerEmail'          => $order_info['email'],
            'customerAddress'        => $order_info['payment_address_1'],
            'customerAddress2'       => $order_info['payment_address_2'] ?? '',
            'customerCity'           => $order_info['payment_city'],
            'customerState'          => $order_info['payment_zone'],
            'customerPostcode'       => $order_info['payment_postcode'],
            'customerCountry'        => $order_info['payment_iso_code_2'] ?? 'BD',
            'customerPhone'          => $order_info['telephone'],

            'productName'            => 'Order #' . $order_info['order_id'],
            'ProductList'            => $product_list
        ];

        $x_hash = $this->model_extension_payment_eps->generateXHash($merchant_tx_id, $hash_key);

        $headers = [
            'x-hash: ' . $x_hash,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $response = $this->apiCall('POST', $base_url . '/v1/EPSEngine/InitializeEPS', $body, $headers);

        if (!empty($response['RedirectURL'])) {
            $json['redirect'] = $response['RedirectURL'];
        } else {
            $json['error'] = $response['ErrorMessage'] ?? 'EPS gateway error';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function getEpsToken($base_url, $username, $password, $hash_key) {
        // 15-minute cache (prevents rate limit)
        if (isset($this->session->data['eps_token']) && (time() - ($this->session->data['eps_token_time'] ?? 0) < 900)) {
            return $this->session->data['eps_token'];
        }

        $x_hash = $this->model_extension_payment_eps->generateXHash($username, $hash_key);

        $response = $this->apiCall('POST', $base_url . '/v1/Auth/GetToken', [
            'userName' => $username,
            'password' => $password
        ], ['x-hash: ' . $x_hash]);

        if (!empty($response['token'])) {
            $this->session->data['eps_token'] = $response['token'];
            $this->session->data['eps_token_time'] = time();
            return $response['token'];
        }
        return false;
    }

    private function apiCall($method, $url, $data = [], $headers = []) {
        $ch = curl_init($url);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_unique($headers));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);

        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result, true) ?? [];
    }

        public function callback() {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/eps');

        // Clean query parameters (fixes &amp; issue)
        $get = [];
        foreach ($this->request->get as $key => $value) {
            $clean_key = str_replace('amp;', '', $key);
            $get[$clean_key] = $value;
        }

        $merchant_tx_id = $get['MerchantTransactionId'] ?? $get['merchantTransactionId'] ?? $this->session->data['eps_merchant_tx_id'] ?? '';

        if (empty($merchant_tx_id)) {
            $this->response->redirect($this->url->link('checkout/failure'));
        }

        $order_id = $this->session->data['order_id'] ?? 0;
        if (!$order_id && strpos($merchant_tx_id, 'OC-') === 0) {
            $parts = explode('-', $merchant_tx_id);
            $order_id = isset($parts[1]) ? (int)$parts[1] : 0;
        }

        if (!$order_id) {
            $this->response->redirect($this->url->link('checkout/failure'));
        }

        $sandbox  = $this->config->get('payment_eps_sandbox');
        $base_url = $sandbox ? 'https://sandboxpgapi.eps.com.bd' : 'https://pgapi.eps.com.bd';

        $hash_key = $this->config->get('payment_eps_hashkey');
        $token    = $this->getEpsToken($base_url, $this->config->get('payment_eps_username'), $this->config->get('payment_eps_password'), $hash_key);

        if (!$token) {
            $this->model_checkout_order->addOrderHistory($order_id, 10, 'EPS Callback - Token failed', false);
            $this->response->redirect($this->url->link('checkout/failure'));
        }

        // V5 FIX: ALWAYS use merchantTransactionId for hash in Verify (this was the problem)
        $x_hash = $this->model_extension_payment_eps->generateXHash($merchant_tx_id, $hash_key);

        $headers = [
            'x-hash: ' . $x_hash,
            'Authorization: Bearer ' . $token
        ];

        $verify_url = $base_url . '/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . urlencode($merchant_tx_id);

        $verify = $this->apiCall('GET', $verify_url, [], $headers);

        // Log the exact response so we can debug if needed
        $this->log->write('EPS VERIFY RESPONSE: ' . json_encode($verify));

        if (isset($verify['Status']) && strtoupper($verify['Status']) === 'SUCCESS') {
            $comment = 'EPS Payment Successful | MerchantTx: ' . $merchant_tx_id;
            if (!empty($verify['EpsTransactionId'] ?? $get['EPSTransactionId'])) {
                $comment .= ' | EPSTx: ' . ($verify['EpsTransactionId'] ?? $get['EPSTransactionId']);
            }

            $this->model_checkout_order->addOrderHistory($order_id, 
                $this->config->get('payment_eps_order_status_id'), 
                $comment, 
                true);

            unset($this->session->data['eps_merchant_tx_id']);
            $this->response->redirect($this->url->link('checkout/success'));
        } else {
            $this->model_checkout_order->addOrderHistory($order_id, 10, 'EPS Failed/Cancelled', false);
            $this->session->data['error'] = 'Payment was not completed.';
            $this->response->redirect($this->url->link('checkout/failure'));
        }
    }
}