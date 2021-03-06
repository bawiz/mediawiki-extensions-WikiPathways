<?php
/**
 * Copyright (C) 2017  J. David Gladstone Institutes
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author
 * @author Mark A. Hershberger
 */
namespace WikiPathways;

use Linker;
use Title;

class NewPathways extends \QueryPage {
	public function __construct() {
		parent::__construct( "NewPathwaysPage" );
	}

	/**
	 * @return string
	 */
	public function getName() {
		return "NewPathwaysPage";
	}

	public function isExpensive() {
		# page_counter is not indexed
		return true;
	}

	public function isSyndicated() {
		return false;
	}

	public function getSQL() {
		$dbr = wfGetDB( DB_SLAVE );
		$page = $dbr->tableName( 'page' );
		$recentchanges = $dbr->tableName( 'recentchanges' );

		return
			"SELECT DISTINCT 'Newpathwaypages' as type,
					rc_namespace as namespace,
					page_title as title,
				rc_user as user_id,
				rc_user_text as utext,
				rc_timestamp as value
			FROM $page, $recentchanges
			WHERE page_title=rc_title
			AND rc_new=1
			AND rc_bot=0
			AND rc_namespace=".NS_PATHWAY." ";
	}

	public function formatResult( $skin, $result ) {
		global $wgLang, $wgContLang;

		$titleName = $result->title;
		$titleID = Title::makeTitle( $result->namespace, $result->title );
		try {
			$pathway = Pathway::newFromTitle( $titleID );
			if ( !$pathway->isReadable() || $pathway->isDeleted() ) {
				// Don't display this title when user is not allowed to read
				return '';
			}
			$titleName = $pathway->getSpecies().":".$pathway->getName();
		} catch ( Exception $e ) {
		}
		$title = Title::makeTitle( $result->namespace, $titleName );
		$link = Linker::link(
			$titleID, htmlspecialchars(
				$wgContLang->convert( $title->getBaseText() )
			)
		);
		$nv = "<b>". $wgLang->date( $result->value )
			. "</b> by <b>" . Linker::userLink(
				$result->user_id, $result->utext
			) ."</b>";
		return $wgLang->specialList( $link, $nv );
	}
}
