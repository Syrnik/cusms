<?php

/**
 * @package shopCusmsPlugin.controller
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @license http://www.webasyst.com/terms/#eula Webasyst
 * @version 1.0.1
 *
 * CHANGELOG:
 * 1.0.1
 *   - Escaping reciever's name
 *
 */
class shopCusmsPluginSendsmsController extends waJsonController
{

    public function execute()
    {
        $from = waRequest::post('from', null, waRequest::TYPE_STRING_TRIM);
        $phone = waRequest::post('phone', null, waRequest::TYPE_STRING_TRIM);
        $text = waRequest::post('smstext', null, waRequest::TYPE_STRING_TRIM);
        $order_id = waRequest::post('order_id', null, waRequest::TYPE_INT);

        if (!$phone) {
            $this->setError(_wp("Invalid phone number"));
        }

        if (!$text) {
            $this->setError(_wp("Invalid text"));
        }

        if ($this->errors) {
            return;
        }

        try {
            $view = wa('shop')->getView();
            $view->assign($this->orderData($order_id));
            $text = $view->fetch("string:$text");
        } catch (SmartyException $e) {
            $this->setError($e->getMessage());
            return;
        }

        if($from) {
            $from = htmlspecialchars($from);
        }

        $sms = new waSMS();
        if ($sms->send($phone, $text, ($from ? $from : null))) {

            $this->logOrder($order_id, $phone, $text);

        } else {
            $this->setError(_wp("SMS gateway error"));
        }

        $this->response = _wp("Message sent");
    }

    /**
     * Записывает в лог операций с заказом факт отправки сообщения
     *
     * @param string|int $order_id
     * @param string $phone
     */
    private function logOrder($order_id, $phone, $message)
    {
        if ($order_id) {
            $Order = new shopOrderModel();
            $order = $Order->getOrder($order_id);

            if ($order) {
                $OrderLog = new shopOrderLogModel();
                $phoneFormatter = new waContactPhoneFormatter();

                $OrderLog->add(array(
                    'order_id'        => $order_id,
                    'action_id'       => '',
                    'before_state_id' => $order['state_id'],
                    'after_state_id'  => $order['state_id'],
                    'text'            => '<i class="icon16 mobile"></i> ' .
                        sprintf(_wp("%s <b>sent SMS to the customer on the number %s</b><p>%s</p>"), wa()->getUser()->getName(), $phoneFormatter->format($phone), htmlspecialchars($message)),
                ));
            }
        }
    }

