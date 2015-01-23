<?php
/**
 * This file is a part of Cusms plugin for Shopscript-5
 *
 * @package shopCusmsPlugin
 * @author Serge Rodovnichenko <sergerod@syrnik.com>
 * @license http://www.webasyst.com/terms/#eula Webasyst
 * @copyright Syrnik.Com, 2015
 */

/**
 *
 */
class shopCusmsPlugin extends shopPlugin
{
    /**
     * @param array $order Order info
     * @return array
     */
    public function backendOrderHandler($order)
    {
        $view = wa()->getView();

        $phones = isset($order['contact']) ? $this->getPhones($order['contact']) : array();
        $order_id = $order['id'];

        $view->assign(compact('phones', 'order_id'));

        return array("action_link" => $view->fetch("{$this->path}/templates/dialog.html"));
    }

    /**
     * @param array $contact
     * @return array
     */
    private function getPhones($contact)
    {
        $phones = array();
        $result = array();

        // В заказе указан телефон
        if (isset($contact['phone']) && !empty($contact['phone'])) {
            $phones[] = array('value' => $contact['phone']);
        }

        // Телефоны контакта
        if (isset($contact['id']) && !empty($contact['id'])) {
            $oContact = new waContact($contact['id']);
            $contact_phones = $oContact->get('phone');
            $phones = array_merge($phones, $contact_phones);
        }

        $phoneFormatter = new waContactPhoneFormatter();

        foreach ($phones as $phone) {
            if (isset($phone['value']) && !empty($phone['value']) && !isset($result[$phone['value']])) {
                $result[$phone['value']] = $phoneFormatter->format($phone);
            }
        }

        return $result;
    }
}
