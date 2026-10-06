<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_UA {

	private static $bots = array(
		'Googlebot'      => 'googlebot|google-inspectiontool|adsbot-google|mediapartners-google|apis-google|google-read-aloud|storebot-google|googleother',
		'Bingbot'        => 'bingbot|adidxbot|bingpreview|microsoftpreview',
		'DuckDuckBot'    => 'duckduckbot|duckduckgo',
		'Yandex'         => 'yandex',
		'Baidu'          => 'baiduspider|baidu',
		'Applebot'       => 'applebot',
		'Yahoo'          => 'slurp',
		'Facebook'       => 'facebookexternalhit|facebookcatalog|meta-externalagent|facebot',
		'Twitter'        => 'twitterbot',
		'LinkedIn'       => 'linkedinbot',
		'Pinterest'      => 'pinterestbot|pinterest/',
		'Discord'        => 'discordbot',
		'Telegram'       => 'telegrambot',
		'Slack'          => 'slackbot|slack-imgproxy',
		'WhatsApp'       => 'whatsapp',
		'GPTBot'         => 'gptbot|chatgpt-user|oai-searchbot',
		'ClaudeBot'      => 'claudebot|anthropic-ai|claude-web',
		'PerplexityBot'  => 'perplexitybot|perplexity-user',
		'CCBot'          => 'ccbot',
		'Bytespider'     => 'bytespider|tiktokspider',
		'Amazonbot'      => 'amazonbot',
		'PetalBot'       => 'petalbot',
		'SemrushBot'     => 'semrushbot',
		'AhrefsBot'      => 'ahrefsbot',
		'MJ12bot'        => 'mj12bot',
		'DotBot'         => 'dotbot',
		'Screaming Frog' => 'screaming frog',
		'Uptime monitor' => 'uptimerobot|pingdom|statuscake|site24x7|betteruptime|freshping|hetrixtools',
		'Lighthouse'     => 'chrome-lighthouse|lighthouse',
		'Headless'       => 'headlesschrome|phantomjs|puppeteer|playwright|selenium|electron',
		'HTTP client'    => 'curl/|wget/|python-requests|python-urllib|go-http-client|okhttp|java/|libwww-perl|httpclient|axios/|node-fetch|scrapy|aiohttp',
		'Bot'            => 'bot\b|crawler|crawl|spider|scraper|fetcher|monitor|validator|feedfetcher|preview',
	);

	public static function parse( $ua ) {
		$ua  = (string) $ua;
		$low = strtolower( $ua );
		$out = array(
			'browser'     => '',
			'browser_ver' => '',
			'os'          => '',
			'device'      => 'desktop',
			'is_bot'      => 0,
			'bot_name'    => '',
		);

		if ( '' === trim( $ua ) ) {
			$out['is_bot']   = 1;
			$out['bot_name'] = 'No user agent';
			return $out;
		}

		foreach ( self::$bots as $name => $pattern ) {
			if ( preg_match( '~(?:' . $pattern . ')~i', $ua ) ) {
				$out['is_bot']   = 1;
				$out['bot_name'] = $name;
				break;
			}
		}

		if ( preg_match( '/Windows NT (\d+\.\d+)/', $ua, $m ) ) {
			$map = array(
				'10.0' => 'Windows 10/11',
				'6.3'  => 'Windows 8.1',
				'6.2'  => 'Windows 8',
				'6.1'  => 'Windows 7',
				'6.0'  => 'Windows Vista',
				'5.1'  => 'Windows XP',
			);
			$out['os'] = isset( $map[ $m[1] ] ) ? $map[ $m[1] ] : 'Windows';
		} elseif ( false !== strpos( $ua, 'iPhone' ) || false !== strpos( $ua, 'iPod' ) ) {
			$out['os']     = 'iOS';
			$out['device'] = 'phone';
			if ( preg_match( '/OS (\d+)[_.](\d+)/', $ua, $m ) ) {
				$out['os'] = 'iOS ' . $m[1] . '.' . $m[2];
			}
		} elseif ( false !== strpos( $ua, 'iPad' ) ) {
			$out['os']     = 'iPadOS';
			$out['device'] = 'tablet';
		} elseif ( false !== strpos( $ua, 'Android' ) ) {
			$out['os']     = 'Android';
			$out['device'] = ( false !== strpos( $ua, 'Mobile' ) ) ? 'phone' : 'tablet';
			if ( preg_match( '/Android (\d+(?:\.\d+)?)/', $ua, $m ) ) {
				$out['os'] = 'Android ' . $m[1];
			}
		} elseif ( false !== strpos( $ua, 'CrOS' ) ) {
			$out['os'] = 'ChromeOS';
		} elseif ( preg_match( '/Mac OS X (\d+)[_.](\d+)/', $ua, $m ) ) {
			$out['os'] = 'macOS ' . $m[1] . '.' . $m[2];
		} elseif ( false !== strpos( $ua, 'Macintosh' ) ) {
			$out['os'] = 'macOS';
		} elseif ( false !== strpos( $ua, 'Linux' ) ) {
			$out['os'] = 'Linux';
		}

		$checks = array(
			array( 'Edge', '/Edg(?:e|A|iOS)?\/(\d+(?:\.\d+)?)/' ),
			array( 'Opera', '/OPR\/(\d+(?:\.\d+)?)/' ),
			array( 'Opera', '/Opera[\/ ](\d+(?:\.\d+)?)/' ),
			array( 'Samsung Internet', '/SamsungBrowser\/(\d+(?:\.\d+)?)/' ),
			array( 'UC Browser', '/UCBrowser\/(\d+(?:\.\d+)?)/' ),
			array( 'Brave', '/Brave\/(\d+(?:\.\d+)?)/' ),
			array( 'Vivaldi', '/Vivaldi\/(\d+(?:\.\d+)?)/' ),
			array( 'Yandex Browser', '/YaBrowser\/(\d+(?:\.\d+)?)/' ),
			array( 'Firefox', '/(?:Firefox|FxiOS)\/(\d+(?:\.\d+)?)/' ),
			array( 'Internet Explorer', '/MSIE (\d+(?:\.\d+)?)/' ),
			array( 'Internet Explorer', '/Trident\/.*rv:(\d+(?:\.\d+)?)/' ),
			array( 'Chrome', '/(?:Chrome|CriOS)\/(\d+(?:\.\d+)?)/' ),
			array( 'Safari', '/Version\/(\d+(?:\.\d+)?).*Safari/' ),
		);
		foreach ( $checks as $check ) {
			if ( preg_match( $check[1], $ua, $m ) ) {
				$out['browser']     = $check[0];
				$out['browser_ver'] = $m[1];
				break;
			}
		}
		if ( '' === $out['browser'] && false !== strpos( $low, 'safari' ) ) {
			$out['browser'] = 'Safari';
		}
		if ( '' === $out['browser'] && ! $out['is_bot'] ) {
			$out['browser'] = 'Other';
		}

		if ( 'desktop' === $out['device'] && preg_match( '/Mobile|Windows Phone|BlackBerry|Opera Mini/i', $ua ) ) {
			$out['device'] = 'phone';
		}

		return $out;
	}
}
