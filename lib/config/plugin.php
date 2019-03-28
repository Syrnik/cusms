<?php
return array(
    'name'          => 'SMS to customer',
    'img'           => 'img/sms_icon.png',
    'version'       => '1.1.0',
    'vendor'        => '670917',
    'shop_settings' => false,
    'handlers'      =>
        array(
            'backend_order' => 'backendOrderHandler'
        ),
);
