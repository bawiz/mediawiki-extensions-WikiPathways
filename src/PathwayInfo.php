<?php
/**
 * Generates info text for pathway page
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
 * @author Alexander Pico <apico@gladstone.ucsf.edu>
 * @author Mark A. Hershberger <mah@nichework.com>
 */
namespace WikiPathways;

use Exception;
use Parser;
use Linker;

class PathwayInfo extends PathwayData {
	private $parser;

	/**
	 * Get the pathway info text.
	 *
	 * @param Parser $parser guess
	 * @param string $pathway identifier
	 * @param string $type to get
	 * @return string
	 */
	public static function getPathwayInfoText( Parser $parser, $pathway, $type ) {
		global $wgRequest;
		$parser->disableCache();
		try {
			$pathway = Pathway::newFromTitle( $pathway );
			$oldid = $wgRequest->getval( 'oldid' );
			if ( $oldid ) {
				$pathway->setActiveRevision( $oldid );
			}
			$info = new PathwayInfo( $parser, $pathway );
			if ( method_exists( $info, $type ) ) {
				return $info->$type();
			} else {
				throw new Exception( "method PathwayInfo->$type doesn't exist" );
			}
		} catch ( Exception $e ) {
			return "Error: $e";
		}
	}

	public function __construct( Parser $parser, $pathway ) {
		parent::__construct( $pathway );
		$this->parser = $parser;
	}

	/**
	 * Creates a table of all datanodes and their info
	 * @return array
	 */
	public function datanodes() {
		$table = '<table class="wikitable sortable" id="dnTable">';
		$table .= '<tbody><th>Name<th>Type<th>Database reference<th>Comment';
		// style="border:1px #AAA solid;margin:1em 1em 0;background:#F9F9F9"
		$all = $this->getElements( 'DataNode' );
		// Check for uniqueness, based on textlabel and xref
		$nodes = [];
		foreach ( $all as $elm ) {
			$key = $elm['TextLabel'];
			$key .= $elm->Xref['ID'];
			$key .= $elm->Xref['Database'];
			$nodes[(string)$key] = $elm;
		}
		// Create collapse button
		$nrShow = 5;
		$button = "";
		if ( count( $nodes ) > $nrShow ) {
			$expand = "<b>View all...</b>";
			$collapse = "<b>View last " . ( $nrShow ) . "...</b>";
			$button = "<table><td width='51%'> <div onClick='"
					. 'doToggle("dnTable", this, "'.$expand.'", "'.$collapse.'")'
					. "' style='cursor:pointer;color:#0000FF'>"
					. "$expand<td width='45%'></table>";
		}
		// Sort and iterate over all elements
		ksort( $nodes );
		$i = 0;
		foreach ( $nodes as $datanode ) {
			$xref = $datanode->Xref;
			$xid = (string)$xref['ID'];
			$xds = (string)$xref['Database'];
			$link = DataSource::getLinkout( $xid, $xds );
			$id = trim( $xref['ID'] );
			if ( $link ) {
				$l = new Linker();
				$link = $l->makeExternalLink( $link, "$id ({$xref['Database']})" );
			} elseif ( $id != '' ) {
				$link = $id;
				if ( $xref['Database'] != '' ) {
					$link .= ' (' . $xref['Database'] . ')';
				}
			}

			// Add xref info button
			$html = $link;
			if ( $xid && $xds ) {
				$this->parser->getOutput()->addModules( [ "wpi.XrefPanel" ] );
				$html = XrefPanel::getXrefHTML(
					$xid, $xds, $datanode['TextLabel'], $link, $this->getOrganism()
				);
			}

			// Comment Data
			$comment = [];
			$biopaxRef = [];
			foreach ( $datanode->children() as $child ) {
				if ( $child->getName() == 'Comment' ) {
					$comment[] = (string)$child;
				} elseif ( $child->getName() == 'BiopaxRef' ) {
					$biopaxRef[] = (string)$child;
				}
			}

			$doShow = $i++ < $nrShow ? "" : " class='toggleMe'";
			$table .= "<tr$doShow>";
			$table .= '<td>' . $datanode['TextLabel'];
			$table .= '<td class="path-type">' . $datanode['Type'];
			$table .= '<td class="path-dbref">' . $html;
			$table .= "<td class='path-comment'>";

			$table .= self::displayItem( $comment );
			// http://developers.pathvisio.org/ticket/800#comment:9
			// $table .= self::displayItem( $biopaxRef );
		}
		$table .= '</tbody></table>';
		if ( count( $nodes ) == 0 ) { $table = "<cite>No datanodes</cite>";
		}
		return [ $button . $table, 'isHTML' => 1, 'noparse' => 1 ];
	}

