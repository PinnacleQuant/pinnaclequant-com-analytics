<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Referrer {

	private static $sources = array(
		array( '/(^|\.)gemini\.google\.com$/', 'Gemini', 'ai' ),
		array( '/(^|\.)google\.[a-z.]+$/', 'Google', 'search' ),
		array( '/(^|\.)bing\.com$/', 'Bing', 'search' ),
		array( '/(^|\.)yahoo\.(com|co\.jp|[a-z.]+)$/', 'Yahoo', 'search' ),
		array( '/(^|\.)duckduckgo\.com$/', 'DuckDuckGo', 'search' ),
		array( '/(^|\.)yandex\.[a-z.]+$/', 'Yandex', 'search' ),
		array( '/(^|\.)baidu\.com$/', 'Baidu', 'search' ),
		array( '/(^|\.)ecosia\.org$/', 'Ecosia', 'search' ),
		array( '/(^|\.)search\.brave\.com$/', 'Brave Search', 'search' ),
		array( '/(^|\.)startpage\.com$/', 'Startpage', 'search' ),
		array( '/(^|\.)qwant\.com$/', 'Qwant', 'search' ),
		array( '/(^|\.)(youtube\.com|youtu\.be)$/', 'YouTube', 'video' ),
		array( '/(^|\.)vimeo\.com$/', 'Vimeo', 'video' ),
		array( '/(^|\.)(twitter\.com|x\.com|t\.co)$/', 'X/Twitter', 'social' ),
		array( '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/', 'Facebook', 'social' ),
		array( '/(^|\.)instagram\.com$/', 'Instagram', 'social' ),
		array( '/(^|\.)threads\.(net|com)$/', 'Threads', 'social' ),
		array( '/(^|\.)(linkedin\.com|lnkd\.in)$/', 'LinkedIn', 'social' ),
		array( '/(^|\.)reddit\.com$/', 'Reddit', 'social' ),
		array( '/(^|\.)(pinterest\.[a-z.]+|pin\.it)$/', 'Pinterest', 'social' ),
		array( '/(^|\.)tiktok\.com$/', 'TikTok', 'social' ),
		array( '/(^|\.)quora\.com$/', 'Quora', 'social' ),
		array( '/(^|\.)snapchat\.com$/', 'Snapchat', 'social' ),
		array( '/(^|\.)(t\.me|telegram\.org)$/', 'Telegram', 'social' ),
		array( '/(^|\.)whatsapp\.com$/', 'WhatsApp', 'social' ),
		array( '/(^|\.)discord\.com$/', 'Discord', 'social' ),
		array( '/(^|\.)medium\.com$/', 'Medium', 'social' ),
		array( '/(^|\.)substack\.com$/', 'Substack', 'social' ),
		array( '/(^|\.)(chatgpt\.com|openai\.com)$/', 'ChatGPT', 'ai' ),
		array( '/(^|\.)perplexity\.ai$/', 'Perplexity', 'ai' ),
		array( '/(^|\.)copilot\.microsoft\.com$/', 'Copilot', 'ai' ),
		array( '/(^|\.)claude\.ai$/', 'Claude', 'ai' ),
		array( '/(^|\.)(you\.com|phind\.com|meta\.ai|grok\.com)$/', 'AI assistant', 'ai' ),
		array( '/(^|\.)stocktwits\.com$/', 'StockTwits', 'social' ),
		array( '/(^|\.)tradingview\.com$/', 'TradingView', 'referral' ),
	);

	private static $click_ids = array(
		'gclid'     => 'Google Ads',
		'gbraid'    => 'Google Ads',
		'wbraid'    => 'Google Ads',
		'dclid'     => 'Google Display',
		'msclkid'   => 'Microsoft Ads',
		'oppref'    => 'ChatGPT Ads',
		'olref'     => 'ChatGPT Ads',
		'ScCid'     => 'Snapchat Ads',
		'tblci'     => 'Taboola',
		'dicbo'     => 'Outbrain',
		'fbclid'    => 'Facebook',
		'ttclid'    => 'TikTok Ads',
		'twclid'    => 'X Ads',
		'li_fat_id' => 'LinkedIn Ads',
		'epik'      => 'Pinterest Ads',
		'rdt_cid'   => 'Reddit Ads',
		'yclid'     => 'Yandex Ads',
	);

	public static function host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = strtolower( $host );
		return preg_replace( '/^(www|m|l|lm|mobile|amp)\./', '', $host );
	}

	public static function classify( $referrer, $landing, $site_host ) {
		$out = array(
			'source'        => 'Direct',
			'source_type'   => 'direct',
			'click_id_type' => '',
			'keyword'       => '',
			'utm_source'    => '',
			'utm_medium'    => '',
			'utm_campaign'  => '',
			'referrer'      => '',
		);

		$query = array();
		$qs    = wp_parse_url( (string) $landing, PHP_URL_QUERY );
		if ( is_string( $qs ) && '' !== $qs ) {
			parse_str( $qs, $query );
		}

		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $key ) {
			if ( isset( $query[ $key ] ) && is_string( $query[ $key ] ) ) {
				$out[ $key ] = PQAnalytics_DB::cut( sanitize_text_field( $query[ $key ] ), 150 );
			}
		}
		if ( isset( $query['utm_term'] ) && is_string( $query['utm_term'] ) ) {
			$out['keyword'] = PQAnalytics_DB::cut( sanitize_text_field( $query['utm_term'] ), 255 );
		}

		$click_network = '';
		foreach ( self::$click_ids as $param => $network ) {
			if ( isset( $query[ $param ] ) && '' !== $query[ $param ] ) {
				$click_network        = $network;
				$out['click_id_type'] = $param;
				break;
			}
		}

		$ref_host = self::host( $referrer );
		$internal = ( '' !== $ref_host && $ref_host === $site_host );
		if ( '' !== $ref_host && ! $internal ) {
			$out['referrer'] = PQAnalytics_DB::cut( (string) $referrer, 1000 );
		}

		if ( '' !== $click_network ) {
			$out['source']      = $click_network;
			$out['source_type'] = ( 'fbclid' === $out['click_id_type'] ) ? 'social' : 'paid';
			if ( 'fbclid' === $out['click_id_type'] && ( 'cpc' === strtolower( $out['utm_medium'] ) || 'paid' === strtolower( $out['utm_medium'] ) ) ) {
				$out['source_type'] = 'paid';
			}
			return $out;
		}

		if ( '' !== $out['utm_source'] ) {
			$medium             = strtolower( $out['utm_medium'] );
			$paid               = in_array( $medium, array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_social', 'display', 'cpm' ), true );
			$out['source']      = ucfirst( $out['utm_source'] );
			$out['source_type'] = $paid ? 'paid' : ( '' !== $medium ? $medium : 'campaign' );
			$out['source_type'] = PQAnalytics_DB::cut( $out['source_type'], 20 );
			return $out;
		}

		if ( '' === $ref_host || $internal ) {
			return $out;
		}
		foreach ( self::$sources as $row ) {
			if ( preg_match( $row[0], $ref_host ) ) {
				$out['source']      = $row[1];
				$out['source_type'] = $row[2];
				return $out;
			}
		}
		$out['source']      = PQAnalytics_DB::cut( $ref_host, 40 );
		$out['source_type'] = 'referral';
		return $out;
	}
}
