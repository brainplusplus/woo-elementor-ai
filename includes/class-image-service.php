<?php
namespace WooElementorAI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Image_Service {

    public function resolve_image( string $keywords ): ?array {
        if ( empty( $keywords ) ) {
            return null;
        }

        $log = \WooElementorAI\Log_Service::get_instance();

        $settings_obj = new Settings();
        $settings     = $settings_obj->get_settings();
        $source       = $settings['image_source'] ?? 'none';

        if ( 'none' === $source ) {
            return null;
        }

        $log->log( 'image_resolve', 'info', "Resolving image: keywords='{$keywords}' source={$source}", [ 'keywords' => $keywords, 'source' => $source ] );

        $image_url = null;

        switch ( $source ) {
            case 'unsplash':
                $log->log( 'image_resolve', 'info', "Searching Unsplash for: {$keywords}", [ 'keywords' => $keywords, 'source' => 'unsplash' ] );
                $image_url = $this->search_unsplash( $keywords, $settings['unsplash_api_key'] ?? '' );
                break;
            case 'pexels':
                $log->log( 'image_resolve', 'info', "Searching Pexels for: {$keywords}", [ 'keywords' => $keywords, 'source' => 'pexels' ] );
                $image_url = $this->search_pexels( $keywords, $settings['pexels_api_key'] ?? '' );
                break;
            case 'openai_compatible':
                $log->log( 'image_resolve', 'info', "Generating image via OpenAI compatible for: {$keywords}", [ 'keywords' => $keywords, 'source' => 'openai_compatible' ] );
                $image_url = $this->generate_openai_compatible( $keywords, $settings );
                break;
        }

        if ( ! $image_url ) {
            $log->log( 'image_resolve', 'warning', "No image found for: {$keywords} (source: {$source})", [ 'keywords' => $keywords, 'source' => $source ] );
            return null;
        }

        $log->log( 'image_resolve', 'info', "Found image URL from {$source}: {$image_url}", [ 'url' => $image_url, 'source' => $source ] );

        $result = $this->download_to_media( $image_url, $keywords );

        if ( ! $result ) {
            $log->log( 'image_resolve', 'warning', "Image download failed for: {$image_url}", [ 'url' => $image_url ] );
            return null;
        }

        $log->log( 'image_resolve', 'success', "Image resolved: attachment_id={$result['id']}", [
            'url' => $result['url'],
            'keywords' => $keywords,
        ] );

        return $result;
    }

    public function resolve_images_in_elements( array &$elements ): void {
        foreach ( $elements as &$element ) {
            $this->resolve_in_element( $element );
        }
        unset( $element );
    }

    private function resolve_in_element( array &$element ): void {
        $widget_type = $element['settings']['widgetType'] ?? ( $element['widgetType'] ?? '' );
        $el_type     = $element['elType'] ?? '';

        // Resolve images in widget elements (image, image-box)
        if ( 'widget' === $el_type ) {
            $this->resolve_widget_image( $element, $widget_type );
        }

        // Resolve background images on any element (container, section, column)
        $this->resolve_background_image( $element );

        // Recurse into children
        if ( ! empty( $element['elements'] ) ) {
            foreach ( $element['elements'] as &$child ) {
                $this->resolve_in_element( $child );
            }
            unset( $child );
        }
    }

    /**
     * Resolve image in widget elements.
     * Strategy: check image_search → image.alt → image.title → title/heading text nearby.
     * If image URL is empty/placeholder, search Pexels and download to media library.
     */
    private function resolve_widget_image( array &$element, string $widget_type ): void {
        $settings = &$element['settings'];

        // Skip widgets that don't have images
        $image_widgets = [ 'image', 'image-box', 'image-carousel', 'image-gallery', 'icon-box' ];
        if ( ! in_array( $widget_type, $image_widgets, true ) ) {
            return;
        }

        // Extract search keywords from multiple sources (priority order)
        $keywords = $this->extract_image_keywords( $settings, $widget_type );

        if ( empty( $keywords ) ) {
            return;
        }

        // Check if image already has a valid URL
        if ( $this->has_valid_image_url( $settings ) ) {
            return;
        }

        // Search and download
        $result = $this->resolve_image( $keywords );
        if ( ! $result ) {
            return;
        }

        // Apply based on widget type
        $this->apply_resolved_image( $settings, $result, $widget_type );
    }

