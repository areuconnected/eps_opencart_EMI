<?php
class ControllerExtensionPaymentEps extends Controller {
    private $error = [];

    public function index() {
        $this->load->language('extension/payment/eps');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_eps', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        // ... standard error/success messages (copy from any other payment like sslcommerz)

        $data['heading_title'] = $this->language->get('heading_title');

        // Fields
        $fields = ['payment_eps_hashkey', 'payment_eps_username', 'payment_eps_password', 'payment_eps_storeid',
                   'payment_eps_sandbox', 'payment_eps_debug', 'payment_eps_total', 'payment_eps_order_status_id', 'payment_eps_geo_zone_id',
                   'payment_eps_status', 'payment_eps_sort_order'];

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/eps', $data));
    }

    private function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/eps')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}