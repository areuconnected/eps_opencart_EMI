<?php
class ControllerExtensionPaymentEps extends Controller {
    private $error = array();

    public function index() {
        // Since we aren't creating a separate language file for brevity, 
        // we'll define the core text strings directly in the controller.
        $this->load->language('extension/payment/eps');
        $this->document->setTitle('EPS Payment Gateway (with EMI)');

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_eps', $this->request->post);
            $this->session->data['success'] = 'Success: You have modified EPS payment module settings!';
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        // Language Strings
        $data['heading_title'] = 'EPS Payment Gateway (with EMI)';
        $data['text_edit'] = 'Edit EPS Payment Gateway';
        $data['text_enabled'] = 'Enabled';
        $data['text_disabled'] = 'Disabled';
        $data['text_yes'] = 'Yes';
        $data['text_no'] = 'No';
        
        $data['entry_status'] = 'Status';
        $data['entry_test'] = 'Sandbox (Test Mode)';
        $data['entry_username'] = 'API Username';
        $data['entry_password'] = 'API Password';
        $data['entry_merchant_id'] = 'Merchant ID';
        $data['entry_store_id'] = 'Store ID';
        $data['entry_hashkey'] = 'Hash Key';
        $data['entry_order_status'] = 'Order Status (Success)';
        $data['entry_sort_order'] = 'Sort Order';

        $data['button_save'] = 'Save';
        $data['button_cancel'] = 'Cancel';

        // Error Handling
        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $errors = array('username', 'password', 'merchant_id', 'store_id', 'hashkey');
        foreach ($errors as $error_field) {
            if (isset($this->error[$error_field])) {
                $data['error_' . $error_field] = $this->error[$error_field];
            } else {
                $data['error_' . $error_field] = '';
            }
        }

        // Breadcrumbs
        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => 'Home',
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => 'Extensions',
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => 'EPS Payment',
            'href' => $this->url->link('extension/payment/eps', 'user_token=' . $this->session->data['user_token'], true)
        );

        // Actions
        $data['action'] = $this->url->link('extension/payment/eps', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        // Config Data Loading
        $fields = array(
            'payment_eps_status',
            'payment_eps_test',
            'payment_eps_username',
            'payment_eps_password',
            'payment_eps_merchant_id',
            'payment_eps_store_id',
            'payment_eps_hashkey',
            'payment_eps_order_status_id',
            'payment_eps_sort_order'
        );

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        // Order Statuses for Dropdown
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // Render Templates
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/eps', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/eps')) {
            $this->error['warning'] = 'Warning: You do not have permission to modify EPS payment!';
        }

        if (!$this->request->post['payment_eps_username']) {
            $this->error['username'] = 'API Username Required!';
        }
        if (!$this->request->post['payment_eps_password']) {
            $this->error['password'] = 'API Password Required!';
        }
        if (!$this->request->post['payment_eps_merchant_id']) {
            $this->error['merchant_id'] = 'Merchant ID Required!';
        }
        if (!$this->request->post['payment_eps_store_id']) {
            $this->error['store_id'] = 'Store ID Required!';
        }
        if (!$this->request->post['payment_eps_hashkey']) {
            $this->error['hashkey'] = 'Hash Key Required!';
        }

        return !$this->error;
    }
}