    /**
     * Extract keywords for image search from element settings.
     * Priority: image_search → alt → title → site topic fallback
     */
    private function extract_image_keywords( array $settings, string $widget_type ): string {
        // 1. Explicit image_search field (backward compat)
        if ( ! empty( $settings['image_search'] ) ) {
            return $this->clean_search_keywords( $settings['image_search'] );
        }

        // 2. Alt text from image settings
        $image = $settings['image'] ?? [];
        if ( is_array( $image ) ) {
            if ( ! empty( $image['alt'] ) ) {
                return $this->clean_search_keywords( $image['alt'] );
            }
            if ( ! empty( $image['title'] ) ) {
                return $this->clean_search_keywords( $image['title'] );
            }
        }

        // 3. Image-box has title/description that describe the image
        if ( 'image-box' === $widget_type ) {
            if ( ! empty( $settings['title_text'] ) ) {
                return $this->clean_search_keywords( $settings['title_text'] );
            }
        }

        // 4. Icon-box title as fallback
        if ( 'icon-box' === $widget_type ) {
            if ( ! empty( $settings['title_text'] ) ) {
                return $this->clean_search_keywords( $settings['title_text'] );
            }
        }

        return '';
    }

    /**
     * Clean and optimize AI-generated keywords for stock photo search.
     * AI outputs long, multi-language descriptions like "trophies akrilik penghargaan juara piala".
     * Pexels/Unsplash need short (1-4 words), English-only queries for best results.
     */
    private function clean_search_keywords( string $keywords ): string {
        $keywords = trim( $keywords );

        if ( empty( $keywords ) ) {
            return '';
        }

        // Remove common non-English stop words (Indonesian etc.)
        // These appear frequently in AI-generated alt text alongside English
        $stop_words = [
            'dan', 'untuk', 'dengan', 'dari', 'yang', 'di', 'ke', 'pada', 'ini', 'itu',
            'adalah', 'oleh', 'bagi', 'tentang', 'secara', 'sebagai', 'dalam', 'atas',
            'akrilik', 'custom', 'produk', 'jual', 'beli', 'harga', 'murah', 'terbaik',
            'premium', 'terpercaya', 'sejak', 'kualitas', 'indo', 'indonesia',
            'jasa', 'pembuatan', 'pabrik', 'supplier', 'distributor', 'toko', 'online',
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'has',
            'showing', 'displaying', 'featuring', 'featuring', 'showcasing', 'illustration',
            'image', 'photo', 'picture', 'stock', 'background', 'closeup', 'view',
        ];
        $stop_lower = array_map( 'strtolower', $stop_words );

        // Split into words
        $words = preg_split( '/[\s,;|\/\-]+/', $keywords );
        $filtered = [];
        foreach ( $words as $word ) {
            $word = trim( $word );
            $lower = strtolower( $word );
            if ( empty( $word ) || strlen( $word ) < 2 ) continue;
            if ( in_array( $lower, $stop_lower, true ) ) continue;
            $filtered[] = $word;
        }

        // If we still have >4 words, take only the first 4 most meaningful ones
        if ( count( $filtered ) > 4 ) {
            $filtered = array_slice( $filtered, 0, 4 );
        }

        $result = implode( ' ', $filtered );

        // If result is too short after cleaning, fall back to first 3 words of original
        if ( strlen( $result ) < 3 && strlen( $keywords ) >= 3 ) {
            $fallback = array_slice( $words, 0, 3 );
            $result = implode( ' ', $fallback );
        }

        return $result;
    }

