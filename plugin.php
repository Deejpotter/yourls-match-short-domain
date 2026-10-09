<?php
/*
Plugin Name: Match Short Domain to Destination
Plugin URI: https://github.com/Deejpotter/yourls-match-short-domain
Description: Shows each short link on the short domain that matches where it points, and can let the same keyword go to a different page on each short domain. Set up under "Match Short Domain Settings".
Version: 2.0
Author: Daniel Potter
Author URI: https://github.com/Deejpotter
*/

// Author: Daniel Potter
// Date: 09/10/26
// Description: Email providers flag links whose domain doesn't match the site they go to.
// YOURLS shows every short link on its main address (YOURLS_SITE). This plugin uses a list
// of rules, edited on its settings page, each with:
//   - destination site: e.g. example.com (also matches www. and other subdomains)
//   - short domain: e.g. go.example.com, a domain that already serves this YOURLS
//   - keyword prefix (optional): e.g. us-
// 1. Display: a link pointing at the destination site is shown on the short domain
//    (new link box, admin table, API). With a prefix, "us-abc" is shown as "short domain/abc".
// 2. Redirects (only for rules with a prefix): opening "short domain/abc" goes to the
//    "us-abc" link if it exists, otherwise YOURLS behaves as normal.
// Any error inside the plugin is caught and YOURLS carries on as if the plugin wasn't there.
// DP - 09/10/26

// Opened directly in a browser, this file would run outside YOURLS's login and checks, so refuse.
if ( ! defined( 'YOURLS_ABSPATH' ) ) {
	die();
}

// The rules are kept as a YOURLS option, so they can be changed on the settings page without editing code.
define( 'MS_MATCH_DOMAIN_OPTION', 'ms_match_domain_rules' );

// yourls_link: so every place YOURLS shows a short link shows the matching domain.
// get_request: so a shared keyword can lead to a different page on each short domain.
// plugins_loaded: so staff can manage the rules from the admin.
yourls_add_filter( 'yourls_link', 'ms_match_domain_link' );
yourls_add_filter( 'get_request', 'ms_match_domain_request' );
yourls_add_action( 'plugins_loaded', 'ms_match_domain_add_page' );

/**
 * The saved rules (dest, short, prefix). Returns an empty list if nothing is saved yet or the
 * setting is damaged, so the plugin then simply does nothing instead of erroring.
 */
function ms_match_domain_rules() {
	$saved = yourls_get_option( MS_MATCH_DOMAIN_OPTION, array() );
	$rules = array();
	// Skip anything that isn't a complete rule, so a damaged setting can't cause PHP warnings.
	foreach ( is_array( $saved ) ? $saved : array() as $rule ) {
		if ( is_array( $rule ) && ! empty( $rule['dest'] ) && ! empty( $rule['short'] ) ) {
			$rules[] = array(
				'dest'   => (string) $rule['dest'],
				'short'  => (string) $rule['short'],
				'prefix' => isset( $rule['prefix'] ) ? (string) $rule['prefix'] : '',
			);
		}
	}
	return $rules;
}

/**
 * Links are often saved with or without www., so a rule for example.com should cover
 * www.example.com too. True if $host is $site or a subdomain of it.
 */
function ms_match_domain_host_matches( $host, $site ) {
	return $host === $site || substr( $host, -( strlen( $site ) + 1 ) ) === '.' . $site;
}

/**
 * Where a keyword points. Both features need it: display picks the domain from it, and
 * redirects use it to check a prefixed link exists. False if the keyword doesn't exist.
 */
function ms_match_domain_longurl( $keyword ) {
	$longurl = yourls_get_keyword_longurl( $keyword );

	// Without this, a brand-new link shows the wrong domain in the "Your short link" box:
	// YOURLS checked the keyword was free just before saving and still remembers "not found".
	if ( ! $longurl ) {
		$longurl = yourls_get_db()->fetchValue(
			'SELECT `url` FROM `' . YOURLS_DB_TABLE_URL . '` WHERE `keyword` = :keyword',
			array( 'keyword' => $keyword )
		);
	}

	return $longurl ? $longurl : false;
}

