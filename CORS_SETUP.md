# CORS Configuration for Digital Ocean Deployment

## Issue
CORS errors when accessing the API from the client application deployed on Digital Ocean.

## Solution Applied (same structure as DBEST)

### 1. Updated `config/cors.php`
- Added the Digital Ocean client URLs to `allowed_origins` (with and without trailing slash)
- Added pattern matching for CPC client domains: `#^https://cpc-client-.*\.ondigitalocean\.app$#`
- `max_age` set to 86400 (24 hours) for better caching
- Exposed headers: Authorization, X-Requested-With, Content-Type, X-Account-Id, X-CSRF-TOKEN
- Laravel's built-in CORS middleware uses this config (no custom middleware)

### 2. Updated `config/sanctum.php`
- Stateful domains include localhost and CPC client URLs (set via `SANCTUM_STATEFUL_DOMAINS` in .env)

### 3. `bootstrap/app.php`
- CSRF validation disabled for `api/*` only (same as DBEST)
- No custom CORS middleware; framework handles CORS via `config/cors.php`

### 4. Environment Variables (Required on Digital Ocean server)
In your `.env` on the server:

```env
SANCTUM_STATEFUL_DOMAINS=https://cpc-client-vj8hx.ondigitalocean.app,https://cpc-client-vj8bx.ondigitalocean.app,localhost,localhost:3000,localhost:5173
FRONTEND_URL=https://cpc-client-vj8hx.ondigitalocean.app
APP_URL=https://cpc-server-4ckzd.ondigitalocean.app
```

### 5. After Deployment
1. Clear and cache config:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```
2. Restart the application on Digital Ocean

### 6. Verify CORS Headers
Response headers should include:
- `Access-Control-Allow-Origin` with your client URL
- `Access-Control-Allow-Methods`
- `Access-Control-Allow-Headers` including `Authorization` and `Content-Type`

Test preflight:
```bash
curl -X OPTIONS "https://cpc-server-4ckzd.ondigitalocean.app/api/accounting/chart-of-accounts" \
  -H "Origin: https://cpc-client-vj8hx.ondigitalocean.app" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization,X-Account-Id" \
  -v
```

You should see `Access-Control-Allow-Origin: https://cpc-client-vj8hx.ondigitalocean.app` in the response.
