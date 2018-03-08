<?php
/**
 * Provide an information and cross-reference panel for xrefs on a wiki page.
 *
 * <Xref id="1234" datasource="L" species="Homo sapiens">Label</Xref>
 *
 * Copyright (C) 2017  J. David Gladstone Institutes
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Thomas Kelder <thomaskelder@gmail.com>
 * @author Mark A. Hershberger <mah@nichework.com>
 */

namespace WikiPathways;

use Parser;
use Html;

class XrefPanel {
	public static function renderXref( $input, $argv, Parser $parser ) {
		$this->parser->getOutput()->addModules( [ "wpi.XrefPanel" ] );

		return self::getXrefHTML(
			$argv['id'], $argv['datasource'], $input, $argv['species']
		);
	}

	public static function getXrefHTML(
		$xrefID,
		$datasource,
		$label,
		$text,
		$species
	) {
		$html = $text
			  . Html::element( 'img', [
				  'title' => 'Show additional info and linkouts',
				  'class' => 'xrefPanel',
				  'data-xrefID' => $xrefID,
				  'data-dataSource' => $datasource,
				  'data-species' => $species,
				  'data-label' => $label,
				  'src' => SITE_URL . '/extensions/WikiPathways/images/info.png'
			  ] );

		return $html;
	}

	public static function getJsSnippets() {
		global $wpiXrefPanelDisableAttributes, $wpiBridgeUrl,
		$wpiBridgeUseProxy;

		$js = [];

		$js[] = 'XrefPanel_searchUrl = "' . SITE_URL
		. '/index.php?title=Special:SearchPathways'
		. '&doSearch=1&ids=$ID&codes=$DATASOURCE&type=xref";';
		if ( $wpiXrefPanelDisableAttributes ) {
			$js[] = 'XrefPanel_lookupAttributes = false;';
		}

		$bridge = "XrefPanel_dataSourcesUrl = '" . WPI_CACHE_PATH
		. "/datasources.txt';\n";

		if ( $wpiBridgeUrl !== false ) {
			if ( !isset( $wpiBridgeUrl ) || $wpiBridgeUseProxy ) {
				// Point to bridgedb proxy by default
				$bridge .= "XrefPanel_bridgeUrl = '" . WPI_URL
				. '/extensions/bridgedb.php' . "';\n";
			} else {
				$bridge .= "XrefPanel_bridgeUrl = '$wpiBridgeUrl';\n";
			}
		}
		$js[] = $bridge;

		return $js;
	}
}