	/**
	 * Creates a table of all interactions and their info
	 * @return string
	 */
	public function interactionAnnotations() {
		$table = '<table class="wikitable sortable" id="inTable">';
		$table .= '<tbody><th>Source<th>Target<th>Type<th>Database reference<th>Comment';
		$all = $this->getAllAnnotatedInteractions();

		// Check for uniqueness, based on Source-Target-Type-Xref
		$nodes = [];
		foreach ( $all as $elm ) {
			if ( $elm->getEdge()->Xref['ID'] != "" && $elm->getEdge()->Xref['Database'] != "" ) {
				$key = $elm->getSource()['TextLabel'];
				$key .= $elm->getTarget()['TextLabel'];
				$key .= $elm->getType();
				$key .= $elm->getEdge()->Xref['ID'];
				$key .= $elm->getEdge()->Xref['Database'];
				$nodes[(string)$key] = $elm;
			}
		}
		// Create collapse button
		$nrShow = 5;
		$button = "";
		if ( count( $nodes ) > $nrShow ) {
			$expand = "<b>View all...</b>";
			$collapse = "<b>View last " . ( $nrShow ) . "...</b>";
			$button = "<table><td width='51%'> <div onClick='"
					. 'doToggle("inTable", this, "'.$expand.'", "'
					. $collapse.'")'."' style='cursor:pointer;color:#0000FF'>"
					. "$expand<td width='45%'></table>";
		}
		// Sort and iterate over all elements
		ksort( $nodes );
		$i = 0;
		foreach ( $nodes as $datanode ) {
			$int = $datanode->getEdge();
			$xref = $int->Xref;
			$xid = (string)$xref['ID'];
			$xds = (string)$xref['Database'];
			$link = DataSource::getLinkout( $xid, $xds );
			$id = trim( $xref['ID'] );
			if ( $link ) {
				$l = new Linker();
				$link = $l->makeExternalLink( $link, "$id ({$xref['Database']})" );
			} elseif ( $id != '' ) {
				$link = $id;
				if ( $xref['Database'] != '' ) {
					$link .= ' (' . $xref['Database'] . ')';
				}
			}
			// Add xref info button
			$html = $link;
			if ( $xid && $xds ) {
				$this->parser->getOutput()->addModules( [ "wpi.XrefPanel" ] );
				$html = XrefPanel::getXrefHTML(
					$xid, $xds, $xref['ID'], $link, $this->getOrganism()
				);
			}
			// Comment Data
			$comment = [];
			foreach ( $int->Comment as $child ) {
				if ( $child->getName() == 'Comment' ) {
					$comment[] = (string)$child;
				}
			}
			$doShow = $i++ < $nrShow ? "" : " class='toggleMe'";
			$table .= "<tr$doShow>";
			$table .= '<td class="path-source">' .$datanode->getSource()['TextLabel'];
			$table .= '<td class="path-target" align="center">'.$datanode->getTarget()['TextLabel'];
			$table .= '<td class="path-type" align="center">' .$datanode->getType();
			$table .= '<td class="path-dbref" align="center">' . $html;
			$table .= "<td class='path-comment'>";
			if ( count( $comment ) > 1 ) {
				$table .= "<ul>";
				foreach ( $comment as $c ) {
					$table .= "<li>$c";
				}
				$table .= "</ul>";
			} elseif ( count( $comment ) == 1 ) {
				$table .= $comment[0]."</br>";
			}
		}
		$table .= '</tbody></table>';
		if ( count( $nodes ) == 0 ) { $table = "<cite>No annotated interactions</cite>";
		}
		return [ $button . $table, 'isHTML' => 1, 'noparse' => 1 ];
	}

	protected static function displayItem( $item ) {
		$ret = "";
		if ( count( $item ) > 1 ) {
			$ret .= "<ul>";
			foreach ( $item as $c ) {
				$ret .= "<li>$c";
			}
			$ret .= "</ul>";
		} elseif ( count( $item ) == 1 ) {
			$ret .= $item[0];
		}
		return $ret;
	}

	public function interactions() {
		$interactions = $this->getInteractionsSoft();
		foreach ( $interactions as $ia ) {
			$table .= "\n|-\n";
			$table .= "| {$ia->getNameSoft()}\n";
			$table .= "|";
			$xrefs = $ia->getPublicationXRefs( $this );
			if ( !$xrefs ) { $xrefs = [];
			}
			foreach ( $xrefs as $ref ) {
				$attr = $ref->attributes( 'rdf', true );
				$table .= "<cite>" . $attr['id'] . "</cite>";
			}
		}
		if ( $table ) {
			$table = "=== Interactions ===\n{|class='wikitable'\n" . $table . "\n|}";
		} else {
			$table = "";
		}
		return $table;
	}
}
