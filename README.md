# kalakotra/silverstripe-monitoring-client

Passive monitoring endpoint for SilverStripe 6.  
The admin module periodically **pulls** data from this endpoint - the client does not send anything.

---

## Installation

```bash
composer require kalakotra/silverstripe-monitoring-client
vendor/bin/sake dev/build flush=1
```

---

## ENV Configuration (`.env`)

```dotenv
# Secret key - enter the same key in the Project record on the admin side
MONITORING_SECRET_KEY="min-32-random-chars-hex-or-any"
```

Generate a secure key:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

---

## Endpoint

```
GET https://client-page.com/silverstripe-monitoring/data?key=<MONITORING_SECRET_KEY>
```

### Example Response

```json
{
    "php_version":       "8.2.15",
    "ss_version":        "6.0.3",
    "ss_recipe_version": "6.0.1",
    "page_count":        84,
    "published_count":   79,
    "draft_count":       5,
    "broken_links":      2,
    "object_count":      14832,
    "table_count":       67,
    "member_count":      12,
    "admin_count":       2,
    "environment":       "live",
    "base_url":          "https://vas-sajt.ba/",
    "default_locale":    "bs_BA",
    "php_memory_limit":  "256M",
    "php_max_execution": 30,
    "disk_free_gb":      42.15,
    "disk_total_gb":     100.0,
    "reported_at":       "2025-04-21 14:30:00"
}
```

---

## Security

- The key is validated with `hash_equals()` for timing-attack protection
- If `MONITORING_SECRET_KEY` is not configured, the endpoint returns `500`
- No CMS dependencies - minimal footprint on the client site