/**
 * Purpose: staff copy short links from the admin into emails, so the link they're shown must
 * already use the domain that matches its destination (a mismatch hurts email delivery).
 */
function ms_match_domain_link( $link, $keyword = '' ) {
	try {
		// No keyword means YOURLS wants its own base address (page titles, admin links).
		// Changing that would break the admin, which only works on YOURLS_SITE.
		if ( '' === $keyword ) {
			return $link;
		}

		// Stats links (keyword+) should move to the matching domain too, so their stats pages still work.
		$suffix = ( '+' === substr( $keyword, -1 ) ) ? '+' : '';
		$lookup = rtrim( $keyword, '+' );

		$longurl = ms_match_domain_longurl( $lookup );
		if ( ! $longurl ) {
			return $link;
		}
		$dest_host = strtolower( (string) parse_url( $longurl, PHP_URL_HOST ) );

		foreach ( ms_match_domain_rules() as $rule ) {
			if ( ! ms_match_domain_host_matches( $dest_host, $rule['dest'] ) ) {
				continue;
			}

			// The prefix only exists to keep keywords unique in YOURLS; customers should see "abc".
			// ms_match_domain_request() adds it back when the link is opened.
			$shown = $lookup;
			if ( '' !== $rule['prefix'] && 0 === strpos( $lookup, $rule['prefix'] ) && strlen( $lookup ) > strlen( $rule['prefix'] ) ) {
				$shown = substr( $lookup, strlen( $rule['prefix'] ) );
			}

			// Keep YOURLS's https/http choice, and any folder YOURLS is installed in (e.g. /yourls/),
			// so the plugin also works for installs that aren't at the root of the domain.
			$scheme = parse_url( $link, PHP_URL_SCHEME );
			$path   = (string) parse_url( $link, PHP_URL_PATH );
			$folder = substr( $path, 0, strrpos( $path, '/' ) + 1 );
			return ( $scheme ? $scheme : 'https' ) . '://' . $rule['short'] . ( '' === $folder ? '/' : $folder ) . $shown . $suffix;
		}
	} catch ( \Throwable $e ) {
		// A display problem must never break YOURLS: show the normal link instead.
	}

	return $link;
}

/**
 * Purpose: lets two sites' emails use the same keyword (e.g. go.example.com.au/abc and
 * go.example.com/abc) even though YOURLS only allows each keyword once. On a short domain
 * with a prefix rule, "abc" opens the "us-abc" link if it exists.
 */
function ms_match_domain_request( $request ) {
	try {
		$host = strtolower( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' );
		$host = preg_replace( '/:\d+$/', '', $host ); // a :port would stop the domain matching its rule

		// Stats pages (abc+ / abc+all) should follow the same switch, so split them off the way YOURLS's loader does.
		if ( '' === $host || ! preg_match( '@^(.+?)(\+(all)?)?/?$@', (string) $request, $m ) ) {
			return $request;
		}
		$keyword = $m[1];
		$suffix  = isset( $m[2] ) ? $m[2] : '';

		foreach ( ms_match_domain_rules() as $rule ) {
			if ( '' === $rule['prefix'] || $host !== $rule['short'] ) {
				continue;
			}
			// Someone used "us-abc" directly: it already works, and adding the prefix again would break it.
			if ( 0 === strpos( $keyword, $rule['prefix'] ) ) {
				return $request;
			}
			// Only switch if the prefixed link exists, so older links without a prefixed version keep working.
			if ( ms_match_domain_longurl( $rule['prefix'] . $keyword ) ) {
				return $rule['prefix'] . $keyword . $suffix;
			}
			return $request;
		}
	} catch ( \Throwable $e ) {
		// A problem here must never stop redirects: let YOURLS handle the request as normal.
	}

	return $request;
}

/**
 * So the rules can be changed from the admin (Manage Plugins menu) without server access.
 */
function ms_match_domain_add_page() {
	yourls_register_plugin_page( 'ms_match_domain', 'Match Short Domain Settings', 'ms_match_domain_page' );
}

/**
 * People paste domains in different forms (https://, trailing /, capitals). Normalise them so
 * they compare correctly with real hosts; '' if it isn't a valid domain, so the row is dropped.
 */
function ms_match_domain_clean_host( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '@^https?://@', '', $value );
	$value = rtrim( preg_replace( '@/.*$@', '', $value ), '.' );
	return preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value ) ? $value : '';
}

