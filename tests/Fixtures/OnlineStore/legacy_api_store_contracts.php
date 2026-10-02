<?php

/*
 * Sanitized structural snapshots of the pre-change Store compatibility API.
 * Values are type markers only: this file contains no captured identities,
 * credentials, tokens, contact details, or production data.
 */
return [
    'meta' => [
        'source' => 'routes/api_store.php',
        'sanitized' => true,
        'value_notation' => 'type markers and field names only',
    ],
    'auth' => [
        'login' => [
            'request' => ['email' => 'string', 'password' => 'string', 'userToken' => 'nullable|string'],
            'success' => ['user' => 'user_payload', 'token' => 'string'],
            'error' => ['message' => 'string'],
        ],
        'check_user' => [
            'request' => ['UserId' => 'scalar'],
            'success' => 'user_payload',
            'error' => ['message' => 'string'],
        ],
        'forgot_password_legacy_insecure' => [
            'request' => ['Email' => 'string'],
            'success' => ['userId' => 'string', 'email' => 'string', 'otp' => 'string', 'message' => 'string'],
        ],
    ],
    'catalog' => [
        'list' => [
            'request_keys' => ['Name', 'MainCategory', 'mainCategoryId'],
            'response' => ['rows' => 'array<product_or_category>', 'paginationInfo' => ['totalRowsCount' => 'integer', 'totalPagesCount' => 'integer']],
        ],
        'item' => [
            'request' => ['itemId' => 'scalar'],
            'response' => 'product_payload',
            'product_keys' => ['id', 'nameAr', 'nameEng', 'nameAbree', 'isShow', 'descriptionAr', 'descriptionEng', 'descriptionAbree', 'videoUrl', 'normailPrice', 'wholesalePrice', 'stock', 'model', 'isNewItem', 'isMoreSales', 'rate', 'manufactureYear', 'discount', 'userIdAdd', 'dateAdd', 'userIdUpdate', 'dateUpdate', 'supCategory', 'normalImagesItems', '_3DImagesItems', 'viewImagesItems', 'itemSizes'],
        ],
    ],
    'orders' => [
        'manage' => [
            'request_keys' => ['details', 'cityId', 'shiplyVillageId', 'address', 'customerName', 'phoneNum1', 'userAddId', 'isWholesale', 'priceDelivery', 'totalPriceWithDiscound', 'totalPriceWithOutDiscound', 'discoundCode'],
            'response' => 'order_payload',
        ],
        'history' => [
            'request_keys' => ['userId', 'statusOrder'],
            'response' => ['rows' => 'array<order_payload>', 'paginationInfo' => ['totalRowsCount' => 'integer', 'totalPagesCount' => 'integer']],
        ],
        'cancel' => ['request_keys' => ['id', 'orderId', 'userId', 'userUpdate'], 'response' => 'order_payload'],
    ],
    'settings' => [
        'request' => [],
        'response' => ['data' => ['id' => 'integer', 'isClose' => 'boolean', 'message' => 'string', 'call' => 'string', 'whatsApp' => 'string', 'instagram' => 'string', 'twitter' => 'string'], 'isSuccess' => 'boolean', 'error' => 'nullable', 'isFailure' => 'boolean'],
    ],
    'comments' => [
        'list' => ['response' => ['rows' => 'array', 'total' => 'integer', 'totalNotFiltered' => 'integer']],
        'manage' => ['response' => ['message' => 'string', 'isSuccess' => 'boolean', 'error' => 'nullable', 'isFailure' => 'boolean']],
    ],
    'cities' => [
        'cities' => ['response' => ['rows' => 'array<city_payload>', 'paginationInfo' => 'pagination_payload']],
        'villages' => ['request' => ['cityId' => 'scalar'], 'response' => ['rows' => 'array<village_payload>', 'paginationInfo' => 'pagination_payload']],
        'delivery_fee' => ['request_keys' => ['villageId', 'shiplyVillageId', 'price'], 'response' => ['deliveryCost' => 'float', 'priceDelivery' => 'float', 'fees' => 'object']],
    ],
    'notifications' => [
        'list' => ['request_keys' => ['userId'], 'response' => ['rows' => 'array<notification_payload>', 'paginationInfo' => 'pagination_payload']],
        'edit' => ['request_keys' => ['id', 'notificationId'], 'response' => ['message' => 'string']],
    ],
];
