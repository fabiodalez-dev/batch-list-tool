<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| The addresses (IPs or CIDR ranges, comma-separated) of the reverse proxies
| or HTTPS terminators allowed to tell the application the client's IP,
| host, scheme, port and path prefix through the X-Forwarded-* headers.
| Laravel's TrustProxies middleware reads this value.
|
| Empty (the default) trusts nobody: the X-Forwarded-* headers are ignored
| and the request is taken as it reaches PHP. That is right whenever the web
| server itself terminates HTTPS (archivetool.eu: LiteSpeed). Set it only
| when a separate proxy sits in front, to that proxy's address. Never '*':
| any client could then forge the host of password-reset links and the IP
| the login throttle and the audit log rely on (security audit 2026-11-13).
*/

return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
