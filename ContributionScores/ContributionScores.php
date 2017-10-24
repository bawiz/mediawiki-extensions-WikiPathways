<?php
/** \file
* \brief Contains setup code for the Contribution Scores Extension.
*/

# Not a valid entry point, skip unless MEDIAWIKI is defined
if ( !defined( 'MEDIAWIKI' ) ) {
	echo "Contribution Scores extension";
	exit( 1 );
}

$wgExtensionCredits['specialpage'][] = [
	'name' => 'Contribution Scores',
	'url' => 'http://www.mediawiki.org/wiki/Extension:Contribution_Scores',
	'author' => 'Tim Laqua',
	'description' => 'Polls wiki database for highest user contribution volume',
	'version' => '1.7.1'
];

define( 'CONTRIBUTIONSCORES_PATH', __DIR__ );
define( 'CONTRIBUTIONSCORES_EXTPATH', str_replace( $_SERVER['DOCUMENT_ROOT'], '/', CONTRIBUTIONSCORES_PATH ) );
define( 'CONTRIBUTIONSCORES_MAXINCLUDELIMIT', 50 );
$contribScoreReports = null;

$wgAutoloadClasses['ContributionScores'] = CONTRIBUTIONSCORES_PATH . '/ContributionScores_body.php';
$wgSpecialPages['ContributionScores'] = 'ContributionScores';

if ( version_compare( $wgVersion, '1.11', '>=' ) ) {
	$wgExtensionMessagesFiles['ContributionScores'] = CONTRIBUTIONSCORES_PATH . '/ContributionScores.i18n.php';
} else {
	$wgExtensionFunctions[] = 'efContributionScores';
}

///Message Cache population for versions that did not support $wgExtensionFunctions
function efContributionScores() {
	global $wgMessageCache;

	# Add Messages
	require CONTRIBUTIONSCORES_PATH . '/ContributionScores.i18n.php';
	foreach ( $messages as $key => $value ) {
		  $wgMessageCache->addMessages( $messages[$key], $key );
	}
}

function efContributionScores_addHeadScripts( &$out ) {
	global $wgScriptPath;

	$out->addScript( "<link rel=\"stylesheet\" type=\"text/css\" href=\"$wgScriptPath/skins/wikipathways/TableColor.css\" /> \n" );
	return true;
}