    protected function orderData($order_id)
    {
        $data = [];

        $data['order'] = (new shopOrderModel)->getById($order_id);
        $workflow = new shopWorkflow();

        try {
            $status = $workflow->getStateById($data['order']['state_id']);
            if ($status) {
                $data['status'] = $status->getName();
            } else {
                $data['status'] = $data['order']['state_id'];
            }
        } catch (waException $e) {
            $data['status'] = '';
        }

        $data['order']['params'] = (new shopOrderParamsModel)->get($order_id, true);
        $data['order']['items'] = (new shopOrderItemsModel)->getItems($order_id);

        // Routing params to generate full URLs to products
        $source = 'backend';
        $storefront_route = null;
        $storefront_domain = null;
        $storefront_route_url = null;
        if (isset($data['order']['params']['storefront'])) {
            $storefront = $data['order']['params']['storefront'];
            if (substr($storefront, -1) === '/') {
                $source = $storefront . '*';
            } else {
                $source = $storefront . '/*';
            }

            $storefront = rtrim($storefront, '/');

            foreach (wa()->getRouting()->getByApp('shop') as $domain => $routes) {
                foreach ($routes as $r) {
                    if (!isset($r['url'])) {
                        continue;
                    }
                    $st = rtrim(rtrim($domain, '/') . '/' . $r['url'], '/.*');
                    if ($st == $storefront) {
                        $storefront_route = $r;
                        $storefront_route_url = $r['url'];
                        $storefront_domain = $domain;
                        break 2;
                    }
                }
            }
        }
        $data['source'] = $source;
        $data['storefront_route'] = $storefront_route;
        $data['storefront_domain'] = $storefront_domain;

        // Products info
        $product_ids = array();
        $sku_ids = array();
        foreach ($data['order']['items'] as $i) {
            $product_ids[$i['product_id']] = 1;
            $sku_ids[$i['sku_id']] = $i['sku_id'];
        }

        $root_url = rtrim(wa()->getRootUrl(true), '/');
        $root_url_len = strlen($root_url);

        $d = $storefront_domain ? 'http://' . $storefront_domain : $root_url;

        $collection = new shopProductsCollection(
            'id/' . join(',', array_keys($product_ids)),
            array('absolute_image_url' => true)
        );
        $products = $collection->getProducts('*,image');
        foreach ($products as &$p) {
            $p['frontend_url'] = wa()->getRouteUrl('shop/frontend/product', array(
                'product_url' => $p['url'],
            ), true, $storefront_domain, $storefront_route_url);
            if (!empty($p['image'])) {
                if ($d !== $root_url) {
                    foreach (array('thumb_url', 'big_url', 'crop_url') as $url_type) {
                        $p['image'][$url_type] = $d . substr($p['image'][$url_type], $root_url_len);
                    }
                }
            }
        }
        unset($p);

        // URLs and products for order items
        foreach ($data['order']['items'] as &$i) {
            if (!empty($i['file_name'])) {
                $i['download_link'] = wa()->getRouteUrl('shop/frontend/myOrderDownload', array(
                    'id'   => $data['order']['id'],
                    'code' => $data['order']['params']['auth_code'],
                    'item' => $i['id'],
                ), true, $storefront_domain, $storefront_route_url);
            }
            if (!empty($products[$i['product_id']])) {
                $i['product'] = $products[$i['product_id']];
                if (isset($skus[$i['sku_id']]) && !empty($skus[$i['sku_id']]['image'])) {
                    $i['product']['image'] = $skus[$i['sku_id']]['image'];
                }
            } else {
                $i['product'] = array();
            }
        }

        unset($i);

        // Shipping info
        if (!empty($data['order']['params']['shipping_id'])) {
            try {
                $data['shipping_plugin'] = shopShipping::getPlugin(ifset($data['order']['params']['shipping_plugin']), $data['order']['params']['shipping_id']);
            } catch (waException $e) {
            }
        }

        // Shipping date and time
        $data['shipping_interval'] = null;
        list($data['shipping_date'], $data['shipping_time_start'], $data['shipping_time_end']) = shopHelper::getOrderShippingInterval($data['order']['params']);
        if ($data['shipping_date']) {
            $data['shipping_interval'] = wa_date('shortdate', $data['shipping_date']) . ', ' . $data['shipping_time_start'] . '–' . $data['shipping_time_end'];
        }

        // Signup url
        if (isset($data['order']['params']['signup_url'])) {
            $data['signup_url'] = $data['order']['params']['signup_url'];
            unset($data['order']['params']['signup_url']);
        }

        // normalize customer
        $customer = ifset($data['customer'], new shopCustomer(ifset($data['order']['contact_id'], 0)));
        if (!($customer instanceof shopCustomer)) {
            if ($customer instanceof waContact) {
                $customer = new shopCustomer($customer->getId());
            } elseif (is_array($customer) && isset($customer['id'])) {
                $customer = new shopCustomer($customer['id']);
            } else {
                $customer = new shopCustomer(ifset($data['order']['contact_id'], 0));
            }
        }

        $customer_data = $customer->getCustomerData();
        foreach (ifempty($customer_data, array()) as $field_id => $value) {
            if ($field_id !== 'contact_id') {
                $customer[$field_id] = $value;
            }
        }
        $data['customer'] = $customer;


        // affiliate bonus
        if (shopAffiliate::isEnabled()) {
            $data['is_affiliate_enabled'] = true;
            $data['add_affiliate_bonus'] = shopAffiliate::calculateBonus($data['order']);
        }

        $data['order_url'] = wa()->getRouteUrl('/frontend/myOrderByCode', array(
            'id'   => $data['order']['id'],
            'code' => ifset($data['order']['params']['auth_code'])
        ), true, $storefront_domain, $storefront_route_url);

        shopHelper::workupOrders($data['order'], true);

        $data['courier'] = null;
        if (!empty($data['order']['params']['courier_id'])) {
            $courier_model = new shopApiCourierModel();
            $data['courier'] = $courier_model->getById($data['order']['params']['courier_id']);
            if (!empty($data['courier'])) {
                foreach ($data['courier'] as $field => $value) {
                    if (strpos($field, 'api_') === 0) {
                        unset($data['courier'][$field]);
                    }
                }
                if (!empty($data['courier']['contact_id'])) {
                    $data['courier']['contact'] = new waContact($data['courier']['contact_id']);
                }
            }
        }

        // empty defaults, to avoid notices
        $empties = $this->getDataEmpties();
        $data = self::arrayMergeRecursive($data, $empties);

        return $data;
    }

    /**
     * @return array
     * @throws waException
     */
    private function getDataEmpties()
    {
        return array(
            'status'               => '',
            'order_url'            => '',
            'signup_url'           => '',
            'add_affiliate_bonus'  => 0,
            'is_affiliate_enabled' => false,
            'order'                => array(
                'id'       => '',
                'currency' => '',
                'items'    => array(),
                'discount' => '',
                'tax'      => '',
                'shipping' => 0,
                'total'    => 0,
                'comment'  => '',
                'params'   => array(
                    'shipping_name'         => '',
                    'shipping_description'  => '',
                    'payment_name'          => '',
                    'payment_description'   => '',
                    'auth_pin'              => '',
                    'storefront'            => '',
                    'ip'                    => '',
                    'user_agent'            => '',
                    'shipping_est_delivery' => '',
                    'tracking_number'       => ''
                )
            ),
            'customer'             => new shopCustomer(0),
            'shipping_address'     => '',
            'billing_address'      => '',
            'action_data'          => array(
                'text'   => '',
                'params' => array(
                    'tracking_number' => ''
                )
            )
        );
    }

    /**
     * @param $merge_to
     * @param $merge_from
     * @return mixed
     */
    public function arrayMergeRecursive(array $merge_to, array $merge_from)
    {
        foreach ($merge_from as $key => $value) {
            if (!array_key_exists($key, $merge_to)) {
                $merge_to[$key] = $value;
            } elseif (is_array($merge_to[$key]) && is_array($merge_from[$key])) {
                $merge_to[$key] = $this->arrayMergeRecursive($merge_to[$key], $merge_from[$key]);
            }
        }
        return $merge_to;
    }
}