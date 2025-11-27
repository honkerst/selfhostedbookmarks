# Cloudflare Proxy Configuration

This guide explains how to configure Cloudflare proxy (orange cloud) for your del.icio.us Clone installation.

## ✅ Compatibility

**Yes, Cloudflare proxy will work!** The application is fully compatible with Cloudflare's proxy service. Here's what you need to know:

## Required Cloudflare Settings

### 1. SSL/TLS Mode
- **Set to**: `Full` or `Full (strict)`
- **Why**: Required for secure sessions and bookmarklet clipboard access
- **Location**: SSL/TLS → Overview → Encryption mode

### 2. Caching Configuration
**Important**: Disable caching for authenticated pages and API endpoints.

#### Page Rules to Create:
1. **Disable caching for API endpoints**:
   - URL Pattern: `*bookmarks.thoughton.co.uk/api/*`
   - Settings:
     - Cache Level: Bypass
     - Edge Cache TTL: Bypass

2. **Disable caching for authenticated pages**:
   - URL Pattern: `*bookmarks.thoughton.co.uk/*.php`
   - Settings:
     - Cache Level: Bypass
     - Edge Cache TTL: Bypass

3. **Optional - Cache static assets** (CSS, JS, images):
   - URL Pattern: `*bookmarks.thoughton.co.uk/assets/*`
   - Settings:
     - Cache Level: Standard
     - Edge Cache TTL: 4 hours

### 3. Security Headers (Optional but Recommended)
In Cloudflare → Rules → Transform Rules → Modify Response Header:

Add security headers:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`

### 4. Origin Server Configuration
Make sure your origin server accepts connections from Cloudflare IPs:

**Cloudflare IP Ranges:**
- IPv4: https://www.cloudflare.com/ips-v4
- IPv6: https://www.cloudflare.com/ips-v6

Your web server should allow these IPs. If using Apache/Nginx, you may need to configure `.htaccess` or nginx rules.

## How It Works

### Session Cookies
- PHP sessions use cookies for authentication
- Cloudflare passes cookies through unchanged
- Session cookies are configured with `HttpOnly` and `Secure` flags (when HTTPS is enabled)
- This ensures sessions work correctly through Cloudflare

### Bookmarklet
- The bookmarklet opens a popup window to your domain
- Uses the same session cookies for authentication
- Works seamlessly through Cloudflare proxy
- **Requires HTTPS** for clipboard access (Cloudflare provides this)

### API Endpoints
- All API endpoints require session authentication
- Cookies are passed through Cloudflare
- No CORS issues since bookmarklet uses same origin

## Testing Checklist

After enabling Cloudflare proxy:

1. ✅ Test login - should work normally
2. ✅ Test dashboard - should load bookmarks
3. ✅ Test bookmarklet - should open popup and save bookmarks
4. ✅ Test API endpoints - should authenticate correctly
5. ✅ Verify HTTPS is active (lock icon in browser)
6. ✅ Check that caching is disabled for `.php` files

## Troubleshooting

### Sessions not persisting
- Check SSL/TLS mode is set to `Full` or `Full (strict)`
- Verify cookies are being set (check browser DevTools → Application → Cookies)
- Ensure Cloudflare isn't stripping cookie headers

### Bookmarklet not working
- Verify HTTPS is enabled (Cloudflare provides this automatically)
- Check browser console for errors
- Ensure session cookies are being sent (check Network tab)

### API endpoints returning errors
- Verify page rules are set to bypass cache for `/api/*`
- Check that Cloudflare isn't modifying request headers unexpectedly
- Review Cloudflare logs for blocked requests

## Performance Benefits

With Cloudflare proxy enabled, you get:
- ✅ DDoS protection
- ✅ Global CDN for faster asset delivery
- ✅ Free SSL certificate
- ✅ Bot protection
- ✅ Analytics and insights

## Security Considerations

- Session cookies are automatically set with `Secure` flag when HTTPS is enabled
- `HttpOnly` flag prevents JavaScript access to cookies (XSS protection)
- `SameSite=Lax` prevents CSRF attacks while allowing bookmarklet popup

## Notes

- The application automatically detects HTTPS and configures secure cookies
- No code changes needed - works out of the box with Cloudflare
- Session storage is handled by PHP (file-based or configured session handler)
- Database (SQLite) remains on your origin server - not proxied through Cloudflare

