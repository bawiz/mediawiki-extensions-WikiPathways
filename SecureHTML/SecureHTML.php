<?php
/**
 * @author Jean-Lou Dupont
 * @package SecureHTML
 * @version @@package-version@@
 * @Id $Id$
 */
// <source lang=php>*/
if ( class_exists( 'StubManager' ) ) {
	$wgExtensionCredits['other'][] = [
		'name'        => 'SecureHTML',
		'version'     => '@@package-version@@',
		'author'      => 'Jean-Lou Dupont',
		'description' => 'Enables secure HTML code on protected pages',
		'url' 		=> 'http://mediawiki.org/wiki/Extension:SecureHTML',
	];

	StubManager::createStub( 'SecureHTML',
								__DIR__.'/SecureHTML.body.php',
								null,
								[ 'ArticleSave', 'ArticleViewHeader' ],
								false,	// no need for logging support
								null,	// tags
								[ 'html', 'shtml' ],
								null,	// no magic words
								null	// no namespace triggering
							 );
} else { echo '[[Extension:SecureHTML]] requires [[Extension:StubManager]] and optionally [[Extension:ParserFunctionsHelper]].';
}
// </source>
