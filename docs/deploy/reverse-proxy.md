# Reverse proxy, HTTPS and `TRUSTED_PROXIES`

The application builds its links (password-reset e-mails, redirects, asset URLs) from the address the request carries, and it takes the client's IP from the request too: the login throttle and the audit log rely on it. Behind a reverse proxy, the real address and IP arrive in the `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-Port` and `X-Forwarded-Prefix` headers. Any client can send these headers, so the application believes them only when the request comes from a proxy listed in `TRUSTED_PROXIES`.

## The rule

- **The web server terminates HTTPS itself** (Apache, nginx or LiteSpeed serving PHP directly, no other server in front): leave `TRUSTED_PROXIES` empty. This is the default and the archivetool.eu setup (LiteSpeed, no proxy or CDN in front).
- **A separate reverse proxy or HTTPS terminator sits in front** (an nginx or Apache proxy, a load balancer, a firewall that terminates TLS): set `TRUSTED_PROXIES` to that proxy's address as PHP sees it, for example `TRUSTED_PROXIES=10.20.0.5`. Several addresses or CIDR ranges are comma-separated: `TRUSTED_PROXIES=10.20.0.5,10.20.0.6`. If the proxy runs on the same machine, it is usually `127.0.0.1`.
- **Never `*`.** With `*` any client can make a password-reset e-mail point to a host they choose, and can change the IP the login throttle counts and the audit log records.

After changing `.env`, run `php artisan config:cache` (the deploy script does it).

## What the proxy must do

The proxy must **overwrite** `X-Forwarded-For`, `X-Forwarded-Host` and `X-Forwarded-Proto` with its own values, not append to what the client sent. Otherwise a client's forged value travels through the trusted proxy. For nginx:

```nginx
proxy_set_header Host              $host;
proxy_set_header X-Forwarded-For   $remote_addr;
proxy_set_header X-Forwarded-Host  $host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Port  $server_port;
```

`$remote_addr` replaces the header. `$proxy_add_x_forwarded_for` would append to the client's value, so do not use it here. If the application is mounted in a sub-folder (for example `https://intranet.example/archive/`), the proxy also sets `X-Forwarded-Prefix /archive`.

## `APP_URL`

Set `APP_URL` to the final address users type to reach the application, as served by the proxy: scheme, internal host name and any sub-folder, for example `APP_URL=https://archive.intranet.example`. Mail sent from scheduled jobs and queued work, where there is no request to read the address from, uses this value.

## Checking it

From a browser on the network, open the login page and check that the links and the form action use the address in `APP_URL` over `https`. Then request a password reset for a test account and check that the link in the e-mail points to the same address.
