# CORS Configuration for Digital Ocean Deployment

## Issue
CORS errors when accessing the API from the client application deployed on Digital Ocean.

## Solution Applied (same structure as DBEST)

### 1. `config/cors.php`
- Added Digital Ocean client URLs to `allowed_origins`
- Pattern: `#^https://cpc-client-.*\.ondigitalocean\.app$#` so any `cpc-client-*.ondigitalocean.app` is allowed
- `max_age` => 86400 (24 hours) for preflight caching
- `exposed_headers` includes Authorization, X-Account-Id, Content-Type

### 2. `config/sanctum.php`
- `stateful` domains include production client URLs by default (and from env `SANCTUM_STATEFUL_DOMAINS`)

### 3. `bootstrap/app.php`
- `AddCorsHeaders` middleware prepended so every API response gets CORS headers (including 401/403/422)
- CSRF validation disabled for `api/*` (Bearer token auth, not cookies)

### 4. Environment variables (required on Digital Ocean server)
In the **server** app `.env` on Digital Ocean, set:

```env
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,localhost:5173,https://cpc-client-vj8bx.ondigitalocean.app,https://cpc-client-vj8hx.ondigitalocean.app
FRONTEND_URL=https://cpc-client-vj8bx.ondigitalocean.app
APP_URL=https://cpc-server-4ckzd.ondigitalocean.app
```

(Replace with your actual client and server URLs if different.)

### 5. After deployment (server)
1. Clear and cache config:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```
2. Restart the application on Digital Ocean

### 6. Client (Vite env at BUILD time)
In the **client** app on Digital Ocean, set **build-time** env vars (e.g. in App Platform → Environment Variables):

```env
VITE_LARAVEL_API=https://cpc-server-4ckzd.ondigitalocean.app/api
```

Then **trigger a new build** so the value is embedded. Without this, the built JS will still call `http://localhost:8000/api`.

### 7. Verify CORS
Preflight check:

```bash
curl -X OPTIONS "https://cpc-server-4ckzd.ondigitalocean.app/api/accounting/chart-of-accounts" \
  -H "Origin: https://cpc-client-vj8bx.ondigitalocean.app" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization,X-Account-Id" \
  -v
```

You should see `Access-Control-Allow-Origin: https://cpc-client-vj8bx.ondigitalocean.app` in the response.
