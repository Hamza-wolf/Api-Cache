# Stream-Proxy Cache (PHP 8)

A lightweight caching proxy that sits between your anime player and one or more upstream APIs  
(HiAnime, VidCloud or any other **itzzzme/anime-api** fork).  
It automatically:

* chooses the fastest/healthy server (`hd-1 / hd-2 / hd-3`);
* falls back to a backup API when the primary one is empty;
* adds CORS and domain-lock headers;
* caches successful **HiAnime** responses in Redis (via **Upstash**);
* returns a clean JSON payload including extra `api_info` metadata.

---

## ✨ Features
* **Smart server priority** &nbsp;— tries the user-requested server first, then the rest.  
* **Dual-API fallback** &nbsp;— primary (HiAnime) → secondary (Backup).  
* **Redis TTL cache** &nbsp;— 10-minute cache for primary links, no cache for backup links.  
* **Simple config block** &nbsp;— all values live at the top of the file—no spelunking required.  
* **Zero dependencies** &nbsp;— only `ext-curl` (built-in) and `ext-redis` (optional).

---

## 📝 Requirements
| Requirement | Notes |
|-------------|-------|
| PHP 8.0 +   | Tested on 8.0/8.1/8.2 |
| cURL ext.   | Built-in on most hosts |
| Redis ext.  | Optional but recommended (enables caching) |
| Upstash     | _Serverless_ Redis used in the default sample |

---

## 🚀 Quick start

1) Clone

git clone https://github.com/<your-user>/stream-proxy.git
cd stream-proxy
2) Copy the file into your server

cp stream-proxy.php /var/www/html/api/stream-proxy.php # adjust path
3) Edit the CONFIG block (top of the file)

nano /var/www/html/api/stream-proxy.php
4) Test

curl 'https://example.com/api/stream-proxy.php?id=naruto-1'    Default to HD-2 and Sub
curl 'https://example.com/api/stream-proxy.php?id=naruto-1&type=sub'
curl 'https://example.com/api/stream-proxy.php?id=naruto-1&server=hd-2&type=Dub'

text

---

## ⚙️ CONFIG options (top of `stream-proxy.php`)

const ALLOWED_ORIGINS = [
'https://example.com',
'https://player.example.com',
];

const REDIS_DSN = 'rediss://default:<PASSWORD>@<HOST>:6379';
const API_HIANIME = 'https://api.your-hianime-api.com/api/stream';
const API_BACKUP = 'https://api.your-backup-api.com/api/stream';

const CACHE_TTL = 600; // seconds
const SERVER_PRIORITY = ['hd-2', 'hd-1', 'hd-3'];

text

* **ALLOWED_ORIGINS** – exact origins (scheme+host) allowed to call the proxy.  
* **REDIS_DSN** – copy/paste from **Upstash → Redis → Connect**.  
* **API_HIANIME / API_BACKUP** – any self-hosted instances of  
  [`itzzzme/anime-api`](https://github.com/itzzzme/anime-api).  
* **CACHE_TTL** – how long successful _HiAnime_ responses are cached.  
* **SERVER_PRIORITY** – default fallback order when the client doesn’t specify `server=`.

---

## 🔌 Endpoint

GET /stream-proxy.php?id={anime-id}[&ep=12345]&type=sub|dub[&server=hd-1|hd-2|hd-3]

text

Returns:

{
"success": true,
"results": {
"streamingLink": { ... },
"...": "..."
},
"api_info": {
"source": "hianime",
"server_used": "hd-2",
"provider": "HiAnime"
}
}

text

*If no upstream returns a link, proxy replies with HTTP 502 and `success:false`.*

---


## 🙏 Special Thanks

| Name | GitHub |
|------|--------|
| **Itzzzme (Sayan)** – original anime API Creator | <https://github.com/itzzzme> |
| **Upstash** – serverless Redis | <https://upstash.com/> |

---

## © Credits

Created with ❤️ by **Hami (Hamza Wolf)**  
<https://github.com/Hamza-wolf>

Released under the MIT License – feel free to fork, star, and improve!