/**
 * Save the settings form. Incomplete or invalid rows are dropped rather than saved, so a typo
 * can't create a rule that sends links to a broken domain.
 */
function ms_match_domain_save() {
	$dests    = isset( $_POST['dest'] ) ? (array) $_POST['dest'] : array();
	$shorts   = isset( $_POST['short'] ) ? (array) $_POST['short'] : array();
	$prefixes = isset( $_POST['prefix'] ) ? (array) $_POST['prefix'] : array();

	$rules = array();
	foreach ( $dests as $i => $dest ) {
		$dest  = ms_match_domain_clean_host( $dest );
		$short = ms_match_domain_clean_host( isset( $shorts[ $i ] ) ? $shorts[ $i ] : '' );
		// A prefix with characters YOURLS doesn't allow in keywords could never match a real link.
		$prefix = yourls_sanitize_keyword( isset( $prefixes[ $i ] ) ? $prefixes[ $i ] : '' );
		if ( '' !== $dest && '' !== $short ) {
			$rules[] = array( 'dest' => $dest, 'short' => $short, 'prefix' => $prefix );
		}
	}

	yourls_update_option( MS_MATCH_DOMAIN_OPTION, $rules );
	return count( $rules );
}

/**
 * The settings page. The nonce check stops another site tricking a logged-in admin's browser
 * into changing the rules.
 */
function ms_match_domain_page() {
	$message = '';
	if ( isset( $_POST['dest'] ) ) {
		yourls_verify_nonce( 'ms_match_domain' );
		$message = '<p><strong>Saved ' . (int) ms_match_domain_save() . ' rule(s).</strong></p>';
	}

	$nonce = yourls_create_nonce( 'ms_match_domain' );
	$rules = ms_match_domain_rules();
	// Spare empty rows so new rules can be added without extra buttons or JavaScript.
	for ( $i = 0; $i < 3; $i++ ) {
		$rules[] = array( 'dest' => '', 'short' => '', 'prefix' => '' );
	}

	$rows = '';
	foreach ( $rules as $rule ) {
		$rows .= '<tr>'
			. '<td><input type="text" name="dest[]" value="' . yourls_esc_attr( $rule['dest'] ) . '" placeholder="example.com" /></td>'
			. '<td><input type="text" name="short[]" value="' . yourls_esc_attr( $rule['short'] ) . '" placeholder="go.example.com" /></td>'
			. '<td><input type="text" name="prefix[]" value="' . yourls_esc_attr( $rule['prefix'] ) . '" placeholder="us- (optional)" size="10" /></td>'
			. '</tr>';
	}

	echo <<<HTML
		<main>
			<h2>Match Short Domain Settings</h2>
			$message
			<p>Each row: links pointing at the <strong>destination site</strong> (or its www. and other subdomains) are shown on the <strong>short domain</strong>.
			The short domain must already serve this YOURLS install. Links not matching any row keep the main address.</p>
			<p><strong>Keyword prefix</strong> (optional): with <code>us-</code>, a link saved as <code>us-abc</code> is shown as <code>short domain/abc</code>,
			and opening <code>short domain/abc</code> goes to <code>us-abc</code>. If there's no <code>us-abc</code>, it opens <code>abc</code> as normal.
			Leave it blank to only change the displayed domain.</p>
			<p>To remove a row, clear its destination site and save.</p>
			<form method="post">
			<input type="hidden" name="nonce" value="$nonce" />
			<table>
			<thead><tr><th>Destination site</th><th>Short domain</th><th>Keyword prefix</th></tr></thead>
			<tbody>$rows</tbody>
			</table>
			<p><input type="submit" value="Save" class="button" /></p>
			</form>
		</main>
HTML;
}
