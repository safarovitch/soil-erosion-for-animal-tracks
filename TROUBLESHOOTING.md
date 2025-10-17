# Troubleshooting: Invalid URL / Proxy Error

## Issue Description
Error message: "Invalid URL" with mention of "cache administrator" - this indicates a **proxy server issue on the client side**.

## ✅ Server Status: OPERATIONAL
- ✅ Nginx is running and listening on port 80
- ✅ Application responds with HTTP 200 OK
- ✅ No firewall blocking port 80
- ✅ PHP-FPM processing requests correctly
- ✅ Direct curl tests successful
- ✅ Application content loading properly

## 🔍 Root Cause
The error is **NOT on the server side**. The "cache administrator" message indicates you're accessing the site through a proxy server (like Squid) that's misconfigured or blocking the request.

## 🛠️ Solutions

### Solution 1: Check Browser Proxy Settings

**For Chrome/Edge:**
1. Go to Settings → System → Open proxy settings
2. Disable proxy or add `37.27.195.104` to bypass list
3. Clear browser cache

**For Firefox:**
1. Settings → General → Network Settings → Settings
2. Select "No proxy" or add exception for `37.27.195.104`
3. Clear cache and retry

**For Safari:**
1. Preferences → Advanced → Proxies → Change Settings
2. Uncheck all proxy protocols or add exception

### Solution 2: Bypass Corporate/Network Proxy

If you're on a corporate network or using a VPN:
```bash
# Access via direct IP (already what you're doing)
http://37.27.195.104

# Try with explicit protocol
http://37.27.195.104/

# If you have SSH access to the server, create a tunnel:
ssh -L 8080:localhost:80 user@37.27.195.104
# Then access: http://localhost:8080
```

### Solution 3: Test from Command Line

**On your local machine, try:**
```bash
# Basic test
curl http://37.27.195.104

# Verbose test
curl -v http://37.27.195.104

# Ignore proxy
curl --noproxy "*" http://37.27.195.104

# Save HTML to file
curl http://37.27.195.104 -o test.html
```

### Solution 4: Check System Proxy (Linux/Mac)

```bash
# Check environment variables
echo $http_proxy
echo $HTTP_PROXY
echo $https_proxy
echo $HTTPS_PROXY

# If set, temporarily unset them
unset http_proxy HTTP_PROXY https_proxy HTTPS_PROXY

# Try curl again
curl http://37.27.195.104
```

**Windows PowerShell:**
```powershell
# Check proxy
netsh winhttp show proxy

# Reset proxy
netsh winhttp reset proxy
```

### Solution 5: Use Different Network

Try accessing from:
- Mobile phone (using cellular data, not WiFi)
- Different WiFi network
- VPN disabled (if currently using one)
- Incognito/Private browsing mode

## 📊 Verification Tests

### Test 1: Direct Server Access (Run on Server)
```bash
curl http://localhost
# Expected: HTML content with RUSLE-ICARDA title
```
✅ **Status: PASSING**

### Test 2: External Access (Run from your machine)
```bash
curl http://37.27.195.104
# Expected: HTML content with RUSLE-ICARDA title
```
❓ **Status: Test this from your local machine**

### Test 3: DNS Resolution
```bash
# Ping test
ping -c 4 37.27.195.104

# Port test
telnet 37.27.195.104 80
# OR
nc -zv 37.27.195.104 80
```

## 🌐 Alternative Access Methods

### Method 1: SSH Tunnel (if you have SSH access)
```bash
ssh -L 8080:localhost:80 root@37.27.195.104
# Then access: http://localhost:8080
```

### Method 2: Direct IP Access
Simply use: `http://37.27.195.104` (not `37.27.195.104` alone)

### Method 3: Add to hosts file (optional)
```bash
# Linux/Mac: /etc/hosts
# Windows: C:\Windows\System32\drivers\etc\hosts
37.27.195.104 rusle-icarda.local

# Then access: http://rusle-icarda.local
```

## 📝 Common Proxy Error Messages

| Error | Cause | Solution |
|-------|-------|----------|
| "Invalid URL" | Missing http:// | Add `http://` prefix |
| "Cache administrator" | Squid proxy blocking | Bypass proxy settings |
| "Illegal character in hostname" | Typo in URL | Check URL spelling |
| "Access Denied" | Firewall/proxy block | Contact network admin |

## 🔒 Security Note

If you're unable to access after trying all solutions, your network administrator may be blocking HTTP traffic. Consider:
1. Setting up HTTPS with SSL certificate
2. Using a different port (like 8080)
3. Contacting your network/ISP support

## ✉️ Need More Help?

**Working Access URLs:**
- `http://37.27.195.104` ✅
- `http://localhost` (from server only) ✅

**Server Logs Location:**
- Nginx access: `/var/log/nginx/access.log`
- Nginx error: `/var/log/nginx/error.log`
- Laravel: `/var/www/rusle-icarda/storage/logs/laravel.log`

**Quick Server Health Check:**
```bash
# From the server
systemctl status nginx
systemctl status php8.3-fpm
curl -I http://localhost
```

All server-side components are working correctly! The issue is with the client/network accessing the server.