    /**
     * Check if settings already have a valid, non-placeholder image URL.
     */
    private function has_valid_image_url( array $settings ): bool {
        $image = $settings['image'] ?? null;

        if ( ! is_array( $image ) ) {
            return false;
        }

        $url = $image['url'] ?? '';

        if ( empty( $url ) ) {
            return false;
        }

        // Reject placeholder URLs
        $placeholders = [
            'example.com',
            'placeholder',
            'via.placeholder',
            'placehold.co',
            'dummyimage',
            'lorempixel',
            'picsum.photos',
        ];

        foreach ( $placeholders as $ph ) {
            if ( false !== stripos( $url, $ph ) ) {
                return false;
            }
        }

        // URL looks valid
        return true;
    }

    /**
     * Apply resolved image to element settings based on widget type.
     */
    private function apply_resolved_image( array &$settings, array $result, string $widget_type ): void {
        $image_data = [
            'url' => $result['url'],
            'id'  => (string) $result['id'],
        ];

        // Clean up image_search if present
        unset( $settings['image_search'] );

        // For image-carousel and image-gallery, add to items array
        if ( 'image-carousel' === $widget_type || 'image-gallery' === $widget_type ) {
            if ( empty( $settings['carousel'] ) && empty( $settings['gallery'] ) ) {
                $settings['gallery'] = [ $image_data ];
            }
            return;
        }

        // For image, image-box, icon-box — set the image
        $settings['image'] = $image_data;
    }

    /**
     * Resolve background images on containers, sections, columns.
     */
    private function resolve_background_image( array &$element ): void {
        $settings = &$element['settings'];

        // Check for explicit background_image_search (backward compat)
        if ( ! empty( $settings['background_image_search'] ) ) {
            $keywords = $settings['background_image_search'];
            $result   = $this->resolve_image( $keywords );
            if ( $result ) {
                $settings['background_image'] = [
                    'url' => $result['url'],
                    'id'  => (string) $result['id'],
                ];
                $settings['background_background'] = 'classic';
            }
            unset( $settings['background_image_search'] );
            return;
        }

        // Check if background_image exists but has no valid URL
        $bg_image = $settings['background_image'] ?? null;
        if ( ! is_array( $bg_image ) ) {
            return;
        }

        $url = $bg_image['url'] ?? '';
        if ( ! empty( $url ) && ! $this->is_placeholder_url( $url ) ) {
            return; // Already has valid image
        }

        // Try to get keywords from alt or title
        $keywords = '';
        if ( ! empty( $bg_image['alt'] ) ) {
            $keywords = $bg_image['alt'];
        } elseif ( ! empty( $settings['background_image_alt'] ) ) {
            $keywords = $settings['background_image_alt'];
        } elseif ( ! empty( $bg_image['title'] ) ) {
            $keywords = $bg_image['title'];
        }

        if ( empty( $keywords ) ) {
            return;
        }

        $result = $this->resolve_image( $keywords );
        if ( $result ) {
            $settings['background_image'] = [
                'url' => $result['url'],
                'id'  => (string) $result['id'],
            ];
            $settings['background_background'] = 'classic';
        }
    }

    /**
     * Check if URL is a placeholder.
     */
    private function is_placeholder_url( string $url ): bool {
        $placeholders = [ 'example.com', 'placeholder', 'via.placeholder', 'placehold.co', 'dummyimage', 'lorempixel', 'picsum.photos' ];
        foreach ( $placeholders as $ph ) {
            if ( false !== stripos( $url, $ph ) ) {
                return true;
            }
        }
        return false;
    }

