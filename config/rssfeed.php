<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Service
    |--------------------------------------------------------------------------
    |
    | Set this to false to disable the RSS feed service entirely.
    |
    */
    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | API keys for restricted access, blocked/allowed hosts configuration.
    |
    */
    'key_required' => false,
    'api_keys' => [],
    'allowed_hosts' => [], // Empty = all allowed
    'blocked_hosts' => [],
    'blocked_message' => '<strong>URL blocked</strong>',

    /*
    |--------------------------------------------------------------------------
    | Entry Limits
    |--------------------------------------------------------------------------
    |
    | Number of feed items to process by default and maximum allowed.
    |
    */
    'default_entries' => 5,
    'max_entries' => 10,
    'default_entries_with_key' => 5,
    'max_entries_with_key' => 30,

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific features of the package.
    |
    */
    'singlepage_enabled' => true, // Try to fetch single-page version
    'multipage_enabled' => true,  // Follow next-page links
    'caching_enabled' => false,   // Enable cache
    'xss_filter_enabled' => false, // Enable XSS filtering on output
    'detect_language' => true,    // Detect article language
    'rewrite_relative_urls' => true, // Convert relative URLs to absolute

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Cache configuration for storing feed results.
    |
    */
    'cache_time' => 10, // minutes
    'cache_prefix' => 'rssfeed_',

    /*
    |--------------------------------------------------------------------------
    | Site Config Paths
    |--------------------------------------------------------------------------
    |
    | Paths to site configuration files for content extraction.
    |
    */
    'site_config_path' => __DIR__.'/../site_config/custom',
    'site_config_fallback_path' => __DIR__.'/../site_config/standard',

    /*
    |--------------------------------------------------------------------------
    | HTML Parser Settings
    |--------------------------------------------------------------------------
    |
    | Choose which HTML parser to use. Options: 'libxml', 'html5php'
    |
    */
    'html_parser' => 'html5php',
    'allowed_parsers' => ['libxml', 'html5php'],

    /*
    |--------------------------------------------------------------------------
    | Image Storage Options
    |--------------------------------------------------------------------------
    |
    | Define the path and disk to store images from RSS feed items.
    |
    */
    'image_storage_path' => 'images',
    'spatie_media_type' => 'image',
    'spatie_disk' => 'public',
    'spatie_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Configuration for HTTP requests.
    |
    */
    'http_verify_ssl' => true,
    'http_timeout' => 15,
    'http_retry_times' => 2,
    'http_retry_sleep_ms' => 200,

    /*
    |--------------------------------------------------------------------------
    | HTTP Response Caching
    |--------------------------------------------------------------------------
    |
    | Cache fetched HTML content to reduce redundant HTTP requests.
    | Set to 0 to disable caching of individual HTTP responses.
    |
    */
    'http_cache_time' => 0, // minutes (0 = disabled)

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Minimum delay in milliseconds between consecutive HTTP requests.
    | Set to 0 to disable rate limiting.
    |
    */
    'http_rate_limit_ms' => 500, // milliseconds between requests

    /*
    |--------------------------------------------------------------------------
    | User-Agent Rotation
    |--------------------------------------------------------------------------
    |
    | Rotate user agents to avoid detection. Set rotate_user_agent to false
    | to use a fixed user_agent string.
    |
    */
    'rotate_user_agent' => true,
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',

    /*
    |--------------------------------------------------------------------------
    | Content Extraction Settings
    |--------------------------------------------------------------------------
    |
    | Domain-specific XPath selectors and content cleanup rules.
    |
    */
    'content_selectors' => [],

    'remove_selectors' => [
        // Donation forms and payment
        '.donation-form', '.donate-form', '.donate-box', '.donation-box',
        '[class*="donate"]', '[id*="donate"]', '[class*="donation"]', '[id*="donation"]',
        '.patreon', '.paypal', '.stripe-payment', '.payment-form',

        // Social sharing
        '.share-buttons', '.social-share', '.sharing-buttons', '.share-box',
        '[class*="share"]', '[class*="social"]', '.addthis', '.addtoany',

        // Comments
        '.comments', '#comments', '.comment-section', '.comment-form',
        '[class*="comment"]', '.disqus', '#disqus_thread',

        // Ads and promotions
        '.ad', '.ads', '.advertisement', '.promo', '.promotion',
        '[class*="advert"]', '[id*="ad-"]', '.sponsored', '.affiliate',

        // Sidebars and widgets
        '.sidebar', '.widget', '.widgets', '#sidebar', '[class*="widget"]',

        // Navigation within content
        '.nav', '.navigation', '.menu', '.breadcrumb', '.breadcrumbs',
        '.pagination', '.next-prev', '.post-nav',

        // Footers and headers within article
        '.entry-footer', '.post-footer', '.article-footer',
        '.entry-meta', '.post-meta', '.meta-info', '.byline',
        '.author-box', '.author-info', '.bio',

        // Related posts
        '.related-posts', '.related-articles', '.read-more', '.see-also',
        '[class*="related"]', '.you-may-like',

        // Newsletter signup
        '.newsletter', '.subscribe', '.subscription', '.mailchimp',
        '[class*="newsletter"]', '[class*="subscribe"]',

        // Other non-content
        '.tags', '.tag-cloud', '.categories-list', '.post-tags',
        '.print-button', '.pdf-button', '.download-button',
        '.vote', '.rating', '.thumbs-up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Selector
    |--------------------------------------------------------------------------
    |
    | When a domain-specific selector is not defined, these XPath selectors
    | are tried in order to extract main article content.
    |
    */
    'default_selector' => implode(' | ', [
        '//article',
        '//div[@id="content"]',
        '//div[contains(@class, "entry-content")]',
        '//div[contains(@class, "post-content")]',
        '//div[contains(@class, "article-content")]',
        '//div[contains(@class, "main-content")]',
        '//div[contains(@class, "post-body")]',
        '//section[contains(@class, "article-body")]',
        '//div[contains(@class, "content-area")]',
        '//div[contains(@class, "blog-post-content")]',
        '//div[contains(@class, "post-inner")]',
        '//div[contains(@class, "main-article")]',
        '//div[contains(@class, "article-body")]',
        '//div[contains(@class, "story-content")]',
        '//div[contains(@class, "entry-body")]',
        '//div[contains(@class, "main-wrapper")]',
    ]),

    /*
    |--------------------------------------------------------------------------
    | Readability Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the Readability content extraction algorithm.
    |
    */
    'readability' => [
        'light_clean' => true,  // Preserve more content (images, embeds)
        'convert_links_to_footnotes' => false,
        'revert_forced_paragraphs' => true,
        'strip_unlikely_elements' => true,
        'weight_classes' => true,
        'clean_conditionally' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprints
    |--------------------------------------------------------------------------
    |
    | HTML fingerprints used to detect specific CMS platforms and apply
    | appropriate site configurations automatically.
    |
    */
    'fingerprints' => [
        '<meta name="generator" content="WordPress' => ['hostname' => 'fingerprint.wordpress.com', 'head' => true],
        '<meta content=\'blogger\' name=\'generator\'' => ['hostname' => 'fingerprint.blogspot.com', 'head' => true],
        '<meta name="generator" content="Blogger"' => ['hostname' => 'fingerprint.blogspot.com', 'head' => true],
        '<meta data-rh="true" property="al:ios:app_name" content="Medium"/>' => ['hostname' => 'fingerprint.medium.com', 'head' => true],
        '<link rel="stylesheet" type="text/css" href="https://substackcdn.com/min/main.css' => ['hostname' => 'fingerprint.substack.com', 'head' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML Purifier Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for HTML sanitization when XSS filtering is enabled.
    |
    */
    'html_purifier' => [
        'allowed_elements' => 'p,b,a[href],i,em,strong,ul,ol,li,br,img[src|alt],h1,h2,h3,h4,h5,h6,blockquote,cite,code,pre,table,thead,tbody,tr,td,th',
        'allowed_css_properties' => 'font,font-size,font-weight,font-style,font-family,text-decoration,color,background-color,text-align',
        'remove_inline_styles' => false, // Set to true to strip all inline styles
    ],
];
