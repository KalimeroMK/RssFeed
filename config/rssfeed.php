<?php
return [
    'content_element_xpaths' => [
        '//div[@class="post-content"]',
        '//div[@class="article-body"]',
        '//div[@class="td-post-content"]',
        '//div[contains(concat(" ", normalize-space(@class), " "), " post-single-content ") and contains(concat(" ", normalize-space(@class), " "), " box ") and contains(concat(" ", normalize-space(@class), " "), " mark-links ")]',
    ],
];