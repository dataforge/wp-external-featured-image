(function($) {
    'use strict';

    if (!window.XEFIEditorData) {
        return;
    }

    const data = window.XEFIEditorData;
    const supportsFlickr = !!(data.settings && data.settings.supportsFlickr);

    const isHttps = (value) => /^https:\/\//i.test(value);
    const imageExtensions = (data.validation && data.validation.imageExtensions) || ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    const imageExtRegex = new RegExp(`\\.(${imageExtensions.join('|')})$`, 'i');
    const anyExtRegex = /\.[a-z0-9]+$/i;
    const isDirectImage = (value) => {
        try {
            const path = new URL(value).pathname;
            // Accept known image extensions, or paths with no extension at all
            // (CDN/image-proxy URLs). Reject paths whose extension is something else.
            if (imageExtRegex.test(path)) {
                return true;
            }
            return !anyExtRegex.test(path);
        } catch (e) {
            return false;
        }
    };
    const isFlickrUrl = (value) => /^https:\/\/(?:www\.)?flickr\.com\/photos\/[^/]+\/\d+(?:\/|$)/i.test(value);

    let previewContainer = null;
    let currentRequest = null;

    function createPreviewContainer() {
        if (!previewContainer) {
            previewContainer = $('<div class="xefi-preview" style="margin-top: 12px;"></div>');
            $('#xefi-external-url').after(previewContainer);
        }
        return previewContainer;
    }

    function showPreview(imageUrl) {
        const container = createPreviewContainer();
        const img = $('<img>', {
            alt: 'Preview',
            css: { width: '100%', height: 'auto', borderRadius: '4px', border: '1px solid #ddd' }
        }).attr('src', imageUrl);
        container.empty().append(img);
    }

    function showLoading() {
        const container = createPreviewContainer();
        container.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <span>Resolving preview…</span>');
    }

    function hidePreview() {
        if (previewContainer) {
            previewContainer.empty();
        }
    }

    function updatePreview() {
        const url = $('#xefi-external-url').val();

        if (currentRequest) {
            currentRequest.abort();
            currentRequest = null;
        }

        if (!url) {
            hidePreview();
            return;
        }

        if (!isHttps(url)) {
            hidePreview();
            return;
        }

        // Direct image - show immediately
        if (isDirectImage(url)) {
            showPreview(url);
            return;
        }

        // Flickr URL - resolve via API
        if (isFlickrUrl(url)) {
            if (!supportsFlickr) {
                hidePreview();
                return;
            }

            showLoading();

            const postId = $('#post_ID').val() || 0;

            currentRequest = $.ajax({
                url: window.wpApiSettings.root + 'xefi/v1/resolve',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', window.wpApiSettings.nonce);
                },
                data: JSON.stringify({
                    url: url,
                    postId: parseInt(postId, 10)
                }),
                contentType: 'application/json',
                success: function(response) {
                    currentRequest = null;
                    if (response && response.url) {
                        showPreview(response.url);
                    } else {
                        hidePreview();
                    }
                },
                error: function() {
                    currentRequest = null;
                    hidePreview();
                }
            });

            return;
        }

        hidePreview();
    }

    $(document).ready(function() {
        // Update preview on URL change (with debounce)
        let urlTimeout;
        $('#xefi-external-url').on('input', function() {
            clearTimeout(urlTimeout);
            urlTimeout = setTimeout(updatePreview, 400);
        });

        // Initial preview
        updatePreview();
    });

})(jQuery);
