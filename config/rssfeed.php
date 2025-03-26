<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Image Storage Options
    |--------------------------------------------------------------------------
    |
    | Define the path and disk to store images from RSS feed items. If you are
    | not using Spatie Media Library, images will be stored in the folder
    | specified by "image_storage_path".
    |
    */
    'image_storage_path' => 'images',
    'spatie_media_type' => 'image',
    'spatie_disk' => 'public',
    'spatie_enabled' => false, // Set to true if you want to use Spatie Media Library

    /*
    |--------------------------------------------------------------------------
    | Domain-Specific XPath Selectors
    |--------------------------------------------------------------------------
    |
    | Here you can map specific domains to custom XPath selectors for fetching
    | full content from a post. If the post URL belongs to one of these domains,
    | its selector will be used.
    |
    */
    'content_selectors' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Selector
    |--------------------------------------------------------------------------
    |
    | When a domain-specific selector is not defined, the package uses the
    | default selector. This default selector is a union of 100 common XPath
    | expressions used to extract main article content from web pages.
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
        '//div[contains(@class, "content")]',
        '//div[contains(@id, "primary")]',
        '//div[contains(@class, "entry")]',
        '//div[contains(@class, "story-content")]',
        '//div[contains(@class, "main-wrapper")]',
        '//div[contains(@class, "article-wrapper")]',
        '//div[contains(@id, "post")]',
        '//div[contains(@class, "post-wrapper")]',
        '//div[contains(@class, "article-inner")]',
        '//div[contains(@class, "article-body")]',
        '//div[contains(@class, "postArticle-content")]',
        '//div[contains(@class, "c-entry-content")]',
        '//div[contains(@class, "entry-body")]',
        '//div[contains(@class, "post-entry")]',
        '//div[contains(@class, "content-wrapper")]',
        '//div[contains(@class, "story-body")]',
        '//div[contains(@class, "mainstory")]',
        '//div[contains(@class, "articleMain")]',
        '//div[contains(@class, "postMain")]',
        '//div[contains(@class, "postcontent")]',
        '//div[contains(@class, "entryText")]',
        '//div[contains(@class, "main-entry")]',
        '//div[contains(@class, "primary-content")]',
        '//div[contains(@class, "article-text")]',
        '//div[contains(@class, "post-text")]',
        '//div[contains(@class, "content-main")]',
        '//div[contains(@id, "main")]',
        '//div[contains(@id, "article")]',
        '//div[contains(@class, "news-article")]',
        '//div[contains(@class, "articleContainer")]',
        '//div[contains(@class, "entry-container")]',
        '//div[contains(@class, "post-container")]',
        '//div[contains(@class, "entry-content-wrapper")]',
        '//div[contains(@class, "main-article-content")]',
        '//section[contains(@class, "post-content")]',
        '//section[contains(@class, "entry-content")]',
        '//section[contains(@class, "article-content")]',
        '//div[contains(@class, "wrapper-content")]',
        '//div[contains(@class, "articleWrapper")]',
        '//div[contains(@class, "post-wrapper-inner")]',
        '//div[contains(@class, "article-body-wrapper")]',
        '//div[contains(@class, "content-holder")]',
        '//div[contains(@class, "entry-holder")]',
        '//div[contains(@class, "post-holder")]',
        '//div[contains(@class, "main-holder")]',
        '//div[contains(@class, "body-content")]',
        '//div[contains(@class, "body-article")]',
        '//div[contains(@class, "text-content")]',
        '//div[contains(@class, "articleText")]',
        '//div[contains(@class, "postText")]',
        '//div[contains(@class, "mainText")]',
        '//div[contains(@class, "contentArticle")]',
        '//div[contains(@class, "entryArticle")]',
        '//div[contains(@class, "full-article")]',
        '//div[contains(@class, "full-content")]',
        '//div[contains(@class, "main-detail")]',
        '//div[contains(@class, "detail-content")]',
        '//div[contains(@class, "post-detail")]',
        '//div[contains(@class, "article-detail")]',
        '//div[contains(@class, "articleContentContainer")]',
        '//div[contains(@class, "mainArticleContent")]',
        '//article[contains(@class, "post")]',
        '//article[contains(@class, "entry")]',
        '//article[contains(@class, "article")]',
        '//div[contains(@role, "main")]',
        '//div[contains(@id, "main-content-area")]',
        '//div[contains(@id, "primary-content")]',
        '//div[contains(@class, "content-area-inner")]',
        '//div[contains(@class, "articleContent")]',
        '//div[contains(@class, "article_post")]',
        '//div[contains(@class, "mainPost")]',
        '//div[contains(@class, "postArticle")]',
        '//div[contains(@class, "postDetail")]',
        '//div[contains(@class, "entry-detail")]',
        '//div[contains(@class, "blog-entry")]',
        '//div[contains(@class, "single-entry")]',
        '//div[contains(@class, "post-entry-container")]',
        '//div[contains(@class, "content-article")]',
        '//div[contains(@class, "article-block")]',
        '//div[contains(@class, "entry-block")]',
        '//div[contains(@class, "post-block")]',
        '//div[contains(@class, "article-view")]',
        '//div[contains(@class, "entry-view")]',
        '//div[contains(@class, "post-view")]',
        '//div[contains(@class, "main-view")]',
        '//div[contains(@class, "article-main-view")]',
        '//div[contains(@class, "blog-content")]',
        '//div[contains(@class, "entry-content-wrapper")]',
    ]),
];
