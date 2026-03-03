<?php
class ModelExtensionPaymentEps extends Model {

    public function getMethod($address, $total) {
        $this->load->language('extension/payment/eps');

        // Geo Zone Check
        if ($this->config->get('payment_eps_geo_zone_id')) {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone 
                WHERE geo_zone_id = '" . (int)$this->config->get('payment_eps_geo_zone_id') . "' 
                AND country_id = '" . (int)$address['country_id'] . "' 
                AND (zone_id = '0' OR zone_id = '" . (int)$address['zone_id'] . "')");

            if (!$query->num_rows) {
                return [];
            }
        }

        // Minimum total check
        if ($this->config->get('payment_eps_total') > $total) {
            return [];
        }

        return [
            'code'       => 'eps',
            'title'      => $this->config->get('payment_eps_title') ?: $this->language->get('text_title'),
            'terms'      => '',
            'sort_order' => $this->config->get('payment_eps_sort_order')
        ];
    }

    public function generateXHash($data, $hashKey) {
        if (empty($data) || empty($hashKey)) {
            return '';
        }
        // HMAC-SHA512 + Base64 (PHP 8.2+ safe)
        return base64_encode(hash_hmac('sha512', $data, $hashKey, true));
    }
}