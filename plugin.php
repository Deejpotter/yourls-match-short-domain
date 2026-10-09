<?php
/*
Plugin Name: Match Short Domain to Destination
Plugin URI: https://github.com/Deejpotter/yourls-match-short-domain
Description: Shows each short link on the short domain that matches where it points, and can let the same keyword go to a different page on each short domain. Set up under "Match Short Domain Settings".
Version: 2.2
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
// 3. New links (only for rules with a prefix): a custom keyword "abc" for a link to the
//    destination site is saved as "us-abc" automatically, so nobody has to remember the prefix.
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
// custom_keyword: so the prefix is added automatically when a link is created.
// plugins_loaded: so staff can manage the rules from the admin.
yourls_add_filter( 'yourls_link', 'ms_match_domain_link' );
yourls_add_filter( 'custom_keyword', 'ms_match_domain_custom_keyword' );
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
 * Purpose: people shouldn't have to remember the prefix. When a link is created with a custom
 * keyword (e.g. "abc") and its destination matches a rule with a prefix (e.g. us-), save it as
 * "us-abc". It's still shown and used as "short domain/abc". Random keywords are left alone:
 * they're unique anyway, so they never need a prefix.
 */
function ms_match_domain_custom_keyword( $keyword, $url = '', $title = '' ) {
	try {
		$dest_host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
		if ( '' === $keyword || '' === $dest_host ) {
			return $keyword;
		}
		foreach ( ms_match_domain_rules() as $rule ) {
			if ( ! ms_match_domain_host_matches( $dest_host, $rule['dest'] ) ) {
				continue;
			}
			// Add the prefix only if the rule has one and the person didn't already type it.
			if ( '' !== $rule['prefix'] && 0 !== strpos( $keyword, $rule['prefix'] ) ) {
				return $rule['prefix'] . $keyword;
			}
			return $keyword;
		}
	} catch ( \Throwable $e ) {
		// A problem here must never stop links being created: keep the keyword as typed.
	}
	return $keyword;
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
 * Check one submitted row. Returns the clean rule, or an error message so the user knows
 * exactly what to fix instead of the row silently disappearing.
 */
function ms_match_domain_check_row( $dest_in, $short_in, $prefix_in ) {
	$dest_in   = trim( (string) $dest_in );
	$short_in  = trim( (string) $short_in );
	$prefix_in = trim( (string) $prefix_in );

	// A fully empty row is the spare "add a rule" row (or a rule being removed): not an error.
	if ( '' === $dest_in && '' === $short_in && '' === $prefix_in ) {
		return null;
	}
	if ( '' === $dest_in ) {
		return 'destination site is missing';
	}
	if ( '' === $short_in ) {
		return 'short domain is missing';
	}

	$dest  = ms_match_domain_clean_host( $dest_in );
	$short = ms_match_domain_clean_host( $short_in );
	if ( '' === $dest ) {
		return 'destination site "' . $dest_in . '" isn\'t a valid domain';
	}
	if ( '' === $short ) {
		return 'short domain "' . $short_in . '" isn\'t a valid domain';
	}

	// A prefix with characters YOURLS doesn't allow in keywords could never match a real link.
	$prefix = yourls_sanitize_keyword( $prefix_in );
	if ( $prefix !== $prefix_in ) {
		return 'keyword prefix "' . $prefix_in . '" can only use characters allowed in short URLs';
	}

	return array( 'dest' => $dest, 'short' => $short, 'prefix' => $prefix );
}

/**
 * Save the settings form. Only valid rows are saved, so a typo can't create a rule that sends
 * links to a broken domain. Invalid rows are returned with their errors so the page can show
 * them, filled in as typed, for fixing.
 */
function ms_match_domain_save() {
	$dests    = isset( $_POST['dest'] ) ? (array) $_POST['dest'] : array();
	$shorts   = isset( $_POST['short'] ) ? (array) $_POST['short'] : array();
	$prefixes = isset( $_POST['prefix'] ) ? (array) $_POST['prefix'] : array();

	$rules  = array();
	$failed = array();
	$row    = 0;
	foreach ( $dests as $i => $dest ) {
		$row++;
		$short  = isset( $shorts[ $i ] ) ? $shorts[ $i ] : '';
		$prefix = isset( $prefixes[ $i ] ) ? $prefixes[ $i ] : '';
		$result = ms_match_domain_check_row( $dest, $short, $prefix );
		if ( is_array( $result ) ) {
			$rules[] = $result;
		} elseif ( is_string( $result ) ) {
			$failed[] = array( 'row' => $row, 'error' => $result, 'dest' => (string) $dest, 'short' => (string) $short, 'prefix' => (string) $prefix );
		}
	}

	yourls_update_option( MS_MATCH_DOMAIN_OPTION, $rules );
	return array( 'saved' => count( $rules ), 'failed' => $failed );
}

/**
 * One row of the settings table.
 */
function ms_match_domain_row_html( $dest, $short, $prefix ) {
	return '<tr>'
		. '<td><input type="text" name="dest[]" value="' . yourls_esc_attr( $dest ) . '" placeholder="e.g. example.com" /></td>'
		. '<td><input type="text" name="short[]" value="' . yourls_esc_attr( $short ) . '" placeholder="e.g. go.example.com" /></td>'
		. '<td><input type="text" name="prefix[]" value="' . yourls_esc_attr( $prefix ) . '" placeholder="optional, e.g. us-" size="12" /></td>'
		. '</tr>';
}

/**
 * The settings page. The nonce check stops another site tricking a logged-in admin's browser
 * into changing the rules.
 */
function ms_match_domain_page() {
	$message = '';
	$failed  = array();
	if ( isset( $_POST['dest'] ) ) {
		yourls_verify_nonce( 'ms_match_domain' );
		$result  = ms_match_domain_save();
		$failed  = $result['failed'];
		$message = '<p><strong>Saved ' . (int) $result['saved'] . ' rule(s).</strong></p>';
		foreach ( $failed as $f ) {
			// Escaped because it repeats what the user typed.
			$message .= '<p style="color:#b00"><strong>Row ' . (int) $f['row'] . ' not saved:</strong> ' . yourls_esc_html( $f['error'] ) . '. It\'s still in the form below so you can fix it.</p>';
		}
	}

	$nonce = yourls_create_nonce( 'ms_match_domain' );

	$rows = '';
	foreach ( ms_match_domain_rules() as $rule ) {
		$rows .= ms_match_domain_row_html( $rule['dest'], $rule['short'], $rule['prefix'] );
	}
	// Rows that failed come back as typed, so nothing the user entered is lost.
	foreach ( $failed as $f ) {
		$rows .= ms_match_domain_row_html( $f['dest'], $f['short'], $f['prefix'] );
	}
	// One spare empty row for the next rule; a new one appears after each save.
	$rows .= ms_match_domain_row_html( '', '', '' );

	echo <<<HTML
		<main>
			<h2>Match Short Domain Settings</h2>
			$message
			<p>Each rule shows links on the right short domain. Fill in the empty row and click <strong>Save</strong>; a new empty row appears for the next rule.
			Links that don't match any rule keep the main address. To remove a rule, clear all its boxes and save.</p>
			<form method="post">
			<input type="hidden" name="nonce" value="$nonce" />
			<table>
			<thead><tr><th>Destination site</th><th>Short domain</th><th>Keyword prefix</th></tr></thead>
			<tbody>$rows</tbody>
			</table>
			<p><input type="submit" value="Save" class="button" /></p>
			</form>
			<h3>What to enter</h3>
			<ul>
			<li><strong>Destination site:</strong> the site the links go to, as a domain only, e.g. <code>example.com</code>. No <code>https://</code> and no <code>/</code> (if you paste them, they're removed). It also covers <code>www.example.com</code> and other subdomains.</li>
			<li><strong>Short domain:</strong> the short link domain to show those links on, e.g. <code>go.example.com</code>. Domain only, as above. It must already open this YOURLS (set up in DNS and your hosting first).</li>
			<li><strong>Keyword prefix</strong> (optional): lets the same keyword go to a different page on each short domain. With <code>us-</code>, a link to the destination site made with keyword <code>abc</code> is saved as <code>us-abc</code> automatically; it's shown as <code>go.example.com/abc</code>, and opening <code>go.example.com/abc</code> goes to <code>us-abc</code>. If there's no <code>us-abc</code>, it opens <code>abc</code> as normal. Leave it blank to only change the displayed domain.</li>
			</ul>
		</main>
HTML;
}
