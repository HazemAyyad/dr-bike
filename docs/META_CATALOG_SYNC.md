# Meta / WhatsApp Catalog Sync

Dr Bike is the source of truth for product names, prices, inventory and images. Meta Catalog is a display and sales channel only; edits made directly in Meta may be overwritten by the next sync.

## Configuration

Create the catalog in Meta Commerce Manager and connect it to the same WhatsApp Business Account used by WhatsApp Cloud API.

```env
META_CATALOG_ID=1014695750934512
META_CATALOG_PUBLIC_URL=https://dr-bike.duosparktech.com/public
WHATSAPP_ACCESS_TOKEN=your_server_side_system_user_token
WHATSAPP_API_VERSION=v25.0
```

Never send the access token to Flutter. The token should be a long-lived System User token with the permissions required by the configured Meta business assets:

- `catalog_management`
- `business_management`
- `whatsapp_business_management`
- `whatsapp_business_messaging`

The System User must have access to catalog `1014695750934512`.

If Meta returns error code `100` with subcode `33` (`Unsupported get/post request`),
the token cannot see the catalog. In Meta Business Settings, open **Users → System
Users**, select the System User that generated the token, choose **Add assets →
Catalogs**, select **Dr Bike Products**, and grant **Manage catalog**. Then generate
a new long-lived token with `catalog_management` and `business_management`.

## Deploy

```bash
php artisan migrate
php artisan config:clear
php artisan cache:clear
php artisan queue:work
```

Keep a queue worker running in production using Supervisor or the hosting control panel. Bulk sync adds one retry-safe job per catalog item.

## API usage

All endpoints require the existing authenticated admin/stock permission:

- `POST /api/meta/catalog/products/{id}/sync` syncs one product. If it has size/color variants, every variant is synced separately.
- `POST /api/meta/catalog/bulk-sync` queues all active products.
- `POST /api/meta/catalog/test-product` with `{"product_id": 123}` performs a real test sync.
- `GET /api/meta/catalog/sync-log` shows request results without exposing credentials.

## Product rules

A product needs an Arabic or English name, a price greater than zero, active display status, and a public HTTPS image. Products without variants use `products.stock`. Products with size/color variants use each `size_colors` price, stock and optional image.

Meta availability is `in stock` when quantity is greater than zero and `out of stock` otherwise. Meta may show availability rather than an exact quantity in some WhatsApp surfaces. Enable **إظهار الكمية داخل وصف المنتج** if the exact local quantity should also be appended to the description.

Local relative images are expanded using `META_CATALOG_PUBLIC_URL` (falling
back to `APP_URL`). It must be the public HTTPS Laravel root. Legacy
`Images/Items/...` images are exposed through Laravel's HTTPS image proxy.
Placeholder or genuinely missing images fail clearly and are not sent.

Retailer IDs are stable:

- Product: `DRBIKE-P-{product_id}`
- Variant: `DRBIKE-V-{size_color_id}`

Never change these identifiers after catalog items have been created.

## Category hierarchy and Product Sets

Meta Catalog does not support Laravel-style nested categories. Dr Bike preserves
the hierarchy using flat Product Sets:

- main category: `اسم التصنيف`
- subcategory: `اسم التصنيف / اسم التصنيف الفرعي`

Products carry stable Meta custom labels (`DRBIKE-C-{id}` and
`DRBIKE-S-{id}`), and Product Sets use dynamic filters for those labels. A
product can therefore belong to its main-category set and to multiple
subcategory sets. Changing a product category or subcategory updates its labels
on the next sync and Meta updates set membership automatically.

Use `POST /api/meta/catalog/sync-hierarchy` to create or update every set and
queue product membership refreshes. `GET /api/meta/catalog/product-sets` returns
the local-to-Meta mapping and errors. When automatic catalog sync is enabled,
category, subcategory, and product-category changes queue the required updates.
Orphaned sets are deleted only after their corresponding local category no
longer exists and no local products remain assigned.