    private function search_unsplash( string $keywords, string $api_key ): ?string {
        $log = \WooElementorAI\Log_Service::get_instance();
        if ( empty( $api_key ) ) {
            $log->log( 'image_resolve', 'error', 'search_unsplash: API key empty', [ 'keywords' => $keywords ] );
            return null;
        }

        $query = $this->clean_search_keywords( $keywords );
        $log->log( 'image_resolve', 'info', 'search_unsplash: sending request', [
            'original_keywords' => $keywords,
            'cleaned_query' => $query,
        ] );

        $url = add_query_arg( [
            'query'       => $query,
            'per_page'    => 5,
            'orientation' => 'landscape',
        ], 'https://api.unsplash.com/search/photos' );

        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Client-ID ' . $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $log->log( 'image_resolve', 'error', 'search_unsplash: wp_remote_get failed', [
                'error' => $response->get_error_message(),
            ] );
            return null;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );

        $log->log( 'image_resolve', 'info', 'search_unsplash: response received', [
            'http_code' => $http_code,
            'results_count' => is_array( $body ) ? count( $body['results'] ?? [] ) : 0,
            'total' => $body['total'] ?? 0,
            'raw_body_preview' => substr( $raw_body, 0, 500 ),
        ] );

        if ( empty( $body['results'] ) || ! is_array( $body['results'] ) ) return null;

        $idx = array_rand( $body['results'] );
        $photo = $body['results'][ $idx ];

        $url = $photo['urls']['regular'] ?? null;
        if ( ! $url ) return null;

        $download_location = $photo['links']['download_location'] ?? null;
        if ( $download_location ) {
            wp_remote_get( $download_location, [
                'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
                'timeout' => 5,
                'blocking' => false,
            ] );
        }

        return $url;
    }

    private function search_pexels( string $keywords, string $api_key ): ?string {
        $log = \WooElementorAI\Log_Service::get_instance();
        if ( empty( $api_key ) ) {
            $log->log( 'image_resolve', 'error', 'search_pexels: API key empty', [ 'keywords' => $keywords ] );
            return null;
        }

        $query = $this->clean_search_keywords( $keywords );
        $log->log( 'image_resolve', 'info', 'search_pexels: sending request', [
            'original_keywords' => $keywords,
            'cleaned_query' => $query,
            'api_key_present' => ! empty( $api_key ),
        ] );

        $url = add_query_arg( [
            'query'       => $query,
            'per_page'    => 5,
            'orientation' => 'landscape',
            'locale'      => 'en-US',
        ], 'https://api.pexels.com/v1/search' );

        $log->log( 'image_resolve', 'info', 'search_pexels: full URL', [ 'url' => $url ] );

        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $log->log( 'image_resolve', 'error', 'search_pexels: wp_remote_get failed', [
                'error' => $response->get_error_message(),
            ] );
            return null;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );

        $log->log( 'image_resolve', 'info', 'search_pexels: response received', [
            'http_code' => $http_code,
            'photos_count' => is_array( $body ) ? count( $body['photos'] ?? [] ) : 0,
            'total_results' => $body['total_results'] ?? 0,
            'raw_body_preview' => substr( $raw_body, 0, 500 ),
        ] );

        if ( empty( $body['photos'] ) || ! is_array( $body['photos'] ) ) return null;

        $idx = array_rand( $body['photos'] );
        return $body['photos'][ $idx ]['src']['large'] ?? null;
    }

    private function generate_openai_compatible( string $keywords, array $settings ): ?string {
        if ( empty( $settings['image_base_url'] ) || empty( $settings['image_api_key'] ) ) return null;

        $url = rtrim( $settings['image_base_url'], '/' ) . $settings['image_endpoint'];

        $response = wp_remote_post( $url, [
            'timeout' => 90,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $settings['image_api_key'],
            ],
            'body' => wp_json_encode( [
                'model'  => $settings['image_model'] ?? 'dall-e-3',
                'prompt' => $keywords . ', high quality, professional web design, clean composition',
                'n'      => 1,
                'size'   => '1024x1024',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'][0]['url'] ?? null;
    }

    private function download_to_media( string $url, string $description ): ?array {
        $log = \WooElementorAI\Log_Service::get_instance();

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            $log->log( 'image_resolve', 'error', 'download_url failed: ' . $tmp->get_error_message(), [ 'url' => $url ] );
            return null;
        }

        $ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
        if ( ! $ext || ! in_array( strtolower( $ext ), [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], true ) ) {
            $ext = 'jpg';
        }

        $file_array = [
            'name'     => sanitize_file_name( 'ai-' . wp_generate_password( 8, false ) . '.' . $ext ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0, $description );

        if ( is_wp_error( $attachment_id ) ) {
            $log->log( 'image_resolve', 'error', 'media_handle_sideload failed: ' . $attachment_id->get_error_message(), [ 'url' => $url ] );
            @unlink( $tmp );
            return null;
        }

        return [
            'id'  => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
        ];
    }
}
