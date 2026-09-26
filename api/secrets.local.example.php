<?php
// Opcional. Copiar como api/secrets.local.php en el servidor; nunca versionar el real.
// MP_CLIENT_ID y MP_CLIENT_SECRET van en api/mercadopago.local.php (el de todas las webs).

// Con este valor (Mercado Pago → Tus integraciones → Webhooks) la firma se exige.
// defined('MP_WEBHOOK_SECRET') || define('MP_WEBHOOK_SECRET', '');

// Solo si no se usa "Conectar con Mercado Pago": Access Token de producción cargado a mano.
// defined('MP_ACCESS_TOKEN') || define('MP_ACCESS_TOKEN', 'APP_USR-...');
